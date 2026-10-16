<?php

declare(strict_types=1);

namespace Quittio\Core\Dunning;

use Brick\Math\BigDecimal;
use Quittio\Core\Exceptions\InvalidArgumentException;
use Quittio\Core\Support\Date;

/**
 * The reference interest rate of a country's late payment law, e.g. the German Basiszinssatz
 * (section 247 BGB) or the ECB main refinancing rate. Default interest is the reference rate
 * plus the points of the DunningLaw.
 */
interface ReferenceRates
{
    /**
     * The rate in percent on the date.
     *
     * @throws InvalidArgumentException when no rate is known for the date
     */
    public function rateOn(Date $date): BigDecimal;

    /**
     * Periods with a constant rate between two dates (inclusive start, exclusive end).
     *
     * @return list<array{from: Date, until: Date, rate: BigDecimal}>
     *
     * @throws InvalidArgumentException when no rate is known for part of the time
     */
    public function periods(Date $from, Date $until): array;
}
