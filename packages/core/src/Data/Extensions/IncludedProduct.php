<?php

declare(strict_types=1);

namespace Quittio\Core\Data\Extensions;

use Brick\Math\BigDecimal;
use Quittio\Core\Codes\RawCode;
use Quittio\Core\Data\Concerns\Immutable;
use Quittio\Core\Enums\UnitCode;

/**
 * Component of a set or bundle (profile EXTENDED).
 */
final readonly class IncludedProduct
{
    use Immutable;

    public function __construct(
        public string $name,
        public ?BigDecimal $quantity = null,
        public UnitCode|RawCode|null $unit = null,
        public ?string $description = null,
        public ?string $sellerId = null,
    ) {}
}
