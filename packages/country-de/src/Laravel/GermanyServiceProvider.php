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
 * of quittio-de.base_rates and of the file written by quittio:base-rate:update are registered
 * with BaseRates, so that DefaultInterest uses them.
 */
final class GermanyServiceProvider extends ServiceProvider
{
    /**
     * Merges the configuration quittio-de.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(self::path('config/quittio-de.php'), 'quittio-de');
    }

    /**
     * Registers the configured and the updated base rates, the command and the publishable configuration.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([self::path('config/quittio-de.php') => $this->app->configPath('quittio-de.php')], 'quittio-de-config');
            $this->commands([UpdateBaseRateCommand::class]);
        }

        self::registerBaseRates($this->app->make(Repository::class));
    }

    /**
     * Registers the rates of the file and then those of the configuration (which win).
     */
    public static function registerBaseRates(Repository $config): void
    {
        $file = $config->get('quittio-de.base_rate_file');
        if (is_string($file) && $file !== '' && is_file($file)) {
            BaseRates::register(self::readFile($file));
        }
        $configured = $config->get('quittio-de.base_rates', []);
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

    private static function path(string $path): string
    {
        return dirname(__DIR__, 2).'/'.$path;
    }
}
