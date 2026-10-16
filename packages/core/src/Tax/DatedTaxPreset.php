<?php

declare(strict_types=1);

namespace Quittio\Core\Tax;

use Quittio\Core\Support\Date;

/**
 * A preset whose rate depends on the date of the supply, e.g. a standard rate that was lowered
 * for half a year. The builder resolves it with the supply date of each line (SupplyDate); rate()
 * returns the rate of today.
 */
interface DatedTaxPreset extends TaxPreset
{
    /**
     * The preset as it holds on the date, with the same key.
     */
    public function on(Date $date): TaxPreset;
}
