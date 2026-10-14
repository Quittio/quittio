<?php

declare(strict_types=1);

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;
use Quittio\Core\Pdf\Renderers\MpdfRenderer;
use Quittio\Core\Quittio as CoreQuittio;
use Quittio\Core\Testing\Scenarios;
use Quittio\Core\Validation\Drivers\KositArtifacts;
use Quittio\Laravel\Contracts\ManagedInstaller;
use Quittio\Laravel\Contracts\ManagedInvoiceRenderer;
use Quittio\Laravel\Facades\Quittio;
use Quittio\Laravel\QuittioServiceProvider;

function tempDirectory(): string
{
    $dir = sys_get_temp_dir().'/quittio-cmd-'.bin2hex(random_bytes(4));
    mkdir($dir);

    return $dir;
}

it('renders a preview', function (string $format) {
    $target = tempDirectory().'/preview.'.$format;

    $this->artisan('quittio:preview', ['--scenario' => 'mixed-vat', '--format' => $format, '--output' => $target])
        ->expectsOutputToContain('Preview written to')
        ->assertSuccessful();

    expect(file_get_contents($target))->toContain($format === 'pdf' ? '%PDF-' : 'VORSCHAU');
})->with(['pdf', 'html']);

it('rejects unknown preview scenarios', function () {
    $this->artisan('quittio:preview', ['--scenario' => 'nope'])->assertFailed();
});

it('renders an invoice from JSON', function () {
    $dir = tempDirectory();
    file_put_contents($dir.'/invoice.json', Scenarios::make('simple')->buyerReference('04011000-12345-03')->build()->toJson());

    $this->artisan('quittio:render', ['--json' => $dir.'/invoice.json', '--format' => 'xrechnung-cii', '--output' => $dir])
        ->expectsOutputToContain('RE-2026-0042 (xrechnung-cii, profile xrechnung)')
        ->assertSuccessful();

    expect(file_get_contents($dir.'/Rechnung_RE-2026-0042.xml'))->toContain('xrechnung_3.0');
});

it('reports invalid JSON invoices', function () {
    $dir = tempDirectory();
    file_put_contents($dir.'/invoice.json', Scenarios::make('simple')->number('')->build()->toJson());

    $this->artisan('quittio:render', ['--json' => $dir.'/invoice.json', '--format' => 'cii'])
        ->expectsOutputToContain('BR-02')
        ->assertFailed();
});

it('validates files', function () {
    $dir = tempDirectory();
    file_put_contents($dir.'/valid.pdf', Quittio::make(Scenarios::make('simple')->build())->format('zugferd')->render()->pdf());
    file_put_contents($dir.'/invalid.xml', str_replace('<ram:TypeCode>380</ram:TypeCode>', '<ram:TypeCode>999</ram:TypeCode>', Quittio::make(Scenarios::make('simple')->build())->format('cii')->render()->xml()));

    $this->artisan('quittio:validate', ['file' => $dir.'/valid.pdf', '--profile' => 'en16931'])
        ->expectsOutputToContain('The file is valid.')
        ->assertSuccessful();
    $this->artisan('quittio:validate', ['file' => $dir.'/valid.pdf', '--profile' => 'xrechnung'])->assertFailed();
    $this->artisan('quittio:validate', ['file' => $dir.'/invalid.xml'])->assertFailed();
    $this->artisan('quittio:validate', ['file' => $dir.'/missing.xml'])->assertFailed();
});

it('validates with all configured settings of the KoSIT and veraPDF drivers', function () {
    $dir = tempDirectory();
    file_put_contents($dir.'/valid.pdf', Quittio::make(Scenarios::make('simple')->build())->format('zugferd')->render()->pdf());
    config([
        'quittio.validation.drivers.kosit' => ['mode' => 'cli', 'jar' => '/nonexistent/validator.jar', 'java' => '/nonexistent/java', 'timeout' => 7, 'fail_on_unavailable' => false],
        'quittio.validation.drivers.verapdf' => ['mode' => 'docker', 'docker' => '/nonexistent/docker', 'timeout' => 9, 'fail_on_unavailable' => false],
    ]);
    Quittio::flush();

    // fail_on_unavailable = false: missing programs are warnings, the file stays valid.
    $this->artisan('quittio:validate', ['file' => $dir.'/valid.pdf', '--kosit' => true, '--verapdf' => true])
        ->expectsOutputToContain('KOSIT-UNAVAILABLE')
        ->expectsOutputToContain('VERAPDF-UNAVAILABLE')
        ->expectsOutputToContain('The file is valid.')
        ->assertSuccessful();

    $drivers = [];
    foreach (Quittio::coreWithLevels(['kosit', 'verapdf'])->validators() as $driver) {
        $drivers[$driver->name()] = $driver;
    }
    $value = static fn (string $driver, string $property): mixed => (new ReflectionProperty($drivers[$driver], $property))->getValue($drivers[$driver]);

    expect($value('kosit', 'java'))->toBe('/nonexistent/java')
        ->and($value('kosit', 'timeout'))->toBe(7)
        ->and($value('kosit', 'failOnUnavailable'))->toBeFalse()
        ->and($value('verapdf', 'mode'))->toBe('docker')
        ->and($value('verapdf', 'docker'))->toBe('/nonexistent/docker')
        ->and($value('verapdf', 'timeout'))->toBe(9)
        ->and($value('verapdf', 'failOnUnavailable'))->toBeFalse()
        // The cached core of the application keeps the configured levels.
        ->and(array_map(static fn ($driver): string => $driver->name(), Quittio::core()->validators()))->not->toContain('kosit')->not->toContain('verapdf');

    config()->set('quittio.validation.drivers.kosit.fail_on_unavailable', true);
    Quittio::flush();
    $this->artisan('quittio:validate', ['file' => $dir.'/valid.pdf', '--kosit' => true])->assertFailed();
});

it('reads files', function () {
    $dir = tempDirectory();
    file_put_contents($dir.'/invoice.xml', Quittio::make(Scenarios::make('simple')->build())->format('ubl')->render()->xml());

    $this->artisan('quittio:read', ['file' => $dir.'/invoice.xml'])
        ->expectsOutputToContain('RE-2026-0042')
        ->expectsOutputToContain('Webentwicklung')
        ->assertSuccessful();
    $this->artisan('quittio:read', ['file' => $dir.'/invoice.xml', '--json' => true])
        ->expectsOutputToContain('"number": "RE-2026-0042"')
        ->assertSuccessful();
});

it('lists the country packs', function () {
    $this->artisan('quittio:countries')
        ->expectsOutputToContain('Quittio\Country\DE\CountryDE')
        ->assertSuccessful();
});

it('measures performance', function () {
    $this->artisan('quittio:benchmark', ['--count' => '1', '--format' => ['cii']])
        ->expectsOutputToContain('Peak memory')
        ->assertSuccessful();
});

it('creates a template from the default', function () {
    $target = resource_path('views/invoices/acme');
    File::deleteDirectory($target);

    $this->artisan('make:quittio-template', ['name' => 'acme'])->assertSuccessful();

    expect(file_get_contents($target.'/layout.blade.php'))->toContain("'invoices.acme.partials'")
        ->and($target.'/partials/totals.blade.php')->toBeFile();

    $html = Quittio::make(Scenarios::make('simple')->build())->format('html')->template('invoices.acme.layout')->render()->html();
    expect($html)->toContain('Zahlbetrag');

    $this->artisan('make:quittio-template', ['name' => 'acme'])->assertFailed();
    $this->artisan('make:quittio-template', ['name' => 'Bad Name'])->assertFailed();

    // The preview accepts the same views as rendering (quittio.pdf.templates).
    $preview = tempDirectory().'/acme.html';
    $this->artisan('quittio:preview', ['--template' => 'invoices.acme.layout', '--format' => 'html', '--output' => $preview])->assertSuccessful();
    expect(file_get_contents($preview))->toContain('Zahlbetrag');
    $this->artisan('quittio:preview', ['--template' => 'invoices.acme.partials.totals', '--format' => 'html'])->assertFailed();
    $this->artisan('quittio:preview', ['--template' => 'invoices.missing.layout', '--format' => 'html'])->assertFailed();

    config()->set('quittio.pdf.templates', []);
    $this->artisan('quittio:preview', ['--template' => 'invoices.acme.layout', '--format' => 'html'])->assertFailed();
    File::deleteDirectory($target);
});

it('publishes the fonts so that the renderer finds them', function () {
    // --tag=quittio-fonts copies the bundled fonts to resources/fonts/quittio.
    $mapping = ServiceProvider::pathsToPublish(QuittioServiceProvider::class, 'quittio-fonts');
    expect(array_values($mapping))->toBe([resource_path('fonts/quittio')]);

    // Published into an application directory of this process: the shared Testbench skeleton
    // must not change while other test processes render with it.
    $base = sys_get_temp_dir().'/quittio-fonts-'.getmypid();
    File::deleteDirectory($base);
    app()->setBasePath($base);
    $published = resource_path('fonts/quittio');
    $directories = static fn (): array => (new ReflectionProperty(MpdfRenderer::class, 'fontDirectories'))->getValue((new ReflectionProperty(CoreQuittio::class, 'renderer'))->getValue(Quittio::flush()->core()));

    expect($directories())->not->toContain($published);

    File::copyDirectory((string) array_key_first($mapping), $published);
    // Fonts that exist only in the published directories (Inter is published to quittio/inter).
    copy($published.'/inter/Inter-Regular.ttf', $published.'/inter/PublishedTest-Regular.ttf');
    copy($published.'/inter/Inter-Bold.ttf', $published.'/PublishedTest-Bold.ttf');
    config()->set('quittio.pdf.font_directories', ['/configured/fonts']);
    config()->set('quittio.pdf.fonts', ['publishedtest' => ['R' => 'PublishedTest-Regular.ttf', 'B' => 'PublishedTest-Bold.ttf']]);
    config()->set('quittio.pdf.font', 'publishedtest');

    expect($directories())->toBe(['/configured/fonts', $published, $published.'/inter'])
        ->and(Quittio::make(Scenarios::make('simple')->build())->format('pdf')->render()->pdf())->toStartWith('%PDF-');

    // Configured explicitly, a directory is not added twice and keeps its position.
    config()->set('quittio.pdf.font_directories', [$published.'/inter/', '/configured/fonts']);
    expect($directories())->toBe([$published.'/inter/', '/configured/fonts', $published]);
    File::deleteDirectory($base);
});

it('publishes the configuration', function () {
    File::delete(config_path('quittio.php'));

    $this->artisan('quittio:install')->assertSuccessful();

    expect(config_path('quittio.php'))->toBeFile();
    File::delete(config_path('quittio.php'));
});

it('downloads the validator artifacts and compiles the Peppol rules', function () {
    $zip = static function (array $files): string {
        $path = tempDirectory().'/archive.zip';
        $archive = new ZipArchive;
        $archive->open($path, ZipArchive::CREATE);
        foreach ($files as $name => $content) {
            $archive->addFromString($name, $content);
        }
        $archive->close();

        return (string) file_get_contents($path);
    };
    $configuration = $zip(['scenarios.xml' => '<scenarios/>']);
    $schxslt = $zip(['xslt/2.0/pipeline-for-svrl.xsl' => '<xsl/>']);
    $commands = [];
    $this->app->instance(KositArtifacts::class, new KositArtifacts(
        fetch: fn (string $url): string => match (true) {
            str_ends_with($url, 'standalone.jar') => 'jar',
            str_ends_with($url, '.sch') => '<schema/>',
            str_contains($url, 'schxslt') => $schxslt,
            default => $configuration,
        },
        run: function (array $command) use (&$commands): array {
            $commands[] = $command;
            file_put_contents(substr((string) end($command), 3), '<compiled/>');

            return ['exitCode' => 0, 'stdout' => '', 'stderr' => ''];
        },
    ));
    $target = tempDirectory();

    $this->artisan('quittio:schemas:download', ['--path' => $target])
        // The daemon is started on the port of the default URL (the validator itself defaults to 8080).
        ->expectsOutputToContain('-D -P 8081')
        ->expectsOutputToContain("'jar' => '{$target}/validator.jar'")
        ->expectsOutputToContain('peppol-scenarios.xml')
        ->assertSuccessful();

    expect($target.'/xrechnung/scenarios.xml')->toBeFile()
        ->and($target.'/peppol/PEPPOL-EN16931-UBL.xsl')->toBeFile()
        ->and($commands[0])->toContain('net.sf.saxon.Transform');
})->skip(! class_exists(ZipArchive::class), 'The zip extension is not available.');

it('can skip the Peppol rules', function () {
    $zip = tempDirectory().'/config.zip';
    $archive = new ZipArchive;
    $archive->open($zip, ZipArchive::CREATE);
    $archive->addFromString('scenarios.xml', '<scenarios/>');
    $archive->close();
    $this->app->instance(KositArtifacts::class, new KositArtifacts(fn (string $url): string => str_ends_with($url, '.jar') ? 'jar' : (string) file_get_contents($zip)));
    $target = tempDirectory();

    $this->artisan('quittio:schemas:download', ['--path' => $target, '--without-peppol' => true])->assertSuccessful();

    expect($target.'/peppol')->not->toBeDirectory();
})->skip(! class_exists(ZipArchive::class), 'The zip extension is not available.');

it('regenerates code lists when the Schematron is available', function () {
    $xsl = dirname(__DIR__, 4).'/tools/validators/xrechnung/resources/cii/16b/xsl/EN16931-CII-validation.xsl';
    $target = tempDirectory();

    $this->artisan('quittio:codelists:update', ['xsl' => $xsl, '--target' => $target])
        ->expectsOutputToContain('unit-codes')
        ->assertSuccessful();

    expect($target.'/vatex-codes.json')->toBeFile();
})->skip(! is_file(dirname(__DIR__, 4).'/tools/validators/xrechnung/resources/cii/16b/xsl/EN16931-CII-validation.xsl'), 'Run tools/schemas/download.sh first.');

it('fails gracefully without Schematron', function () {
    $this->artisan('quittio:codelists:update', ['xsl' => '/missing.xsl'])->assertFailed();
});

it('points to quittio/managed when the Managed layer is missing', function () {
    $this->artisan('quittio:install', ['--managed' => true])->expectsOutputToContain('quittio/managed')->assertFailed();
    $this->artisan('quittio:render', ['invoice' => 'RE-1'])->expectsOutputToContain('quittio/managed')->assertFailed();
});

it('delegates to the Managed layer when it is installed', function () {
    $calls = [];
    $this->app->instance(ManagedInstaller::class, new class($calls) implements ManagedInstaller
    {
        public function __construct(private array &$calls) {}

        public function install(Command $command, bool $force): int
        {
            $this->calls[] = 'install';

            return 0;
        }
    });
    $this->app->instance(ManagedInvoiceRenderer::class, new class($calls) implements ManagedInvoiceRenderer
    {
        public function __construct(private array &$calls) {}

        public function render(string $invoice, Command $command): int
        {
            $this->calls[] = 'render '.$invoice;

            return 0;
        }
    });

    $this->artisan('quittio:install', ['--managed' => true])->assertSuccessful();
    $this->artisan('quittio:render', ['invoice' => 'RE-2026-0042'])->assertSuccessful();

    expect($calls)->toBe(['install', 'render RE-2026-0042']);
    File::delete(config_path('quittio.php'));
});
