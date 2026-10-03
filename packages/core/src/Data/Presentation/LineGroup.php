<?php

declare(strict_types=1);

namespace Quittio\Core\Data\Presentation;

use Quittio\Core\Data\Concerns\Immutable;

/**
 * Group of invoice lines with an optional subtotal. Presentation only.
 */
final readonly class LineGroup
{
    use Immutable;

    public function __construct(
        public string $id,
        public string $title,
        public bool $showSubtotal = true,
    ) {}
}
