<?php

declare(strict_types=1);

namespace Quittio\Core\Calculation;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Quittio\Core\Codes\RawCode;
use Quittio\Core\Data\AllowanceCharge\DocumentAllowanceCharge;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Data\Lines\InvoiceLine;
use Quittio\Core\Data\Lines\LineVat;
use Quittio\Core\Data\Meta;
use Quittio\Core\Data\Totals\Totals;
use Quittio\Core\Data\Totals\VatBreakdown;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Enums\VatExemptionReasonCode;
use Quittio\Core\Support\Amount;
use Quittio\Core\Support\Decimal;

/**
 * Calculation according to EN 16931.
 *
 * Amounts are rounded half up to two decimals at the defined points only.
 * VAT is calculated per VAT group (category and rate), not per line (BR-CO-17).
 *
 * With Meta::FLAG_DECLARED_AMOUNTS (cancellations and unchanged data of InvoiceBuilder::from()
 * whose declared amounts another system rounded differently) and a matching fingerprint of the
 * amount inputs (DeclaredAmounts), declared line net amounts (BT-131) within 0.02 of quantity ×
 * price ± allowances and charges (PEPPOL-EN16931-R120) and declared VAT category tax amounts
 * (BT-117) less than one currency unit from the recalculation (BR-CO-17) are kept; otherwise
 * the recalculated amounts are used and Meta::FLAG_DECLARED_AMOUNTS_RECALCULATED is set
 * (QUITTIO-DECLARED-AMOUNTS). Data whose amount inputs changed after the amounts were declared
 * (fingerprint mismatch, e.g. a change with with() or in stored JSON) is recalculated completely.
 * A declared VAT total in accounting currency (BT-111, Meta::declaredVatInTaxCurrency()) is kept
 * within 0.01 of round2(BT-110 × exchange rate), e.g. the BT-111 of a partial cancellation
 * aligned with the earlier ones (Corrections\CancellationHistory). In gross price mode the rounding amount (BT-114) follows
 * the amounts finally used, so that the amount due stays the gross amount of the lines.
 * The CalculationPolicy decides whether declared amounts are kept, recalculated or rejected.
 * The flags are read and written as DeclaredAmountsState.
 *
 * @phpstan-type VatGroup array{category: VatCategory, rate: ?BigDecimal, lines: BigDecimal, allowances: BigDecimal, charges: BigDecimal, reasons: list<string>, codes: array<string, VatExemptionReasonCode|RawCode|null>}
 */
final class En16931Calculator implements TotalsCalculator
{
    public function __construct(
        private readonly LineCalculator $lineCalculator = new LineCalculator,
        private readonly GrossPriceConverter $grossPriceConverter = new GrossPriceConverter,
        private readonly CalculationPolicy $policy = new CalculationPolicy,
    ) {}

    /**
     * The same calculator with another policy.
     */
    public function withPolicy(CalculationPolicy $policy): self
    {
        return new self($this->lineCalculator, $this->grossPriceConverter, $policy);
    }

    /**
     * The policy of this calculator.
     */
    public function policy(): CalculationPolicy
    {
        return $this->policy;
    }

    public function calculate(InvoiceData $invoice): InvoiceData
    {
        $currency = $invoice->currency;
        $state = DeclaredAmountsState::fromMeta($invoice->meta);
        $flagged = $state->isDeclared();
        $declared = $flagged && DeclaredAmounts::isTrusted($invoice);
        $deviates = false;
        // Recalculate ignores declared amounts, except the VAT of a partial cancellation aligned with the earlier ones.
        $replaced = $declared && $this->policy->declaredAmounts === DeclaredAmountsMode::Recalculate && ! $state->vatAligned;
        if ($replaced) {
            $declared = false;
        }

        [$lines, $gross] = $this->convertGrossPrices($invoice, $declared && $state->vatAligned);
        $lines = $this->lineAmounts($invoice, $lines, $declared, $deviates);
        [$groups, $lineNetSum] = $this->lineGroups($lines);
        // 7.3 Document allowances and charges, both resolved against the line groups.
        $allowances = array_map(fn (DocumentAllowanceCharge $a) => $this->resolveDocumentLevel($a, $groups, $currency), $invoice->allowances);
        $charges = array_map(fn (DocumentAllowanceCharge $c) => $this->resolveDocumentLevel($c, $groups, $currency), $invoice->charges);
        $allowanceSum = $this->addToGroups($allowances, 'allowances', $groups);
        $chargeSum = $this->addToGroups($charges, 'charges', $groups);
        [$breakdown, $vatTotal] = $this->vatBreakdown($invoice, $groups, $declared, $deviates);
        if ($deviates && $this->policy->declaredAmounts === DeclaredAmountsMode::Reject) {
            throw new CalculationException(sprintf('The declared amounts of invoice "%s" differ from the recalculation by more than the tolerances of EN 16931 (declared amounts mode "reject").', $invoice->number));
        }

        $totals = $this->totals($invoice, $lineNetSum, $allowanceSum, $chargeSum, $vatTotal, $gross, $allowances !== [], $charges !== [], $declared ? $state : null);
        $state = match (true) {
            $replaced => $state->withDeclared(false)->withVatInTaxCurrency(null)->withRecalculated(),
            // Sticky: calculating the result again (e.g. when rendering calculated data) finds no
            // deviation from the already recalculated amounts, but the original is still not reversed.
            $declared => $state->withRecalculated($deviates || $state->isRecalculated()),
            // Amount inputs changed after the amounts were declared: a changed document.
            $flagged => DeclaredAmountsState::none(),
            default => $state,
        };

        $result = $invoice->with([
            'lines' => $lines,
            'allowances' => $allowances,
            'charges' => $charges,
            'vatBreakdown' => $breakdown,
            'totals' => $totals,
            'dueDate' => $invoice->dueDate ?? $this->dueDate($invoice),
        ]);

        // Fingerprint of the amount inputs of the result: later changes (with(), stored JSON) make
        // its amounts stale, so that from(), Cancellation and the calculator never keep them.
        return $result->with(['meta' => $state->withFingerprint(DeclaredAmounts::fingerprint($result))->applyTo($invoice->meta)]);
    }

    /**
     * Gross price mode: net prices from the gross prices of the lines, and G, the gross amount
     * of all lines (GrossPriceConverter); otherwise the lines unchanged and no G.
     *
     * @return array{list<InvoiceLine>, ?BigDecimal}
     */
    private function convertGrossPrices(InvoiceData $invoice, bool $alignedNets): array
    {
        if (! $this->grossPriceConverter->appliesTo($invoice)) {
            return [$invoice->lines, null];
        }

        // The net amounts of a partial cancellation aligned with the earlier ones (taxable amounts of
        // its declared VAT breakdown) replace N = round2(G / (1 + rate)) within 0.01.
        $nets = [];
        if ($alignedNets) {
            foreach ($invoice->vatBreakdown as $entry) {
                $nets[DeclaredAmounts::breakdownKey($entry->category, $entry->rate)] = $entry->taxableAmount->getAmount();
            }
        }
        $converted = $this->grossPriceConverter->convert($invoice, $nets);

        return [$converted['lines'], $converted['gross']];
    }

    /**
     * 7.2 Line amounts; with declared amounts the declared line net amounts within tolerance.
     *
     * @param  list<InvoiceLine>  $lines
     * @return list<InvoiceLine>
     */
    private function lineAmounts(InvoiceData $invoice, array $lines, bool $declared, bool &$deviates): array
    {
        $lines = array_map(fn (InvoiceLine $line): InvoiceLine => $this->lineCalculator->calculate($line, $invoice->currency), $lines);
        if ($declared) {
            foreach ($lines as $index => $line) {
                $lines[$index] = self::declaredLine($line, $invoice->lines[$index] ?? null, $deviates);
            }
        }

        return $lines;
    }

    /**
     * Group sums of the line amounts per VAT category and rate, in order of first appearance,
     * and the sum of all line net amounts (BT-106).
     *
     * @param  list<InvoiceLine>  $lines
     * @return array{array<string, VatGroup>, BigDecimal}
     */
    private function lineGroups(array $lines): array
    {
        $groups = [];
        $lineNetSum = BigDecimal::zero();
        foreach ($lines as $line) {
            $amount = $line->netAmount?->getAmount() ?? BigDecimal::zero();
            $lineNetSum = $lineNetSum->plus($amount);
            $key = $line->vat->groupKey();
            $groups[$key] ??= $this->group($line->vat->category, $line->vat->rate);
            $groups[$key]['lines'] = $groups[$key]['lines']->plus($amount);
            // BT-120/BT-121 only exist for categories that require them (BR-S-10, BR-Z-10 forbid them).
            if ($line->vat->category->requiresExemptionReason()) {
                self::collectExemptionReason($groups[$key], $line->vat->exemptionReason, $line->vat->exemptionReasonCode);
            }
        }

        return [$groups, $lineNetSum];
    }

    /**
     * Adds resolved document level allowances or charges to their VAT groups and returns their sum.
     *
     * @param  list<DocumentAllowanceCharge>  $entries
     * @param  'allowances'|'charges'  $kind
     * @param  array<string, VatGroup>  $groups
     */
    private function addToGroups(array $entries, string $kind, array &$groups): BigDecimal
    {
        $sum = BigDecimal::zero();
        foreach ($entries as $entry) {
            $value = $entry->amount?->getAmount() ?? BigDecimal::zero();
            $sum = $sum->plus($value);
            $key = LineVat::key($entry->vatCategory, $entry->vatRate);
            $groups[$key] ??= $this->group($entry->vatCategory, $entry->vatRate);
            $groups[$key][$kind] = $groups[$key][$kind]->plus($value);
            $this->takeExemptionReason($groups[$key], $entry);
        }

        return $sum;
    }

    /**
     * 7.4 VAT breakdown. EN 16931 allows one breakdown per category and rate (BR-E-01 …) with
     * one exemption reason: different reason texts of the group are joined, so that no legally
     * required note (e.g. "Gebrauchtgegenstände/Sonderregelung", § 14a (6) UStG) is lost. A code
     * that holds only for part of the group is omitted (QUITTIO-EXEMPTION-CONFLICT).
     *
     * @param  array<string, VatGroup>  $groups
     * @return array{list<VatBreakdown>, BigDecimal}
     */
    private function vatBreakdown(InvoiceData $invoice, array $groups, bool $declared, bool &$deviates): array
    {
        /** @var array<string, VatBreakdown> $declaredBreakdown */
        $declaredBreakdown = [];
        foreach ($declared ? $invoice->vatBreakdown : [] as $entry) {
            $declaredBreakdown[DeclaredAmounts::breakdownKey($entry->category, $entry->rate)] = $entry;
        }
        if ($declaredBreakdown !== [] && count($declaredBreakdown) !== count($groups)) {
            $deviates = true;
        }

        $breakdown = [];
        $vatTotal = BigDecimal::zero();
        foreach ($groups as $group) {
            $taxable = $group['lines']->minus($group['allowances'])->plus($group['charges']);
            $tax = $group['category']->isZeroTax() || $group['rate'] === null
                ? BigDecimal::zero()
                : Decimal::round($taxable->multipliedBy($group['rate'])->dividedBy(100, 10, RoundingMode::HalfUp), 2);
            $entry = $declaredBreakdown[DeclaredAmounts::breakdownKey($group['category'], $group['rate'])] ?? null;
            if ($entry !== null) {
                $declaredTax = $entry->taxAmount->getAmount();
                // Without VAT (categories Z, E, AE, K, G, O, or a rate of 0) BT-117 must be 0 (BR-Z-09, BR-E-09 …).
                $withoutVat = $group['category']->isZeroTax() || $group['rate'] === null || $group['rate']->isZero();
                if ($entry->taxableAmount->getAmount()->isEqualTo($taxable) && ($withoutVat ? $declaredTax->isZero() : self::withinTolerance($declaredTax, $tax))) {
                    $tax = $declaredTax->toScale(2);
                } else {
                    $deviates = true;
                }
            } elseif ($declaredBreakdown !== []) {
                $deviates = true;
            }
            $vatTotal = $vatTotal->plus($tax);

            $breakdown[] = new VatBreakdown(
                taxableAmount: Amount::fromDecimal($taxable, $invoice->currency),
                taxAmount: Amount::fromDecimal($tax, $invoice->currency),
                category: $group['category'],
                rate: $group['category']->requiresRate() ? ($group['rate'] ?? BigDecimal::zero()) : null,
                exemptionReason: $group['reasons'] === [] ? null : implode('; ', $group['reasons']),
                exemptionReasonCode: count($group['codes']) === 1 || $group['reasons'] === [] ? (array_values(array_filter($group['codes']))[0] ?? null) : null,
            );
        }

        return [$breakdown, $vatTotal];
    }

    /**
     * 7.7 Totals; $declared is the state of data whose declared amounts are kept.
     */
    private function totals(InvoiceData $invoice, BigDecimal $lineNetSum, BigDecimal $allowanceSum, BigDecimal $chargeSum, BigDecimal $vatTotal, ?BigDecimal $gross, bool $hasAllowances, bool $hasCharges, ?DeclaredAmountsState $declared): Totals
    {
        $currency = $invoice->currency;
        $netTotal = $lineNetSum->minus($allowanceSum)->plus($chargeSum);
        $grossTotal = $netTotal->plus($vatTotal);
        $prepaid = $invoice->prepaidAmount?->getAmount();
        $rounding = $invoice->roundingAmount?->getAmount();
        // Gross price mode: BT-114 = G − (N + T) with the net and VAT amounts used, also declared ones
        // (e.g. the aligned VAT of a partial cancellation), so that the amount due stays G.
        $grossRounding = $gross?->minus($grossTotal);
        if ($grossRounding !== null && ! $grossRounding->isZero()) {
            $rounding = ($rounding ?? BigDecimal::zero())->plus($grossRounding);
        }
        $amountDue = $grossTotal->minus($prepaid ?? BigDecimal::zero())->plus($rounding ?? BigDecimal::zero());

        // XRechnung extension (BR-DEX-09): amounts collected for third parties are added to the amount due.
        foreach ($invoice->xrechnungExtension->thirdPartyPayments ?? [] as $payment) {
            $amountDue = $amountDue->plus($payment->amount->getAmount());
        }

        return new Totals(
            lineNetSum: Amount::fromDecimal($lineNetSum, $currency),
            netTotal: Amount::fromDecimal($netTotal, $currency),
            grossTotal: Amount::fromDecimal($grossTotal, $currency),
            amountDue: Amount::fromDecimal($amountDue, $currency),
            allowanceSum: $hasAllowances ? Amount::fromDecimal($allowanceSum, $currency) : null,
            chargeSum: $hasCharges ? Amount::fromDecimal($chargeSum, $currency) : null,
            vatTotal: Amount::fromDecimal($vatTotal, $currency),
            vatTotalInTaxCurrency: $this->vatInTaxCurrency($invoice, $vatTotal, $declared),
            prepaidAmount: $prepaid === null ? null : Amount::fromDecimal($prepaid, $currency),
            roundingAmount: $rounding === null || $rounding->isZero() ? null : Amount::fromDecimal($rounding, $currency),
        );
    }

    /**
     * Keeps the declared line net amount (BT-131), also of sub lines, if it is within 0.02 of the
     * unrounded line formula (as PEPPOL-EN16931-R120 checks it).
     */
    private static function declaredLine(InvoiceLine $calculated, ?InvoiceLine $declared, bool &$deviates): InvoiceLine
    {
        if ($declared === null) {
            return $calculated;
        }

        $subLines = $calculated->subLines;
        $subSum = null;
        if ($subLines !== [] && count($subLines) === count($declared->subLines)) {
            $subSum = BigDecimal::zero();
            foreach ($subLines as $index => $sub) {
                $subLines[$index] = self::declaredLine($sub, $declared->subLines[$index], $deviates);
                $subSum = $subSum->plus($subLines[$index]->netAmount?->getAmount() ?? BigDecimal::zero());
            }
        }

        $amount = $declared->netAmount?->getAmount();
        $net = $calculated->netAmount;
        if ($amount !== null && $net !== null && ! $amount->isEqualTo($net->getAmount())) {
            // A line with sub lines may state the sum of its sub lines (BR-DEX-02), e.g. a group
            // with quantity 0 in the XRechnung extension.
            if (self::withinLineTolerance($amount, $calculated) || ($subSum !== null && $amount->isEqualTo($subSum))) {
                $net = Amount::fromDecimal($amount, $net->getCurrency()->getCurrencyCode());
            } else {
                $deviates = true;
            }
        }

        return $calculated->with(['netAmount' => $net, 'subLines' => $subLines]);
    }

    /**
     * Less than one currency unit apart, as BR-CO-17 accepts for BT-117.
     */
    private static function withinTolerance(BigDecimal $declared, BigDecimal $calculated): bool
    {
        return $declared->minus($calculated)->abs()->isLessThan(1);
    }

    /**
     * At most 0.02 from quantity × (price / base quantity) ± allowances and charges, unrounded.
     * EN 16931 has no tolerance for BT-131; PEPPOL-EN16931-R120 accepts 0.02.
     */
    private static function withinLineTolerance(BigDecimal $declared, InvoiceLine $calculated): bool
    {
        $exact = LineCalculator::exactAmount($calculated) ?? $calculated->netAmount?->getAmount();

        return $exact !== null && $declared->minus($exact)->abs()->isLessThanOrEqualTo(DeclaredAmounts::LINE_TOLERANCE);
    }

    /**
     * @return array{category: VatCategory, rate: ?BigDecimal, lines: BigDecimal, allowances: BigDecimal, charges: BigDecimal, reasons: list<string>, codes: array<string, VatExemptionReasonCode|RawCode|null>}
     */
    private function group(VatCategory $category, ?BigDecimal $rate): array
    {
        return [
            'category' => $category,
            'rate' => $rate,
            'lines' => BigDecimal::zero(),
            'allowances' => BigDecimal::zero(),
            'charges' => BigDecimal::zero(),
            'reasons' => [],
            'codes' => [],
        ];
    }

    /**
     * Exemption reason of a document allowance or charge, used only if the lines of the
     * VAT group provide none (e.g. an exempt charge on an otherwise standard rated invoice).
     *
     * @param  array{reasons: list<string>, codes: array<string, VatExemptionReasonCode|RawCode|null>, ...}  $group
     */
    private function takeExemptionReason(array &$group, DocumentAllowanceCharge $entry): void
    {
        if (! $entry->vatCategory->requiresExemptionReason() || $group['reasons'] !== [] || $group['codes'] !== []) {
            return;
        }

        self::collectExemptionReason($group, $entry->exemptionReason, $entry->exemptionReasonCode);
    }

    /**
     * Distinct reason texts in order of appearance and the codes (by value, "" for a text without code).
     *
     * @param  array{reasons: list<string>, codes: array<string, VatExemptionReasonCode|RawCode|null>, ...}  $group
     */
    private static function collectExemptionReason(array &$group, ?string $reason, VatExemptionReasonCode|RawCode|null $code): void
    {
        $reason = $reason === null || trim($reason) === '' ? null : $reason;
        if ($reason === null && $code === null) {
            return;
        }
        if ($reason !== null && ! in_array($reason, $group['reasons'], true)) {
            $group['reasons'][] = $reason;
        }
        $group['codes'][$code === null ? '' : $code->value] = $code;
    }

    /**
     * BT-92 = input or round2(BT-93 × BT-94 / 100). Without base amount the line sum
     * of the matching VAT group is used; without such lines it is an error, unless the
     * data has no lines at all (draft).
     *
     * @template T of DocumentAllowanceCharge
     *
     * @param  T  $entry
     * @param  array<string, array{lines: BigDecimal, ...}>  $groups
     * @return T
     */
    private function resolveDocumentLevel(DocumentAllowanceCharge $entry, array $groups, string $currency): DocumentAllowanceCharge
    {
        if ($entry->amount !== null) {
            return $entry;
        }

        if ($entry->percent === null) {
            throw new CalculationException('A document allowance or charge requires an amount or a percentage.');
        }

        $base = $entry->baseAmount?->getAmount()
            ?? ($groups[LineVat::key($entry->vatCategory, $entry->vatRate)]['lines'] ?? null)
            // Drafts without lines are calculated with a zero line sum; with lines, a missing group is a mistake.
            ?? ($groups === [] ? BigDecimal::zero() : throw new CalculationException(sprintf(
                'A percentage document allowance or charge (%s %%) without base amount (BT-93/BT-100) needs invoice lines of its VAT category %s and rate %s %%; none were found, so the amount would be 0.00. Pass the base amount or correct the VAT category and rate.',
                $entry->percent,
                $entry->vatCategory->value,
                $entry->vatRate ?? '-',
            )));
        $amount = Decimal::round($base->multipliedBy($entry->percent)->dividedBy(100, 10, RoundingMode::HalfUp), 2);

        return $entry->with([
            'amount' => Amount::fromDecimal($amount, $currency),
            'baseAmount' => Amount::fromDecimal($base, $currency),
        ]);
    }

    /**
     * BT-111 = round2(BT-110 × exchange rate).
     *
     * A declared BT-111 in the totals belongs to the declared BT-110: it is kept only as long as the
     * recalculated BT-110 equals the declared one, otherwise it would be stale. Without an exchange
     * rate (e.g. for data read from XML) it is kept as it is. With declared amounts
     * (Meta::FLAG_DECLARED_AMOUNTS) it, or the one of DeclaredAmountsState::$vatInTaxCurrency, is kept within
     * 0.01 of round2(BT-110 × exchange rate). EN 16931 does not check BT-111 against BT-110 (BR-53
     * requires it, BR-DEC-15 two decimals); 0.01 is what the cumulative rounding of partial
     * cancellations needs (Corrections\CancellationHistory). A VAT total of zero is zero in every
     * currency. InvoiceData::withImpliedExchangeRate() derives the rate from declared totals, e.g.
     * before read data is changed.
     */
    private function vatInTaxCurrency(InvoiceData $invoice, BigDecimal $vatTotal, ?DeclaredAmountsState $declaredAmounts): ?\Brick\Money\Money
    {
        if ($invoice->taxCurrency === null || $invoice->taxCurrency === $invoice->currency) {
            return null;
        }

        $declared = $invoice->totals?->vatTotalInTaxCurrency;
        $declaredVat = $invoice->totals?->vatTotal;
        if ($declared === null
            || $declaredVat === null
            || $declared->getCurrency()->getCurrencyCode() !== $invoice->taxCurrency
            || ! $declaredVat->getAmount()->isEqualTo($vatTotal)) {
            $declared = null;
        }

        $rate = $invoice->meta->exchangeRate;
        if ($rate === null) {
            return $declared ?? ($vatTotal->isZero() ? Amount::fromDecimal($vatTotal, $invoice->taxCurrency) : null);
        }

        $calculated = Amount::fromDecimal($vatTotal->multipliedBy($rate), $invoice->taxCurrency);
        $kept = $declaredAmounts === null ? null : ($declaredAmounts->vatInTaxCurrency ?? $declared?->getAmount());
        if ($kept !== null && $kept->minus($calculated->getAmount())->abs()->isLessThanOrEqualTo(DeclaredAmounts::TAX_CURRENCY_TOLERANCE)) {
            return Amount::fromDecimal($kept, $invoice->taxCurrency);
        }

        return $calculated;
    }

    private function dueDate(InvoiceData $invoice): ?\Quittio\Core\Support\Date
    {
        $days = $invoice->paymentTerms?->dueDays;

        return $days === null ? null : $invoice->issueDate->addDays($days);
    }
}
