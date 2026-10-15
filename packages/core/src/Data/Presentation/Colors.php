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
        public string $primary = '#2741d9',
        public string $text = '#15171c',
        public string $muted = '#5f6470',
        public ?string $secondary = null,
    ) {}
}
