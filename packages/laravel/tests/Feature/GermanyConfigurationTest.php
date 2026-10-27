<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;
use Quittio\Core\Support\Date;
use Quittio\Country\DE\CountryDE;
use Quittio\Country\DE\Dunning\BaseRates;
use Quittio\Country\DE\Dunning\BundesbankBaseRates;
use Quittio\Country\DE\Laravel\GermanyServiceProvider;
use Quittio\Laravel\Facades\Quittio;

const GERMAN_DEFAULTS = ['base_rates' => [], 'base_rate_file' => '/default/base-rates.json'];

const GERMAN_LEGACY_ERROR = 'The configuration quittio-de (config/quittio-de.php) is no longer read since quittio/country-de 1.0';

/**
 * @param  array<string, mixed>  $items
 */
function mergeGermanConfiguration(array $items): Repository
{
    $config = new Repository($items);
    GermanyServiceProvider::mergeConfiguration($config, GERMAN_DEFAULTS);

    return $config;
}

/**
 * Loads a config directory as Laravel does when the application starts.
 *
 * @param  array<string, string>  $files  path below config/ => PHP source
 */
function loadConfigurationDirectory(array $files): Repository
{
    $directory = sys_get_temp_dir().'/quittio-config-'.bin2hex(random_bytes(4));
    foreach ($files as $path => $source) {
        File::ensureDirectoryExists(dirname($directory.'/'.$path));
        file_put_contents($directory.'/'.$path, $source);
    }
    $original = app()->configPath();
    $config = new Repository;
    try {
        app()->useConfigPath($directory);
        (fn () => $this->loadConfigurationFiles(app(), $config))->call(new LoadConfiguration);
    } finally {
        app()->useConfigPath($original);
        File::deleteDirectory($directory);
    }

    return $config;
}

it('merges the defaults of the pack into quittio.countries.de', function () {
    expect(config('quittio.countries.de.base_rates'))->toBe([])
        ->and(config('quittio.countries.de.base_rate_file'))->toBe(sys_get_temp_dir().'/quittio-base-rates-'.getmypid().'.json')
        // The old key is no longer filled with defaults.
        ->and(config('quittio-de'))->toBeNull()
        // The settings beside the pack classes do not disturb the country packs.
        ->and(config('quittio.countries.DE'))->toBe(CountryDE::class)
        ->and(array_keys(Quittio::countries()->all()))->toBe(['DE']);

    $defaults = require dirname(__DIR__, 3).'/country-de/config/countries/de.php';
    expect($defaults)->toBe(['base_rates' => [], 'base_rate_file' => storage_path('app/quittio/base-rates.json'), 'consumer_default_notice' => true, 'prior_year_turnover_above_800k' => false, 'omit_vat_ids_for_category_o' => true]);
});

it('keeps the keys of quittio.countries.de and fills the others with the defaults', function () {
    $config = mergeGermanConfiguration([
        'quittio' => ['countries' => ['de' => ['base_rates' => ['2027-01-01' => '1.77']]]],
    ]);

    expect($config->get('quittio.countries.de'))->toBe(['base_rates' => ['2027-01-01' => '1.77'], 'base_rate_file' => '/default/base-rates.json']);

    // Merged again, e.g. from a cached configuration, the result stays the same.
    GermanyServiceProvider::mergeConfiguration($config, GERMAN_DEFAULTS);
    expect($config->get('quittio.countries.de'))->toBe(['base_rates' => ['2027-01-01' => '1.77'], 'base_rate_file' => '/default/base-rates.json']);
});

it('refuses the old configuration quittio-de instead of ignoring its settings', function () {
    expect(fn () => mergeGermanConfiguration([
        'quittio' => ['countries' => ['de' => ['base_rates' => []]]],
        'quittio-de' => ['prior_year_turnover_above_800k' => true],
    ]))->toThrow(RuntimeException::class, GERMAN_LEGACY_ERROR);
});

it('moves a pack class listed under the key de', function () {
    $config = mergeGermanConfiguration([
        'quittio' => ['countries' => [3 => 'Acme\CountryAT', 'de' => 'App\CountryDE', 'CH' => 'Acme\CountryCH']],
    ]);

    expect($config->get('quittio.countries'))->toBe([3 => 'Acme\CountryAT', 4 => 'App\CountryDE', 'CH' => 'Acme\CountryCH', 'de' => GERMAN_DEFAULTS]);
});

it('loads config/quittio/countries/de.php', function () {
    $config = loadConfigurationDirectory([
        'quittio.php' => "<?php return ['countries' => ['AT' => 'Acme\\\\CountryAT']];",
        'quittio/countries/de.php' => "<?php return ['base_rates' => ['2027-07-01' => '2.00']];",
    ]);
    GermanyServiceProvider::mergeConfiguration($config, GERMAN_DEFAULTS);
    GermanyServiceProvider::registerBaseRates($config);

    expect($config->get('quittio.countries'))->toBe(['AT' => 'Acme\CountryAT', 'de' => ['base_rates' => ['2027-07-01' => '2.00'], 'base_rate_file' => '/default/base-rates.json']])
        ->and(BaseRates::table()->rateOn(Date::of(2027, 8, 1))->toString())->toBe('2.00');
});

it('refuses to start an application that still has config/quittio-de.php', function () {
    config()->set('quittio-de', ['base_rates' => ['2027-01-01' => '1.77'], 'base_rate_file' => null]);

    try {
        expect(fn () => (new GermanyServiceProvider(app()))->register())->toThrow(RuntimeException::class, GERMAN_LEGACY_ERROR);
    } finally {
        config()->set('quittio-de', null);
    }
});

it('publishes the configuration to config/quittio/countries/de.php', function () {
    expect(ServiceProvider::publishableGroups())->toContain('quittio-de-config')
        ->and(ServiceProvider::pathsToPublish(GermanyServiceProvider::class, 'quittio-de-config'))
        ->toBe([dirname((string) (new ReflectionClass(GermanyServiceProvider::class))->getFileName(), 3).'/config/countries/de.php' => config_path('quittio/countries/de.php')]);
});

it('stores the updated base rates in quittio.countries.de.base_rate_file', function () {
    $new = sys_get_temp_dir().'/quittio-base-rates-new-'.getmypid().'.json';
    app()->instance(BundesbankBaseRates::class, new BundesbankBaseRates(fn (): string => "\"\";BBIN1.M.DE.BBK.BBKBAS2.EUR.ME;FLAGS\n2027-01;1,77;"));

    try {
        config()->set('quittio.countries.de.base_rate_file', $new);
        $this->artisan('quittio:base-rate:update')->assertSuccessful();
        expect($new)->toBeFile();

        // Without quittio.countries.de (replaced at runtime), the default of the pack.
        config()->set('quittio.countries', ['DE' => CountryDE::class]);
        expect(GermanyServiceProvider::setting(config(), 'base_rate_file'))->toBe(storage_path('app/quittio/base-rates.json'))
            ->and(GermanyServiceProvider::setting(config(), 'base_rates'))->toBe([]);

        config()->set('quittio.countries.de.base_rate_file', null);
        $this->artisan('quittio:base-rate:update')->expectsOutputToContain('quittio.countries.de.base_rate_file is not set')->assertFailed();
    } finally {
        File::delete($new);
    }
});
