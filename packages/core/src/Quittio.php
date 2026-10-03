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
use Quittio\Core\Countries\CountryPack;
use Quittio\Core\Countries\CountryRegistry;
use Quittio\Core\Countries\CountryResolver;
use Quittio\Core\Countries\DefaultCountryResolver;
use Quittio\Core\Countries\MandatoryField;
use Quittio\Core\Countries\ResolvedCountries;
use Quittio\Core\Data\InvoiceData;
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
use Quittio\Core\Pdf\PdfRenderer;
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
use Quittio\Core\Validation\ValidationResult;
use Quittio\Core\Validation\ValidationSubject;
use Quittio\Core\View\Formatter;
use Quittio\Core\View\IntlFormatter;
use Quittio\Core\View\MandatoryFieldGuard;
use Quittio\Core\View\MandatoryFieldMissing;
use Quittio\Core\View\ViewContext;
use Quittio\Core\View\ViewModelFactory;
use Quittio\Core\Xml\Cii\CiiWriter;
use Quittio\Core\Xml\SerializationOptions;
use Quittio\Core\Xml\Ubl\UblWriter;
use Quittio\Core\Xml\XmlSerializer;

/**
 * Entry point without Laravel (specification 14.6):
 *
 *     $quittio = Quittio::create(Config::fromArray([...]), [new CountryDE]);
 *     $result = $quittio->make($invoice)->format('zugferd')->render();
 *
 * Every run calculates the data, chooses format and profile, validates, writes the XML,
 * renders the template and produces the PDF/A with the embedded XML.
 */
final class Quittio
{
    /** @var array<string, XmlSerializer> */
    private array $serializers = [];

    /** @var list<ValidationDriver> */
    private array $validators = [];

    private FormatResolver $formatResolver;

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
        $this->formatResolver = $formatResolver ?? new DefaultFormatResolver($config->profile, $config->autoUpgradeProfile);
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
     * Builder for the full cancellation of an issued invoice (specification 17.3). The sign
     * strategy comes from the argument, the configuration or the country pack (in this order).
     */
    public function cancellation(InvoiceData $original, string $number, ?string $reason = null, ?string $strategy = null, ?Date $issueDate = null): InvoiceBuilder
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
            title: $policy->title('cancellation', $locale),
        );
    }

    public function document(DocumentData $document): PendingDocument
    {
        return new PendingDocument($this, $document);
    }

    public function read(string $pathOrContent): ReadResult
    {
        return (new InvoiceReader(
            profiles: $this->profiles,
            calculator: $this->calculator,
            pdfExtractor: new PdfEmbeddedFileExtractor($this->config->maxPdfBytes, $this->config->maxDecompressedBytes),
            maxXmlBytes: $this->config->maxXmlBytes,
        ))->read($pathOrContent);
    }

    /**
     * Reads and validates an incoming XML or PDF e-invoice with all configured levels.
     */
    public function validateFile(string $pathOrContent): ValidationResult
    {
        $read = $this->read($pathOrContent);
        $profile = $this->profiles->has($read->profile ?? '') ? $this->profiles->get($read->profile ?? '') : $this->profiles->get($this->config->profile);
        $countries = $this->countryResolver->resolve($read->data);
        $subject = new ValidationSubject(
            invoice: $read->data,
            context: $this->validationContext($profile, $countries, $this->config->locale ?? $countries->leading->defaults()->locale),
            xml: $read->xml,
            syntax: Syntax::tryFrom($read->syntax),
            pdf: $read->embeddedFilename === null ? null : $read->raw,
        );

        $result = new ValidationResult($read->findings, [ValidationLevel::L1]);
        foreach ($this->validators as $driver) {
            if ($driver->supports($subject)) {
                $result = $result->merge($driver->validate($subject));
            }
        }

        return $result;
    }

    public function render(InvoiceData $data, RenderOptions $options = new RenderOptions): RenderedInvoice
    {
        $invoice = $this->calculator->calculate($this->withDefaults($data));
        $countries = $this->countryResolver->resolve($invoice, $options->country);
        $locale = $options->locale ?? $invoice->meta->locale ?? $this->config->locale ?? $countries->leading->defaults()->locale;

        $results = [];
        foreach ($options->formats === [] ? [$this->config->format] : $options->formats as $key) {
            [$format, $profile] = $this->choose($invoice, $countries, $key, $options->profile);
            $results[$format->key] = $this->renderFormat($invoice, $countries, $format, $profile, $locale, $options);
        }

        foreach ($results as $result) {
            $result->linkSiblings($results);
        }

        return $results[array_key_first($results)];
    }

    /**
     * Validates like a render run, without template and PDF.
     */
    public function check(InvoiceData $data, RenderOptions $options = new RenderOptions): ValidationResult
    {
        $invoice = $this->calculator->calculate($this->withDefaults($data));
        $countries = $this->countryResolver->resolve($invoice, $options->country);
        $locale = $options->locale ?? $invoice->meta->locale ?? $this->config->locale ?? $countries->leading->defaults()->locale;

        $result = new ValidationResult;
        foreach ($options->formats === [] ? [$this->config->format] : $options->formats as $key) {
            [$format, $profile] = $this->choose($invoice, $countries, $key, $options->profile);
            $subject = new ValidationSubject($invoice, $this->validationContext($profile, $countries, $locale, $options));
            $result = $result->merge($this->validateStage($subject, 'data', $format));
            if ($format->syntax !== null) {
                $xml = $this->serializer($format->syntax)->serialize($invoice, $profile, new SerializationOptions($locale));
                $result = $result->merge($this->validateStage($subject->withXml($xml, $format->syntax), 'xml', $format));
            }
        }

        return $result;
    }

    public function renderDocument(DocumentData $document, RenderOptions $options = new RenderOptions): RenderedDocument
    {
        $document = (new DocumentCalculator($this->calculator))->calculate($document);
        $draft = $document->toInvoiceDraft(billableOnly: false);
        $countries = $this->countryResolver->resolve($draft, $options->country);
        $locale = $options->locale ?? $document->meta->locale ?? $this->config->locale ?? $countries->leading->defaults()->locale;

        $view = (new ViewModelFactory)->buildDocument($document, $this->viewContext($locale, $countries, null, $options));
        $html = $this->templates->render($options->template ?? $document->presentation->template ?? $this->config->template, $view);
        $pdf = in_array('html', $options->formats, true) ? null : $this->renderer->render(new RenderRequest(
            html: $html,
            title: $view->title(),
            author: $document->seller->name,
            paper: $countries->leading->defaults()->paper,
            pdfa: $this->config->pdfa,
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
        $profile = $this->profiles->get($requested ?? $resolvedProfile ?? $format->defaultProfile ?? $this->config->profile);

        if ($requested === null && $resolvedProfile === null && $format->syntax === Syntax::Cii
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
        $subject = new ValidationSubject($invoice, $this->validationContext($profile, $countries, $locale, $options));
        $mode = $options->validation ?? $this->config->validation;
        $result = $this->validated($this->validateStage($subject, 'data', $format, $mode), $mode);

        $xml = null;
        $syntax = $format->syntax ?? ($format->pdf && $this->config->b2cEmbedXml ? Syntax::Cii : null);
        if ($syntax !== null) {
            $xml = $this->serializer($syntax)->serialize($invoice, $profile, new SerializationOptions($locale));
            $subject = $subject->withXml($xml, $syntax);
            $result = $this->validated($result->merge($this->validateStage($subject, 'xml', $format, $mode)), $mode);
        }

        $html = null;
        $pdf = null;
        $title = null;
        if ($format->pdf || $format->html) {
            $view = (new ViewModelFactory)->build($invoice, $this->viewContext($locale, $countries, $profile, $options));
            $html = $this->templates->render($options->template ?? $invoice->presentation->template ?? $this->config->template, $view);
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
                $embedded = $xml === null ? null : new EmbeddedXml(
                    content: $xml,
                    filename: $profile->embeddedFilename() ?? 'factur-x.xml',
                    relationship: $profile->afRelationship() ?? 'Alternative',
                    conformanceLevel: $profile->xmpConformanceLevel(),
                );
                $pdf = $this->renderer->render(new RenderRequest(
                    html: $html,
                    title: $title.' '.$invoice->number,
                    author: $invoice->seller->name,
                    paper: $countries->leading->defaults()->paper,
                    pdfa: $this->config->pdfa || $embedded !== null,
                    embedded: $embedded,
                ));
                $result = $this->validated($result->merge($this->validateStage($subject->withPdf($pdf), 'pdf', $format, $mode)), $mode);
            }
            if (! $format->html) {
                $html = null;
            }
        }

        $title ??= Translations::translator()->get('quittio::labels.title_'.$invoice->typeCodeValue(), [], $locale);

        return new RenderedInvoice(
            data: $invoice,
            format: $format->key,
            profile: $format->syntax === null && ! $this->config->b2cEmbedXml ? null : $profile,
            validation: $result,
            filenamePattern: $this->filenamePattern($title, $invoice->number, $invoice->issueDate->toIsoString(), $format->key),
            xml: $xml,
            pdf: $pdf,
            html: $html,
            json: $format->json ? $invoice->toJson(JSON_PRETTY_PRINT) : null,
        );
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
                $driver = new PhpRulesDriver(levels: array_values(array_filter($driver->levels(), static fn (ValidationLevel $l): bool => $l !== ValidationLevel::L2)));
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

    private function validationContext(Profile $profile, ResolvedCountries $countries, string $locale, ?RenderOptions $options = null): ValidationContext
    {
        return new ValidationContext(
            profile: $profile,
            countryPacks: $countries->all(),
            locale: $locale,
            options: [...$this->config->options, ...($options->options ?? [])],
        );
    }

    private function viewContext(string $locale, ResolvedCountries $countries, ?Profile $profile, RenderOptions $options): ViewContext
    {
        return new ViewContext(
            locale: $locale,
            country: $countries->leading,
            profile: $profile,
            formatter: $this->formatter,
            defaults: $options->presentation ?? $this->config->presentation,
            options: [
                'girocode_amount' => $this->config->girocodeAmount,
                'logo_cache' => ($this->config->tempDir ?? sys_get_temp_dir().'/quittio-mpdf').'/logos',
                ...$this->config->options,
                ...$options->options,
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
