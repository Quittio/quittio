<?php

declare(strict_types=1);

namespace Quittio\Core\Mapping\Attributes;

use Attribute;

/**
 * Binary string; arrays and JSON carry it base64 encoded.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final readonly class Binary {}
