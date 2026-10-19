<?php

declare(strict_types=1);

namespace Quittio\Country\AT\Tax;

use Brick\Math\BigDecimal;
use Quittio\Core\Support\Date;
use Quittio\Core\Support\DatedValues;

/**
 * Austrian tax values, each valid from its date on.
 *
 * Not reviewed by a tax adviser yet (see the README of the pack).
 */
final class TaxTables
{
    /** Standard VAT rate (section 10 (1) UStG 1994). */
    public const string STANDARD_RATE = '20';

    /** Reduced VAT rate (section 10 (2) UStG 1994). */
    public const string REDUCED_RATE = '10';

    /** Second reduced VAT rate (section 10 (3) UStG 1994). */
    public const string REDUCED_RATE_13 = '13';

    /**
     * Rate of the basic foods of section 10 (1a) UStG 1994 (BGBl. I Nr. 37/2026): 4.9 % from
     * 1 July 2026, before that the reduced rate of 10 %.
     */
    public const array BASIC_FOOD_RATE = [
        '1995-01-01' => '10',
        '2026-07-01' => '4.9',
    ];

    /** Gross limit of a simplified invoice in EUR (section 11 (6) UStG 1994). */
    public const array SIMPLIFIED_INVOICE_LIMIT = [
        '1995-01-01' => '150.00',
        '2014-03-01' => '400.00',
    ];

    /**
     * Gross amount in EUR above which the invoice to a business must state the buyer's VAT
     * identifier (section 11 (1) no. 3 lit. b UStG 1994).
     */
    public const string BUYER_VAT_ID_LIMIT = '10000.00';

    /**
     * The rate of the basic foods on the date of the supply.
     */
    public static function basicFoodRateOn(Date $date): BigDecimal
    {
        return BigDecimal::of((new DatedValues(self::BASIC_FOOD_RATE))->on($date) ?? self::BASIC_FOOD_RATE['1995-01-01']);
    }

    /**
     * The gross limit of a simplified invoice in EUR on the issue date.
     */
    public static function simplifiedInvoiceLimitOn(Date $date): BigDecimal
    {
        return BigDecimal::of((new DatedValues(self::SIMPLIFIED_INVOICE_LIMIT))->on($date) ?? self::SIMPLIFIED_INVOICE_LIMIT['1995-01-01']);
    }
}
