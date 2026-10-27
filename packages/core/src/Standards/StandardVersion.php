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

    /**
     * Specification identifier (BT-24) of the plain EN 16931 invoice in this version, e.g.
     * "urn:cen.eu:en16931:2017".
     */
    public function specificationId(): string
    {
        return match ($this) {
            self::En16931_2017 => 'urn:cen.eu:en16931:2017',
            self::En16931_2026 => 'urn:cen.eu:en16931:2026',
        };
    }

    /**
     * The version of a specification identifier (BT-24): EN 16931:2026 for identifiers that
     * start with its URN, otherwise EN 16931:2017.
     */
    public static function fromSpecificationId(?string $specificationId): self
    {
        foreach (self::cases() as $version) {
            if ($specificationId !== null && $version !== self::En16931_2017 && str_starts_with($specificationId, $version->specificationId())) {
                return $version;
            }
        }

        return self::En16931_2017;
    }

    /**
     * The version used when none is chosen: EN 16931:2017.
     */
    public static function default(): self
    {
        return self::En16931_2017;
    }
}
