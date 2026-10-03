<?php

declare(strict_types=1);

namespace Quittio\Core\View;

use JsonSerializable;
use Stringable;

/**
 * A value for templates: formatted for display and available in raw form.
 * Casting to string returns the formatted value, so {{ $value }} prints it.
 */
final readonly class FormattedValue implements JsonSerializable, Stringable
{
    public function __construct(
        public string $formatted,
        public mixed $raw = null,
    ) {}

    public function __toString(): string
    {
        return $this->formatted;
    }

    public function jsonSerialize(): string
    {
        return $this->formatted;
    }
}
