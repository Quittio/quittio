<?php

declare(strict_types=1);

namespace Quittio\Core\Profiles;

/**
 * Ordered Factur-X/ZUGFeRD profile levels. Each level contains all fields of the lower levels.
 */
enum ProfileLevel: int
{
    case Minimum = 10;
    case BasicWl = 20;
    case Basic = 30;
    case En16931 = 40;
    case Extended = 50;

    public function label(): string
    {
        return match ($this) {
            self::Minimum => 'MINIMUM',
            self::BasicWl => 'BASIC WL',
            self::Basic => 'BASIC',
            self::En16931 => 'EN 16931',
            self::Extended => 'EXTENDED',
        };
    }

    public function includes(self $other): bool
    {
        return $this->value >= $other->value;
    }
}
