<?php

declare(strict_types=1);

namespace Quittio\Core\Profiles;

use Quittio\Core\Standards\StandardVersion;
use Quittio\Core\Validation\Rules\PeppolRules;

/**
 * Peppol BIS Billing 3.0, a UBL based CIUS of EN 16931 (specification 26.2).
 */
final class PeppolBis3 extends AbstractProfile
{
    public const string BUSINESS_PROCESS = 'urn:fdc:peppol.eu:2017:poacc:billing:01:1.0';

    public function key(): string
    {
        return 'peppol';
    }

    public function name(): string
    {
        return 'Peppol BIS Billing 3.0';
    }

    public function level(): ProfileLevel
    {
        return ProfileLevel::En16931;
    }

    public function specificationId(StandardVersion $version = StandardVersion::En16931_2017): string
    {
        return 'urn:cen.eu:en16931:2017#compliant#urn:fdc:peppol.eu:2017:poacc:billing:3.0';
    }

    public function syntaxes(): array
    {
        return [Syntax::Ubl];
    }

    public function embeddedFilename(): ?string
    {
        return null;
    }

    public function afRelationship(): ?string
    {
        return null;
    }

    public function xmpConformanceLevel(): ?string
    {
        return null;
    }

    public function requiredBusinessTerms(): array
    {
        return ['BT-23', 'BT-34', 'BT-49'];
    }

    public function defaultBusinessProcess(): string
    {
        return self::BUSINESS_PROCESS;
    }

    public function rules(): array
    {
        return PeppolRules::all();
    }
}
