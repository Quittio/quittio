<?php

declare(strict_types=1);

namespace Quittio\Core\Profiles;

use Quittio\Core\Standards\StandardVersion;

/**
 * Factur-X/ZUGFeRD EXTENDED: EN 16931 plus additional elements.
 */
final class Extended extends AbstractProfile
{
    public function key(): string
    {
        return 'extended';
    }

    public function name(): string
    {
        return 'Factur-X/ZUGFeRD EXTENDED';
    }

    public function level(): ProfileLevel
    {
        return ProfileLevel::Extended;
    }

    public function specificationId(StandardVersion $version = StandardVersion::En16931_2017): string
    {
        return 'urn:cen.eu:en16931:2017#conformant#urn:factur-x.eu:1p0:extended';
    }

    public function xmpConformanceLevel(): string
    {
        return 'EXTENDED';
    }
}
