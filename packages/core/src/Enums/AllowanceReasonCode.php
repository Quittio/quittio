<?php

declare(strict_types=1);

namespace Quittio\Core\Enums;

use Quittio\Core\Codes\CodeList;
use Quittio\Core\Enums\Concerns\HasLabel;

/**
 * Allowance reason code (BT-98, BT-140), UNTDID 5189 subset.
 */
enum AllowanceReasonCode: string
{
    use HasLabel;

    case BonusForWorksAheadOfSchedule = '41';
    case OtherBonus = '42';
    case ManufacturersConsumerDiscount = '60';
    case DueToMilitaryStatus = '62';
    case DueToWorkAccident = '63';
    case SpecialAgreement = '64';
    case ProductionErrorDiscount = '65';
    case NewOutletDiscount = '66';
    case SampleDiscount = '67';
    case EndOfRangeDiscount = '68';
    case IncotermDiscount = '70';
    case PointOfSalesThresholdAllowance = '71';
    case MaterialSurchargeDeduction = '88';
    case Discount = '95';
    case SpecialRebate = '100';
    case FixedLongTerm = '102';
    case Temporary = '103';
    case Standard = '104';
    case YearlyTurnover = '105';

    /**
     * The labels of these codes are under "allowance_reason" in resources/lang/{locale}/codes.php
     * (see label()).
     */
    public static function labelGroup(): string
    {
        return 'allowance_reason';
    }

    /**
     * Whether a raw code value is valid according to the official code list.
     */
    public static function isValidCode(string $code): bool
    {
        return CodeList::contains(CodeList::ALLOWANCE_REASONS, $code);
    }
}
