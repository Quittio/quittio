<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Exceptions\ConfigurationException;
use Quittio\Core\I18n\Translations;
use Quittio\Core\Pdf\Qr\PaymentQrGenerator;
use Quittio\Core\Serialization\Snapshots\SnapshotUpcaster;
use Quittio\Core\Serialization\Snapshots\SnapshotUpcasterChain;
use Quittio\Core\Testing\Scenarios;
use Quittio\Core\Validation\ValidationLevel;
use Quittio\Country\DE\CountryDE;
use Quittio\Laravel\Facades\Quittio;
use Quittio\Laravel\QuittioManager;
use Quittio\Laravel\Seller;
use Quittio\Laravel\Support\CountryPackDiscovery;
use Quittio\Laravel\Support\LaravelTranslator;
use Quittio\Laravel\Testing\QuittioFake;

it('registers the manager, configuration and views', function () {
    expect(app(QuittioManager::class))->toBe(app('quittio'))
        ->and(Quittio::getFacadeRoot())->toBeInstanceOf(QuittioManager::class)
        ->and(config('quittio.pdf.renderer'))->toBe('mpdf')
        ->and(view()->exists('quittio::templates.default.layout'))->toBeTrue()
        ->and(view()->exists('quittio::templates.default.partials.totals'))->toBeTrue()
        // Transmission settings are not part of this package.
        ->and(config('quittio.networks'))->toBeNull()
        ->and(config('quittio.format_resolver.peppol_lookup'))->toBeNull();
});

it('documents every public method of the manager and the fake on the facade', function () {
    preg_match_all('/@method\s+static\s+\S+\s+(\w+)\(([^)]*)\)/', (string) (new ReflectionClass(Quittio::class))->getDocComment(), $matches, PREG_SET_ORDER);
    $documented = [];
    foreach ($matches as [, $name, $parameters]) {
        preg_match_all('/\$(\w+)/', $parameters, $names);
        $documented[$name] = $names[1];
    }

    $methods = [];
    foreach ([QuittioManager::class, QuittioFake::class] as $class) {
        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (! str_starts_with($method->getName(), '__')) {
                $methods[$method->getName()] = array_map(static fn (ReflectionParameter $p): string => $p->getName(), $method->getParameters());
            }
        }
    }
    ksort($methods);
    ksort($documented);

    expect($documented)->toBe($methods);
});

it('bridges labels to the Laravel translator', function () {
    expect(Translations::translator())->toBeInstanceOf(LaravelTranslator::class)
        ->and(__('quittio::labels.amount_due', [], 'de'))->toBe('Zahlbetrag')
        ->and(Translations::translator()->get('quittio::labels.amount_due', [], 'en'))->toBe('Amount due');
});

it('registers configured country packs and their profiles', function () {
    expect(array_keys(Quittio::countries()->all()))->toBe(['DE'])
        ->and(Quittio::profiles()->has('xrechnung'))->toBeTrue()
        ->and(Quittio::formats()->has('xrechnung-cii'))->toBeTrue();
});

it('builds the seller from the configuration', function () {
    $seller = Seller::fromConfig();

    expect($seller->name)->toBe('Muster GmbH')
        ->and($seller->vatId)->toBe('DE123456789')
        ->and($seller->address->city)->toBe('Musterstadt')
        ->and($seller->electronicAddress?->value)->toBe('rechnung@muster.de');
});

it('maps the configuration to the core', function () {
    config()->set('quittio.validation.levels', ['php']);
    config()->set('quittio.pdf.colors.primary', '#ff0000');
    config()->set('quittio.validation.mandatory_field_guard', null);
    $config = app(QuittioManager::class)->flush()->core()->config();

    expect($config->validationLevels)->not->toContain(ValidationLevel::L4)
        ->and($config->presentation?->colors?->primary)->toBe('#ff0000')
        ->and($config->mandatoryFields)->toBe('exception');
});

it('rejects unknown extension types and renderers', function () {
    Quittio::extend('unknown', 'x', fn () => new stdClass);
})->throws(InvalidArgumentException::class);

it('discovers country packs from Composer metadata', function () {
    $vendor = dirname((string) (new ReflectionClass(ClassLoader::class))->getFileName(), 2);

    expect(CountryPackDiscovery::discover($vendor))->toContain(CountryDE::class)
        ->and(CountryPackDiscovery::discover('/nonexistent/vendor'))->toBe([]);
});

it('keeps discovered country packs in a cache file until Composer changes the packages', function () {
    $root = sys_get_temp_dir().'/quittio-discovery-'.bin2hex(random_bytes(4));
    mkdir($root.'/vendor/composer', 0775, true);
    $installed = $root.'/vendor/composer/installed.json';
    $cache = $root.'/countries.php';
    file_put_contents($root.'/composer.json', '{}');
    $write = static function (string $class, int $time) use ($installed): void {
        file_put_contents($installed, json_encode(['packages' => [['name' => 'acme/pack', 'extra' => ['quittio' => ['country-packs' => [$class]]]]]], JSON_THROW_ON_ERROR));
        touch($installed, $time);
    };

    try {
        $write(CountryDE::class, 1_000_000);
        expect(CountryPackDiscovery::discover($root.'/vendor', $cache))->toBe([CountryDE::class])
            ->and(is_file($cache))->toBeTrue();

        // Another process reads the cache file instead of installed.json.
        CountryPackDiscovery::flush();
        file_put_contents($installed, 'not JSON');
        touch($installed, 1_000_000);
        expect(CountryPackDiscovery::discover($root.'/vendor', $cache))->toBe([CountryDE::class]);

        // composer update rewrites installed.json: the cache is read again.
        $write(stdClass::class.'Missing', 2_000_000);
        expect(CountryPackDiscovery::discover($root.'/vendor', $cache))->toBe([]);
    } finally {
        CountryPackDiscovery::flush();
        array_map(unlink(...), [...(glob($root.'/vendor/composer/*') ?: []), ...(glob($root.'/*.*') ?: [])]);
        rmdir($root.'/vendor/composer');
        rmdir($root.'/vendor');
        rmdir($root);
    }
});

it('lets configured country packs replace discovered ones', function () {
    $custom = new class extends CountryDE {};
    config(['quittio.countries' => ['DE' => $custom::class]]);
    Quittio::flush();

    expect(Quittio::countries()->get('DE'))->toBeInstanceOf($custom::class);
});

it('registers the payment QR generator from the configuration', function () {
    $generator = new class implements PaymentQrGenerator
    {
        public function key(): string
        {
            return 'swiss-qr-bill';
        }

        public function supports(InvoiceData $invoice): bool
        {
            return true;
        }

        public function payload(InvoiceData $invoice, array $options = []): ?string
        {
            return 'SPC';
        }
    };
    config(['quittio.pdf.payment_qr' => ['type' => 'swiss-qr-bill', 'generator' => $generator::class]]);
    Quittio::flush();

    $html = Quittio::make(Scenarios::simple()->build())->format('html')->render()->html();

    expect($html)->toContain('data:image/png;base64');

    config(['quittio.pdf.payment_qr.generator' => stdClass::class]);
    Quittio::flush();
    expect(fn () => Quittio::core())->toThrow(ConfigurationException::class, 'does not implement');
});

it('registers snapshot upcasters from the configuration', function () {
    $upcaster = new class implements SnapshotUpcaster
    {
        public function from(): int
        {
            return 1;
        }

        public function upcast(array $snapshot): array
        {
            return $snapshot;
        }
    };
    config()->set('quittio.snapshots.upcasters', [$upcaster]);
    $this->app->forgetInstance(SnapshotUpcasterChain::class);

    expect(app(SnapshotUpcasterChain::class)->upcast(['a' => 1], 1, 2))->toBe(['a' => 1])
        ->and(SnapshotUpcasterChain::default())->toBeInstanceOf(SnapshotUpcasterChain::class);
});
