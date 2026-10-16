<?php

declare(strict_types=1);

namespace Quittio\Core\Countries\Capabilities;

use Quittio\Core\Dunning\ReferenceRates;

/**
 * A country pack whose late payment law has a reference interest rate, so that
 * Dunning\DefaultInterest::forCountry() calculates default interest for it.
 */
interface ProvidesReferenceRates
{
    /**
     * The current reference rates of the country, including rates registered at runtime.
     */
    public function referenceRates(): ReferenceRates;
}
