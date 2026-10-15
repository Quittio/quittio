<?php

declare(strict_types=1);

namespace Quittio\Laravel;

use Closure;
use Composer\Autoload\ClassLoader;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Support\Traits\Macroable;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Config;
use Quittio\Core\Contracts\ProvidesInvoiceData;
use Quittio\Core\Corrections\CancellationHistory;
use Quittio\Core\Countries\CountryPack;
use Quittio\Core\Countries\CountryRegistry;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Data\Party\BuyerParty;
use Quittio\Core\Documents\DocumentBuilder;
use Quittio\Core\Documents\DocumentData;
use Quittio\Core\Exceptions\ConfigurationException;
use Quittio\Core\Formats\DefaultFormatResolver;
use Quittio\Core\Formats\FormatRegistry;
use Quittio\Core\Formats\FormatResolver;
use Quittio\Core\Pdf\PdfRenderer;
use Quittio\Core\Pdf\Qr\PaymentQrGenerator;
use Quittio\Core\Pdf\Renderers\MpdfRenderer;
use Quittio\Core\Profiles\ProfileRegistry;
use Quittio\Core\Quittio;
use Quittio\Core\Reading\ReadResult;
use Quittio\Core\Rendering\PreparedInvoice;
use Quittio\Core\Rendering\RenderOptions;
use Quittio\Core\Support\Date;
use Quittio\Core\Tax\CountryDefaultVat;
use Quittio\Core\Tax\TaxPreset;
use Quittio\Core\Validation\Drivers\PhpRulesDriver;
use Quittio\Core\Validation\Plausibility\VatIdVerifier;
use Quittio\Core\Validation\Plausibility\ViesVerifier;
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
 * events, Blade templates, storage and extension points.
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
     * Builder for the cancellation of an issued invoice; render it like any invoice:
     * Quittio::make(Quittio::cancellation($original, 'ST-2026-0001', 'Doppelt berechnet'))->render().
     *
     * @param  list<array{line: string, quantity?: \Brick\Math\BigDecimal|int|string, amount?: string}>|null  $lines  partial cancellation; null = full
     * @param  CancellationHistory|null  $history  earlier partial cancellations, for the cumulative rounding of the VAT
     * @param  string|null  $title  document title in the PDF, e.g. "Stornorechnung"; null = the title of the country
     *                               pack's correction policy
     */
    public function cancellation(InvoiceData $original, string $number, ?string $reason = null, ?string $strategy = null, ?Date $issueDate = null, ?array $lines = null, ?CancellationHistory $history = null, ?string $title = null): InvoiceBuilder
    {
        return $this->core()->cancellation($original, $number, $reason, $strategy, $issueDate, $lines, $history, $title);
    }

    public function document(DocumentData|DocumentBuilder $document): PendingDocument
    {
        return new PendingDocument($this, $document instanceof DocumentBuilder ? $document->build() : $document);
    }

    public function read(string $content): ReadResult
    {
        return $this->core()->read($content);
    }

    public function readFile(string $path): ReadResult
    {
        return $this->core()->readFile($path);
    }

    public function validateFile(string $content): ValidationResult
    {
        return $this->core()->validateFile($content);
    }

    public function validatePath(string $path): ValidationResult
    {
        return $this->core()->validatePath($path);
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

        // Mode "off" validates nothing: an empty result must not look like a passed validation.
        if (($options->validation ?? $this->core()->config()->validation) !== Config::VALIDATION_OFF) {
            $events->dispatch(new InvoiceValidated($result->data(), $result->validation()));
        }
        $events->dispatch(new InvoiceRendered($result));

        return $result;
    }

    public function check(InvoiceData $invoice, RenderOptions $options): ValidationResult
    {
        return $this->core()->check($invoice, $options);
    }

    public function prepare(InvoiceData $invoice, RenderOptions $options): PreparedInvoice
    {
        return $this->core()->prepare($invoice, $options);
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

    /**
     * VAT of invoice lines without VAT treatment (InvoiceBuilder::useDefaultVat()): the preset
     * of quittio.defaults.vat, otherwise the standard preset of the seller's country pack;
     * invoices of small businesses use the small business preset of the pack.
     */
    public function defaultVat(?string $sellerCountry, bool $smallBusiness): ?TaxPreset
    {
        $key = $this->settings()->nullableString('defaults.vat');
        $preset = $key === null ? null : ($this->countries()->taxPreset($key)
            ?? throw new ConfigurationException(sprintf('quittio.defaults.vat: unknown tax preset "%s".', $key)));

        return (new CountryDefaultVat($this->countries(), $preset))($sellerCountry, $smallBusiness);
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

    /**
     * A new core (not the cached one) that validates with the configured levels plus
     * $levels, every driver with its settings of validation.drivers.* exactly as in a render
     * run; e.g. quittio:validate --kosit --verapdf.
     *
     * @param  list<string>  $levels  names as in quittio.validation.levels, e.g. kosit, verapdf
     */
    public function coreWithLevels(array $levels): Quittio
    {
        return $this->build(array_values(array_unique([...$this->settings()->strings('validation.levels'), ...$levels])));
    }

    /**
     * @param  list<string>|null  $levels  validation levels instead of quittio.validation.levels
     */
    protected function build(?array $levels = null): Quittio
    {
        $settings = $this->settings();
        $levels ??= $settings->strings('validation.levels');
        $production = $this->app->bound('env') && $this->app->make('env') === 'production';
        $config = ConfigMapper::toCore($settings, $production, $levels);
        $logger = $this->app->bound(LoggerInterface::class) ? $this->app->make(LoggerInterface::class) : null;

        $quittio = Quittio::create(
            config: $config,
            countryPacks: $this->resolveCountryPacks($settings),
            renderer: $this->renderer($settings),
            templates: BladeTemplateEngine::fromSettings($this->app->make(ViewFactory::class), $settings),
            logger: $logger instanceof LoggerInterface ? $logger : null,
            formatResolver: $this->formatResolver($settings, $config),
        );

        $this->withVatIdVerifier($quittio, $settings->section('validation.drivers.vies'));
        $generator = $settings->nullableString('pdf.payment_qr.generator');
        if ($generator !== null) {
            $instance = $this->app->make($generator);
            if (! $instance instanceof PaymentQrGenerator) {
                throw new ConfigurationException(sprintf('quittio.pdf.payment_qr.generator: %s does not implement %s.', $generator, PaymentQrGenerator::class));
            }
            $quittio->withPaymentQrGenerator($instance);
        }

        foreach ($this->extensions['serializer'] as $name => $factory) {
            $quittio->withSerializer($this->instance('serializer', $name, $factory($this->app, $settings->array('serializers.'.$name)), XmlSerializer::class));
        }
        foreach ($levels as $level) {
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
     * Online VAT identifier check (validation.drivers.vies): the PHP rules get a VIES verifier.
     * A VatIdVerifier bound in the container replaces the VIES client (e.g. in tests).
     */
    private function withVatIdVerifier(Quittio $quittio, Settings $vies): void
    {
        if (! $vies->bool('enabled', false)) {
            return;
        }

        $verifier = $this->app->bound(VatIdVerifier::class) ? $this->app->make(VatIdVerifier::class) : null;
        $verifier = $verifier instanceof VatIdVerifier ? $verifier : new ViesVerifier($vies->int('timeout', 5), $vies->int('cache_ttl', 86400));

        $validators = $quittio->validators();
        $quittio->withoutValidators();
        foreach ($validators as $driver) {
            $quittio->withValidator($driver instanceof PhpRulesDriver ? $driver->withVatIdVerifier($verifier) : $driver);
        }
    }

    /**
     * @return list<CountryPack>
     */
    private function resolveCountryPacks(Settings $settings): array
    {
        // Per country code the last one wins: discovered packs, then quittio.countries (e.g. a
        // subclass of a discovered pack), then registerCountry().
        $vendor = dirname((string) (new ReflectionClass(ClassLoader::class))->getFileName(), 2);
        $classes = [...CountryPackDiscovery::discover($vendor), ...$settings->strings('countries')];

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
            fontDirectories: $this->fontDirectories($settings),
            defaultFont: $settings->string('pdf.font', 'inter'),
            pdfaAuto: $options->bool('pdfa_auto', false),
            simpleTables: $options->bool('simple_tables', false),
        );
    }

    /**
     * pdf.font_directories, then resources/fonts/quittio and its subdirectories (--tag=quittio-fonts
     * publishes Inter to resources/fonts/quittio/inter) when they exist and are not configured
     * already. mPDF takes a font file from the first directory that has it.
     *
     * @return list<string>
     */
    private function fontDirectories(Settings $settings): array
    {
        $directories = $settings->strings('pdf.font_directories');
        $published = $this->app instanceof Application ? $this->app->resourcePath('fonts/quittio') : null;
        if ($published === null || ! is_dir($published)) {
            return $directories;
        }

        $normalize = static fn (string $directory): string => rtrim($directory, '/\\');
        $configured = array_map($normalize, $directories);
        $subdirectories = glob($published.'/*', GLOB_ONLYDIR);
        foreach ([$published, ...(is_array($subdirectories) ? $subdirectories : [])] as $directory) {
            if (! in_array($normalize($directory), $configured, true)) {
                $directories[] = $directory;
            }
        }

        return $directories;
    }

    /**
     * The resolver of format_resolver.driver. For "default" the core builds its own resolver
     * unless format_resolver.peppol_lookup names a lookup: then it is the same resolver with
     * that lookup.
     */
    private function formatResolver(Settings $settings, Config $config): ?FormatResolver
    {
        $name = $settings->string('format_resolver.driver', 'default');
        if ($name === 'default') {
            $lookup = $this->peppolLookup($settings);

            return $lookup === null ? null : new DefaultFormatResolver($config->profile ?? Quittio::DEFAULT_PROFILE, $config->autoUpgradeProfile, $lookup);
        }

        $factory = $this->extensions['format_resolver'][$name]
            ?? throw new ConfigurationException(sprintf('Unknown format resolver "%s".', $name));

        return $this->instance('format_resolver', $name, $factory($this->app, $settings->array('format_resolver')), FormatResolver::class);
    }

    /**
     * format_resolver.peppol_lookup: a class resolved from the container (or a callable set at
     * runtime) that receives the BuyerParty and returns whether the buyer is reachable via Peppol.
     *
     * @return (Closure(BuyerParty): bool)|null
     */
    private function peppolLookup(Settings $settings): ?Closure
    {
        $lookup = $settings->get('format_resolver.peppol_lookup');
        if ($lookup === null || $lookup === '') {
            return null;
        }

        $instance = is_string($lookup) ? $this->app->make($lookup) : $lookup;
        if (! is_callable($instance)) {
            throw new ConfigurationException(sprintf('quittio.format_resolver.peppol_lookup: %s is not callable; use an invokable class that receives the %s and returns a bool.', is_string($lookup) ? $lookup : get_debug_type($lookup), BuyerParty::class));
        }

        return static fn (BuyerParty $buyer): bool => $instance($buyer) === true;
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
