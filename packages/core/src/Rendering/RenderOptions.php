<?php

declare(strict_types=1);

namespace Quittio\Core\Rendering;

use Quittio\Core\Data\Presentation\Presentation;
use Quittio\Core\Profiles\Profile;

/**
 * Per call options of a render run; unset values fall back to the configuration.
 */
final readonly class RenderOptions
{
    /**
     * @param  list<string>  $formats  empty = configured or automatically chosen format
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public array $formats = [],
        public Profile|string|null $profile = null,
        public ?string $template = null,
        public ?string $locale = null,
        public ?string $country = null,
        public ?string $validation = null,
        public ?Presentation $presentation = null,
        public array $options = [],
    ) {}
}
