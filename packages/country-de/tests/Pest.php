<?php

declare(strict_types=1);

use Quittio\Core\I18n\Translations;
use Quittio\Country\DE\Builder\GermanInvoiceMacros;

// The builder macros of the pack (smallAmount() …), also when no test of the process created
// a CountryDE before.
uses()->beforeEach(function () {
    Translations::reset();
    GermanInvoiceMacros::register();
})->in(__DIR__);
