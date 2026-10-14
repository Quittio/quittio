<?php

declare(strict_types=1);

namespace Quittio\Core;

use Closure;
use Psr\Log\LoggerInterface;
use Quittio\Core\Builder\InvoiceBuilder;
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
use Quittio\Core\Countries\ResolvedCountries;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Data\Presentation\Presentation;
use Quittio\Core\Documents\DocumentCalculator;
use Quittio\Core\Documents\DocumentData;
use Quittio\Core\Exceptions\ConfigurationException;
use Quittio\Core\Formats\DefaultFormatResolver;
use Quittio\Core\Formats\ExtendedFeatures;
use Quittio\Core\Formats\FormatRegistry;
use Quittio\Core\Formats\FormatResolver;
use Quittio\Core\Formats\OutputFormat;
use Quittio\Core\I18n\Translations;
use Quittio\Core\Pdf\EmbeddedXml;
use Quittio\Core\Pdf\PdfEmbeddedFileExtractor;
use Quittio\Core\Pdf\PdfException;
use Quittio\Core\Pdf\PdfRenderer;
use Quittio\Core\Pdf\PostProcessing\MpdfPostProcessor;
use Quittio\Core\Pdf\Qr\PaymentQrGenerator;
use Quittio\Core\Pdf\Renderers\MpdfRenderer;
use Quittio\Core\Pdf\RenderRequest;
use Quittio\Core\Profiles\Profile;
use Quittio\Core\Profiles\ProfileLevel;
use Quittio\Core\Profiles\ProfileRegistry;
use Quittio\Core\Profiles\Syntax;
use Quittio\Core\Reading\InvoiceReader;
use Quittio\Core\Reading\ReadResult;
use Quittio\Core\Rendering\PendingDocument;
use Quittio\Core\Rendering\PendingInvoice;
use Quittio\Core\Rendering\PreparedInvoice;
use Quittio\Core\Rendering\RenderedDocument;
use Quittio\Core\Rendering\RenderedInvoice;
use Quittio\Core\Rendering\RenderOptions;
use Quittio\Core\Support\Date;
use Quittio\Core\Templates\PhpTemplateEngine;
use Quittio\Core\Templates\TemplateEngine;
use Quittio\Core\Validation\Drivers\KositValidator;
use Quittio\Core\Validation\Drivers\PhpRulesDriver;
use Quittio\Core\Validation\Drivers\VeraPdfValidator;
use Quittio\Core\Validation\Drivers\XsdValidator;
use Quittio\Core\Validation\ValidationContext;
use Quittio\Core\Validation\ValidationDriver;
use Quittio\Core\Validation\ValidationException;
use Quittio\Core\Validation\ValidationLevel;
use Quittio\Core\Validation\ValidationMessage;
use Quittio\Core\Validation\ValidationResult;
use Quittio\Core\Validation\ValidationSubject;
use Quittio\Core\View\Formatter;
use Quittio\Core\View\IntlFormatter;
use Quittio\Core\View\InvoiceTitle;
use Quittio\Core\View\MandatoryFieldGuard;
use Quittio\Core\View\MandatoryFieldMissing;
use Quittio\Core\View\ViewContext;
use Quittio\Core\View\ViewModelFactory;
use Quittio\Core\Xml\Cii\CiiWriter;
use Quittio\Core\Xml\SerializationOptions;
use Quittio\Core\Xml\Ubl\UblWriter;
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
        $this->withSerializer(new CiiWriter)->withSerializer(new UblWriter);
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
        $quittio->withValidator(new PhpRulesDriver(levels: $phpLevels));
        if (in_array(ValidationLevel::L4, $levels, true)) {
            $quittio->withValidator(new XsdValidator);
        }
        if ($config->kosit !== null) {
            $k = $config->kosit;
            $quittio->withValidator(new KositValidator(
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
            $quittio->withValidator(new VeraPdfValidator(
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

    public function document(DocumentData $document): PendingDocument
    {
        return new PendingDocument($this, $document);
    }

    /**
     * Reads an incoming XML or PDF e-invoice from its content (bytes), never from a path.
     */
    public function read(string $content): ReadResult
    {
        return $this->reader()->read($content);
    }

    /**
     * Reads an incoming XML or PDF e-invoice from a file.
     */
    public function readFile(string $path): ReadResult
    {
        return $this->reader()->readFile($path);
    }

    /**
     * Reads and validates an incoming XML or PDF e-invoice (content, never a path) with all configured levels.
     */
    public function validateFile(string $content): ValidationResult
    {
        return $this->validateRead($this->read($content));
    }

    /**
     * Reads and validates an incoming XML or PDF e-invoice from a file with all configured levels.
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
        $profile = $this->profiles->has($read->profile ?? '') ? $this->profiles->get($read->profile ?? '') : $this->profiles->get($this->config->profile ?? self::DEFAULT_PROFILE);
        $countries = $this->countryResolver->resolve($read->data);
        $subject = new ValidationSubject(
            invoice: $read->data,
            context: $this->validationContext($profile, $countries, $this->config->locale ?? $countries->leading->defaults()->locale, syntax: Syntax::tryFrom($read->syntax)),
            xml: $read->xml,
            syntax: Syntax::tryFrom($read->syntax),
            pdf: $read->embeddedFilename === null ? null : $read->raw,
        );

        $findings = new ValidationResult($read->findings, [ValidationLevel::L1]);
        $result = $findings;
        foreach ($this->validators as $driver) {
            if ($driver->supports($subject)) {
                $result = $result->merge($driver->validate($subject));
            }
        }

        // Read data without document totals is not "not calculated": the reader reports the
        // missing totals with the EN 16931 rules (BR-12 to BR-15), which say what is wrong.
        if ($read->data->totals === null && $findings->has('BR-13')) {
            $result = new ValidationResult(
                array_values(array_filter($result->messages, static fn (ValidationMessage $m): bool => $m->code !== 'QUITTIO-NOT-CALCULATED')),
                $result->levels,
            );
        }

        return $result;
    }

    public function render(InvoiceData $data, RenderOptions $options = new RenderOptions): RenderedInvoice
    {
        $invoice = $this->calculator->calculate($this->withDefaults($data));
        $countries = $this->countryResolver->resolve($invoice, $options->country);
        $locale = $options->locale ?? $invoice->meta->locale ?? $this->config->locale ?? $countries->leading->defaults()->locale;

        $results = [];
        foreach ($this->chooseFormats($invoice, $countries, $options) as [$format, $profile]) {
            $results[$format->key] = $this->renderFormat($invoice, $countries, $format, $profile, $locale, $options);
        }

        foreach ($results as $result) {
            $result->linkSiblings($results);
        }

        return $results[array_key_first($results)];
    }

    /**
     * Validates like a render run, without template and PDF; see prepare().
     *
     * An explicit validation request: it validates whatever the validation mode of the
     * configuration or the call (also "off" and withoutValidation()) and returns the result
     * without throwing a ValidationException.
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
     */
    public function prepare(InvoiceData $data, RenderOptions $options = new RenderOptions): PreparedInvoice
    {
        $invoice = $this->calculator->calculate($this->withDefaults($data));
        $countries = $this->countryResolver->resolve($invoice, $options->country);
        $locale = $options->locale ?? $invoice->meta->locale ?? $this->config->locale ?? $countries->leading->defaults()->locale;
        $watermarked = $this->hasWatermark($invoice->presentation, $options);

        $result = new ValidationResult;
        $formats = [];
        foreach ($this->chooseFormats($invoice, $countries, $options) as [$format, $profile]) {
            $syntax = $this->xmlSyntax($format, $watermarked);
            $formats[$format->key] = $syntax === null ? null : $profile;
            $subject = new ValidationSubject($invoice, $this->validationContext($profile, $countries, $locale, $options, $syntax));
            $result = $result->merge($this->validateStage($subject, 'data', $format));
            if ($syntax !== null) {
                $xml = $this->serializer($syntax)->serialize($invoice, $profile, new SerializationOptions($locale));
                $result = $result->merge($this->validateStage($subject->withXml($xml, $syntax), 'xml', $format));
            }
        }

        return new PreparedInvoice($invoice, $formats, $countries->leading, $countries->buyer, $locale, $result);
    }

    public function renderDocument(DocumentData $document, RenderOptions $options = new RenderOptions): RenderedDocument
    {
        $document = (new DocumentCalculator($this->calculator))->calculate($document);
        $draft = $document->toInvoiceDraft(billableOnly: false);
        $countries = $this->countryResolver->resolve($draft, $options->country);
        $locale = $options->locale ?? $document->meta->locale ?? $this->config->locale ?? $countries->leading->defaults()->locale;

        $htmlOnly = in_array('html', $options->formats, true);
        // PDF/A forbids the transparency of watermarks: watermarked documents are plain PDF.
        $pdfa = ! $htmlOnly && $this->config->pdfa && ! $this->hasWatermark($document->presentation, $options);
        $view = (new ViewModelFactory)->buildDocument($document, $this->viewContext($locale, $countries, null, $options, $pdfa));
        $html = $this->templates->render($this->template($document->presentation, $options), $view);
        $pdf = $htmlOnly ? null : $this->renderPdf(new RenderRequest(
            html: $html,
            title: $view->title(),
            author: $document->seller->name,
            paper: $this->paper($document->presentation, $options, $countries->leading),
            pdfa: $pdfa,
        ));

        return new RenderedDocument($document, $html, $this->filenamePattern($view->title(), $document->number, $document->issueDate->toIsoString(), $document->type->value), $pdf);
    }

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

    public function withSerializer(XmlSerializer $serializer): self
    {
        $this->serializers[$serializer->syntax()->value] = $serializer;

        return $this;
    }

    public function withValidator(ValidationDriver $driver): self
    {
        $this->validators[] = $driver;

        return $this;
    }

    public function withoutValidators(): self
    {
        $this->validators = [];

        return $this;
    }

    public function withRenderer(PdfRenderer $renderer): self
    {
        $this->renderer = $renderer;

        return $this;
    }

    public function withTemplateEngine(TemplateEngine $templates): self
    {
        $this->templates = $templates;

        return $this;
    }

    /**
     * Payment QR code generator for the type "auto" (before the country pack's) and the type
     * "swiss-qr-bill", for which Quittio ships no generator; null removes it.
     */
    public function withPaymentQrGenerator(?PaymentQrGenerator $generator): self
    {
        $this->paymentQr = $generator;

        return $this;
    }

    public function withFormatResolver(FormatResolver $resolver): self
    {
        $this->formatResolver = $resolver;

        return $this;
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function profiles(): ProfileRegistry
    {
        return $this->profiles;
    }

    public function countries(): CountryRegistry
    {
        return $this->countries;
    }

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
     * Formats and profiles of a run: the requested formats, otherwise the configured format
     * or the automatic choice.
     *
     * Drafts and previews carry a watermark and must never look like an issued e-invoice:
     * no format of the run may produce e-invoice XML, also not next to a watermarked PDF.
     *
     * @return non-empty-list<array{OutputFormat, Profile}>
     */
    private function chooseFormats(InvoiceData $invoice, ResolvedCountries $countries, RenderOptions $options): array
    {
        $chosen = [];
        foreach ($options->formats === [] ? [$this->config->format] : $options->formats as $key) {
            $chosen[] = $this->choose($invoice, $countries, $key, $options->profile);
        }

        if ($this->hasWatermark($invoice->presentation, $options)) {
            foreach ($chosen as [$format]) {
                if ($format->syntax !== null) {
                    throw new ConfigurationException(sprintf('The format "%s" produces e-invoice XML and cannot be rendered with a watermark (draft or preview). Render drafts with the format "pdf" or "html" only.', $format->key));
                }
            }
        }

        return $chosen;
    }

    /**
     * Syntax of the XML a format writes: its own syntax, or CII for "pdf" with b2cEmbedXml
     * (embedded as in ZUGFeRD) unless the run has a watermark; null = no XML.
     */
    private function xmlSyntax(OutputFormat $format, bool $watermarked): ?Syntax
    {
        return $format->syntax ?? ($format->pdf && $this->config->b2cEmbedXml && ! $watermarked ? Syntax::Cii : null);
    }

    /**
     * Format and profile for one requested format key (null = automatic choice).
     *
     * @return array{OutputFormat, Profile}
     */
    private function choose(InvoiceData $invoice, ResolvedCountries $countries, ?string $key, Profile|string|null $requested): array
    {
        $resolvedProfile = null;
        if ($key === null) {
            $choice = $this->formatResolver->resolve($invoice, $countries);
            $key = $choice->format;
            $resolvedProfile = $choice->profile;
        }

        $format = $this->formats->get($key);
        // A requested profile wins; the configured one applies wherever the format allows it,
        // also when the format was requested or chosen by a country rule.
        $configured = $this->config->profile === null ? null : $this->profiles->get($this->config->profile);
        $profile = match (true) {
            $requested !== null => $this->profiles->get($requested),
            $configured !== null && $this->fits($format, $configured) => $configured,
            default => $this->profiles->get($resolvedProfile ?? $format->defaultProfile ?? self::DEFAULT_PROFILE),
        };

        // Only formats that allow EXTENDED are upgraded; the others (e.g. XRechnung) report the
        // EXTENDED features in the validation instead.
        if ($requested === null && $format->syntax === Syntax::Cii && $format->allowsProfile('extended')
            && $profile->level() !== ProfileLevel::Extended && $profile->key() !== 'xrechnung-extension' && ExtendedFeatures::used($invoice)) {
            if (! $this->config->autoUpgradeProfile) {
                throw new ConfigurationException('The invoice uses features of profile EXTENDED (e.g. sub lines), but the automatic profile upgrade is switched off.');
            }
            $profile = $this->profiles->get('extended');
        }

        if (! $format->allowsProfile($profile->key())) {
            throw new ConfigurationException(sprintf('The format "%s" does not support profile "%s".', $format->key, $profile->key()));
        }
        if ($format->syntax !== null && ! in_array($format->syntax, $profile->syntaxes(), true)) {
            throw new ConfigurationException(sprintf('Profile "%s" is not available in syntax %s (format "%s").', $profile->key(), strtoupper($format->syntax->value), $format->key));
        }
        if ($format->embedsXml && $profile->embeddedFilename() === null) {
            throw new ConfigurationException(sprintf('Profile "%s" cannot be embedded into a PDF (format "%s").', $profile->key(), $format->key));
        }

        return [$format, $profile];
    }

    private function renderFormat(InvoiceData $invoice, ResolvedCountries $countries, OutputFormat $format, Profile $profile, string $locale, RenderOptions $options): RenderedInvoice
    {
        // Checked for the whole run in render(); kept here for formats with XML (defence in depth).
        $watermarked = $this->hasWatermark($invoice->presentation, $options);
        if ($watermarked && $format->syntax !== null) {
            throw new ConfigurationException(sprintf('The format "%s" produces e-invoice XML and cannot be rendered with a watermark (draft or preview). Render drafts with the format "pdf" or "html" only.', $format->key));
        }

        $syntax = $this->xmlSyntax($format, $watermarked);
        $subject = new ValidationSubject($invoice, $this->validationContext($profile, $countries, $locale, $options, $syntax));
        $mode = $options->validation ?? $this->config->validation;
        $result = $this->validated($this->validateStage($subject, 'data', $format, $mode), $mode);

        $xml = null;
        if ($syntax !== null) {
            $xml = $this->serializer($syntax)->serialize($invoice, $profile, new SerializationOptions($locale));
            $subject = $subject->withXml($xml, $syntax);
            $result = $this->validated($result->merge($this->validateStage($subject, 'xml', $format, $mode)), $mode);
        }

        $html = null;
        $pdf = null;
        $title = null;
        if ($format->pdf || $format->html) {
            // PDF/A for embedded XML, otherwise as configured; watermarks need transparency (no PDF/A).
            $pdfa = $format->pdf && ($xml !== null || ($this->config->pdfa && ! $watermarked));
            $view = (new ViewModelFactory)->build($invoice, $this->viewContext($locale, $countries, $profile, $options, $pdfa));
            $html = $this->templates->render($this->template($invoice->presentation, $options), $view);
            $title = $view->title();

            $mandatory = MandatoryFieldGuard::baseFields($invoice)->merge($countries->leading->mandatoryOutput($invoice));
            try {
                $missing = (new MandatoryFieldGuard($this->config->mandatoryFields, $this->logger))->check($mandatory, $view->accessLog());
            } catch (MandatoryFieldMissing $e) {
                if ($this->mandatoryFieldListener !== null) {
                    ($this->mandatoryFieldListener)($invoice, $e->fields);
                }
                throw $e;
            }
            if ($missing !== [] && $this->mandatoryFieldListener !== null) {
                ($this->mandatoryFieldListener)($invoice, $missing);
            }

            if ($format->pdf) {
                $filename = $profile->embeddedFilename() ?? 'factur-x.xml';
                $embedded = $xml === null ? null : new EmbeddedXml(
                    content: $xml,
                    filename: $filename,
                    relationship: $profile->afRelationship() ?? 'Alternative',
                    conformanceLevel: $profile->xmpConformanceLevel(),
                    description: $filename === 'xrechnung.xml' ? 'XRechnung Invoice' : 'Factur-X/ZUGFeRD Invoice',
                    version: self::xmpVersion($profile),
                );
                $pdf = $this->renderPdf(new RenderRequest(
                    html: $html,
                    title: $title.' '.$invoice->number,
                    author: $invoice->seller->name,
                    paper: $this->paper($invoice->presentation, $options, $countries->leading),
                    pdfa: $pdfa,
                    embedded: $embedded,
                ));
                $result = $this->validated($result->merge($this->validateStage($subject->withPdf($pdf), 'pdf', $format, $mode)), $mode);
            }
            if (! $format->html) {
                $html = null;
            }
        }

        // Formats without PDF and HTML: the same title as the view model, never a raw label key.
        $title ??= $invoice->presentation->title
            ?? InvoiceTitle::policyTitle($invoice, $countries->leading, $locale)
            ?? Translations::translator()->get('quittio::labels.'.InvoiceTitle::key($invoice), [], $locale);

        return new RenderedInvoice(
            data: $invoice,
            format: $format->key,
            profile: $format->syntax === null && $xml === null ? null : $profile,
            validation: $result,
            filenamePattern: $this->filenamePattern($title, $invoice->number, $invoice->issueDate->toIsoString(), $format->key),
            xml: $xml,
            pdf: $pdf,
            html: $html,
            json: $format->json ? $invoice->toJson(JSON_PRETTY_PRINT) : null,
        );
    }

    /**
     * Same precedence as the view model: invoice presentation, then the presentation
     * defaults of the call and the configuration, then the "watermark" option.
     */
    private function hasWatermark(?Presentation $own, RenderOptions $options): bool
    {
        $option = $options->options['watermark'] ?? $this->config->options['watermark'] ?? null;
        $defaults = $this->presentationDefaults($options);
        $watermark = $own === null ? $defaults?->watermark : ViewModelFactory::mergePresentation($own, $defaults ?? new Presentation)->watermark;

        return ($watermark ?? (is_string($option) ? $option : null)) !== null;
    }

    /**
     * Renders the PDF; PDF/A and the embedded XML are added afterwards for renderers that
     * cannot do it themselves.
     */
    private function renderPdf(RenderRequest $request): string
    {
        $pdf = $this->renderer->render($request);
        if ($this->renderer->supportsEmbedding() || (! $request->pdfa && $request->embedded === null)) {
            return $pdf;
        }
        if (! str_starts_with($pdf, '%PDF')) {
            throw new PdfException(sprintf('The PDF renderer %s did not return a PDF.', $this->renderer::class));
        }

        return (new MpdfPostProcessor)->process($pdf, $request->embedded, $request->title, $request->author);
    }

    /**
     * Template of a run: the call (template()), the invoice presentation, the presentation
     * defaults of the call and the configuration, then the configured template.
     */
    private function template(?Presentation $own, RenderOptions $options): string
    {
        return $options->template ?? $own->template ?? $this->presentationDefaults($options)->template ?? $this->config->template;
    }

    /**
     * Whether the profile can be used with the format (allowed profile, syntax, embedding).
     */
    private function fits(OutputFormat $format, Profile $profile): bool
    {
        return $format->allowsProfile($profile->key())
            && ($format->syntax === null || in_array($format->syntax, $profile->syntaxes(), true))
            && (! $format->embedsXml || $profile->embeddedFilename() !== null);
    }

    /**
     * Presentation defaults of a run: the presentation of the call over the configured one,
     * property by property (unset values of the call keep the configured design).
     */
    private function presentationDefaults(RenderOptions $options): ?Presentation
    {
        $call = $options->presentation;
        $configured = $this->config->presentation;
        if ($call === null || $configured === null) {
            return $call ?? $configured;
        }

        return ViewModelFactory::mergePresentation($call, $configured);
    }

    /**
     * Paper size: invoice presentation, then the presentation defaults of the call and the
     * configuration, then an explicitly configured size (option "paper", e.g. pdf.paper),
     * then the country pack. The presentation default "A4" counts as not set unless the
     * presentation names "paper" in $explicit.
     */
    private function paper(?Presentation $own, RenderOptions $options, CountryPack $pack): string
    {
        $default = (new Presentation)->paper;
        foreach ([$own, $this->presentationDefaults($options)] as $presentation) {
            if ($presentation !== null && $presentation->paper !== '' && ($presentation->paper !== $default || $presentation->isExplicit('paper'))) {
                return $presentation->paper;
            }
        }

        $explicit = $options->options['paper'] ?? $this->config->options['paper'] ?? null;

        return is_string($explicit) && $explicit !== '' ? $explicit : $pack->defaults()->paper;
    }

    /**
     * fx:Version of the XMP metadata: "1.0" for the Factur-X profiles, the XRechnung version
     * (e.g. "3.0") for the ZUGFeRD conformance level XRECHNUNG.
     */
    private static function xmpVersion(Profile $profile): string
    {
        if ($profile->xmpConformanceLevel() === 'XRECHNUNG' && preg_match('/xrechnung_(\d+\.\d+)/', $profile->specificationId(), $match) === 1) {
            return $match[1];
        }

        return '1.0';
    }

    /**
     * Applies configured defaults that the invoice does not set itself.
     */
    private function withDefaults(InvoiceData $invoice): InvoiceData
    {
        if ($this->config->dueDays !== null && $invoice->dueDate === null && $invoice->paymentTerms === null && ! $invoice->isCreditNote()) {
            return $invoice->with(['dueDate' => $invoice->issueDate->addDays($this->config->dueDays)]);
        }

        return $invoice;
    }

    /**
     * Runs the drivers of one stage: data (L1–L3, L7), xml (L4, L5) or pdf (L6).
     */
    private function validateStage(ValidationSubject $subject, string $stage, OutputFormat $format, string $mode = Config::VALIDATION_ERROR): ValidationResult
    {
        $result = new ValidationResult;
        if ($mode === Config::VALIDATION_OFF) {
            return $result;
        }
        foreach ($this->validators as $driver) {
            if ($this->stageOf($driver) !== $stage || ! $driver->supports($subject)) {
                continue;
            }
            // Formats without XML are not e-invoices: the profile field matrix does not apply.
            if ($stage === 'data' && ! $format->isEInvoice() && $driver instanceof PhpRulesDriver) {
                $driver = $driver->withLevels(array_values(array_filter($driver->levels(), static fn (ValidationLevel $l): bool => $l !== ValidationLevel::L2)));
            }
            $result = $result->merge($driver->validate($subject));
        }

        return $result;
    }

    private function stageOf(ValidationDriver $driver): string
    {
        $levels = $driver->levels();
        if (in_array(ValidationLevel::L6, $levels, true)) {
            return 'pdf';
        }
        if (in_array(ValidationLevel::L4, $levels, true) || in_array(ValidationLevel::L5, $levels, true)) {
            return 'xml';
        }

        return 'data';
    }

    private function validated(ValidationResult $result, string $mode): ValidationResult
    {
        $blocked = ! $result->isValid() || ($this->config->blockOnWarnings && $result->hasWarnings());
        if ($mode === Config::VALIDATION_ERROR && $blocked) {
            throw new ValidationException($result);
        }

        return $result;
    }

    /**
     * The syntax of the XML that will be written (option "syntax") lets syntax specific rules
     * apply, e.g. BR-DEX-15 or third party payments that only UBL can carry.
     */
    private function validationContext(Profile $profile, ResolvedCountries $countries, string $locale, ?RenderOptions $options = null, ?Syntax $syntax = null): ValidationContext
    {
        return new ValidationContext(
            profile: $profile,
            countryPacks: $countries->all(),
            locale: $locale,
            options: [...$this->config->options, ...($options->options ?? []), ...($syntax === null ? [] : ['syntax' => $syntax])],
        );
    }

    private function viewContext(string $locale, ResolvedCountries $countries, ?Profile $profile, RenderOptions $options, bool $pdfa = false): ViewContext
    {
        return new ViewContext(
            locale: $locale,
            country: $countries->leading,
            profile: $profile,
            formatter: $this->formatter,
            defaults: $this->presentationDefaults($options),
            paymentQr: $this->paymentQr,
            options: [
                'girocode_amount' => $this->config->girocodeAmount,
                'logo_cache' => ($this->config->tempDir ?? sys_get_temp_dir().'/quittio-mpdf').'/logos',
                ...$this->config->options,
                ...$options->options,
                // Set by the run, not configurable: logos are prepared for PDF/A (no transparency, no SVG).
                'pdfa' => $pdfa,
            ],
        );
    }

    private function serializer(Syntax $syntax): XmlSerializer
    {
        return $this->serializers[$syntax->value]
            ?? throw new ConfigurationException(sprintf('No serializer registered for syntax %s.', $syntax->value));
    }

    private function filenamePattern(string $title, string $number, string $date, string $format): string
    {
        $safe = static fn (string $value): string => trim((string) preg_replace('/[^\p{L}\p{N}._-]+/u', '_', $value), '_');

        return str_replace(
            ['{title}', '{number}', '{date}', '{format}'],
            [$safe($title), $safe($number), $date, $format],
            $this->config->filename,
        );
    }
}
