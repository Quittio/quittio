<?php

declare(strict_types=1);

namespace Quittio\Laravel;

use Closure;
use Composer\Autoload\ClassLoader;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Support\Traits\Macroable;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Contracts\ProvidesInvoiceData;
use Quittio\Core\Countries\CountryPack;
use Quittio\Core\Countries\CountryRegistry;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Documents\DocumentBuilder;
use Quittio\Core\Documents\DocumentData;
use Quittio\Core\Exceptions\ConfigurationException;
use Quittio\Core\Formats\FormatRegistry;
use Quittio\Core\Formats\FormatResolver;
use Quittio\Core\Pdf\PdfRenderer;
use Quittio\Core\Pdf\Renderers\MpdfRenderer;
use Quittio\Core\Profiles\ProfileRegistry;
use Quittio\Core\Quittio;
use Quittio\Core\Reading\ReadResult;
use Quittio\Core\Rendering\RenderOptions;
use Quittio\Core\Validation\ValidationDriver;
use Quittio\Core\Validation\ValidationException;
use Quittio\Core\Validation\ValidationResult;
use Quittio\Core\Xml\XmlSerializer;
use Quittio\Laravel\Events\InvoiceRendered;
use Quittio\Laravel\Events\InvoiceRendering;
use Quittio\Laravel\Events\InvoiceValidated;
use Quittio\Laravel\Events\InvoiceValidationFailed;
use Quittio\Laravel\Events\MandatoryFieldMissing;
use Quittio\Laravel\Rendering\PendingDocument;
use Quittio\Laravel\Rendering\PendingInvoice;
use Quittio\Laravel\Rendering\RenderedDocument;
use Quittio\Laravel\Rendering\RenderedInvoice;
use Quittio\Laravel\Support\ConfigMapper;
use Quittio\Laravel\Support\CountryPackDiscovery;
use Quittio\Laravel\Support\Settings;
use Quittio\Laravel\Templates\BladeTemplateEngine;
use ReflectionClass;

/**
 * Root of the Quittio facade: builds the core from the Laravel configuration, adds
 * events, Blade templates, storage and extension points (specification 14).
 */
class QuittioManager
{
    use Macroable;

    public const array EXTENSION_TYPES = ['renderer', 'validator', 'serializer', 'format_resolver'];

    private ?Quittio $core = null;

    /** @var array<string, array<string, Closure(Container, array<string, mixed>): object>> */
    private array $extensions = ['renderer' => [], 'validator' => [], 'serializer' => [], 'format_resolver' => []];

    /** @var list<CountryPack> */
    private array $countryPacks = [];

    public function __construct(
        protected readonly Container $app,
    ) {}

    public function make(InvoiceData|ProvidesInvoiceData|InvoiceBuilder $data): PendingInvoice
    {
        $invoice = match (true) {
            $data instanceof ProvidesInvoiceData => $data->toInvoiceData(),
            $data instanceof InvoiceBuilder => $data->build(),
            default => $data,
        };

        return new PendingInvoice($this, $invoice);
    }

    /**
     * Builder for the full cancellation of an issued invoice; render it like any invoice:
     * Quittio::make(Quittio::cancellation($original, 'ST-2026-0001', 'Doppelt berechnet'))->render().
     */
    public function cancellation(InvoiceData $original, string $number, ?string $reason = null, ?string $strategy = null): InvoiceBuilder
    {
        return $this->core()->cancellation($original, $number, $reason, $strategy);
    }

    public function document(DocumentData|DocumentBuilder $document): PendingDocument
    {
        return new PendingDocument($this, $document instanceof DocumentBuilder ? $document->build() : $document);
    }

    public function read(string $pathOrContent): ReadResult
    {
        return $this->core()->read($pathOrContent);
    }

    public function validateFile(string $pathOrContent): ValidationResult
    {
        return $this->core()->validateFile($pathOrContent);
    }

    public function renderInvoice(InvoiceData $invoice, RenderOptions $options): RenderedInvoice
    {
        $events = $this->app->make(Dispatcher::class);
        $events->dispatch(new InvoiceRendering($invoice, $options));

        try {
            $result = new RenderedInvoice($this->core()->render($invoice, $options));
        } catch (ValidationException $e) {
            $events->dispatch(new InvoiceValidationFailed($invoice, $e->result));
            throw $e;
        }

        $events->dispatch(new InvoiceValidated($result->data(), $result->validation()));
        $events->dispatch(new InvoiceRendered($result));

        return $result;
    }

    public function check(InvoiceData $invoice, RenderOptions $options): ValidationResult
    {
        return $this->core()->check($invoice, $options);
    }

    public function renderDocument(DocumentData $document, RenderOptions $options): RenderedDocument
    {
        return new RenderedDocument($this->core()->renderDocument($document, $options));
    }

    /**
     * Registers a driver: renderer, validator, serializer or format_resolver.
     * The factory receives the container and the driver's configuration.
     *
     * @param  Closure(Container, array<string, mixed>): object  $factory
     */
    public function extend(string $type, string $name, Closure $factory): static
    {
        if (! in_array($type, self::EXTENSION_TYPES, true)) {
            throw new InvalidArgumentException(sprintf('Unknown extension type "%s". Known types: %s.', $type, implode(', ', self::EXTENSION_TYPES)));
        }

        $this->extensions[$type][$name] = $factory;
        $this->core = null;

        return $this;
    }

    public function registerCountry(CountryPack $pack): static
    {
        $this->countryPacks[] = $pack;
        $this->core?->registerCountry($pack);

        return $this;
    }

    public function countries(): CountryRegistry
    {
        return $this->core()->countries();
    }

    public function profiles(): ProfileRegistry
    {
        return $this->core()->profiles();
    }

    public function formats(): FormatRegistry
    {
        return $this->core()->formats();
    }

    public function settings(): Settings
    {
        $values = $this->app->make(Repository::class)->get('quittio', []);

        return new Settings(is_array($values) ? $values : []);
    }

    /**
     * Forgets the built core, e.g. after configuration changes in tests.
     */
    public function flush(): static
    {
        $this->core = null;

        return $this;
    }

    public function core(): Quittio
    {
        return $this->core ??= $this->build();
    }

    protected function build(): Quittio
    {
        $settings = $this->settings();
        $production = $this->app->bound('env') && $this->app->make('env') === 'production';
        $config = ConfigMapper::toCore($settings, $production);
        $logger = $this->app->bound(LoggerInterface::class) ? $this->app->make(LoggerInterface::class) : null;

        $quittio = Quittio::create(
            config: $config,
            countryPacks: $this->resolveCountryPacks($settings),
            renderer: $this->renderer($settings),
            templates: new BladeTemplateEngine($this->app->make(ViewFactory::class), $settings->string('pdf.template', 'quittio::templates.default.layout')),
            logger: $logger instanceof LoggerInterface ? $logger : null,
            formatResolver: $this->formatResolver($settings),
        );

        foreach ($this->extensions['serializer'] as $name => $factory) {
            $quittio->withSerializer($this->instance('serializer', $name, $factory($this->app, $settings->array('serializers.'.$name)), XmlSerializer::class));
        }
        foreach ($settings->strings('validation.levels') as $level) {
            $factory = $this->extensions['validator'][$level] ?? null;
            if ($factory !== null) {
                $quittio->withValidator($this->instance('validator', $level, $factory($this->app, $settings->array('validation.drivers.'.$level)), ValidationDriver::class));
            }
        }

        $events = $this->app->make(Dispatcher::class);
        $quittio->onMandatoryFieldsMissing(static function (InvoiceData $invoice, array $fields) use ($events): void {
            $events->dispatch(new MandatoryFieldMissing($invoice, $fields));
        });

        return $quittio;
    }

    /**
     * @return list<CountryPack>
     */
    private function resolveCountryPacks(Settings $settings): array
    {
        $classes = $settings->strings('countries');
        $vendor = dirname((string) (new ReflectionClass(ClassLoader::class))->getFileName(), 2);
        foreach (CountryPackDiscovery::discover($vendor) as $class) {
            $classes[] = $class;
        }

        $packs = [];
        foreach (array_unique($classes) as $class) {
            $pack = $this->app->make($class);
            if (! $pack instanceof CountryPack) {
                throw new ConfigurationException(sprintf('%s is not a country pack.', $class));
            }
            $packs[$pack->code()] = $pack;
        }
        foreach ($this->countryPacks as $pack) {
            $packs[$pack->code()] = $pack;
        }

        return array_values($packs);
    }

    private function renderer(Settings $settings): PdfRenderer
    {
        $name = $settings->string('pdf.renderer', 'mpdf');
        $options = $settings->section('pdf.renderers.'.$name);

        if (isset($this->extensions['renderer'][$name])) {
            return $this->instance('renderer', $name, $this->extensions['renderer'][$name]($this->app, $settings->array('pdf.renderers.'.$name)), PdfRenderer::class);
        }
        if ($name !== 'mpdf') {
            throw new ConfigurationException(sprintf('Unknown PDF renderer "%s". Register it with Quittio::extend(\'renderer\', ...).', $name));
        }

        $fonts = [];
        foreach ($settings->array('pdf.fonts') as $family => $variants) {
            foreach (is_array($variants) ? $variants : [] as $style => $file) {
                if (is_string($file)) {
                    $fonts[$family][(string) $style] = $file;
                }
            }
        }

        return new MpdfRenderer(
            tempDir: $settings->nullableString('pdf.cache_path'),
            fonts: $fonts,
            fontDirectories: $settings->strings('pdf.font_directories'),
            defaultFont: $settings->string('pdf.font', 'inter'),
            pdfaAuto: $options->bool('pdfa_auto', false),
            simpleTables: $options->bool('simple_tables', false),
        );
    }

    private function formatResolver(Settings $settings): ?FormatResolver
    {
        $name = $settings->string('format_resolver.driver', 'default');
        if ($name === 'default') {
            return null;
        }

        $factory = $this->extensions['format_resolver'][$name]
            ?? throw new ConfigurationException(sprintf('Unknown format resolver "%s".', $name));

        return $this->instance('format_resolver', $name, $factory($this->app, $settings->array('format_resolver')), FormatResolver::class);
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $expected
     * @return T
     */
    private function instance(string $type, string $name, object $instance, string $expected): object
    {
        if (! $instance instanceof $expected) {
            throw new ConfigurationException(sprintf('The %s "%s" must return an instance of %s.', $type, $name, $expected));
        }

        return $instance;
    }
}
