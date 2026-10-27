<?php

declare(strict_types=1);

namespace Quittio\Core\Profiles;

use Quittio\Core\Standards\StandardVersion;

/**
 * Defaults shared by all profiles. Profiles override what differs.
 */
abstract class AbstractProfile implements Profile
{
    public function syntaxes(): array
    {
        return [Syntax::Cii];
    }

    /**
     * Only EN 16931:2017 by default; profiles that also support EN 16931:2026 override it.
     */
    public function supportsStandard(StandardVersion $version): bool
    {
        return $version === StandardVersion::En16931_2017;
    }

    public function embeddedFilename(): ?string
    {
        return 'factur-x.xml';
    }

    public function afRelationship(): ?string
    {
        return 'Alternative';
    }

    public function isEn16931Compliant(): bool
    {
        return true;
    }

    public function requiredBusinessTerms(): array
    {
        return [];
    }

    public function rules(): array
    {
        return [];
    }

    public function defaultBusinessProcess(): ?string
    {
        return null;
    }

    /**
     * Whether the profile is an XML-only CIUS that is not embedded into PDFs by default.
     */
    public function supportsSyntax(Syntax $syntax): bool
    {
        return in_array($syntax, $this->syntaxes(), true);
    }
}
