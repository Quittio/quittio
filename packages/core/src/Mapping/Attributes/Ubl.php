<?php

declare(strict_types=1);

namespace Quittio\Core\Mapping\Attributes;

use Attribute;

/**
 * Location of the field in UBL 2.1, relative to the parent element (informative).
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final readonly class Ubl
{
    public function __construct(
        public string $path,
    ) {}
}
