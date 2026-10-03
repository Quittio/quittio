<?php

declare(strict_types=1);

namespace Quittio\Core\Mapping\Attributes;

use Attribute;

/**
 * Element type of an array property, used for hydration and schema generation.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final readonly class ListOf
{
    /**
     * @param  class-string|'string'|'int'  $type
     */
    public function __construct(
        public string $type,
    ) {}
}
