<?php

declare(strict_types=1);

namespace Quittio\Core\Profiles;

use Quittio\Core\Standards\StandardVersion;

/**
 * Factur-X/ZUGFeRD BASIC WL (without lines). Not an e-invoice in the sense of EN 16931.
 */
final class BasicWl extends AbstractProfile
{
    public function key(): string
    {
        return 'basic-wl';
    }

    public function name(): string
    {
        return 'Factur-X/ZUGFeRD BASIC WL';
    }

    public function level(): ProfileLevel
    {
        return ProfileLevel::BasicWl;
    }

    public function specificationId(StandardVersion $version = StandardVersion::En16931_2017): string
    {
        return 'urn:factur-x.eu:1p0:basicwl';
    }

    public function xmpConformanceLevel(): string
    {
        return 'BASIC WL';
    }

    public function afRelationship(): string
    {
        return 'Data';
    }

    public function isEn16931Compliant(): bool
    {
        return false;
    }
}
