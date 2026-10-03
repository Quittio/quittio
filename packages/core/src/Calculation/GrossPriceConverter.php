<?php

declare(strict_types=1);

namespace Quittio\Core\Calculation;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Data\Lines\InvoiceLine;
use Quittio\Core\Support\Decimal;

/**
 * Gross price mode for B2C and shops (specification 7.6).
 *
 * Shops calculate with prices including VAT, EN 16931 calculates net. Per VAT group:
 *
 *   1. G = Σ round2(quantity × price incl. VAT)
 *   2. N = round2(G / (1 + rate / 100))
 *   3. T = round2(N × rate / 100)              (BR-CO-17)
 *   4. N is distributed over the lines (largest remainder), net prices are derived
 *      with the precision needed so that the line formula yields the line amount.
 *   5. G − (N + T) is returned as rounding amount for BT-114, so that the amount
 *      due equals the gross amount the customer saw.
 *
 * Limits: all lines must use gross prices, and gross lines must not have line or
 * document allowances or charges. Model shipping costs and discounts as lines.
 */
final class GrossPriceConverter
{
    public function appliesTo(InvoiceData $invoice): bool
    {
        foreach ($invoice->lines as $line) {
            if ($line->price->isGrossMode()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{lines: list<InvoiceLine>, rounding: BigDecimal}
     */
    public function convert(InvoiceData $invoice): array
    {
        if ($invoice->allowances !== [] || $invoice->charges !== []) {
            throw new CalculationException('Document allowances and charges are not supported in gross price mode. Add them as invoice lines instead.');
        }

        /** @var array<string, array{rate: BigDecimal, lines: array<int, BigDecimal>}> $groups */
        $groups = [];
        foreach ($invoice->lines as $index => $line) {
            if (! $line->price->isGrossMode()) {
                throw new CalculationException(sprintf('Line %s has a net price, but the invoice uses gross prices. Use gross prices for all lines.', $line->id));
            }
            if ($line->allowances !== [] || $line->charges !== [] || $line->subLines !== []) {
                throw new CalculationException(sprintf('Line %s: allowances, charges and sub lines are not supported in gross price mode.', $line->id));
            }

            $rate = $line->vat->category->isZeroTax() ? BigDecimal::zero() : ($line->vat->rate ?? BigDecimal::zero());
            $key = $line->vat->groupKey();
            $groups[$key] ??= ['rate' => $rate, 'lines' => []];
            $groups[$key]['lines'][$index] = LineCalculator::baseAmount(
                $line->quantity,
                $line->price->priceInclVat ?? BigDecimal::zero(),
                $line->price->baseQuantity,
            );
        }

        $lines = $invoice->lines;
        $rounding = BigDecimal::zero();

        foreach ($groups as $group) {
            $gross = BigDecimal::zero();
            foreach ($group['lines'] as $amount) {
                $gross = $gross->plus($amount);
            }

            $divisor = BigDecimal::one()->plus($group['rate']->dividedBy(100, 10, RoundingMode::HalfUp));
            $net = Decimal::round($gross->dividedBy($divisor, 10, RoundingMode::HalfUp), 2);
            $tax = Decimal::round($net->multipliedBy($group['rate'])->dividedBy(100, 10, RoundingMode::HalfUp), 2);
            $rounding = $rounding->plus($gross->minus($net)->minus($tax));

            $parts = LargestRemainder::distribute($net, $group['lines']);
            foreach ($parts as $index => $lineNet) {
                $line = $lines[$index];
                $netPrice = $line->quantity->isZero()
                    ? BigDecimal::zero()
                    : LineCalculator::priceFor($lineNet, $line->quantity, $line->price->baseQuantity);
                $lines[$index] = $line->with(['price' => $line->price->with(['netPrice' => $netPrice])]);
            }
        }

        return ['lines' => array_values($lines), 'rounding' => $rounding];
    }
}
