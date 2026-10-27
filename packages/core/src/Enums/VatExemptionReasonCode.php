<?php

declare(strict_types=1);

namespace Quittio\Core\Enums;

use Quittio\Core\Codes\CodeList;
use Quittio\Core\Enums\Concerns\HasLabel;

/**
 * VAT exemption reason code (BT-121), VATEX code list. Further codes can be used as RawCode.
 */
enum VatExemptionReasonCode: string
{
    use HasLabel;

    case ReverseCharge = 'VATEX-EU-AE';
    case IntraCommunitySupply = 'VATEX-EU-IC';
    case Export = 'VATEX-EU-G';
    case NotSubjectToVat = 'VATEX-EU-O';
    case TravelAgents = 'VATEX-EU-D';
    case SecondHandGoods = 'VATEX-EU-F';
    case WorksOfArt = 'VATEX-EU-I';
    case CollectorsItems = 'VATEX-EU-J';
    case Article79c = 'VATEX-EU-79-C';
    case Article132 = 'VATEX-EU-132';
    case Article143 = 'VATEX-EU-143';
    case Article148 = 'VATEX-EU-148';
    case Article151 = 'VATEX-EU-151';
    case Article309 = 'VATEX-EU-309';

    /**
     * The labels of these codes are under "vatex" in resources/lang/{locale}/codes.php (see
     * label()).
     */
    public static function labelGroup(): string
    {
        return 'vatex';
    }

    /**
     * Whether a raw code value is valid according to the official code list.
     */
    public static function isValidCode(string $code): bool
    {
        return CodeList::contains(CodeList::VATEX, $code);
    }
}
