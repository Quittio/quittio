<?php

declare(strict_types=1);

namespace Quittio\Core\Enums;

use Quittio\Core\Codes\CodeList;
use Quittio\Core\Enums\Concerns\HasLabel;

/**
 * Electronic address scheme (BT-34-1, BT-49-1), CEF EAS code list. Further codes can be used as RawCode.
 */
enum ElectronicAddressScheme: string
{
    use HasLabel;

    case Email = 'EM';
    case Siren = '0002';
    case SwedishOrganisationNumber = '0007';
    case Siret = '0009';
    case Duns = '0060';
    case Gln = '0088';
    case DutchKvk = '0106';
    case DanishCvr = '0184';
    case DutchOin = '0190';
    case NorwegianOrganisationNumber = '0192';
    case LeitwegId = '0204';
    case BelgianEnterpriseNumber = '0208';
    case AustrianVatNumber = '9914';
    case AustrianOrganisationNumber = '9915';
    case GermanVatNumber = '9930';
    case FrenchVatNumber = '9957';

    public static function labelGroup(): string
    {
        return 'eas';
    }

    /**
     * Whether a raw code value is valid according to the official code list.
     */
    public static function isValidCode(string $code): bool
    {
        return CodeList::contains(CodeList::EAS, $code);
    }
}
