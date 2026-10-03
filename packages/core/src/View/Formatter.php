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
    public function money(Money $money, string $locale): string;

    /**
     * Prices may have more decimals than amounts; at least two are shown.
     */
    public function price(BigDecimal $price, string $currency, string $locale): string;

    public function quantity(BigDecimal $quantity, string $locale): string;

    public function percent(BigDecimal $percent, string $locale): string;

    public function date(Date $date, string $locale): string;
}
