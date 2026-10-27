<?php

declare(strict_types=1);

namespace Quittio\Core\Enums;

use Quittio\Core\Codes\CodeList;
use Quittio\Core\Enums\Concerns\HasLabel;

/**
 * Unit of measure (BT-130, BT-150), UN/ECE Recommendation 20 and 21. Further codes can be used as RawCode.
 */
enum UnitCode: string
{
    use HasLabel;

    case Unit = 'C62';
    case Piece = 'H87';
    case Set = 'SET';
    case Pair = 'PR';
    case Dozen = 'DZN';
    case LumpSum = 'LS';
    case Second = 'SEC';
    case Minute = 'MIN';
    case Hour = 'HUR';
    case Day = 'DAY';
    case Week = 'WEE';
    case Month = 'MON';
    case Quarter = 'QAN';
    case Year = 'ANN';
    case Gram = 'GRM';
    case Kilogram = 'KGM';
    case Tonne = 'TNE';
    case Millimetre = 'MMT';
    case Centimetre = 'CMT';
    case Metre = 'MTR';
    case Kilometre = 'KMT';
    case SquareMetre = 'MTK';
    case CubicMetre = 'MTQ';
    case Millilitre = 'MLT';
    case Litre = 'LTR';
    case KilowattHour = 'KWH';
    case Kilowatt = 'KWT';
    case Percent = 'P1';
    case ServiceUnit = 'E48';
    case Package = 'XPK';
    case Box = 'XBX';
    case Pallet = 'XPX';

    /**
     * The labels of these codes are under "unit" in resources/lang/{locale}/codes.php (see
     * label()).
     */
    public static function labelGroup(): string
    {
        return 'unit';
    }

    /**
     * Whether a raw code value is valid according to the official code list.
     */
    public static function isValidCode(string $code): bool
    {
        return CodeList::contains(CodeList::UNITS, $code);
    }
}
