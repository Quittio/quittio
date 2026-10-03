<?php

declare(strict_types=1);

namespace Quittio\Core\Profiles;

use Quittio\Core\Standards\StandardVersion;

/**
 * EN 16931 core invoice (Factur-X/ZUGFeRD profile EN 16931, also as plain CII or UBL).
 */
final class En16931 extends AbstractProfile
{
    public function key(): string
    {
        return 'en16931';
    }

    public function name(): string
    {
        return 'EN 16931';
    }

    public function level(): ProfileLevel
    {
        return ProfileLevel::En16931;
    }

    public function specificationId(StandardVersion $version = StandardVersion::En16931_2017): string
    {
        return $version->specificationId();
    }

    public function syntaxes(): array
    {
        return [Syntax::Cii, Syntax::Ubl];
    }

    public function supportsStandard(StandardVersion $version): bool
    {
        return true;
    }

    public function xmpConformanceLevel(): string
    {
        return 'EN 16931';
    }
}
