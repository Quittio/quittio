<?php

declare(strict_types=1);

namespace Quittio\Core\Calculation;

use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;

/**
 * Distributes an amount over weights so that the rounded parts add up exactly
 * (largest remainder method, specification 7.5 and 7.6).
 *
 * Weights are signed: each part is total × weight / Σ weights, so a negative weight
 * (e.g. a VAT group that only contains returns) receives a part with the opposite sign.
 */
final class LargestRemainder
{
    /**
     * @param  array<array-key, BigDecimal>  $weights
     * @param  int<0, max>  $scale
     * @return array<array-key, BigDecimal> parts with $scale decimals, same keys as $weights
     *
     * @throws CalculationException if the weights are not all zero but add up to zero
     */
    public static function distribute(BigDecimal $total, array $weights, int $scale = 2): array
    {
        if ($weights === []) {
            return [];
        }

        $weightSum = BigDecimal::zero();
        $allZero = true;
        foreach ($weights as $weight) {
            $weightSum = $weightSum->plus($weight);
            $allZero = $allZero && $weight->isZero();
        }

        $keys = array_keys($weights);
        if ($allZero) {
            // Equal distribution if all weights are zero.
            $weightSum = BigDecimal::of(count($weights));
            $weights = array_fill_keys($keys, BigDecimal::one());
        } elseif ($weightSum->isZero()) {
            throw new CalculationException('The amount cannot be distributed proportionally, because the weights add up to zero.');
        }

        // Work in integer units of the target scale (e.g. cents). The calculation runs on the
        // magnitude of the total, so that equal remainders are resolved the same way for
        // positive and negative totals.
        $totalUnits = $total->withPointMovedRight($scale)->toScale(0, RoundingMode::HalfUp)->toBigInteger();
        $sign = $totalUnits->getSign() < 0 ? -1 : 1;
        $absUnits = $totalUnits->abs();

        $floors = [];
        $remainders = [];
        $allocated = BigInteger::zero();
        foreach ($keys as $key) {
            $exact = BigDecimal::of($absUnits)->multipliedBy($weights[$key])->dividedBy($weightSum, 20, RoundingMode::Floor);
            $floor = $exact->toScale(0, RoundingMode::Floor)->toBigInteger();
            $floors[$key] = $floor;
            $remainders[$key] = $exact->minus(BigDecimal::of($floor));
            $allocated = $allocated->plus($floor);
        }

        $left = $absUnits->minus($allocated)->toInt();

        // Stable order: larger remainder first, then original position.
        $order = $keys;
        usort($order, static function (int|string $a, int|string $b) use ($remainders, $keys): int {
            $cmp = $remainders[$b]->compareTo($remainders[$a]);

            return $cmp !== 0 ? $cmp : array_search($a, $keys, true) <=> array_search($b, $keys, true);
        });

        for ($i = 0; $i < $left; $i++) {
            $key = $order[$i % count($order)];
            $floors[$key] = $floors[$key]->plus(1);
        }

        $result = [];
        foreach ($keys as $key) {
            $result[$key] = BigDecimal::of($floors[$key])->multipliedBy($sign)->withPointMovedLeft($scale)->toScale($scale);
        }

        return $result;
    }
}
