<?php

declare(strict_types=1);

namespace Quittio\Core\Data\Presentation;

use Quittio\Core\Data\Concerns\Immutable;

/**
 * PDF-only presentation hints for an invoice line. Never written to XML.
 */
final readonly class LinePresentation
{
    use Immutable;

    public function __construct(
        public ?string $groupId = null,
        public bool $highlight = false,
        public ?bool $showGrossPrice = null,
    ) {}
}
