<?php

declare(strict_types=1);

namespace Quittio\Core\Codes;

use JsonSerializable;
use Stringable;

/**
 * A code that is not (yet) part of the code list enums shipped with the package.
 *
 * Code lists are extended twice a year. The reader keeps unknown codes instead
 * of failing, and writers accept them for codes that are valid but have no enum case.
 */
final readonly class RawCode implements JsonSerializable, Stringable
{
    public function __construct(
        public string $value,
    ) {}

    public function __toString(): string
    {
        return $this->value;
    }

    /**
     * Serialises the code as its plain value, like an enum case.
     */
    public function jsonSerialize(): string
    {
        return $this->value;
    }
}
