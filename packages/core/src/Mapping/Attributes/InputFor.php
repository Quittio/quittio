<?php

declare(strict_types=1);

namespace Quittio\Core\Mapping\Attributes;

use Attribute;

/**
 * Marks a user input that the calculator copies into the given business term.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final readonly class InputFor
{
    public function __construct(
        public string $bt,
    ) {}
}
