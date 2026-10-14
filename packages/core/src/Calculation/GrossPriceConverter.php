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
 *   1. G = Σ g_i with g_i = round2(quantity × price incl. VAT)
 *   2. N = round2(G / (1 + rate / 100)); a partial cancellation aligned with the earlier ones
 *      declares its N (Corrections\CancellationHistory), which is used within 0.01
 *   3. T = round2(N × rate / 100)              (BR-CO-17)
 *   4. Every line gets n_i = round2(g_i / (1 + rate / 100)); the few cents N − Σ n_i
 *      are added to the lines with the largest rounding remainders. Each line keeps the
 *      sign of its gross amount (returns and vouchers stay negative). Net prices are
 *      derived with the precision needed so that the line formula yields the line amount.
 *   5. G − (N + T) is returned as rounding amount for BT-114, so that the amount
 *      due equals the gross amount the customer saw. The calculator takes BT-114 from
 *      the total G and the amounts it finally uses, so that it also holds when
 *      declared amounts are kept (e.g. the aligned VAT of a partial cancellation).
 *
 * Limits: all lines must use gross prices, and gross lines must not have line or
 * document allowances or charges. Model shipping costs and discounts as lines; a
 * discount line needs a negative quantity, because prices must not be negative (BR-27).
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
     * N = round2(G / (1 + rate / 100)), step 2.
     */
    public static function netOf(BigDecimal $gross, BigDecimal $rate): BigDecimal
    {
        $divisor = BigDecimal::one()->plus($rate->dividedBy(100, 10, RoundingMode::HalfUp));

        return Decimal::round($gross->dividedBy($divisor, 10, RoundingMode::HalfUp), 2);
    }

    /**
     * @param  array<string, BigDecimal>  $nets  declared net amounts N per VAT group (key:
     *         DeclaredAmounts::breakdownKey()), e.g. of a partial cancellation aligned with the earlier
     *         ones (Corrections\CancellationHistory); used instead of step 2 within 0.01
     * @return array{lines: list<InvoiceLine>, rounding: BigDecimal, gross: BigDecimal} gross: G of all groups
     */
    public function convert(InvoiceData $invoice, array $nets = []): array
    {
        if ($invoice->allowances !== [] || $invoice->charges !== []) {
            throw new CalculationException('Document allowances and charges are not supported in gross price mode. Add them as invoice lines instead.');
        }

        /** @var array<string, array{rate: BigDecimal, lines: array<int, BigDecimal>, declared: ?BigDecimal}> $groups */
        $groups = [];
        foreach ($invoice->lines as $index => $line) {
            if (! $line->price->isGrossMode()) {
                throw new CalculationException(sprintf('Line %s has a net price, but the invoice uses gross prices. Use gross prices for all lines (InvoiceLine::withPriceInclVat() changes the price of an existing line).', $line->id));
            }
            if ($line->allowances !== [] || $line->charges !== [] || $line->subLines !== []) {
                throw new CalculationException(sprintf('Line %s: allowances, charges and sub lines are not supported in gross price mode.', $line->id));
            }
            if ($line->price->priceInclVat?->isNegative() === true) {
                throw new CalculationException(sprintf('Line %s has a negative price including VAT. Prices must not be negative (BR-27); use a negative quantity for discounts, vouchers and returns.', $line->id));
            }

            $rate = $line->vat->category->isZeroTax() ? BigDecimal::zero() : ($line->vat->rate ?? BigDecimal::zero());
            $key = $line->vat->groupKey();
            $groups[$key] ??= ['rate' => $rate, 'lines' => [], 'declared' => $nets[DeclaredAmounts::breakdownKey($line->vat->category, $line->vat->rate)] ?? null];
            $groups[$key]['lines'][$index] = LineCalculator::baseAmount(
                $line->quantity,
                $line->price->priceInclVat ?? BigDecimal::zero(),
                $line->price->baseQuantity,
            );
        }

        $lines = $invoice->lines;
        $rounding = BigDecimal::zero();
        $total = BigDecimal::zero();

        foreach ($groups as $group) {
            $gross = BigDecimal::zero();
            foreach ($group['lines'] as $amount) {
                $gross = $gross->plus($amount);
            }
            $total = $total->plus($gross);

            $divisor = BigDecimal::one()->plus($group['rate']->dividedBy(100, 10, RoundingMode::HalfUp));
            $net = self::netOf($gross, $group['rate']);
            if ($group['declared'] !== null && $group['declared']->minus($net)->abs()->isLessThanOrEqualTo('0.01')) {
                $net = $group['declared']->toScale(2);
            }
            $tax = Decimal::round($net->multipliedBy($group['rate'])->dividedBy(100, 10, RoundingMode::HalfUp), 2);
            $rounding = $rounding->plus($gross->minus($net)->minus($tax));

            $parts = self::lineNets($net, $group['lines'], $divisor);
            foreach ($parts as $index => $lineNet) {
                $line = $lines[$index];
                $netPrice = $line->quantity->isZero()
                    ? BigDecimal::zero()
                    : LineCalculator::priceFor($lineNet, $line->quantity, $line->price->baseQuantity);
                $lines[$index] = $line->with(['price' => $line->price->with(['netPrice' => $netPrice])]);
            }
        }

        return ['lines' => array_values($lines), 'rounding' => $rounding, 'gross' => $total];
    }

    /**
     * Net amount per line: round2(g_i / divisor), then the difference to the group net is
     * added cent by cent to the lines with the largest rounding remainder in the needed
     * direction. A cent never moves a line to zero or across zero (e.g. a voucher of 0.01)
     * while another line can take it; lines without amount are adjusted only if no other
     * line exists.
     *
     * @param  array<int, BigDecimal>  $grossAmounts
     * @return array<int, BigDecimal>
     */
    private static function lineNets(BigDecimal $net, array $grossAmounts, BigDecimal $divisor): array
    {
        $nets = [];
        $remainders = [];
        $sum = BigDecimal::zero();
        foreach ($grossAmounts as $index => $gross) {
            $exact = $gross->dividedBy($divisor, 20, RoundingMode::HalfUp);
            $nets[$index] = Decimal::round($exact, 2);
            $remainders[$index] = $exact->minus($nets[$index]);
            $sum = $sum->plus($nets[$index]);
        }

        $difference = $net->minus($sum)->withPointMovedRight(2)->toBigInteger()->toInt();
        if ($difference === 0) {
            return $nets;
        }

        $step = BigDecimal::of($difference > 0 ? '0.01' : '-0.01');
        $candidates = array_keys(array_filter($grossAmounts, static fn (BigDecimal $g): bool => ! $g->isZero()));
        if ($candidates === []) {
            $candidates = array_keys($grossAmounts);
        }
        // Positive difference: lines rounded down the most first; negative: rounded up the most.
        usort($candidates, static function (int $a, int $b) use ($remainders, $difference): int {
            $cmp = $difference > 0 ? $remainders[$b]->compareTo($remainders[$a]) : $remainders[$a]->compareTo($remainders[$b]);

            return $cmp !== 0 ? $cmp : $a <=> $b;
        });

        $remaining = abs($difference);
        while ($remaining > 0) {
            $moved = false;
            foreach ($candidates as $index) {
                if ($remaining === 0) {
                    break;
                }
                $next = $nets[$index]->plus($step);
                if (! $next->isZero() && $next->getSign() === $grossAmounts[$index]->getSign()) {
                    $nets[$index] = $next;
                    $remaining--;
                    $moved = true;
                }
            }
            if (! $moved) {
                // No line keeps its sign with another cent: fall back to the remainder order.
                foreach ($candidates as $index) {
                    if ($remaining === 0) {
                        break;
                    }
                    $nets[$index] = $nets[$index]->plus($step);
                    $remaining--;
                }
            }
        }

        return $nets;
    }
}
