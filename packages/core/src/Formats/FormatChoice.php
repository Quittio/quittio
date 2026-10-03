<?php

declare(strict_types=1);

namespace Quittio\Core\Formats;

/**
 * Result of the format resolution: output format key and profile key.
 */
final readonly class FormatChoice
{
    public function __construct(
        public string $format,
        public ?string $profile = null,
        public ?string $reason = null,
    ) {}
}
