<?php

declare(strict_types=1);

// Registers the builder helpers of the German country pack as soon as Composer loads it.
Quittio\Country\DE\Builder\GermanInvoiceMacros::register();

// Quittio::resetState() also forgets the base rates registered with BaseRates::register().
Quittio\Core\Quittio::onResetState('country-de.base-rates', static fn () => Quittio\Country\DE\Dunning\BaseRates::reset());
