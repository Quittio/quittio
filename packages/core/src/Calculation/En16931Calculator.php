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
use Quittio\Core\Data\Totals\Totals;
use Quittio\Core\Data\Totals\VatBreakdown;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Enums\VatExemptionReasonCode;
use Quittio\Core\Support\Amount;
use Quittio\Core\Support\Decimal;

/**
 * Calculation according to EN 16931 (specification section 7).
 *
 * Amounts are rounded half up to two decimals at the defined points only.
 * VAT is calculated per VAT group (category and rate), not per line (BR-CO-17).
 */
final class En16931Calculator implements TotalsCalculator
{
    public function __construct(
        private readonly LineCalculator $lineCalculator = new LineCalculator,
        private readonly GrossPriceConverter $grossPriceConverter = new GrossPriceConverter,
    ) {}

    public function calculate(InvoiceData $invoice): InvoiceData
    {
        $currency = $invoice->currency;
        $lines = $invoice->lines;
        $grossRounding = BigDecimal::zero();

        if ($this->grossPriceConverter->appliesTo($invoice)) {
            $converted = $this->grossPriceConverter->convert($invoice);
            $lines = $converted['lines'];
            $grossRounding = $converted['rounding'];
        }

        // 7.2 Line amounts
        $lines = array_map(fn (InvoiceLine $line): InvoiceLine => $this->lineCalculator->calculate($line, $currency), $lines);

        // Group sums of the line amounts, in order of first appearance.
        /** @var array<string, array{category: VatCategory, rate: ?BigDecimal, lines: BigDecimal, allowances: BigDecimal, charges: BigDecimal, reason: ?string, code: VatExemptionReasonCode|RawCode|null}> $groups */
        $groups = [];
        $lineNetSum = BigDecimal::zero();
        foreach ($lines as $line) {
            $amount = $line->netAmount?->getAmount() ?? BigDecimal::zero();
            $lineNetSum = $lineNetSum->plus($amount);
            $key = $line->vat->groupKey();
            $groups[$key] ??= $this->group($line->vat->category, $line->vat->rate);
            $groups[$key]['lines'] = $groups[$key]['lines']->plus($amount);
            $groups[$key]['reason'] ??= $line->vat->exemptionReason;
            $groups[$key]['code'] ??= $line->vat->exemptionReasonCode;
        }

        // 7.3 Document allowances and charges
        $allowances = array_map(fn (DocumentAllowanceCharge $a) => $this->resolveDocumentLevel($a, $groups, $currency), $invoice->allowances);
        $charges = array_map(fn (DocumentAllowanceCharge $c) => $this->resolveDocumentLevel($c, $groups, $currency), $invoice->charges);

        $allowanceSum = BigDecimal::zero();
        foreach ($allowances as $allowance) {
            $value = $allowance->amount?->getAmount() ?? BigDecimal::zero();
            $allowanceSum = $allowanceSum->plus($value);
            $key = LineVat::key($allowance->vatCategory, $allowance->vatRate);
            $groups[$key] ??= $this->group($allowance->vatCategory, $allowance->vatRate);
            $groups[$key]['allowances'] = $groups[$key]['allowances']->plus($value);
        }

        $chargeSum = BigDecimal::zero();
        foreach ($charges as $charge) {
            $value = $charge->amount?->getAmount() ?? BigDecimal::zero();
            $chargeSum = $chargeSum->plus($value);
            $key = LineVat::key($charge->vatCategory, $charge->vatRate);
            $groups[$key] ??= $this->group($charge->vatCategory, $charge->vatRate);
            $groups[$key]['charges'] = $groups[$key]['charges']->plus($value);
        }

        // 7.4 VAT breakdown
        $breakdown = [];
        $vatTotal = BigDecimal::zero();
        foreach ($groups as $group) {
            $taxable = $group['lines']->minus($group['allowances'])->plus($group['charges']);
            $tax = $group['category']->isZeroTax() || $group['rate'] === null
                ? BigDecimal::zero()
                : Decimal::round($taxable->multipliedBy($group['rate'])->dividedBy(100, 10, RoundingMode::HalfUp), 2);
            $vatTotal = $vatTotal->plus($tax);

            $breakdown[] = new VatBreakdown(
                taxableAmount: Amount::fromDecimal($taxable, $currency),
                taxAmount: Amount::fromDecimal($tax, $currency),
                category: $group['category'],
                rate: $group['category']->requiresRate() ? ($group['rate'] ?? BigDecimal::zero()) : null,
                exemptionReason: $group['reason'],
                exemptionReasonCode: $group['code'],
            );
        }

        // 7.7 Totals
        $netTotal = $lineNetSum->minus($allowanceSum)->plus($chargeSum);
        $grossTotal = $netTotal->plus($vatTotal);
        $prepaid = $invoice->prepaidAmount?->getAmount();
        $rounding = $invoice->roundingAmount?->getAmount();
        if (! $grossRounding->isZero()) {
            $rounding = ($rounding ?? BigDecimal::zero())->plus($grossRounding);
        }
        $amountDue = $grossTotal->minus($prepaid ?? BigDecimal::zero())->plus($rounding ?? BigDecimal::zero());

        // XRechnung extension (BR-DEX-09): amounts collected for third parties are added to the amount due.
        foreach ($invoice->xrechnungExtension->thirdPartyPayments ?? [] as $payment) {
            $amountDue = $amountDue->plus($payment->amount->getAmount());
        }

        $totals = new Totals(
            lineNetSum: Amount::fromDecimal($lineNetSum, $currency),
            netTotal: Amount::fromDecimal($netTotal, $currency),
            grossTotal: Amount::fromDecimal($grossTotal, $currency),
            amountDue: Amount::fromDecimal($amountDue, $currency),
            allowanceSum: $allowances === [] ? null : Amount::fromDecimal($allowanceSum, $currency),
            chargeSum: $charges === [] ? null : Amount::fromDecimal($chargeSum, $currency),
            vatTotal: Amount::fromDecimal($vatTotal, $currency),
            vatTotalInTaxCurrency: $this->vatInTaxCurrency($invoice, $vatTotal),
            prepaidAmount: $prepaid === null ? null : Amount::fromDecimal($prepaid, $currency),
            roundingAmount: $rounding === null || $rounding->isZero() ? null : Amount::fromDecimal($rounding, $currency),
        );

        return $invoice->with([
            'lines' => $lines,
            'allowances' => $allowances,
            'charges' => $charges,
            'vatBreakdown' => $breakdown,
            'totals' => $totals,
            'dueDate' => $invoice->dueDate ?? $this->dueDate($invoice),
        ]);
    }

    /**
     * @return array{category: VatCategory, rate: ?BigDecimal, lines: BigDecimal, allowances: BigDecimal, charges: BigDecimal, reason: ?string, code: VatExemptionReasonCode|RawCode|null}
     */
    private function group(VatCategory $category, ?BigDecimal $rate): array
    {
        return [
            'category' => $category,
            'rate' => $rate,
            'lines' => BigDecimal::zero(),
            'allowances' => BigDecimal::zero(),
            'charges' => BigDecimal::zero(),
            'reason' => null,
            'code' => null,
        ];
    }

    /**
     * BT-92 = input or round2(BT-93 × BT-94 / 100). Without base amount the line sum
     * of the matching VAT group is used.
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
            ?? ($groups[LineVat::key($entry->vatCategory, $entry->vatRate)]['lines'] ?? BigDecimal::zero());
        $amount = Decimal::round($base->multipliedBy($entry->percent)->dividedBy(100, 10, RoundingMode::HalfUp), 2);

        return $entry->with([
            'amount' => Amount::fromDecimal($amount, $currency),
            'baseAmount' => Amount::fromDecimal($base, $currency),
        ]);
    }

    /**
     * BT-111 = round2(BT-110 × exchange rate), specification 7.8.
     */
    private function vatInTaxCurrency(InvoiceData $invoice, BigDecimal $vatTotal): ?\Brick\Money\Money
    {
        if ($invoice->taxCurrency === null || $invoice->taxCurrency === $invoice->currency) {
            return null;
        }

        $rate = $invoice->meta->exchangeRate;
        if ($rate === null) {
            return null;
        }

        return Amount::fromDecimal($vatTotal->multipliedBy($rate), $invoice->taxCurrency);
    }

    private function dueDate(InvoiceData $invoice): ?\Quittio\Core\Support\Date
    {
        $days = $invoice->paymentTerms?->dueDays;

        return $days === null ? null : $invoice->issueDate->addDays($days);
    }
}
