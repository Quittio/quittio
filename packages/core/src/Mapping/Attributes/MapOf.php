<?php

declare(strict_types=1);

namespace Quittio\Core\Mapping\Attributes;

use Attribute;

/**
 * Value type of an array property keyed by strings, e.g. line options keyed by line
 * identifier; the hydrator keeps the keys.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final readonly class MapOf
{
    /**
     * @param  class-string|'string'|'int'  $type
     */
    public function __construct(
        public string $type,
    ) {}
}
