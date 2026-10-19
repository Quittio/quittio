<?php

declare(strict_types=1);

namespace Quittio\Country\AT\Profiles;

use Quittio\Core\Standards\StandardVersion;
use Quittio\Country\AT\Rules\CiusAtRules;

/**
 * CIUS-AT-GOV 1.1.0, the CIUS of e-Rechnung.gv.at for invoices to the Austrian federal
 * government: the rules of CIUS-AT-NAT and its own, syntax CII or UBL.
 */
class CiusAtGov extends CiusAtNat
{
    public const string SPECIFICATION_ID = 'urn:cen.eu:en16931:2017#compliant#urn:erechnung.gv.at:CIUS-AT-NAT:1.0.0#compliant#urn:erechnung.gv.at:CIUS-AT-GOV:1.1.0';

    public function key(): string
    {
        return 'cius-at-gov';
    }

    public function name(): string
    {
        return 'CIUS-AT-GOV 1.1.0';
    }

    public function specificationId(StandardVersion $version = StandardVersion::En16931_2017): string
    {
        return self::SPECIFICATION_ID;
    }

    public function rules(): array
    {
        return [...CiusAtRules::national(), ...CiusAtRules::government()];
    }
}
