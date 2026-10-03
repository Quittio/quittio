<?php

declare(strict_types=1);

namespace Quittio\Core\Data;

use Quittio\Core\Codes\RawCode;
use Quittio\Core\Data\Concerns\Immutable;
use Quittio\Core\Enums\IdentifierScheme;

/**
 * Identifier with an optional scheme (ISO/IEC 6523 ICD or another code list depending on the field).
 */
final readonly class Identifier
{
    use Immutable;

    public function __construct(
        public string $value,
        public IdentifierScheme|RawCode|null $scheme = null,
    ) {}

    public static function of(string $value, IdentifierScheme|RawCode|string|null $scheme = null): self
    {
        if (is_string($scheme)) {
            $scheme = IdentifierScheme::tryFrom($scheme) ?? new RawCode($scheme);
        }

        return new self($value, $scheme);
    }

    public function schemeValue(): ?string
    {
        return $this->scheme?->value;
    }
}
