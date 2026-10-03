<?php

declare(strict_types=1);

namespace Quittio\Core\Mapping\Attributes;

use Attribute;

/**
 * The value exists only in the Factur-X/ZUGFeRD EXTENDED profile.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final readonly class ExtendedOnly {}
