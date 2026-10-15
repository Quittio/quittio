<?php

declare(strict_types=1);

namespace Quittio\Core\Support;

use Brick\Math\BigDecimal;
use Brick\Math\BigNumber;
use Brick\Math\Exception\NumberFormatException;
use Brick\Math\RoundingMode;
use Quittio\Core\Exceptions\InvalidArgumentException;

/**
 * Helpers for exact decimal arithmetic. Floats are rejected on purpose, see rejectFloat().
 */
final class Decimal
{
    /**
     * Plain decimal notation (xs:decimal): optional sign, digits and decimal point. No exponent, so
     * that "1e999999999" cannot expand to a billion digits, and no thousands separators.
     */
    public const string PLAIN_PATTERN = '/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)$/';

    /**
     * @param  BigNumber|int|float|string  $value  floats are rejected, see rejectFloat(); strings must
     *                                             use plain decimal notation, see PLAIN_PATTERN
     *
     * @throws NumberFormatException for strings in another notation
     */
    public static function of(mixed $value): BigDecimal
    {
        if (is_float($value)) {
            throw self::floatGiven($value);
        }

        if ($value instanceof BigNumber || is_int($value)) {
            return BigDecimal::of($value);
        }

        if (is_string($value)) {
            $value = trim($value);
            if (! self::isPlain($value)) {
                throw new NumberFormatException(sprintf(
                    'The value "%s" is not a decimal number in plain notation such as \'19.99\' (no exponent, no thousands separators).',
                    mb_strlen($value) > 64 ? mb_substr($value, 0, 64).'…' : $value,
                ));
            }

            return BigDecimal::of($value);
        }

        throw new InvalidArgumentException(sprintf('Unsupported decimal value of type %s.', get_debug_type($value)));
    }

    /**
     * Whether a string is a decimal number in plain notation, see PLAIN_PATTERN.
     */
    public static function isPlain(string $value): bool
    {
        return preg_match(self::PLAIN_PATTERN, $value) === 1;
    }

    public static function ofNullable(BigNumber|int|float|string|null $value): ?BigDecimal
    {
        return $value === null || $value === '' ? null : self::of($value);
    }

    /**
     * Passes numbers through that are converted later and rejects floats right away. Public
     * methods accept float in their signature only to reject it here: without it, PHP would
     * silently cut 19.99 to 19 in files without strict types (int|string coercion).
     *
     * @template T of BigNumber|int|string|null
     *
     * @param  T|float  $value
     * @return T
     */
    public static function rejectFloat(BigNumber|int|float|string|null $value): BigNumber|int|string|null
    {
        if (is_float($value)) {
            throw self::floatGiven($value);
        }

        return $value;
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

    private static function floatGiven(float $value): InvalidArgumentException
    {
        return new InvalidArgumentException(sprintf(
            'Floats are not accepted for amounts, prices, rates and quantities (%s given): most decimal values have no exact float representation. Pass a decimal string such as \'19.99\' or an integer.',
            var_export($value, true),
        ));
    }
}
