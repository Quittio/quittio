<?php

declare(strict_types=1);

namespace Quittio\Core\Corrections;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;
use Quittio\Core\Calculation\CalculationException;
use Quittio\Core\Calculation\DeclaredAmounts;
use Quittio\Core\Calculation\GrossPriceConverter;
use Quittio\Core\Calculation\LargestRemainder;
use Quittio\Core\Calculation\LineCalculator;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Data\Totals\VatBreakdown;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Support\Amount;
use Quittio\Core\Support\Decimal;

/**
 * What the earlier partial cancellations of an invoice cancelled per VAT group (category and
 * rate), so that the VAT of the next partial cancellation is rounded cumulatively
 * (specification 17.4).
 *
 * VAT is rounded per document and VAT group (BR-CO-17). Rounded per partial cancellation on its
 * own, the cancellations together can differ from the original by a cent per earlier part (e.g.
 * 9.67 + 9.67 + 9.66 net at 19 %: 3 × 1.84 = 5.52 against 5.51). With a history, per group:
 *
 *   S      = taxable amount of the earlier cancellations + taxable amount of this one
 *   target = round2(S × rate / 100), half up like the calculator; the original's VAT (BT-117)
 *            when this cancellation closes the group (all its lines are then cancelled
 *            completely)
 *   VAT    = target − VAT of the earlier cancellations
 *
 * After every cancellation the VAT cancelled so far is that of the taxable amount cancelled so
 * far, and once all lines are cancelled exactly the original's VAT (1.84, 1.83, 1.84 = 5.51 in the
 * example). For an original whose VAT another system rounded (BT-117 ≠ round2(BT-116 × rate)),
 * the target is round2(S × BT-117 / BT-116): its rounding difference is spread over the parts in
 * proportion to their taxable amounts instead of falling on the part that closes the group.
 *
 * In gross price mode the taxable amount follows the same way from the gross amount G of the
 * lines: N = round2(G cancelled so far / (1 + rate / 100)) as for the original
 * (GrossPriceConverter), the original's BT-116 when the group closes, minus the taxable amount
 * already cancelled. The taxable amounts of all cancellations then add up exactly to the
 * original's, and so do the rounding amounts (BT-114).
 *
 * Every cancellation therefore differs from round2(own taxable amount × rate) (or × the original's
 * effective rate) by at most 0.01, which BR-CO-17 tolerates, and its VAT keeps the sign of its
 * taxable amount (0.00 on 0.00); assertFits() rejects a history for which this would not hold. The
 * VAT total in accounting currency (BT-111) follows the same way: round2(VAT cancelled so far ×
 * exchange rate), and the original's BT-111 when the cancellation closes all groups.
 *
 * Amounts are signed in the original's direction: a credit note that cancels an invoice and the
 * invoice that cancels a credit note count with their amounts, a negative invoice (strategy
 * negative_invoice) with its amounts negated.
 */
final readonly class CancellationHistory
{
    /**
     * @param  array<string, array{taxable: BigDecimal, tax: BigDecimal, taxInTaxCurrency: ?BigDecimal, gross?: ?BigDecimal}>  $groups  per VAT
     *         group (key: DeclaredAmounts::breakdownKey()) the taxable amount (BT-116) and VAT (BT-117) of the earlier
     *         cancellations together, their share of BT-111 (null if unknown) and, in gross price mode, the gross amount
     *         of their lines (Σ quantity × price including VAT; null or missing if unknown), in the original's direction
     * @param  list<string>  $closes  keys of the VAT groups whose lines are all cancelled completely once this
     *         cancellation is issued, including groups closed earlier
     */
    public function __construct(
        public array $groups = [],
        public array $closes = [],
    ) {}

    /**
     * The history after an issued (calculated) cancellation of $original, e.g. to keep it without the
     * Managed layer; $closes as in the constructor, for the next cancellation.
     *
     * @param  list<string>  $closes
     */
    public function withCancellation(InvoiceData $original, InvoiceData $cancellation, array $closes = []): self
    {
        $groups = $this->groups;
        foreach (self::entries($original, $cancellation) as $entry) {
            $before = $groups[$entry['key']] ?? ['taxable' => BigDecimal::zero(), 'tax' => BigDecimal::zero(), 'taxInTaxCurrency' => BigDecimal::zero(), 'gross' => BigDecimal::zero()];
            $grossBefore = array_key_exists('gross', $before) ? $before['gross'] : null;
            $groups[$entry['key']] = [
                'taxable' => $before['taxable']->plus($entry['taxable']),
                'tax' => $before['tax']->plus($entry['tax']),
                'taxInTaxCurrency' => $before['taxInTaxCurrency'] === null || $entry['taxInTaxCurrency'] === null ? null : $before['taxInTaxCurrency']->plus($entry['taxInTaxCurrency']),
                'gross' => $grossBefore === null || $entry['gross'] === null ? null : $grossBefore->plus($entry['gross']),
            ];
        }

        return new self($groups, $closes);
    }

    /**
     * The VAT groups of an issued (calculated) cancellation of $original in the original's direction,
     * with their share of BT-111 (distributed in proportion to their VAT; null without BT-111) and,
     * in gross price mode, the gross amount of their lines (null otherwise).
     *
     * @return list<array{key: string, category: VatCategory, rate: ?BigDecimal, taxable: BigDecimal, tax: BigDecimal, taxInTaxCurrency: ?BigDecimal, gross: ?BigDecimal}>
     */
    public static function entries(InvoiceData $original, InvoiceData $cancellation): array
    {
        $sign = self::direction($original, $cancellation);
        $gross = self::grossByGroup($cancellation, $sign);
        $taxes = [];
        foreach ($cancellation->vatBreakdown as $index => $entry) {
            $taxes[$index] = $entry->taxAmount->getAmount()->multipliedBy($sign);
        }
        $shares = self::shares($cancellation->totals?->vatTotalInTaxCurrency?->getAmount()->multipliedBy($sign), $taxes);

        $entries = [];
        foreach ($cancellation->vatBreakdown as $index => $entry) {
            $entries[] = [
                'key' => DeclaredAmounts::breakdownKey($entry->category, $entry->rate),
                'category' => $entry->category,
                'rate' => $entry->rate,
                'taxable' => $entry->taxableAmount->getAmount()->multipliedBy($sign),
                'tax' => $taxes[$index],
                'taxInTaxCurrency' => $shares[$index] ?? null,
                'gross' => $gross === null ? null : ($gross[DeclaredAmounts::breakdownKey($entry->category, $entry->rate)] ?? BigDecimal::zero()),
            ];
        }

        return $entries;
    }

    public function taxable(string $key): BigDecimal
    {
        return $this->groups[$key]['taxable'] ?? BigDecimal::zero();
    }

    public function tax(string $key): BigDecimal
    {
        return $this->groups[$key]['tax'] ?? BigDecimal::zero();
    }

    /**
     * Gross amount of the earlier cancellations of a group in gross price mode; null if unknown.
     */
    public function gross(string $key): ?BigDecimal
    {
        if (! isset($this->groups[$key])) {
            return BigDecimal::zero();
        }

        return $this->groups[$key]['gross'] ?? null;
    }

    public function closes(string $key): bool
    {
        return in_array($key, $this->closes, true);
    }

    /**
     * BT-111 of the earlier cancellations together; null if a share is unknown.
     */
    public function taxInTaxCurrency(): ?BigDecimal
    {
        $sum = BigDecimal::zero();
        foreach ($this->groups as $group) {
            if ($group['taxInTaxCurrency'] === null) {
                return null;
            }
            $sum = $sum->plus($group['taxInTaxCurrency']);
        }

        return $sum;
    }

    /**
     * The VAT of a calculated partial cancellation of $original aligned with this history: per VAT
     * group the target minus the VAT already cancelled, and BT-111 the same way. In gross price mode
     * the taxable amount of a group is aligned as well (the net amount of the gross amount cancelled
     * so far minus the taxable amount already cancelled), unless that differs by more than 0.01 from
     * the net amount of the cancellation's own gross amount (a history that does not match): then
     * it keeps its own. Otherwise its taxable amounts stay as they are. Amounts are signed like the
     * cancellation.
     *
     * @return array{breakdown: list<VatBreakdown>, taxInTaxCurrency: ?BigDecimal} taxInTaxCurrency is null
     *         without VAT accounting currency (BT-6), exchange rate or known BT-111 of the earlier cancellations
     */
    public function align(InvoiceData $original, InvoiceData $cancellation): array
    {
        $sign = self::direction($original, $cancellation);
        $originals = self::originalGroups($original);
        $gross = self::grossByGroup($cancellation, $sign);

        $breakdown = [];
        $total = BigDecimal::zero();
        foreach ($cancellation->vatBreakdown as $entry) {
            $key = DeclaredAmounts::breakdownKey($entry->category, $entry->rate);
            $closes = $this->closes($key) && isset($originals[$key]);
            $taxable = $entry->taxableAmount->getAmount()->multipliedBy($sign);
            $before = $this->gross($key);
            if ($gross !== null && $before !== null) {
                // Gross price mode: N = round2(G / (1 + rate)) of the gross amount cancelled so far,
                // like the original's (GrossPriceConverter), and the original's BT-116 once the group
                // closes, so that the taxable amounts of all cancellations add up exactly to it.
                $rate = $entry->category->isZeroTax() ? BigDecimal::zero() : ($entry->rate ?? BigDecimal::zero());
                $own = $gross[$key] ?? BigDecimal::zero();
                $target = $closes ? $originals[$key]['taxable'] : GrossPriceConverter::netOf($before->plus($own), $rate);
                $net = $target->minus($this->taxable($key));
                if ($net->minus(GrossPriceConverter::netOf($own, $rate))->abs()->isLessThanOrEqualTo('0.01')) {
                    $taxable = $net;
                }
            }
            $tax = BigDecimal::zero();
            if (! self::withoutVat($entry->category, $entry->rate) && $entry->rate !== null) {
                $target = $closes
                    ? $originals[$key]['tax']
                    : self::vat($this->taxable($key)->plus($taxable), self::factor($entry->rate, $originals[$key] ?? null));
                $tax = $target->minus($this->tax($key));
            }
            $total = $total->plus($tax);
            $breakdown[] = $entry->with([
                'taxableAmount' => Amount::fromDecimal($taxable->multipliedBy($sign), $cancellation->currency),
                'taxAmount' => Amount::fromDecimal($tax->multipliedBy($sign), $cancellation->currency),
            ]);
        }

        return ['breakdown' => $breakdown, 'taxInTaxCurrency' => $this->alignedTaxInTaxCurrency($original, $total)?->multipliedBy($sign)];
    }

    /**
     * Rejects a history that does not fit $original, checked with the calculated partial cancellation
     * $cancellation and its aligned VAT breakdown $aligned (align()):
     *
     * - every VAT group of the history and every group it closes must exist in the original,
     * - the taxable amount cancelled so far (before and including this cancellation) must not exceed
     *   per group the sum of the magnitudes of the original's line net amounts,
     * - every aligned VAT amount must differ by at most 0.01 from round2(own taxable amount × rate)
     *   (for an original whose VAT another system rounded, × its effective rate BT-117 / BT-116) and
     *   keep the sign of its taxable amount (0.00 on a taxable amount of 0.00).
     *
     * A history built from the earlier cancellations as issued (withCancellation(), the Managed
     * ledger) always fits.
     *
     * @param  list<VatBreakdown>  $aligned
     *
     * @throws InvalidArgumentException
     */
    public function assertFits(InvoiceData $original, InvoiceData $cancellation, array $aligned): void
    {
        $originals = self::originalGroups($original);
        foreach ([...array_keys($this->groups), ...$this->closes] as $key) {
            if (! isset($originals[$key])) {
                throw new InvalidArgumentException(sprintf('The cancellation history names the VAT group "%s", which invoice %s does not have.', $key, $original->number));
            }
        }

        $sign = self::direction($original, $cancellation);
        foreach ($aligned as $entry) {
            $key = DeclaredAmounts::breakdownKey($entry->category, $entry->rate);
            $group = $originals[$key] ?? null;
            if ($group === null) {
                continue;
            }
            $taxable = $entry->taxableAmount->getAmount()->multipliedBy($sign);
            $tax = $entry->taxAmount->getAmount()->multipliedBy($sign);
            foreach ([$this->taxable($key), $this->taxable($key)->plus($taxable)] as $cancelled) {
                if ($cancelled->abs()->isGreaterThan($group['bound'])) {
                    throw new InvalidArgumentException(sprintf('The cancellation history does not fit invoice %s: VAT group "%s" would be cancelled with a taxable amount of %s, the invoice has %s.', $original->number, $key, $cancelled->toString(), $group['taxable']->toString()));
                }
            }
            if (self::withoutVat($entry->category, $entry->rate) || $entry->rate === null) {
                continue;
            }
            $own = self::vat($taxable, self::factor($entry->rate, $group));
            $wrongSign = $taxable->isZero() ? ! $tax->isZero() : (! $tax->isZero() && $tax->getSign() !== $taxable->getSign());
            if ($wrongSign || $tax->minus($own)->abs()->isGreaterThan('0.01')) {
                throw new InvalidArgumentException(sprintf('The cancellation history does not fit invoice %s: VAT group "%s" would carry a VAT of %s on a taxable amount of %s (rounded on its own %s). Build the history from the earlier cancellations as issued (CancellationHistory::withCancellation()); if the invoice\'s VAT was rounded by another system and its group has positive and negative lines, cancel without history.', $original->number, $key, $tax->toString(), $taxable->toString(), $own->toString()));
            }
        }
    }

    /**
     * Whether an issued cancellation of $original carries the VAT this history gives it; false e.g.
     * after another partial cancellation was issued meanwhile.
     */
    public function matches(InvoiceData $original, InvoiceData $cancellation): bool
    {
        $aligned = $this->align($original, $cancellation);
        foreach ($aligned['breakdown'] as $index => $entry) {
            $issued = $cancellation->vatBreakdown[$index];
            if (! $entry->taxAmount->isEqualTo($issued->taxAmount) || ! $entry->taxableAmount->isEqualTo($issued->taxableAmount)) {
                return false;
            }
        }
        $expected = $aligned['taxInTaxCurrency'];
        $actual = $cancellation->totals?->vatTotalInTaxCurrency;

        return $expected === null || ($actual !== null && $actual->getAmount()->isEqualTo($expected));
    }

    /**
     * BT-111 of the cancellation in the original's direction, $tax being its VAT total.
     */
    private function alignedTaxInTaxCurrency(InvoiceData $original, BigDecimal $tax): ?BigDecimal
    {
        $before = $this->taxInTaxCurrency();
        $rate = $original->withImpliedExchangeRate()->meta->exchangeRate;
        if ($original->taxCurrency === null || $original->taxCurrency === $original->currency || $rate === null || $before === null) {
            return null;
        }

        $declared = $original->totals?->vatTotalInTaxCurrency;
        if ($declared !== null && $declared->getCurrency()->getCurrencyCode() === $original->taxCurrency && $this->closesAll($original)) {
            return $declared->getAmount()->minus($before);
        }

        $vat = $tax;
        foreach ($this->groups as $group) {
            $vat = $vat->plus($group['tax']);
        }

        return Decimal::round($vat->multipliedBy($rate), 2)->minus($before);
    }

    /**
     * Whether all VAT groups of the original are closed once this cancellation is issued.
     */
    private function closesAll(InvoiceData $original): bool
    {
        foreach ($original->vatBreakdown as $entry) {
            if (! $this->closes(DeclaredAmounts::breakdownKey($entry->category, $entry->rate))) {
                return false;
            }
        }

        return $original->vatBreakdown !== [];
    }

    /**
     * Per VAT group of the original: taxable amount (BT-116), VAT (BT-117), the bound of the
     * taxable amount that can be cancelled (sum of the magnitudes of its line net amounts, plus a cent
     * per line for the distribution of the net amount over gross price lines) and whether its lines
     * have net amounts of both signs.
     *
     * @return array<string, array{taxable: BigDecimal, tax: BigDecimal, bound: BigDecimal, mixed: bool}>
     */
    private static function originalGroups(InvoiceData $original): array
    {
        $groups = [];
        foreach ($original->vatBreakdown as $entry) {
            $groups[DeclaredAmounts::breakdownKey($entry->category, $entry->rate)] = [
                'taxable' => $entry->taxableAmount->getAmount(),
                'tax' => $entry->taxAmount->getAmount(),
                'bound' => $entry->taxableAmount->getAmount()->abs(),
                'mixed' => false,
            ];
        }
        $lines = [];
        $signs = [];
        foreach ($original->lines as $line) {
            $key = DeclaredAmounts::breakdownKey($line->vat->category, $line->vat->rate);
            $net = $line->netAmount?->getAmount() ?? BigDecimal::zero();
            $lines[$key] = ($lines[$key] ?? BigDecimal::zero())->plus($net->abs())->plus($line->price->isGrossMode() ? '0.01' : '0');
            if (! $net->isZero()) {
                $signs[$key][$net->getSign()] = true;
            }
        }
        foreach ($lines as $key => $bound) {
            if (isset($groups[$key])) {
                $groups[$key]['bound'] = BigDecimal::max($groups[$key]['bound'], $bound);
                $groups[$key]['mixed'] = count($signs[$key] ?? []) > 1;
            }
        }

        return $groups;
    }

    /**
     * The factor of the cumulative VAT as fraction [numerator, denominator]: the rate (rate / 100),
     * or for an original whose VAT another system rounded (BT-117 ≠ round2(BT-116 × rate)) its
     * effective rate BT-117 / BT-116, so that its rounding difference is spread over the parts in
     * proportion to their taxable amounts (exact at the original's taxable amount, without a jump
     * in the part that closes the group). Only for groups whose lines all have the same sign: with
     * positive and negative lines the taxable amount cancelled so far can exceed the original's by
     * far, and the effective rate would distort the VAT of the parts; such a group keeps the rate,
     * and the part that closes it the difference (assertFits() rejects it beyond 0.01).
     *
     * @param  array{taxable: BigDecimal, tax: BigDecimal, bound: BigDecimal, mixed: bool}|null  $original
     * @return array{BigDecimal, BigDecimal}
     */
    private static function factor(BigDecimal $rate, ?array $original): array
    {
        if ($original === null || $original['taxable']->isZero() || $original['mixed']) {
            return [$rate, BigDecimal::of(100)];
        }

        return self::vat($original['taxable'], [$rate, BigDecimal::of(100)])->isEqualTo($original['tax'])
            ? [$rate, BigDecimal::of(100)]
            : [$original['tax'], $original['taxable']];
    }

    /**
     * round2(taxable × numerator / denominator), half up like the calculator.
     *
     * @param  array{BigDecimal, BigDecimal}  $factor
     */
    private static function vat(BigDecimal $taxable, array $factor): BigDecimal
    {
        return Decimal::round($taxable->multipliedBy($factor[0])->dividedBy($factor[1], 10, RoundingMode::HalfUp), 2);
    }

    /**
     * Gross price mode: the gross amount of the lines per VAT group (rounded per line like the
     * GrossPriceConverter), in the original's direction; null if the cancellation uses net prices.
     *
     * @return array<string, BigDecimal>|null
     */
    private static function grossByGroup(InvoiceData $cancellation, int $sign): ?array
    {
        if (! (new GrossPriceConverter)->appliesTo($cancellation)) {
            return null;
        }

        $gross = [];
        foreach ($cancellation->lines as $line) {
            $key = DeclaredAmounts::breakdownKey($line->vat->category, $line->vat->rate);
            $amount = LineCalculator::baseAmount($line->quantity, $line->price->priceInclVat ?? BigDecimal::zero(), $line->price->baseQuantity);
            $gross[$key] = ($gross[$key] ?? BigDecimal::zero())->plus($amount->multipliedBy($sign));
        }

        return $gross;
    }

    /**
     * As the calculator: without VAT (categories Z, E, AE, K, G, O, or a rate of 0) BT-117 is 0.
     */
    private static function withoutVat(VatCategory $category, ?BigDecimal $rate): bool
    {
        return $category->isZeroTax() || $rate === null || $rate->isZero();
    }

    /**
     * 1 if the cancellation carries the original's amounts (a credit note, or the invoice that
     * cancels a credit note), -1 for a negative invoice.
     */
    private static function direction(InvoiceData $original, InvoiceData $cancellation): int
    {
        return $original->isCreditNote() || $cancellation->isCreditNote() ? 1 : -1;
    }

    /**
     * BT-111 split over the VAT groups in proportion to their VAT (largest remainder); VAT amounts
     * that add up to zero leave it with the first group.
     *
     * @param  array<int, BigDecimal>  $taxes
     * @return array<array-key, BigDecimal> same keys as $taxes (LargestRemainder::distribute())
     */
    private static function shares(?BigDecimal $total, array $taxes): array
    {
        if ($total === null || $taxes === []) {
            return [];
        }

        try {
            return LargestRemainder::distribute($total, $taxes);
        } catch (CalculationException) {
            $shares = array_map(static fn (): BigDecimal => BigDecimal::zero()->toScale(2), $taxes);
            $shares[array_key_first($taxes)] = Decimal::round($total, 2);

            return $shares;
        }
    }
}
