<?php

declare(strict_types=1);

namespace Quittio\Core\Mapping\Attributes;

use Attribute;

/**
 * The value is set by the calculator and is not a user input.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final readonly class Computed {}
