<?php

declare(strict_types=1);

namespace Quittio\Country\DE\Laravel;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\ServiceProvider;
use JsonException;
use Quittio\Core\Exceptions\RuntimeException;
use Quittio\Country\DE\Dunning\BaseRates;

/**
 * Laravel integration of the German country pack (discovered automatically): the base rates
 * of quittio.countries.de.base_rates and of the file written by quittio:base-rate:update are
 * registered with BaseRates, so that DefaultInterest uses them.
 *
 * The configuration of the pack is quittio.countries.de: config/quittio/countries/de.php
 * (tag quittio-de-config) or the section 'de' of 'countries' in config/quittio.php. The
 * configuration quittio-de (config/quittio-de.php) of 0.3 is refused, so that its settings are
 * not ignored silently.
 */
final class GermanyServiceProvider extends ServiceProvider
{
    /** Configuration key of the pack. */
    public const string CONFIG_KEY = 'quittio.countries.de';

    /** Configuration key of 0.3 and earlier (config/quittio-de.php), refused since 1.0. */
    private const string LEGACY_CONFIG_KEY = 'quittio-de';

    /**
     * Merges the defaults of the pack into quittio.countries.de.
     */
    public function register(): void
    {
        self::mergeConfiguration($this->app->make(Repository::class), self::defaults());
    }

    /**
     * Registers the configured and the updated base rates, the command and the publishable configuration.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            // quittio:install publishes the tag quittio-{code}-config of every country pack.
            $this->publishes([self::path('config/countries/de.php') => $this->app->configPath('quittio/countries/de.php')], 'quittio-de-config');
            $this->commands([UpdateBaseRateCommand::class]);
        }

        self::registerBaseRates($this->app->make(Repository::class));
    }

    /**
     * Fills quittio.countries.de: the keys set there, then the defaults. The result does not change
     * when it is merged again, e.g. from a cached configuration.
     *
     * @internal
     *
     * @param  array<array-key, mixed>  $defaults
     *
     * @throws RuntimeException while the configuration quittio-de of 0.3 is set
     */
    public static function mergeConfiguration(Repository $config, array $defaults): void
    {
        $legacy = $config->get(self::LEGACY_CONFIG_KEY);
        if (is_array($legacy) && $legacy !== []) {
            throw new RuntimeException(sprintf(
                'The configuration quittio-de (config/quittio-de.php) is no longer read since quittio/country-de 1.0: move its keys to %s (php artisan vendor:publish --tag=quittio-de-config publishes config/quittio/countries/de.php) and delete config/quittio-de.php. If the configuration is cached, delete bootstrap/cache/config.php as well.',
                self::CONFIG_KEY,
            ));
        }

        self::moveConfiguredPackClass($config);

        $current = $config->get(self::CONFIG_KEY);
        $current = is_array($current) ? $current : [];
        $config->set(self::CONFIG_KEY, array_replace($defaults, $current));
    }

    /**
     * A key of the configuration of the pack, e.g. 'base_rate_file': quittio.countries.de;
     * when that does not have the key (e.g. quittio.countries was replaced at runtime), the
     * default of the pack.
     *
     * @internal
     */
    public static function setting(Repository $config, string $key): mixed
    {
        return $config->has(self::CONFIG_KEY.'.'.$key)
            ? $config->get(self::CONFIG_KEY.'.'.$key)
            : self::defaults()[$key] ?? null;
    }

    /**
     * Registers the rates of the file and then those of the configuration (which win).
     */
    public static function registerBaseRates(Repository $config): void
    {
        $file = self::setting($config, 'base_rate_file');
        if (is_string($file) && $file !== '' && is_file($file)) {
            BaseRates::register(self::readFile($file));
        }
        $configured = self::setting($config, 'base_rates') ?? [];
        BaseRates::register(is_array($configured) ? $configured : []);
    }

    /**
     * @return array<array-key, mixed>
     */
    public static function readFile(string $file): array
    {
        try {
            $rates = json_decode((string) file_get_contents($file), true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException(sprintf('The base rate file %s is not valid JSON: %s', $file, $e->getMessage()), 0, $e);
        }

        return is_array($rates) ? $rates : [];
    }

    /**
     * The defaults of config/countries/de.php.
     *
     * @return array<array-key, mixed>
     */
    private static function defaults(): array
    {
        $defaults = require self::path('config/countries/de.php');

        return is_array($defaults) ? $defaults : [];
    }

    /**
     * quittio.countries lists pack classes under any key. A class listed under 'de' is moved
     * to a numeric key at the same position, so that 'de' can hold the configuration.
     */
    private static function moveConfiguredPackClass(Repository $config): void
    {
        $countries = $config->get('quittio.countries');
        if (! is_array($countries) || ! array_key_exists('de', $countries) || is_array($countries['de'])) {
            return;
        }

        $next = max([-1, ...array_filter(array_keys($countries), is_int(...))]) + 1;
        $moved = [];
        foreach ($countries as $key => $value) {
            $moved[$key === 'de' ? $next : $key] = $value;
        }
        $config->set('quittio.countries', $moved);
    }

    private static function path(string $path): string
    {
        return dirname(__DIR__, 2).'/'.$path;
    }
}
