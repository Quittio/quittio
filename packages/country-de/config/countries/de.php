<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Country pack Germany (quittio.countries.de)
|--------------------------------------------------------------------------
| Laravel loads this file as config('quittio.countries.de'), because it lies in
| config/quittio/countries/. Instead of this file, the same keys may be set in
| config/quittio.php under 'countries' => ['de' => [...]]; the file wins.
| Before 0.4 this was config/quittio-de.php (config('quittio-de')).
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Basiszinssatz (§ 247 BGB)
    |--------------------------------------------------------------------------
    | Quittio ships the published rates up to its release. Default interest for
    | later periods needs the rates published since then:
    |
    | base_rates:     rates of your own, ISO date (1 January or 1 July) => percent,
    |                 e.g. ['2027-01-01' => '1.27']. They win over the file below.
    | base_rate_file: JSON file written by php artisan quittio:base-rate:update
    |                 (fetched from the Deutsche Bundesbank); null = not used.
    */
    'base_rates' => [],
    'base_rate_file' => storage_path('app/quittio/base-rates.json'),

    /*
    |--------------------------------------------------------------------------
    | Default notice for consumers (§ 286 Abs. 3 BGB)
    |--------------------------------------------------------------------------
    | Invoices to consumers get the notice that the consumer is in default 30
    | days after the due date and receipt of the invoice. Without it the 30 days
    | do not apply to consumers. false = add the notice yourself
    | (->de()->consumerDefaultNotice()).
    */
    'consumer_default_notice' => true,

];
