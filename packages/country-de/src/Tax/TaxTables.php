<?php

declare(strict_types=1);

namespace Quittio\Country\DE\Tax;

use Brick\Math\BigDecimal;
use Quittio\Core\Support\Date;
use Quittio\Core\Support\DatedValues;

/**
 * German tax values that changed over time, each valid from its date on.
 *
 * The dates and values were confirmed by a tax adviser in October 2026 (legal review in the
 * README), like the note texts of the presets.
 */
final class TaxTables
{
    /** Standard VAT rate (section 12 (1) UStG), including the temporary 16 % of the second half of 2020. */
    public const array STANDARD_RATE = [
        '1983-07-01' => '14',
        '1993-01-01' => '15',
        '1998-04-01' => '16',
        '2007-01-01' => '19',
        '2020-07-01' => '16',
        '2021-01-01' => '19',
    ];

    /** Reduced VAT rate (section 12 (2) UStG), including the temporary 5 % of the second half of 2020. */
    public const array REDUCED_RATE = [
        '1983-07-01' => '7',
        '2020-07-01' => '5',
        '2021-01-01' => '7',
    ];

    /** Gross limit of a small amount invoice in EUR (section 33 UStDV). */
    public const array SMALL_AMOUNT_LIMIT = [
        '2002-01-01' => '100.00',
        '2007-01-01' => '150.00',
        '2017-01-01' => '250.00',
    ];

    /**
     * The standard VAT rate on the date of the supply.
     */
    public static function standardRateOn(Date $date): BigDecimal
    {
        return BigDecimal::of((new DatedValues(self::STANDARD_RATE))->on($date) ?? self::STANDARD_RATE['1983-07-01']);
    }

    /**
     * The reduced VAT rate on the date of the supply.
     */
    public static function reducedRateOn(Date $date): BigDecimal
    {
        return BigDecimal::of((new DatedValues(self::REDUCED_RATE))->on($date) ?? self::REDUCED_RATE['1983-07-01']);
    }

    /**
     * The gross limit of a small amount invoice in EUR on the issue date.
     */
    public static function smallAmountLimitOn(Date $date): BigDecimal
    {
        return BigDecimal::of((new DatedValues(self::SMALL_AMOUNT_LIMIT))->on($date) ?? self::SMALL_AMOUNT_LIMIT['2002-01-01']);
    }
}
