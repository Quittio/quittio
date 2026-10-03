<?php

declare(strict_types=1);

namespace Quittio\Core\Profiles;

use Quittio\Core\Standards\StandardVersion;

/**
 * Factur-X/ZUGFeRD BASIC: a subset of EN 16931 with lines.
 */
final class Basic extends AbstractProfile
{
    public function key(): string
    {
        return 'basic';
    }

    public function name(): string
    {
        return 'Factur-X/ZUGFeRD BASIC';
    }

    public function level(): ProfileLevel
    {
        return ProfileLevel::Basic;
    }

    public function specificationId(StandardVersion $version = StandardVersion::En16931_2017): string
    {
        return 'urn:cen.eu:en16931:2017#compliant#urn:factur-x.eu:1p0:basic';
    }

    public function xmpConformanceLevel(): string
    {
        return 'BASIC';
    }
}
