<?php

declare(strict_types=1);

namespace Quittio\Laravel\Support;

use Closure;
use Composer\Autoload\ClassLoader;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Psr\Log\LoggerInterface;
use Quittio\Core\Calculation\TotalsCalculator;
use Quittio\Core\Config;
use Quittio\Core\Countries\CountryPack;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Data\Party\BuyerParty;
use Quittio\Core\Exceptions\ConfigurationException;
use Quittio\Core\Formats\DefaultFormatResolver;
use Quittio\Core\Formats\FormatResolver;
use Quittio\Core\Pdf\PdfRenderer;
use Quittio\Core\Pdf\Qr\PaymentQrGenerator;
use Quittio\Core\Pdf\Renderers\MpdfRenderer;
use Quittio\Core\Quittio;
use Quittio\Core\Validation\Drivers\PhpRulesDriver;
use Quittio\Core\Validation\Plausibility\VatIdVerifier;
use Quittio\Core\Validation\Plausibility\ViesVerifier;
use Quittio\Core\Validation\Rule;
use Quittio\Core\Validation\RuleOverrides;
use Quittio\Core\Validation\ValidationDriver;
use Quittio\Core\Xml\XmlSerializer;
use Quittio\Laravel\Events\MandatoryFieldMissing;
use Quittio\Laravel\Templates\BladeTemplateEngine;
use ReflectionClass;

/**
 * Builds the core from the quittio configuration: country packs, renderer, Blade templates,
 * format resolver, VIES, payment QR generator, the registered drivers and the event bridge.
 *
 * @internal
 */
final readonly class CoreFactory
{
    public function __construct(
        private Container $container,
        private DriverRegistry $drivers,
        private bool $production,
    ) {}

    /**
     * @param  list<string>  $levels  validation levels, names as in quittio.validation.levels
     * @param  list<CountryPack>  $registered  packs of QuittioManager::registerCountry()
     */
    public function build(Settings $settings, array $levels, array $registered): Quittio
    {
        $packs = $this->resolveCountryPacks($settings, $registered);
        // Without defaults.country, an application with a single country pack defaults to it.
        $config = ConfigMapper::toCore($settings, $this->production, $levels, count($packs) === 1 ? $packs[0]->code() : null);
        $logger = $this->container->bound(LoggerInterface::class) ? $this->container->make(LoggerInterface::class) : null;

        $quittio = Quittio::create(
            config: $config,
            countryPacks: $packs,
            renderer: $this->renderer($settings),
            templates: BladeTemplateEngine::fromSettings($this->container->make(ViewFactory::class), $settings),
            logger: $logger instanceof LoggerInterface ? $logger : null,
            formatResolver: $this->formatResolver($settings, $config),
            calculator: $this->calculator($settings),
        );

        $this->withVatIdVerifier($quittio, $settings->section('validation.drivers.vies'));
        $generator = $settings->nullableString('pdf.payment_qr.generator');
        if ($generator !== null) {
            $instance = $this->container->make($generator);
            if (! $instance instanceof PaymentQrGenerator) {
                throw new ConfigurationException(sprintf('quittio.pdf.payment_qr.generator: %s does not implement %s.', $generator, PaymentQrGenerator::class));
            }
            $quittio->setPaymentQrGenerator($instance);
        }

        foreach ($this->drivers->names('serializer') as $name) {
            $quittio->addSerializer($this->drivers->make('serializer', $name, $this->container, $settings->array('serializers.'.$name), XmlSerializer::class));
        }
        foreach ($levels as $level) {
            if ($this->drivers->has('validator', $level)) {
                $quittio->addValidator($this->drivers->make('validator', $level, $this->container, $settings->array('validation.drivers.'.$level), ValidationDriver::class));
            }
        }

        $replacements = [];
        foreach ($this->drivers->names('rule') as $code) {
            $replacements[$code] = $this->drivers->make('rule', $code, $this->container, [], Rule::class);
        }
        if ($replacements !== []) {
            $quittio->configureRules($config->rules->merge(new RuleOverrides(replacements: $replacements, allowOfficial: $config->rules->allowOfficial)));
        }

        $events = $this->container->make(Dispatcher::class);
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

        $verifier = $this->container->bound(VatIdVerifier::class) ? $this->container->make(VatIdVerifier::class) : null;
        $verifier = $verifier instanceof VatIdVerifier ? $verifier : new ViesVerifier($vies->int('timeout', 5), $vies->int('cache_ttl', 86400));

        $validators = $quittio->validators();
        $quittio->clearValidators();
        foreach ($validators as $driver) {
            $quittio->addValidator($driver instanceof PhpRulesDriver ? $driver->withVatIdVerifier($verifier) : $driver);
        }
    }

    /**
     * @param  list<CountryPack>  $registered  packs of QuittioManager::registerCountry()
     * @return list<CountryPack>
     */
    private function resolveCountryPacks(Settings $settings, array $registered): array
    {
        // Per country code the last one wins: discovered packs, then quittio.countries (e.g. a
        // subclass of a discovered pack), then registerCountry().
        $vendor = dirname((string) (new ReflectionClass(ClassLoader::class))->getFileName(), 2);
        $cache = $this->container instanceof Application ? $this->container->bootstrapPath('cache/quittio-countries.php') : null;
        $classes = [...CountryPackDiscovery::discover($vendor, $cache), ...$settings->strings('countries')];

        $packs = [];
        foreach (array_unique($classes) as $class) {
            $pack = $this->container->make($class);
            if (! $pack instanceof CountryPack) {
                throw new ConfigurationException(sprintf('%s is not a country pack.', $class));
            }
            $packs[$pack->code()] = $pack;
        }
        foreach ($registered as $pack) {
            $packs[$pack->code()] = $pack;
        }

        return array_values($packs);
    }

    private function renderer(Settings $settings): PdfRenderer
    {
        $name = $settings->string('pdf.renderer', 'mpdf');
        $options = $settings->section('pdf.renderers.'.$name);

        if ($this->drivers->has('renderer', $name)) {
            return $this->drivers->make('renderer', $name, $this->container, $settings->array('pdf.renderers.'.$name), PdfRenderer::class);
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
            defaultFont: $settings->string('pdf.font', 'geist'),
            pdfaAuto: $options->bool('pdfa_auto', false),
            simpleTables: $options->bool('simple_tables', false),
        );
    }

    /**
     * calculation.calculator: a TotalsCalculator from the container; null = the core's default.
     */
    private function calculator(Settings $settings): ?TotalsCalculator
    {
        $class = $settings->nullableString('calculation.calculator');
        if ($class === null) {
            return null;
        }

        $instance = $this->container->make($class);
        if (! $instance instanceof TotalsCalculator) {
            throw new ConfigurationException(sprintf('quittio.calculation.calculator: %s does not implement %s.', $class, TotalsCalculator::class));
        }

        return $instance;
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
        $published = $this->container instanceof Application ? $this->container->resourcePath('fonts/quittio') : null;
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

        return $this->drivers->make('format_resolver', $name, $this->container, $settings->array('format_resolver'), FormatResolver::class);
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

        $instance = is_string($lookup) ? $this->container->make($lookup) : $lookup;
        if (! is_callable($instance)) {
            throw new ConfigurationException(sprintf('quittio.format_resolver.peppol_lookup: %s is not callable; use an invokable class that receives the %s and returns a bool.', is_string($lookup) ? $lookup : get_debug_type($lookup), BuyerParty::class));
        }

        return static fn (BuyerParty $buyer): bool => $instance($buyer) === true;
    }
}
