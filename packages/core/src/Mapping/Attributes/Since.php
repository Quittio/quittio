<?php

declare(strict_types=1);

namespace Quittio\Core\Mapping\Attributes;

use Attribute;
use Quittio\Core\Standards\StandardVersion;

/**
 * The field exists from the given version of EN 16931 on.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final readonly class Since
{
    public function __construct(
        public StandardVersion $version,
    ) {}
}
