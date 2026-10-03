<?php

declare(strict_types=1);

namespace Quittio\Core\Data\Extensions;

use Brick\Money\Money;
use Quittio\Core\Data\Concerns\Immutable;

/**
 * Amount collected on behalf of a third party (XRechnung extension, group BG-DEX-09).
 * It is added to the amount due (BR-DEX-09).
 */
final readonly class ThirdPartyPayment
{
    use Immutable;

    public function __construct(
        public string $type,
        public Money $amount,
        public string $description,
    ) {}
}
