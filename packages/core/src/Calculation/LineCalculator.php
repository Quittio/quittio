<?php

declare(strict_types=1);

namespace Quittio\Core\Calculation;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Quittio\Core\Data\AllowanceCharge\LineAllowanceCharge;
use Quittio\Core\Data\Lines\InvoiceLine;
use Quittio\Core\Support\Amount;
use Quittio\Core\Support\Decimal;

/**
 * Calculates the invoice line net amount (BT-131), specification 7.2:
 *
 *   net price   BT-146 = BT-148 − BT-147 (if list price and discount are given)
 *   base amount B      = round2(BT-129 × BT-146 / (BT-149 ?: 1))
 *   allowance   A_i    = BT-136 or round2((BT-137 ?: B) × BT-138 / 100)
 *   charge      C_i    = BT-141 or round2((BT-142 ?: B) × BT-143 / 100)
 *   line amount BT-131 = B − Σ A_i + Σ C_i
 */
final class LineCalculator
{
    public function calculate(InvoiceLine $line, string $currency): InvoiceLine
    {
        $subLines = array_map(fn (InvoiceLine $sub): InvoiceLine => $this->calculate($sub, $currency), $line->subLines);

        $price = $line->price;
        $netPrice = $price->netPrice;

        if ($netPrice === null && $price->grossPrice !== null) {
            $netPrice = $price->discount === null ? $price->grossPrice : $price->grossPrice->minus($price->discount);
        }

        if ($netPrice === null && $subLines !== []) {
            // A parent line without own price takes the sum of its sub lines.
            $sum = BigDecimal::zero();
            foreach ($subLines as $sub) {
                $sum = $sum->plus($sub->netAmount?->getAmount() ?? BigDecimal::zero());
            }
            $netPrice = $line->quantity->isZero() ? $sum : self::priceFor($sum, $line->quantity, $price->baseQuantity);
        }

        if ($netPrice === null) {
            throw new CalculationException(sprintf('Line %s has no net price (BT-146).', $line->id));
        }

        $base = self::baseAmount($line->quantity, $netPrice, $price->baseQuantity);
        $baseMoney = Amount::fromDecimal($base, $currency);

        $allowances = array_map(fn (LineAllowanceCharge $a) => $this->resolve($a, $baseMoney, $currency), $line->allowances);
        $charges = array_map(fn (LineAllowanceCharge $c) => $this->resolve($c, $baseMoney, $currency), $line->charges);

        $net = $base;
        foreach ($allowances as $allowance) {
            $net = $net->minus($allowance->amount?->getAmount() ?? BigDecimal::zero());
        }
        foreach ($charges as $charge) {
            $net = $net->plus($charge->amount?->getAmount() ?? BigDecimal::zero());
        }

        return $line->with([
            'price' => $price->with(['netPrice' => $netPrice]),
            'allowances' => $allowances,
            'charges' => $charges,
            'netAmount' => Amount::fromDecimal($net, $currency),
            'subLines' => $subLines,
        ]);
    }

    public static function baseAmount(BigDecimal $quantity, BigDecimal $netPrice, ?BigDecimal $baseQuantity): BigDecimal
    {
        $value = $quantity->multipliedBy($netPrice);
        if ($baseQuantity !== null && ! $baseQuantity->isZero() && ! $baseQuantity->isEqualTo(1)) {
            $value = $value->dividedBy($baseQuantity, 10, RoundingMode::HalfUp);
        }

        return Decimal::round($value, 2);
    }

    /**
     * Unrounded line amount as PEPPOL-EN16931-R120 checks it: quantity × (net price / base
     * quantity) + line charges − line allowances (BT-129, BT-146, BT-149, BT-141, BT-136).
     * Null without net price (BT-146).
     */
    public static function exactAmount(InvoiceLine $line): ?BigDecimal
    {
        $price = $line->price->netPrice;
        if ($price === null) {
            return null;
        }

        $baseQuantity = $line->price->baseQuantity;
        $value = $baseQuantity === null || $baseQuantity->isZero()
            ? $line->quantity->multipliedBy($price)
            : $line->quantity->multipliedBy($price->dividedBy($baseQuantity, 20, RoundingMode::HalfUp));
        foreach ($line->allowances as $allowance) {
            $value = $value->minus($allowance->amount?->getAmount() ?? BigDecimal::zero());
        }
        foreach ($line->charges as $charge) {
            $value = $value->plus($charge->amount?->getAmount() ?? BigDecimal::zero());
        }

        return $value;
    }

    /**
     * Smallest-precision net price (2 to 10 decimals) for which the line formula yields exactly $amount.
     */
    public static function priceFor(BigDecimal $amount, BigDecimal $quantity, ?BigDecimal $baseQuantity): BigDecimal
    {
        $factor = $baseQuantity !== null && ! $baseQuantity->isZero() ? $baseQuantity : BigDecimal::one();
        $exact = $amount->multipliedBy($factor)->dividedBy($quantity, 20, RoundingMode::HalfUp);

        for ($scale = 2; $scale <= 10; $scale++) {
            $candidate = Decimal::round($exact, $scale);
            if (self::baseAmount($quantity, $candidate, $baseQuantity)->isEqualTo($amount)) {
                return $candidate->getScale() > 2 ? self::trim($candidate) : $candidate;
            }
        }

        return Decimal::round($exact, 10);
    }

    /**
     * @template T of LineAllowanceCharge
     *
     * @param  T  $entry
     * @return T
     */
    private function resolve(LineAllowanceCharge $entry, Money $base, string $currency): LineAllowanceCharge
    {
        if ($entry->amount !== null) {
            return $entry;
        }

        if ($entry->percent === null) {
            throw new CalculationException('A line allowance or charge requires an amount or a percentage.');
        }

        $baseAmount = $entry->baseAmount ?? $base;
        $amount = Decimal::round($baseAmount->getAmount()->multipliedBy($entry->percent)->dividedBy(100, 10, RoundingMode::HalfUp), 2);

        return $entry->with([
            'amount' => Amount::fromDecimal($amount, $currency),
            'baseAmount' => $baseAmount,
        ]);
    }

    private static function trim(BigDecimal $value): BigDecimal
    {
        $stripped = $value->strippedOfTrailingZeros();

        return $stripped->getScale() < 2 ? $stripped->toScale(2) : $stripped;
    }
}
