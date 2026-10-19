<?php

declare(strict_types=1);

namespace Quittio\Country\CH\Tax;

use Brick\Math\BigDecimal;
use Quittio\Core\Support\Date;
use Quittio\Core\Support\DatedValues;

/**
 * Swiss VAT rates (Art. 25 MWSTG), each valid from its date on.
 *
 * The increase of the standard rate to 8.5 % from 2028, on which the vote of 29 November 2026
 * decides, is not included. Not reviewed by a tax adviser yet (see the README of the pack).
 */
final class TaxTables
{
    /** Standard rate (Art. 25 (1) MWSTG). */
    public const array STANDARD_RATE = [
        '2011-01-01' => '8.0',
        '2018-01-01' => '7.7',
        '2024-01-01' => '8.1',
    ];

    /** Reduced rate (Art. 25 (2) MWSTG), e.g. food, books, medicines. */
    public const array REDUCED_RATE = [
        '2011-01-01' => '2.5',
        '2024-01-01' => '2.6',
    ];

    /** Special rate for accommodation (Art. 25 (4) MWSTG). */
    public const array ACCOMMODATION_RATE = [
        '2011-01-01' => '3.8',
        '2018-01-01' => '3.7',
        '2024-01-01' => '3.8',
    ];

    /** Gross amount in CHF up to which a till receipt needs no recipient (Art. 57 MWSTV). */
    public const string TILL_RECEIPT_LIMIT = '400.00';

    public static function standardRateOn(Date $date): BigDecimal
    {
        return self::rateOn(self::STANDARD_RATE, $date);
    }

    public static function reducedRateOn(Date $date): BigDecimal
    {
        return self::rateOn(self::REDUCED_RATE, $date);
    }

    public static function accommodationRateOn(Date $date): BigDecimal
    {
        return self::rateOn(self::ACCOMMODATION_RATE, $date);
    }

    /**
     * @param  array<string, string>  $table
     */
    private static function rateOn(array $table, Date $date): BigDecimal
    {
        return BigDecimal::of((new DatedValues($table))->on($date) ?? $table[array_key_first($table)]);
    }
}
