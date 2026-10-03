<?php

declare(strict_types=1);

namespace Quittio\Core\Xml;

use Brick\Math\BigDecimal;
use Brick\Money\Money;
use Quittio\Core\Support\Amount;
use Quittio\Core\Support\Decimal;

/**
 * Number formats for XML: dot as decimal separator, no grouping, no exponent.
 */
final class Format
{
    /**
     * Amounts always with exactly two decimals.
     */
    public static function amount(Money $money): string
    {
        return Amount::format($money);
    }

    /**
     * Prices keep their precision, but at least two decimals.
     */
    public static function price(BigDecimal $price): string
    {
        return Decimal::format($price, 2);
    }

    /**
     * Quantities without superfluous trailing zeros.
     */
    public static function quantity(BigDecimal $quantity): string
    {
        return Decimal::format($quantity);
    }

    /**
     * Percentages with at least two decimals.
     */
    public static function percent(BigDecimal $percent): string
    {
        return Decimal::format($percent, 2);
    }
}
