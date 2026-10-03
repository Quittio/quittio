<?php

declare(strict_types=1);

namespace Quittio\Core\Data\Payment;

use Brick\Math\BigDecimal;
use Brick\Money\Money;
use Quittio\Core\Data\Concerns\Immutable;

/**
 * Cash discount ("Skonto") granted for payment within $days days.
 *
 * Serialised into BT-20 for XRechnung (#SKONTO#TAGE=..#PROZENT=..#) and into
 * structured elements in profile EXTENDED.
 */
final readonly class CashDiscount
{
    use Immutable;

    public function __construct(
        public int $days,
        public BigDecimal $percent,
        public ?Money $baseAmount = null,
    ) {}
}
