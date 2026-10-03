<?php

declare(strict_types=1);

namespace Quittio\Core\Mapping\Attributes;

use Attribute;

/**
 * Cardinality according to EN 16931: 1, 0..1, 0..n or 1..n.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final readonly class Cardinality
{
    public function __construct(
        public string $value,
    ) {}
}
