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

const GERMAN_DEPRECATION = 'The configuration quittio-de (config/quittio-de.php) is deprecated since quittio/country-de 0.4 and is read until 1.0: move its keys to quittio.countries.de (php artisan vendor:publish --tag=quittio-de-config publishes config/quittio/countries/de.php) and delete config/quittio-de.php.';

/**
 * Runs the callback and returns the deprecations it reported.
 *
 * @return list<string>
 */
function germanDeprecations(callable $callback): array
{
    $deprecations = [];
    set_error_handler(static function (int $level, string $message) use (&$deprecations): bool {
        $deprecations[] = $message;

        return true;
    }, E_USER_DEPRECATED);
    try {
        $callback();
    } finally {
        restore_error_handler();
    }

    return $deprecations;
}

/**
 * @param  array<string, mixed>  $items
 * @return array{config: Repository, deprecations: list<string>}
 */
function mergeGermanConfiguration(array $items): array
{
    $config = new Repository($items);
    $deprecations = germanDeprecations(fn () => GermanyServiceProvider::mergeConfiguration($config, GERMAN_DEFAULTS));

    return ['config' => $config, 'deprecations' => $deprecations];
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
    ['config' => $config, 'deprecations' => $deprecations] = mergeGermanConfiguration([
        'quittio' => ['countries' => ['de' => ['base_rates' => ['2027-01-01' => '1.77']]]],
    ]);

    expect($config->get('quittio.countries.de'))->toBe(['base_rates' => ['2027-01-01' => '1.77'], 'base_rate_file' => '/default/base-rates.json'])
        ->and($deprecations)->toBe([]);
});

it('reads the old configuration quittio-de with a deprecation', function () {
    ['config' => $config, 'deprecations' => $deprecations] = mergeGermanConfiguration([
        'quittio-de' => ['base_rates' => ['2027-01-01' => '1.77'], 'base_rate_file' => null],
    ]);

    expect($config->get('quittio.countries.de'))->toBe(['base_rates' => ['2027-01-01' => '1.77'], 'base_rate_file' => null])
        ->and($deprecations)->toBe([GERMAN_DEPRECATION]);
});

it('prefers quittio.countries.de where it differs from the defaults and quittio-de elsewhere', function () {
    // A newly published config/quittio/countries/de.php has the defaults: it does not hide the
    // rates of the old file. A value changed in the new file wins.
    ['config' => $config, 'deprecations' => $deprecations] = mergeGermanConfiguration([
        'quittio' => ['countries' => ['de' => ['base_rates' => [], 'base_rate_file' => '/new/rates.json']]],
        'quittio-de' => ['base_rates' => ['2027-01-01' => '1.77'], 'base_rate_file' => '/old/rates.json'],
    ]);

    expect($config->get('quittio.countries.de'))->toBe(['base_rates' => ['2027-01-01' => '1.77'], 'base_rate_file' => '/new/rates.json'])
        ->and($deprecations)->toBe([GERMAN_DEPRECATION.' quittio.countries.de sets base_rate_file as well; its value is used.']);

    // Merged again, e.g. from a cached configuration, the result stays the same.
    germanDeprecations(fn () => GermanyServiceProvider::mergeConfiguration($config, GERMAN_DEFAULTS));
    expect($config->get('quittio.countries.de'))->toBe(['base_rates' => ['2027-01-01' => '1.77'], 'base_rate_file' => '/new/rates.json']);
});

it('moves a pack class listed under the key de', function () {
    ['config' => $config] = mergeGermanConfiguration([
        'quittio' => ['countries' => [3 => 'Acme\CountryAT', 'de' => 'App\CountryDE', 'CH' => 'Acme\CountryCH']],
    ]);

    expect($config->get('quittio.countries'))->toBe([3 => 'Acme\CountryAT', 4 => 'App\CountryDE', 'CH' => 'Acme\CountryCH', 'de' => GERMAN_DEFAULTS]);
});

it('loads config/quittio/countries/de.php and the old config/quittio-de.php', function () {
    $config = loadConfigurationDirectory([
        'quittio.php' => "<?php return ['countries' => ['AT' => 'Acme\\\\CountryAT']];",
        'quittio/countries/de.php' => "<?php return ['base_rates' => ['2027-07-01' => '2.00']];",
        'quittio-de.php' => "<?php return ['base_rates' => ['2027-01-01' => '1.77'], 'base_rate_file' => null];",
    ]);
    $deprecations = germanDeprecations(fn () => GermanyServiceProvider::mergeConfiguration($config, GERMAN_DEFAULTS));
    GermanyServiceProvider::registerBaseRates($config);

    expect($config->get('quittio.countries'))->toBe(['AT' => 'Acme\CountryAT', 'de' => ['base_rates' => ['2027-07-01' => '2.00'], 'base_rate_file' => null]])
        ->and($deprecations)->toBe([GERMAN_DEPRECATION.' quittio.countries.de sets base_rates as well; its value is used.'])
        ->and(BaseRates::table()->rateOn(Date::of(2027, 8, 1))->toString())->toBe('2.00');
});

it('registers the base rates of an application that still has config/quittio-de.php', function () {
    config()->set('quittio.countries', ['DE' => CountryDE::class]);
    config()->set('quittio-de', ['base_rates' => ['2027-01-01' => '1.77'], 'base_rate_file' => null]);
    $provider = new GermanyServiceProvider(app());

    $deprecations = germanDeprecations(function () use ($provider): void {
        $provider->register();
        $provider->boot();
    });

    expect($deprecations)->toBe([GERMAN_DEPRECATION])
        ->and(config('quittio.countries.de'))->toBe(['base_rates' => ['2027-01-01' => '1.77'], 'base_rate_file' => null, 'consumer_default_notice' => true, 'prior_year_turnover_above_800k' => false, 'omit_vat_ids_for_category_o' => true])
        ->and(BaseRates::table()->rateOn(Date::of(2027, 3, 1))->toString())->toBe('1.77');
});

it('publishes the configuration to config/quittio/countries/de.php', function () {
    expect(ServiceProvider::publishableGroups())->toContain('quittio-de-config')
        ->and(ServiceProvider::pathsToPublish(GermanyServiceProvider::class, 'quittio-de-config'))
        ->toBe([dirname((string) (new ReflectionClass(GermanyServiceProvider::class))->getFileName(), 3).'/config/countries/de.php' => config_path('quittio/countries/de.php')]);
});

it('stores the updated base rates in quittio.countries.de.base_rate_file', function () {
    $new = sys_get_temp_dir().'/quittio-base-rates-new-'.getmypid().'.json';
    $old = sys_get_temp_dir().'/quittio-base-rates-old-'.getmypid().'.json';
    app()->instance(BundesbankBaseRates::class, new BundesbankBaseRates(fn (): string => "\"\";BBIN1.M.DE.BBK.BBKBAS2.EUR.ME;FLAGS\n2027-01;1,77;"));

    try {
        config()->set('quittio.countries.de.base_rate_file', $new);
        config()->set('quittio-de.base_rate_file', $old);
        $this->artisan('quittio:base-rate:update')->assertSuccessful();
        expect($new)->toBeFile()->and($old)->not->toBeFile();

        // Without quittio.countries.de (replaced at runtime), the old key is still read.
        File::delete($new);
        config()->set('quittio.countries', ['DE' => CountryDE::class]);
        $this->artisan('quittio:base-rate:update')->assertSuccessful();
        expect($old)->toBeFile()->and($new)->not->toBeFile();

        // Without both, the default of the pack.
        config()->set('quittio-de', null);
        expect(GermanyServiceProvider::setting(config(), 'base_rate_file'))->toBe(storage_path('app/quittio/base-rates.json'))
            ->and(GermanyServiceProvider::setting(config(), 'base_rates'))->toBe([]);

        config()->set('quittio.countries.de.base_rate_file', null);
        $this->artisan('quittio:base-rate:update')->expectsOutputToContain('quittio.countries.de.base_rate_file is not set')->assertFailed();
    } finally {
        File::delete([$new, $old]);
    }
});
