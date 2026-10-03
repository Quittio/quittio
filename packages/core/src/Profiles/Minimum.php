<?php

declare(strict_types=1);

namespace Quittio\Core\Profiles;

use Quittio\Core\Standards\StandardVersion;

/**
 * Factur-X/ZUGFeRD MINIMUM. Not an e-invoice in the sense of EN 16931; booking aid only.
 */
final class Minimum extends AbstractProfile
{
    public function key(): string
    {
        return 'minimum';
    }

    public function name(): string
    {
        return 'Factur-X/ZUGFeRD MINIMUM';
    }

    public function level(): ProfileLevel
    {
        return ProfileLevel::Minimum;
    }

    public function specificationId(StandardVersion $version = StandardVersion::En16931_2017): string
    {
        return 'urn:factur-x.eu:1p0:minimum';
    }

    public function xmpConformanceLevel(): string
    {
        return 'MINIMUM';
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
