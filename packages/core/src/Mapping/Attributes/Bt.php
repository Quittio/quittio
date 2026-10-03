<?php

declare(strict_types=1);

namespace Quittio\Core\Mapping\Attributes;

use Attribute;

/**
 * Business term or business group identifier from EN 16931 (e.g. BT-1, BG-25).
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final readonly class Bt
{
    public function __construct(
        public string $id,
    ) {}
}
