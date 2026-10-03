<?php

declare(strict_types=1);

namespace Quittio\Core\Support;

use Brick\Math\BigDecimal;
use Brick\Math\BigNumber;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * Helpers for exact decimal arithmetic. Floats are rejected on purpose.
 */
final class Decimal
{
    /**
     * @param  BigNumber|int|string  $value
     */
    public static function of(mixed $value): BigDecimal
    {
        if (is_float($value)) {
            throw new InvalidArgumentException('Floats are not accepted for monetary values or quantities. Pass a string or an integer instead.');
        }

        if ($value instanceof BigNumber || is_int($value) || is_string($value)) {
            return BigDecimal::of($value instanceof BigNumber ? $value : trim((string) $value));
        }

        throw new InvalidArgumentException(sprintf('Unsupported decimal value of type %s.', get_debug_type($value)));
    }

    public static function ofNullable(BigNumber|int|string|null $value): ?BigDecimal
    {
        return $value === null || $value === '' ? null : self::of($value);
    }

    /**
     * Commercial rounding (half up, away from zero), as required by EN 16931.
     *
     * @param  int<0, max>  $scale
     */
    public static function round(BigDecimal $value, int $scale): BigDecimal
    {
        return $value->toScale($scale, RoundingMode::HalfUp);
    }

    /**
     * Plain string without exponent, trailing zeros removed down to $minScale decimals.
     *
     * @param  int<0, max>  $minScale
     */
    public static function format(BigDecimal $value, int $minScale = 0): string
    {
        $stripped = $value->strippedOfTrailingZeros();
        if ($stripped->getScale() < $minScale) {
            $stripped = $stripped->toScale($minScale);
        }

        return $stripped->toString();
    }

    public static function isZero(BigDecimal $value): bool
    {
        return $value->isZero();
    }
}
