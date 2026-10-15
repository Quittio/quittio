<?php

declare(strict_types=1);

namespace Quittio\Laravel;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Illuminate\Translation\Translator as IlluminateTranslator;
use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Exceptions\ConfigurationException;
use Quittio\Core\I18n\Translations;
use Quittio\Core\Profiles\Profile;
use Quittio\Core\Serialization\Snapshots\SnapshotUpcaster;
use Quittio\Core\Serialization\Snapshots\SnapshotUpcasterChain;
use Quittio\Core\Tax\TaxPreset;
use Quittio\Laravel\Builder\ConfigInvoiceDefaults;
use Quittio\Laravel\Commands\BenchmarkCommand;
use Quittio\Laravel\Commands\CodeListsUpdateCommand;
use Quittio\Laravel\Commands\CountriesCommand;
use Quittio\Laravel\Commands\InstallCommand;
use Quittio\Laravel\Commands\MakeTemplateCommand;
use Quittio\Laravel\Commands\PreviewCommand;
use Quittio\Laravel\Commands\ReadCommand;
use Quittio\Laravel\Commands\RenderCommand;
use Quittio\Laravel\Commands\SchemasDownloadCommand;
use Quittio\Laravel\Commands\ValidateCommand;
use Quittio\Laravel\Contracts\InvoiceDefaults;
use Quittio\Laravel\Http\AuthorizePreview;
use Quittio\Laravel\Support\LaravelTranslator;
use Quittio\Laravel\Support\Preview;
use ReflectionClass;

/**
 * Registers the Quittio manager, configuration, views, translations, commands and the
 * local preview route.
 */
final class QuittioServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(self::packagePath('config/quittio.php'), 'quittio');

        $this->app->singleton(QuittioManager::class, static fn (Application $app): QuittioManager => new QuittioManager($app));
        // quittio/managed binds the current issuer instead.
        $this->app->bindIf(InvoiceDefaults::class, ConfigInvoiceDefaults::class);
        $this->app->alias(QuittioManager::class, 'quittio');
        $this->app->singleton(Preview::class);
        $this->app->singleton(SnapshotUpcasterChain::class, function (Application $app): SnapshotUpcasterChain {
            $chain = new SnapshotUpcasterChain;
            $classes = $app->make(Repository::class)->get('quittio.snapshots.upcasters', []);
            foreach (is_array($classes) ? $classes : [] as $class) {
                $upcaster = is_string($class) ? $app->make($class) : $class;
                if (! $upcaster instanceof SnapshotUpcaster) {
                    throw new ConfigurationException(sprintf('quittio.snapshots.upcasters: %s is not a SnapshotUpcaster.', is_string($class) ? $class : get_debug_type($class)));
                }
                $chain->register($upcaster);
            }

            return $chain;
        });
    }

    public function boot(): void
    {
        $this->loadViewsFrom(self::packagePath('resources/views'), 'quittio');
        $this->loadTranslationsFrom(self::corePath('resources/lang'), 'quittio');

        $this->bridgeTranslations();
        // InvoiceData::fromSnapshot() uses the configured upcasters.
        SnapshotUpcasterChain::useDefault($this->app->make(SnapshotUpcasterChain::class));
        // Lines without ->vat() use quittio.defaults.vat or the default of the seller's country,
        // read from the current application (under Octane the one of the request).
        InvoiceBuilder::useDefaultVat(static fn (?string $country, bool $smallBusiness): ?TaxPreset => Container::getInstance()->make(QuittioManager::class)->defaultVat($country, $smallBusiness));
        $this->registerPublishing();
        $this->registerPreviewRoutes();

        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
                PreviewCommand::class,
                RenderCommand::class,
                ValidateCommand::class,
                ReadCommand::class,
                BenchmarkCommand::class,
                CodeListsUpdateCommand::class,
                SchemasDownloadCommand::class,
                CountriesCommand::class,
                MakeTemplateCommand::class,
            ]);

            AboutCommand::add('Quittio', fn (): array => [
                'Country packs' => fn (): string => implode(', ', array_keys($this->app->make(QuittioManager::class)->countries()->all())),
                'Profiles' => fn (): string => implode(', ', array_map(static fn (Profile $p): string => $p->key(), array_values($this->app->make(QuittioManager::class)->profiles()->all()))),
            ]);
        }
    }

    public static function packagePath(string $path = ''): string
    {
        return dirname(__DIR__, 2).($path === '' ? '' : '/'.$path);
    }

    public static function corePath(string $path = ''): string
    {
        $core = dirname((string) (new ReflectionClass(\Quittio\Core\Quittio::class))->getFileName(), 2);

        return $core.($path === '' ? '' : '/'.$path);
    }

    /**
     * Labels and messages come from Laravel's translator, so that lang/vendor/quittio overrides work.
     */
    private function bridgeTranslations(): void
    {
        $translator = $this->app->make('translator');
        if ($translator instanceof IlluminateTranslator) {
            Translations::use(new LaravelTranslator($translator));
        }
    }

    private function registerPublishing(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([self::packagePath('config/quittio.php') => $this->app->configPath('quittio.php')], 'quittio-config');
        $this->publishes([self::packagePath('resources/views/templates') => $this->app->resourcePath('views/vendor/quittio/templates')], 'quittio-views');
        $this->publishes([self::corePath('resources/lang') => $this->app->langPath('vendor/quittio')], 'quittio-lang');
        $this->publishes([self::corePath('resources/fonts') => $this->app->resourcePath('fonts/quittio')], 'quittio-fonts');
    }

    /**
     * Registers /_quittio/preview when enabled (default: local environment only). Requests
     * pass the configured middleware, the gate "viewQuittioPreview" (AuthorizePreview) and
     * the throttle of quittio.preview.throttle.
     */
    private function registerPreviewRoutes(): void
    {
        $config = $this->app->make(Repository::class);
        $enabled = $config->get('quittio.preview.enabled');
        $enabled = is_bool($enabled) ? $enabled : $this->app->environment('local') === true;
        if (! $enabled || ($this->app instanceof CachesRoutes && $this->app->routesAreCached())) {
            return;
        }

        $path = $config->get('quittio.preview.path', '_quittio/preview');
        $middleware = $config->get('quittio.preview.middleware', ['web']);
        $middleware = is_array($middleware) ? array_values(array_filter($middleware, is_string(...))) : [];
        $middleware[] = AuthorizePreview::class;
        $throttle = $config->get('quittio.preview.throttle', '30,1');
        if (is_string($throttle) && $throttle !== '') {
            $middleware[] = 'throttle:'.$throttle;
        }

        $router = $this->app->make(Router::class);
        $router->group([
            'prefix' => is_string($path) ? $path : '_quittio/preview',
            'middleware' => $middleware,
        ], self::packagePath('routes/preview.php'));
        $router->getRoutes()->refreshNameLookups();
    }
}
