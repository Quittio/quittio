<?php

declare(strict_types=1);

namespace Quittio\Core\Support;

use Brick\Math\BigDecimal;
use Brick\Math\BigNumber;
use Brick\Math\RoundingMode;
use Brick\Money\Context\CustomContext;
use Brick\Money\Currency;
use Brick\Money\Exception\UnknownCurrencyException;
use Brick\Money\Money;
use Quittio\Core\Exceptions\InvalidArgumentException;

/**
 * Factory for monetary amounts.
 *
 * EN 16931 allows at most two decimals for every amount, independent of the
 * currency's minor unit. All amounts therefore use a fixed scale of 2.
 */
final class Amount
{
    public const int SCALE = 2;

    private static ?CustomContext $context = null;

    /** @var array<string, Currency> */
    private static array $currencies = [];

    /**
     * The shared money context with the fixed scale of two decimals (SCALE) that every amount uses.
     */
    public static function context(): CustomContext
    {
        return self::$context ??= new CustomContext(self::SCALE);
    }

    /**
     * Creates an amount. Values with more than two decimals are rejected unless $round is true.
     *
     * @param  Money|BigNumber|int|float|string  $value  floats are rejected (Decimal::rejectFloat())
     */
    public static function of(mixed $value, string $currency, bool $round = false): Money
    {
        if ($value instanceof Money) {
            if ($value->getCurrency()->getCurrencyCode() !== $currency) {
                throw new InvalidArgumentException(sprintf(
                    'Amount in %s given where %s was expected.',
                    $value->getCurrency()->getCurrencyCode(),
                    $currency,
                ));
            }
            $value = $value->getAmount();
        }

        $decimal = Decimal::of($value);

        if (! $round && $decimal->strippedOfTrailingZeros()->getScale() > self::SCALE) {
            throw new InvalidArgumentException(sprintf('Amounts may have at most two decimals, "%s" given.', $decimal));
        }

        return Money::of($decimal, self::currency($currency), self::context(), RoundingMode::HalfUp);
    }

    /**
     * Currency for a code. Codes unknown to ISO 4217 are accepted, so that invalid
     * input leads to a validation finding (BR-CL-04) instead of an exception.
     */
    public static function currency(string $code): Currency
    {
        if ($code === '') {
            throw new InvalidArgumentException('The currency code must not be empty.');
        }

        if (isset(self::$currencies[$code])) {
            return self::$currencies[$code];
        }

        try {
            $currency = Currency::of($code);
        } catch (UnknownCurrencyException) {
            $currency = new Currency($code, 0, $code, self::SCALE);
        }

        return self::$currencies[$code] = $currency;
    }

    /**
     * Like of() without rounding, but null and the empty string give null.
     */
    public static function ofNullable(Money|BigNumber|int|float|string|null $value, string $currency): ?Money
    {
        return $value === null || $value === '' ? null : self::of($value, $currency);
    }

    /**
     * Zero in $currency.
     */
    public static function zero(string $currency): Money
    {
        return Money::zero(self::currency($currency), self::context());
    }

    /**
     * Amount of a decimal rounded half up to two decimals; unlike of(), more decimals are not
     * rejected.
     */
    public static function fromDecimal(BigDecimal $value, string $currency): Money
    {
        return Money::of(Decimal::round($value, self::SCALE), self::currency($currency), self::context());
    }

    /**
     * Amount as string with exactly two decimals (e.g. "1234.50").
     */
    public static function format(Money $money): string
    {
        return $money->getAmount()->toScale(self::SCALE)->toString();
    }
}
