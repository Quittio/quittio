<?php

declare(strict_types=1);

namespace Quittio\Core\Enums;

use Quittio\Core\Codes\CodeList;
use Quittio\Core\Enums\Concerns\HasLabel;

/**
 * Identifier scheme (ISO/IEC 6523 ICD), e.g. for BT-29, BT-46, BT-157. Further codes can be used as RawCode.
 */
enum IdentifierScheme: string
{
    use HasLabel;

    case Siren = '0002';
    case SwedishOrganisationNumber = '0007';
    case Siret = '0009';
    case Duns = '0060';
    case Gln = '0088';
    case DutchKvk = '0106';
    case Gtin = '0160';
    case DanishCvr = '0184';
    case DutchOin = '0190';
    case NorwegianOrganisationNumber = '0192';
    case LeitwegId = '0204';
    case BelgianEnterpriseNumber = '0208';

    public static function labelGroup(): string
    {
        return 'icd';
    }

    /**
     * Whether a raw code value is valid according to the official code list.
     */
    public static function isValidCode(string $code): bool
    {
        return CodeList::contains(CodeList::ICD, $code);
    }
}
