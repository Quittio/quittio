<?php

declare(strict_types=1);

namespace Quittio\Core\Enums;

use Quittio\Core\Codes\CodeList;
use Quittio\Core\Enums\Concerns\HasLabel;

/**
 * Charge reason code (BT-105, BT-145), UNTDID 7161 subset. Further codes can be used as RawCode.
 */
enum ChargeReasonCode: string
{
    use HasLabel;

    case Advertising = 'AA';
    case Telecommunication = 'AAA';
    case Miscellaneous = 'ABK';
    case AdditionalPackaging = 'ABL';
    case OtherServices = 'ADR';
    case PickUp = 'ADT';
    case Delivery = 'DL';
    case Freight = 'FC';
    case Financing = 'FI';
    case Labelling = 'LA';
    case Packing = 'PC';
    case SpecialHandling = 'SH';

    /**
     * The labels of these codes are under "charge_reason" in resources/lang/{locale}/codes.php (see
     * label()).
     */
    public static function labelGroup(): string
    {
        return 'charge_reason';
    }

    /**
     * Whether a raw code value is valid according to the official code list.
     */
    public static function isValidCode(string $code): bool
    {
        return CodeList::contains(CodeList::CHARGE_REASONS, $code);
    }
}
