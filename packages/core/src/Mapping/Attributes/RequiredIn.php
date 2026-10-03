<?php

declare(strict_types=1);

namespace Quittio\Core\Mapping\Attributes;

use Attribute;

/**
 * Profile keys in which an optional EN 16931 field becomes mandatory (e.g. "xrechnung").
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final readonly class RequiredIn
{
    /** @var list<string> */
    public array $profiles;

    public function __construct(string ...$profiles)
    {
        $this->profiles = array_values($profiles);
    }
}
