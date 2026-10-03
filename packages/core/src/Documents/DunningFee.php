<?php

declare(strict_types=1);

namespace Quittio\Core\Documents;

use Brick\Money\Money;

/**
 * Fee of a payment reminder (e.g. reminder fee, flat rate according to national law).
 */
final readonly class DunningFee
{
    public function __construct(
        public string $description,
        public Money $amount,
    ) {}
}
