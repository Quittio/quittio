<?php

declare(strict_types=1);

namespace Quittio\Core\Standards;

/**
 * Version of the semantic standard EN 16931-1.
 */
enum StandardVersion: string
{
    case En16931_2017 = 'en16931-2017';
    case En16931_2026 = 'en16931-2026';

    public function specificationId(): string
    {
        return match ($this) {
            self::En16931_2017 => 'urn:cen.eu:en16931:2017',
            self::En16931_2026 => 'urn:cen.eu:en16931:2026',
        };
    }

    public static function default(): self
    {
        return self::En16931_2017;
    }
}
