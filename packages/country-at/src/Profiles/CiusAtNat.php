<?php

declare(strict_types=1);

namespace Quittio\Country\AT\Profiles;

use Quittio\Core\Profiles\AbstractProfile;
use Quittio\Core\Profiles\ProfileLevel;
use Quittio\Core\Profiles\Syntax;
use Quittio\Core\Standards\StandardVersion;
use Quittio\Country\AT\Rules\CiusAtRules;

/**
 * CIUS-AT-NAT 1.0.0, the Austrian national CIUS of EN 16931 with the statutory rules, syntax CII
 * or UBL.
 */
class CiusAtNat extends AbstractProfile
{
    public const string SPECIFICATION_ID = 'urn:cen.eu:en16931:2017#compliant#urn:erechnung.gv.at:CIUS-AT-NAT:1.0.0';

    public function key(): string
    {
        return 'cius-at-nat';
    }

    public function name(): string
    {
        return 'CIUS-AT-NAT 1.0.0';
    }

    public function level(): ProfileLevel
    {
        return ProfileLevel::En16931;
    }

    public function specificationId(StandardVersion $version = StandardVersion::En16931_2017): string
    {
        return self::SPECIFICATION_ID;
    }

    public function syntaxes(): array
    {
        return [Syntax::Cii, Syntax::Ubl];
    }

    /**
     * Embedded in a ZUGFeRD PDF it is an EN 16931 invoice with its own specification identifier.
     */
    public function xmpConformanceLevel(): string
    {
        return 'EN 16931';
    }

    public function rules(): array
    {
        return CiusAtRules::national();
    }
}
