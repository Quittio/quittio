<?php

declare(strict_types=1);

namespace Quittio\Core\Documents;

use Brick\Math\BigDecimal;

/**
 * Package of a delivery note.
 */
final readonly class Package
{
    public function __construct(
        public string $id,
        public ?string $type = null,
        public ?BigDecimal $weightKg = null,
        public ?string $dimensions = null,
        public ?string $trackingNumber = null,
    ) {}
}
