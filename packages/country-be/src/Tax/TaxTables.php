<?php

declare(strict_types=1);

namespace Quittio\Country\BE\Tax;

use Brick\Math\BigDecimal;
use Quittio\Core\Support\Date;
use Quittio\Core\Support\DatedValues;

/**
 * Belgian VAT rates (Royal Decree No. 20), each valid from its date on.
 *
 * Not reviewed by a tax adviser yet (see the README of the pack).
 */
final class TaxTables
{
    public const string STANDARD_RATE = '21';

    public const string INTERMEDIATE_RATE = '12';

    public const string REDUCED_RATE = '6';

    /** Accommodation in hotels and campsites: 12 % from 1 March 2026, 6 % before. */
    public const array ACCOMMODATION_RATE = [
        '1992-04-01' => '6',
        '2026-03-01' => '12',
    ];

    public static function accommodationRateOn(Date $date): BigDecimal
    {
        return BigDecimal::of((new DatedValues(self::ACCOMMODATION_RATE))->on($date) ?? self::ACCOMMODATION_RATE['1992-04-01']);
    }
}
