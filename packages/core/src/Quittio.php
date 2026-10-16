<?php

declare(strict_types=1);

namespace Quittio\Core;

use Closure;
use Psr\Log\LoggerInterface;
use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Calculation\CalculationException;
use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Calculation\TotalsCalculator;
use Quittio\Core\Contracts\ProvidesInvoiceData;
use Quittio\Core\Corrections\Cancellation;
use Quittio\Core\Corrections\CancellationHistory;
use Quittio\Core\Countries\CountryPack;
use Quittio\Core\Countries\CountryRegistry;
use Quittio\Core\Countries\CountryResolver;
use Quittio\Core\Countries\DefaultCountryResolver;
use Quittio\Core\Countries\MandatoryField;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Documents\DocumentData;
use Quittio\Core\Exceptions\ConfigurationException;
use Quittio\Core\Exceptions\InvalidInvoiceData;
use Quittio\Core\Formats\DefaultFormatResolver;
use Quittio\Core\Formats\FormatChooser;
use Quittio\Core\Formats\FormatRegistry;
use Quittio\Core\Formats\FormatResolver;
use Quittio\Core\I18n\Translations;
use Quittio\Core\Pdf\PdfEmbeddedFileExtractor;
use Quittio\Core\Pdf\PdfException;
use Quittio\Core\Pdf\PdfRenderer;
use Quittio\Core\Pdf\PostProcessing\MpdfPostProcessor;
use Quittio\Core\Pdf\PostProcessing\PdfPostProcessor;
use Quittio\Core\Pdf\Qr\PaymentQrGenerator;
use Quittio\Core\Pdf\Renderers\MpdfRenderer;
use Quittio\Core\Profiles\Profile;
use Quittio\Core\Profiles\ProfileRegistry;
use Quittio\Core\Profiles\Syntax;
use Quittio\Core\Reading\InvoiceReader;
use Quittio\Core\Reading\ReadResult;
use Quittio\Core\Reading\UnreadableInvoiceException;
use Quittio\Core\Rendering\PendingDocument;
use Quittio\Core\Rendering\PendingInvoice;
use Quittio\Core\Rendering\PreparedInvoice;
use Quittio\Core\Rendering\RenderedDocument;
use Quittio\Core\Rendering\RenderedInvoice;
use Quittio\Core\Rendering\RenderOptions;
use Quittio\Core\Rendering\RenderPipeline;
use Quittio\Core\Rendering\RunPresentation;
use Quittio\Core\Serialization\Snapshots\SnapshotUpcasterChain;
use Quittio\Core\Support\Date;
use Quittio\Core\Templates\PhpTemplateEngine;
use Quittio\Core\Templates\TemplateEngine;
use Quittio\Core\Validation\Drivers\KositValidator;
use Quittio\Core\Validation\Drivers\PhpRulesDriver;
use Quittio\Core\Validation\Drivers\VeraPdfValidator;
use Quittio\Core\Validation\Drivers\XsdValidator;
use Quittio\Core\Validation\RuleOverrides;
use Quittio\Core\Validation\ValidationDriver;
use Quittio\Core\Validation\ValidationException;
use Quittio\Core\Validation\ValidationLevel;
use Quittio\Core\Validation\ValidationResult;
use Quittio\Core\View\Formatter;
use Quittio\Core\View\IntlFormatter;
use Quittio\Core\View\MandatoryFieldMissing;
use Quittio\Core\Xml\Cii\CiiWriter;
use Quittio\Core\Xml\Ubl\UblWriter;
use Quittio\Core\Xml\UnsafeXmlException;
use Quittio\Core\Xml\XmlSerializer;

/**
 * Entry point without Laravel:
 *
 *     $quittio = Quittio::create(Config::fromArray([...]), [new CountryDE]);
 *     $result = $quittio->make($invoice)->format('zugferd')->render();
 *
 * Every run calculates the data, chooses format and profile, validates, writes the XML,
 * renders the template and produces the PDF/A with the embedded XML.
 */
final class Quittio
{
    /** Profile when neither the call, the configuration nor the format names one. */
    public const string DEFAULT_PROFILE = 'en16931';

    /** @var array<string, XmlSerializer> */
    private array $serializers = [];

    /** @var list<ValidationDriver> */
    private array $validators = [];

    private FormatResolver $formatResolver;

    private ?PaymentQrGenerator $paymentQr = null;

    private CountryResolver $countryResolver;

    /** @var (Closure(InvoiceData, list<MandatoryField>): void)|null */
    private ?Closure $mandatoryFieldListener = null;

    /** PDF/A and XML embedding for renderers that cannot embed; null = MpdfPostProcessor. */
    private ?PdfPostProcessor $postProcessor = null;

    private readonly RunPresentation $presentation;

    /** @var array<string, Closure(): void> see onResetState() */
    private static array $stateResetters = [];

    public function __construct(
        private readonly Config $config = new Config,
        private readonly ProfileRegistry $profiles = new ProfileRegistry,
        private readonly CountryRegistry $countries = new CountryRegistry,
        private readonly FormatRegistry $formats = new FormatRegistry,
        private PdfRenderer $renderer = new MpdfRenderer,
        private TemplateEngine $templates = new PhpTemplateEngine,
        private readonly TotalsCalculator $calculator = new En16931Calculator,
        private readonly Formatter $formatter = new IntlFormatter,
        private readonly ?LoggerInterface $logger = null,
        ?FormatResolver $formatResolver = null,
        ?CountryResolver $countryResolver = null,
    ) {
        $this->formatResolver = $formatResolver ?? new DefaultFormatResolver($config->profile ?? self::DEFAULT_PROFILE, $config->autoUpgradeProfile);
        $this->countryResolver = $countryResolver ?? new DefaultCountryResolver($countries, $config->country);
        $this->presentation = new RunPresentation($config);
        $this->addSerializer(new CiiWriter)->addSerializer(new UblWriter);
    }

    /**
     * Resets the process-wide state: the translator of Translations, the default snapshot
     * upcasters of SnapshotUpcasterChain::default(), the application default VAT of
     * InvoiceBuilder::useDefaultVat() and the state that extensions registered with
     * onResetState() (e.g. the base rates of quittio/country-de).
     *
     * Test suites call it between tests, long-running processes between tenants. The Laravel
     * integration sets the state again when the application boots.
     */
    public static function resetState(): void
    {
        Translations::reset();
        SnapshotUpcasterChain::useDefault(null);
        InvoiceBuilder::useDefaultVat(null);
        foreach (self::$stateResetters as $reset) {
            $reset();
        }
    }

    /**
     * Registers process-wide state of an extension with resetState(). A second registration
     * under the same key replaces the first.
     *
     * @param  Closure(): void  $reset
     */
    public static function onResetState(string $key, Closure $reset): void
    {
        self::$stateResetters[$key] = $reset;
    }

    /**
     * Instance with default registries, the configured validators and the given country packs.
     *
     * @param  Config|array<string, mixed>  $config
     * @param  iterable<CountryPack>  $countryPacks
     */
    public static function create(
        Config|array $config = [],
        iterable $countryPacks = [],
        ?PdfRenderer $renderer = null,
        ?TemplateEngine $templates = null,
        ?LoggerInterface $logger = null,
        ?FormatResolver $formatResolver = null,
    ): self {
        $config = is_array($config) ? Config::fromArray($config) : $config;

        $quittio = new self(
            config: $config,
            profiles: ProfileRegistry::withDefaults(),
            formats: FormatRegistry::withDefaults(),
            renderer: $renderer ?? new MpdfRenderer(tempDir: $config->tempDir),
            templates: $templates ?? new PhpTemplateEngine($config->templatePaths),
            logger: $logger,
            formatResolver: $formatResolver,
        );
        foreach ($countryPacks as $pack) {
            $quittio->registerCountry($pack);
        }

        $levels = $config->validationLevels;
        $phpLevels = array_values(array_filter($levels, static fn (ValidationLevel $l): bool => in_array($l, [ValidationLevel::L1, ValidationLevel::L2, ValidationLevel::L3, ValidationLevel::L7], true)));
        $quittio->addValidator(new PhpRulesDriver(levels: $phpLevels));
        if (! $config->rules->isEmpty()) {
            $quittio->configureRules($config->rules);
        }
        if (in_array(ValidationLevel::L4, $levels, true)) {
            $quittio->addValidator(new XsdValidator);
        }
        if ($config->kosit !== null) {
            $k = $config->kosit;
            $quittio->addValidator(new KositValidator(
                mode: $k['mode'] ?? KositValidator::MODE_DAEMON,
                url: $k['url'] ?? 'http://127.0.0.1:8081',
                jar: $k['jar'] ?? null,
                configurations: $k['configurations'] ?? [],
                java: $k['java'] ?? 'java',
                timeout: $k['timeout'] ?? 30,
                failOnUnavailable: $k['fail_on_unavailable'] ?? true,
            ));
        }
        if ($config->veraPdf !== null) {
            $v = $config->veraPdf;
            $quittio->addValidator(new VeraPdfValidator(
                mode: $v['mode'] ?? VeraPdfValidator::MODE_DOCKER,
                binary: $v['binary'] ?? 'verapdf',
                image: $v['image'] ?? 'verapdf/cli',
                docker: $v['docker'] ?? 'docker',
                timeout: $v['timeout'] ?? 120,
                failOnUnavailable: $v['fail_on_unavailable'] ?? true,
            ));
        }

        return $quittio;
    }

    /**
     * Starts a render call: format(), profile(), locale() … and render(). A builder is built and a
     * model converted (ProvidesInvoiceData) at once.
     *
     * @throws InvalidInvoiceData when a builder misses an essential part (see InvoiceBuilder::build())
     */
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
     * Builder for the cancellation of an issued invoice. The sign
     * strategy comes from the argument, the configuration or the country pack (in this order).
     *
     * @param  list<array{line: string, quantity?: \Brick\Math\BigDecimal|int|string, amount?: string}>|null  $lines  partial cancellation; null = full
     * @param  CancellationHistory|null  $history  earlier partial cancellations of the original, so that the VAT of a
     *         partial cancellation is rounded cumulatively with theirs (see CancellationHistory)
     * @param  string|null  $title  document title in the PDF, e.g. "Stornorechnung"; null = the title of the country
     *         pack's correction policy ("cancellation", or "cancellation_self_billed" for self-billed originals)
     */
    public function cancellation(InvoiceData $original, string $number, ?string $reason = null, ?string $strategy = null, ?Date $issueDate = null, ?array $lines = null, ?CancellationHistory $history = null, ?string $title = null): InvoiceBuilder
    {
        $pack = $this->countryResolver->resolve($original)->leading;
        $policy = $pack->correctionPolicy();
        $locale = $original->meta->locale ?? $this->config->locale ?? $pack->defaults()->locale;

        return Cancellation::of(
            calculator: $this->calculator,
            original: $original,
            number: $number,
            strategy: $strategy ?? $this->config->cancellationStrategy ?? $policy->cancellationStrategy,
            reason: $reason,
            issueDate: $issueDate,
            locale: $locale,
            title: $title,
            lines: $lines,
            policy: $policy,
            history: $history,
        );
    }

    /**
     * Starts the render call of a quote, order confirmation, delivery note, proforma invoice,
     * payment reminder or account statement.
     */
    public function document(DocumentData $document): PendingDocument
    {
        return new PendingDocument($this, $document);
    }

    /**
     * Reads an incoming XML or PDF e-invoice from its content (bytes), never from a path.
     *
     * @throws UnsafeXmlException when the XML is too large, not well-formed or not allowed (DOCTYPE, limits)
     * @throws PdfException when the PDF is too large, encrypted, malformed or exceeds a limit
     * @throws UnreadableInvoiceException for an unknown format, ZUGFeRD 1.0 or a PDF without an embedded invoice
     */
    public function read(string $content): ReadResult
    {
        return $this->reader()->read($content);
    }

    /**
     * Reads an incoming XML or PDF e-invoice from a file.
     *
     * @throws UnsafeXmlException when the XML is too large, not well-formed or not allowed (DOCTYPE, limits)
     * @throws PdfException when the PDF is too large, encrypted, malformed or exceeds a limit
     * @throws UnreadableInvoiceException for an unknown format, ZUGFeRD 1.0, a PDF without an embedded invoice, or a file that cannot be read
     */
    public function readFile(string $path): ReadResult
    {
        return $this->reader()->readFile($path);
    }

    /**
     * Reads and validates an incoming XML or PDF e-invoice (content, never a path) with all configured levels.
     *
     * @throws UnsafeXmlException when the XML is too large, not well-formed or not allowed (DOCTYPE, limits)
     * @throws PdfException when the PDF is too large, encrypted, malformed or exceeds a limit
     * @throws UnreadableInvoiceException for an unknown format, ZUGFeRD 1.0 or a PDF without an embedded invoice
     */
    public function validateFile(string $content): ValidationResult
    {
        return $this->validateRead($this->read($content));
    }

    /**
     * Reads and validates an incoming XML or PDF e-invoice from a file with all configured levels.
     *
     * @throws UnsafeXmlException when the XML is too large, not well-formed or not allowed (DOCTYPE, limits)
     * @throws PdfException when the PDF is too large, encrypted, malformed or exceeds a limit
     * @throws UnreadableInvoiceException for an unknown format, ZUGFeRD 1.0, a PDF without an embedded invoice, or a file that cannot be read
     */
    public function validatePath(string $path): ValidationResult
    {
        return $this->validateRead($this->readFile($path));
    }

    /**
     * Reader with the CII and UBL readers, then the readers for national formats of the
     * registered country packs (CountryPack::readers()); the first reader that can read a
     * document reads it, so packs add formats but do not take over CII and UBL.
     */
    private function reader(): InvoiceReader
    {
        $readers = InvoiceReader::defaultReaders();
        foreach ($this->countries->all() as $pack) {
            $readers = [...$readers, ...$pack->readers()];
        }

        return new InvoiceReader(
            readers: $readers,
            profiles: $this->profiles,
            calculator: $this->calculator,
            pdfExtractor: new PdfEmbeddedFileExtractor($this->config->maxPdfBytes, $this->config->maxDecompressedBytes),
            maxXmlBytes: $this->config->maxXmlBytes,
        );
    }

    private function validateRead(ReadResult $read): ValidationResult
    {
        return $this->pipeline()->validateRead($read);
    }

    /**
     * The render run behind PendingInvoice::render(): calculates the data, applies the configured
     * defaults, chooses formats and profiles, validates, writes the XML, renders the template and the
     * PDF/A with the embedded XML. Returns the result of the first format; the others are reachable
     * with as().
     *
     * @throws ValidationException when validation finds errors in the mode "error"
     * @throws ConfigurationException for an unknown format, a profile the format does not support or a
     *         watermark with e-invoice XML
     * @throws MandatoryFieldMissing when the template omits mandatory values in the guard mode "exception"
     * @throws CalculationException when the data cannot be calculated
     * @throws InvalidInvoiceData when a text cannot be written to XML (control characters, invalid UTF-8)
     * @throws PdfException when the PDF cannot be rendered or post-processed
     */
    public function render(InvoiceData $data, RenderOptions $options = new RenderOptions): RenderedInvoice
    {
        return $this->pipeline()->render($data, $options);
    }

    /**
     * Validates like a render run, without template and PDF; see prepare().
     *
     * An explicit validation request: it validates whatever the validation mode of the
     * configuration or the call (also "off" and withoutValidation()) and returns the result
     * without throwing a ValidationException.
     *
     * @throws ConfigurationException for an unknown format or a profile the format does not support
     * @throws CalculationException when the data cannot be calculated
     */
    public function check(InvoiceData $data, RenderOptions $options = new RenderOptions): ValidationResult
    {
        return $this->prepare($data, $options)->validation;
    }

    /**
     * Calculates and validates like a render run (without template and PDF) and returns the
     * calculated data with the chosen formats, profiles, country packs and locale.
     *
     * The run takes the same decisions as render(): format and profile, the refusal of XML
     * formats for watermarked data, and the XML of every format that writes one, including
     * the CII XML that "pdf" embeds with b2cEmbedXml (unless the run has a watermark). The data
     * and xml stages (L1–L5, L7) run with all configured drivers; L6 needs the rendered PDF
     * and does not run. The Managed layer freezes invoices with it, so it always validates,
     * whatever the validation mode (also "off" and withoutValidation()), and never throws a
     * ValidationException: the caller decides on the result. Configuration errors (e.g. an
     * unsupported profile) still throw.
     *
     * @throws ConfigurationException for an unknown format or a profile the format does not support
     * @throws CalculationException when the data cannot be calculated
     */
    public function prepare(InvoiceData $data, RenderOptions $options = new RenderOptions): PreparedInvoice
    {
        return $this->pipeline()->prepare($data, $options);
    }

    /**
     * The render run behind PendingDocument::render(): calculates the document and renders
     * the template and, unless the format "html" is requested, the PDF. Documents carry no XML and are
     * not validated.
     *
     * @throws CalculationException when the document cannot be calculated
     * @throws PdfException when the PDF cannot be rendered
     */
    public function renderDocument(DocumentData $document, RenderOptions $options = new RenderOptions): RenderedDocument
    {
        return $this->pipeline()->renderDocument($document, $options);
    }

    /**
     * Registers a country pack with its profiles and translations; a pack with the same code
     * replaces the earlier one. Changes this instance.
     */
    public function registerCountry(CountryPack $pack): self
    {
        $this->countries->register($pack);
        foreach ($pack->profiles() as $profile) {
            $this->profiles->register($profile);
        }
        $path = $pack->translationPath();
        if ($path !== null && is_dir($path)) {
            Translations::translator()->addPath($path, 'quittio-'.strtolower($pack->code()));
        }

        return $this;
    }

    /**
     * Called when a template omits mandatory values, in every guard mode except "off".
     *
     * @param  Closure(InvoiceData, list<MandatoryField>): void  $listener
     */
    public function onMandatoryFieldsMissing(Closure $listener): self
    {
        $this->mandatoryFieldListener = $listener;

        return $this;
    }

    /**
     * Writes the XML of the serializer's syntax with $serializer from now on (replaces the
     * serializer of that syntax). Changes this instance.
     */
    public function addSerializer(XmlSerializer $serializer): self
    {
        $this->serializers[$serializer->syntax()->value] = $serializer;

        return $this;
    }

    /**
     * Runs $driver in every validation after the drivers added before. Changes this instance.
     */
    public function addValidator(ValidationDriver $driver): self
    {
        $this->validators[] = $driver;

        return $this;
    }

    /**
     * Removes every validation driver, e.g. before adding a custom set. Changes this instance.
     */
    public function clearValidators(): self
    {
        $this->validators = [];

        return $this;
    }

    /**
     * Renders PDFs with $renderer from now on. Changes this instance.
     */
    public function setRenderer(PdfRenderer $renderer): self
    {
        $this->renderer = $renderer;

        return $this;
    }

    /**
     * Renders the templates with $templates from now on. Changes this instance.
     */
    public function setTemplateEngine(TemplateEngine $templates): self
    {
        $this->templates = $templates;

        return $this;
    }

    /**
     * Payment QR code generator for the type "auto" (before the country pack's) and the type
     * "swiss-qr-bill", for which Quittio ships no generator; null removes it. Changes this
     * instance.
     */
    public function setPaymentQrGenerator(?PaymentQrGenerator $generator): self
    {
        $this->paymentQr = $generator;

        return $this;
    }

    /**
     * Chooses formats and profiles with $resolver from now on. Changes this instance.
     */
    public function setFormatResolver(FormatResolver $resolver): self
    {
        $this->formatResolver = $resolver;

        return $this;
    }

    /**
     * Adds PDF/A and the embedded XML to the PDFs of renderers that cannot do it themselves
     * (PdfRenderer::supportsEmbedding()); null = MpdfPostProcessor with the configured temp_dir.
     * Changes this instance.
     */
    public function setPostProcessor(?PdfPostProcessor $postProcessor): self
    {
        $this->postProcessor = $postProcessor;

        return $this;
    }

    /**
     * Switches PHP rules off, changes the severity of their findings or replaces them, by rule
     * code (see RuleOverrides); replaces the overrides of every PHP rules driver. Changes this
     * instance.
     *
     * @throws ConfigurationException for a code or pattern that no known rule has
     */
    public function configureRules(RuleOverrides $overrides): self
    {
        $known = $this->ruleCodes();
        foreach ($overrides->patterns() as $pattern) {
            $matches = array_filter($known, static fn (string $code): bool => RuleOverrides::matches($pattern, $code));
            if ($matches === []) {
                throw new ConfigurationException(sprintf('No rule has the code "%s"; check the spelling (codes as in the validation messages, a trailing * matches by prefix).', $pattern));
            }
        }

        $this->validators = array_map(
            static fn (ValidationDriver $driver): ValidationDriver => $driver instanceof PhpRulesDriver ? $driver->withOverrides($overrides) : $driver,
            $this->validators,
        );

        return $this;
    }

    /**
     * Codes of every PHP rule and finding with the registered profiles and country packs.
     *
     * @return list<string>
     */
    public function ruleCodes(): array
    {
        $codes = [];
        foreach ($this->validators as $driver) {
            if ($driver instanceof PhpRulesDriver) {
                $codes = [...$codes, ...$driver->knownCodes($this->profiles->all(), $this->countries->all())];
            }
        }
        $codes = array_values(array_unique($codes));
        sort($codes);

        return $codes;
    }

    /**
     * @deprecated use addSerializer(); like it, it changes this instance
     */
    public function withSerializer(XmlSerializer $serializer): self
    {
        return $this->addSerializer($serializer);
    }

    /**
     * @deprecated use addValidator(); like it, it changes this instance
     */
    public function withValidator(ValidationDriver $driver): self
    {
        return $this->addValidator($driver);
    }

    /**
     * @deprecated use clearValidators(); like it, it changes this instance
     */
    public function withoutValidators(): self
    {
        return $this->clearValidators();
    }

    /**
     * @deprecated use setRenderer(); like it, it changes this instance
     */
    public function withRenderer(PdfRenderer $renderer): self
    {
        return $this->setRenderer($renderer);
    }

    /**
     * @deprecated use setTemplateEngine(); like it, it changes this instance
     */
    public function withTemplateEngine(TemplateEngine $templates): self
    {
        return $this->setTemplateEngine($templates);
    }

    /**
     * @deprecated use setPaymentQrGenerator(); like it, it changes this instance
     */
    public function withPaymentQrGenerator(?PaymentQrGenerator $generator): self
    {
        return $this->setPaymentQrGenerator($generator);
    }

    /**
     * @deprecated use setFormatResolver(); like it, it changes this instance
     */
    public function withFormatResolver(FormatResolver $resolver): self
    {
        return $this->setFormatResolver($resolver);
    }

    /**
     * The configuration of this instance.
     */
    public function config(): Config
    {
        return $this->config;
    }

    /**
     * The registered profiles, those of the country packs included.
     */
    public function profiles(): ProfileRegistry
    {
        return $this->profiles;
    }

    /**
     * The registered country packs.
     */
    public function countries(): CountryRegistry
    {
        return $this->countries;
    }

    /**
     * The output formats (zugferd, xrechnung-cii, ubl, pdf, html, json …).
     */
    public function formats(): FormatRegistry
    {
        return $this->formats;
    }

    /**
     * @return list<ValidationDriver>
     */
    public function validators(): array
    {
        return $this->validators;
    }

    /**
     * The render pipeline with the current extensions of this instance.
     */
    private function pipeline(): RenderPipeline
    {
        return new RenderPipeline(
            config: $this->config,
            profiles: $this->profiles,
            calculator: $this->calculator,
            countryResolver: $this->countryResolver,
            formats: new FormatChooser($this->config, $this->profiles, $this->formats, $this->formatResolver, $this->presentation),
            presentation: $this->presentation,
            renderer: $this->renderer,
            templates: $this->templates,
            formatter: $this->formatter,
            logger: $this->logger,
            serializers: $this->serializers,
            validators: $this->validators,
            paymentQr: $this->paymentQr,
            mandatoryFieldListener: $this->mandatoryFieldListener,
            postProcessor: $this->postProcessor,
        );
    }
}
