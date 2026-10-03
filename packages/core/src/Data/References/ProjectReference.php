<?php

declare(strict_types=1);

namespace Quittio\Core\Data\References;

use Quittio\Core\Data\Concerns\Immutable;

/**
 * Project reference (BT-11). CII requires a name in addition to the identifier.
 */
final readonly class ProjectReference
{
    use Immutable;

    public function __construct(
        public string $id,
        public ?string $name = null,
    ) {}
}
