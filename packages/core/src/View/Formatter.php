<?php

declare(strict_types=1);

namespace Quittio\Core\View;

use Brick\Math\BigDecimal;
use Brick\Money\Money;
use Quittio\Core\Support\Date;

/**
 * Formats values for display according to a locale.
 */
interface Formatter
{
    /**
     * Formats an amount with two decimals and its currency, e.g. "1.234,50 €" for locale de.
     */
    public function money(Money $money, string $locale): string;

    /**
     * Prices may have more decimals than amounts; at least two are shown.
     */
    public function price(BigDecimal $price, string $currency, string $locale): string;

    /**
     * Formats a quantity with as many decimals as it has, without trailing zeros, e.g. "1,5".
     */
    public function quantity(BigDecimal $quantity, string $locale): string;

    /**
     * Formats a percentage such as a VAT rate (BT-152), e.g. "19 %" for locale de.
     */
    public function percent(BigDecimal $percent, string $locale): string;

    /**
     * Formats a date by the conventions of the locale, e.g. "03.10.2026" for locale de.
     */
    public function date(Date $date, string $locale): string;
}
