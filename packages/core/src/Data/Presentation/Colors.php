<?php

declare(strict_types=1);

namespace Quittio\Core\Data\Presentation;

use Quittio\Core\Data\Concerns\Immutable;

/**
 * Colours used by the PDF template.
 */
final readonly class Colors
{
    use Immutable;

    public function __construct(
        public string $primary = '#1e40af',
        public string $text = '#111827',
        public string $muted = '#6b7280',
        public ?string $secondary = null,
    ) {}
}
