<?php

declare(strict_types=1);

namespace Quittio\Core\Mapping\Attributes;

use Attribute;
use Quittio\Core\Profiles\ProfileLevel;

/**
 * Lowest Factur-X/ZUGFeRD profile level in which the field may be used.
 * Fields without this attribute are available from level EN 16931 on.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final readonly class Level
{
    public function __construct(
        public ProfileLevel $level,
    ) {}
}
