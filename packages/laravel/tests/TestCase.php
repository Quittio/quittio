<?php

declare(strict_types=1);

namespace Quittio\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\I18n\Translations;
use Quittio\Core\Serialization\Snapshots\SnapshotUpcasterChain;
use Quittio\Country\DE\CountryDE;
use Quittio\Country\DE\Dunning\BaseRates;
use Quittio\Country\DE\Laravel\GermanyServiceProvider;
use Quittio\Laravel\Facades\Quittio;
use Quittio\Laravel\QuittioServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function tearDown(): void
    {
        parent::tearDown();
        // The bridge points to the translator of the destroyed application.
        Translations::reset();
        SnapshotUpcasterChain::useDefault(null);
        BaseRates::reset();
        InvoiceBuilder::useDefaultVat(null);
    }

    protected function getPackageProviders($app): array
    {
        return [QuittioServiceProvider::class, GermanyServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['Quittio' => Quittio::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('q', 32)));
        $app['config']->set('quittio.countries', ['DE' => CountryDE::class]);
        // A file of this process: parallel test processes must not share it.
        $app['config']->set('quittio-de.base_rate_file', sys_get_temp_dir().'/quittio-base-rates-'.getmypid().'.json');
        $app['config']->set('quittio.pdf.cache_path', sys_get_temp_dir().'/quittio-mpdf');
        $app['config']->set('quittio.validation.mandatory_field_guard', 'exception');
        $app['config']->set('quittio.seller', [
            'name' => 'Muster GmbH',
            'vat_id' => 'DE123456789',
            'address' => ['line1' => 'Hauptstraße 1', 'postcode' => '12345', 'city' => 'Musterstadt', 'country' => 'DE'],
            'contact' => ['name' => null, 'phone' => null, 'email' => 'rechnung@muster.de'],
            'electronic_address' => ['value' => 'rechnung@muster.de', 'scheme' => 'EM'],
            'bank_accounts' => [['iban' => 'DE02120300000000202051', 'bic' => 'BYLADEM1001', 'name' => 'Muster GmbH']],
        ]);
    }
}
