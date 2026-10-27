<?php

declare(strict_types=1);

namespace Quittio\Core\Enums;

use Quittio\Core\Codes\CodeList;
use Quittio\Core\Enums\Concerns\HasLabel;

/**
 * VAT category code (BT-95, BT-102, BT-118, BT-151), UNTDID 5305 subset.
 */
enum VatCategory: string
{
    use HasLabel;

    case StandardRate = 'S';
    case ZeroRated = 'Z';
    case Exempt = 'E';
    case ReverseCharge = 'AE';
    case IntraCommunitySupply = 'K';
    case Export = 'G';
    case NotSubjectToVat = 'O';
    case CanaryIslands = 'L';
    case CeutaMelilla = 'M';
    case TransferredVatItaly = 'B';

    /**
     * The labels of these codes are under "vat_category" in resources/lang/{locale}/codes.php (see
     * label()).
     */
    public static function labelGroup(): string
    {
        return 'vat_category';
    }

    /**
     * Whether a raw code value is valid according to the official code list.
     */
    public static function isValidCode(string $code): bool
    {
        return CodeList::contains(CodeList::VAT_CATEGORIES, $code);
    }

    /**
     * Whether a VAT rate (BT-96, BT-103, BT-119, BT-152) must be given.
     */
    public function requiresRate(): bool
    {
        return $this !== self::NotSubjectToVat;
    }

    /**
     * Whether the VAT amount is always zero in this category.
     */
    public function isZeroTax(): bool
    {
        return ! in_array($this, [self::StandardRate, self::CanaryIslands, self::CeutaMelilla, self::TransferredVatItaly], true);
    }

    /**
     * Whether the category requires an exemption reason (BT-120 or BT-121).
     */
    public function requiresExemptionReason(): bool
    {
        return in_array($this, [self::Exempt, self::ReverseCharge, self::IntraCommunitySupply, self::Export, self::NotSubjectToVat], true);
    }
}
