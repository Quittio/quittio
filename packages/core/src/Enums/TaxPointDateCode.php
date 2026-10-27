<?php

declare(strict_types=1);

namespace Quittio\Core\Enums;

use Quittio\Core\Codes\RawCode;

/**
 * Value added tax point date code (BT-8).
 *
 * The semantic value is the same in both syntaxes, but CII uses UNTDID 2475
 * (5, 29, 72) and UBL uses UNTDID 2005 (3, 35, 432).
 */
enum TaxPointDateCode: string
{
    case IssueDate = 'issue_date';
    case DeliveryDate = 'delivery_date';
    case PaymentDate = 'payment_date';

    /**
     * The code of this date in CII (UNTDID 2475: 5, 29, 72).
     */
    public function ciiCode(): string
    {
        return match ($this) {
            self::IssueDate => '5',
            self::DeliveryDate => '29',
            self::PaymentDate => '72',
        };
    }

    /**
     * The code of this date in UBL (UNTDID 2005: 3, 35, 432).
     */
    public function ublCode(): string
    {
        return match ($this) {
            self::IssueDate => '3',
            self::DeliveryDate => '35',
            self::PaymentDate => '432',
        };
    }

    /**
     * The case for a CII code (UNTDID 2475); a RawCode for an unknown code.
     */
    public static function fromCii(string $code): self|RawCode
    {
        return match ($code) {
            '5' => self::IssueDate,
            '29' => self::DeliveryDate,
            '72' => self::PaymentDate,
            default => new RawCode($code),
        };
    }

    /**
     * The case for a UBL code (UNTDID 2005); a RawCode for an unknown code.
     */
    public static function fromUbl(string $code): self|RawCode
    {
        return match ($code) {
            '3' => self::IssueDate,
            '35' => self::DeliveryDate,
            '432' => self::PaymentDate,
            default => new RawCode($code),
        };
    }
}
