<?php

declare(strict_types=1);

namespace Quittio\Core\Rendering;

use Closure;
use Psr\Log\LoggerInterface;
use Quittio\Core\Calculation\TotalsCalculator;
use Quittio\Core\Config;
use Quittio\Core\Countries\Capabilities\CompletesInvoices;
use Quittio\Core\Countries\CountryResolver;
use Quittio\Core\Countries\MandatoryField;
use Quittio\Core\Countries\ResolvedCountries;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Data\Presentation\Presentation;
use Quittio\Core\Documents\DocumentCalculator;
use Quittio\Core\Documents\DocumentData;
use Quittio\Core\Exceptions\ConfigurationException;
use Quittio\Core\Formats\FormatChooser;
use Quittio\Core\Formats\OutputFormat;
use Quittio\Core\I18n\Translations;
use Quittio\Core\Pdf\EmbeddedXml;
use Quittio\Core\Pdf\LogoCache;
use Quittio\Core\Pdf\PdfException;
use Quittio\Core\Pdf\PdfRenderer;
use Quittio\Core\Pdf\PostProcessing\MpdfPostProcessor;
use Quittio\Core\Pdf\PostProcessing\PdfPostProcessor;
use Quittio\Core\Pdf\Qr\PaymentQrGenerator;
use Quittio\Core\Pdf\RenderRequest;
use Quittio\Core\Profiles\Capabilities\DescribesEmbeddedXml;
use Quittio\Core\Profiles\Profile;
use Quittio\Core\Profiles\ProfileRegistry;
use Quittio\Core\Profiles\Syntax;
use Quittio\Core\Quittio;
use Quittio\Core\Reading\ReadResult;
use Quittio\Core\Templates\TemplateEngine;
use Quittio\Core\Validation\Drivers\PhpRulesDriver;
use Quittio\Core\Validation\ValidationContext;
use Quittio\Core\Validation\ValidationDriver;
use Quittio\Core\Validation\ValidationException;
use Quittio\Core\Validation\ValidationLevel;
use Quittio\Core\Validation\ValidationMessage;
use Quittio\Core\Validation\ValidationResult;
use Quittio\Core\Validation\ValidationSubject;
use Quittio\Core\View\Formatter;
use Quittio\Core\View\InvoiceTitle;
use Quittio\Core\View\MandatoryFieldGuard;
use Quittio\Core\View\MandatoryFieldMissing;
use Quittio\Core\View\ResolvedLogo;
use Quittio\Core\View\ViewContext;
use Quittio\Core\View\ViewModelFactory;
use Quittio\Core\Xml\SerializationOptions;
use Quittio\Core\Xml\XmlSerializer;

/**
 * One render run of a Quittio instance: calculation, validation stages, XML, template, PDF.
 * Quittio creates it per call with its current extensions.
 *
 * @internal
 */
final readonly class RenderPipeline
{
    /**
     * @param  array<string, XmlSerializer>  $serializers
     * @param  list<ValidationDriver>  $validators
     * @param  (Closure(InvoiceData, list<MandatoryField>): void)|null  $mandatoryFieldListener
     */
    public function __construct(
        private Config $config,
        private ProfileRegistry $profiles,
        private TotalsCalculator $calculator,
        private CountryResolver $countryResolver,
        private FormatChooser $formats,
        private RunPresentation $presentation,
        private PdfRenderer $renderer,
        private TemplateEngine $templates,
        private Formatter $formatter,
        private ?LoggerInterface $logger,
        private array $serializers,
        private array $validators,
        private ?PaymentQrGenerator $paymentQr,
        private ?Closure $mandatoryFieldListener,
        private ?PdfPostProcessor $postProcessor,
    ) {}

    /**
     * See Quittio::render().
     */
    public function render(InvoiceData $data, RenderOptions $options = new RenderOptions): RenderedInvoice
    {
        $invoice = $this->calculator->calculate($this->withDefaults($data, $options));
        $countries = $this->countryResolver->resolve($invoice, $options->country);
        $locale = $options->locale ?? $invoice->meta->locale ?? $this->config->locale ?? $countries->leading->defaults()->locale;

        $results = [];
        foreach ($this->formats->choose($invoice, $countries, $options) as [$format, $profile]) {
            $results[$format->key] = $this->renderFormat($invoice, $countries, $format, $profile, $locale, $options);
        }

        foreach ($results as $result) {
            $result->linkSiblings($results);
        }

        return $results[array_key_first($results)];
    }

    /**
     * See Quittio::prepare().
     */
    public function prepare(InvoiceData $data, RenderOptions $options = new RenderOptions): PreparedInvoice
    {
        $invoice = $this->calculator->calculate($this->withDefaults($data, $options));
        $countries = $this->countryResolver->resolve($invoice, $options->country);
        $locale = $options->locale ?? $invoice->meta->locale ?? $this->config->locale ?? $countries->leading->defaults()->locale;
        $watermarked = $this->presentation->hasWatermark($invoice->presentation, $options);

        $result = new ValidationResult;
        $formats = [];
        foreach ($this->formats->choose($invoice, $countries, $options) as [$format, $profile]) {
            $syntax = $this->formats->xmlSyntax($format, $watermarked);
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

    /**
     * See Quittio::renderDocument().
     */
    public function renderDocument(DocumentData $document, RenderOptions $options = new RenderOptions): RenderedDocument
    {
        $document = (new DocumentCalculator($this->calculator))->calculate($document);
        $draft = $document->toInvoiceDraft(billableOnly: false);
        $countries = $this->countryResolver->resolve($draft, $options->country);
        $locale = $options->locale ?? $document->meta->locale ?? $this->config->locale ?? $countries->leading->defaults()->locale;

        $htmlOnly = in_array('html', $options->formats, true);
        // PDF/A forbids the transparency of watermarks: watermarked documents are plain PDF.
        $pdfa = ! $htmlOnly && $this->config->pdfa && ! $this->presentation->hasWatermark($document->presentation, $options);
        $view = (new ViewModelFactory)->buildDocument($document, $this->viewContext($locale, $countries, null, $options, $pdfa, $document->presentation));
        $html = $this->templates->render($this->presentation->template($document->presentation, $options), $view);
        $pdf = $htmlOnly ? null : $this->renderPdf(new RenderRequest(
            html: $html,
            title: $view->title(),
            author: $document->seller->name,
            paper: $this->presentation->paper($document->presentation, $options, $countries->leading),
            pdfa: $pdfa,
        ));

        return new RenderedDocument($document, $html, $this->filenamePattern($view->title(), $document->number, $document->issueDate->toIsoString(), $document->type->value), $pdf);
    }

    /**
     * Validates read data with every configured driver; the reader's findings are part of the result.
     */
    public function validateRead(ReadResult $read): ValidationResult
    {
        $profile = $this->profiles->has($read->profile ?? '') ? $this->profiles->get($read->profile ?? '') : $this->profiles->get($this->config->profile ?? Quittio::DEFAULT_PROFILE);
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

    private function renderFormat(InvoiceData $invoice, ResolvedCountries $countries, OutputFormat $format, Profile $profile, string $locale, RenderOptions $options): RenderedInvoice
    {
        // Checked for the whole run in render(); kept here for formats with XML (defence in depth).
        $watermarked = $this->presentation->hasWatermark($invoice->presentation, $options);
        if ($watermarked && $format->syntax !== null) {
            throw new ConfigurationException(sprintf('The format "%s" produces e-invoice XML and cannot be rendered with a watermark (draft or preview). Render drafts with the format "pdf" or "html" only.', $format->key));
        }

        $syntax = $this->formats->xmlSyntax($format, $watermarked);
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
            $view = (new ViewModelFactory)->build($invoice, $this->viewContext($locale, $countries, $profile, $options, $pdfa, $invoice->presentation));
            $html = $this->templates->render($this->presentation->template($invoice->presentation, $options), $view);
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
                    description: $profile instanceof DescribesEmbeddedXml ? $profile->xmpDescription() : 'Factur-X/ZUGFeRD Invoice',
                    version: $profile instanceof DescribesEmbeddedXml ? $profile->xmpVersion() : '1.0',
                );
                $pdf = $this->renderPdf(new RenderRequest(
                    html: $html,
                    title: $title.' '.$invoice->number,
                    author: $invoice->seller->name,
                    paper: $this->presentation->paper($invoice->presentation, $options, $countries->leading),
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

        return ($this->postProcessor ?? new MpdfPostProcessor($this->config->tempDir))->process($pdf, $request->embedded, $request->title, $request->author);
    }

    /**
     * Applies configured defaults that the invoice does not set itself.
     */
    /**
     * The configured due date, then what the leading country pack completes (CompletesInvoices).
     */
    private function withDefaults(InvoiceData $invoice, RenderOptions $options): InvoiceData
    {
        if ($this->config->dueDays !== null && $invoice->dueDate === null && $invoice->paymentTerms === null && ! $invoice->isCreditNote()) {
            $invoice = $invoice->with(['dueDate' => $invoice->issueDate->addDays($this->config->dueDays)]);
        }
        $pack = $this->countryResolver->resolve($invoice, $options->country)->leading;

        return $pack instanceof CompletesInvoices ? $pack->complete($invoice, $this->config->countrySettings[strtolower($pack->code())] ?? []) : $invoice;
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
            messageLocale: match ($this->config->validationLocale) {
                null, '', 'invoice' => null,
                'app' => Translations::translator()->locale(),
                default => $this->config->validationLocale,
            },
        );
    }

    /**
     * The context of the view model, with the logo of the presentation already resolved
     * (downloaded, checked, scaled and prepared for PDF/A), so that the view layer does no I/O.
     */
    private function viewContext(string $locale, ResolvedCountries $countries, ?Profile $profile, RenderOptions $options, bool $pdfa, ?Presentation $own): ViewContext
    {
        $defaults = $this->presentation->defaults($options);
        $logo = ($own === null ? $defaults : ViewModelFactory::mergePresentation($own, $defaults ?? new Presentation))?->logo;
        $cache = ($this->config->tempDir ?? sys_get_temp_dir().'/quittio-mpdf').'/logos';

        return new ViewContext(
            locale: $locale,
            country: $countries->leading,
            profile: $profile,
            formatter: $this->formatter,
            defaults: $defaults,
            paymentQr: $this->paymentQr,
            options: [
                'girocode_amount' => $this->config->girocodeAmount,
                'logo_cache' => $cache,
                ...$this->config->options,
                ...$options->options,
                // Set by the run, not configurable: logos are prepared for PDF/A (no transparency, no SVG).
                'pdfa' => $pdfa,
            ],
            logo: $logo === null || $logo === '' ? null : new ResolvedLogo($logo, (new LogoCache($cache, pdfa: $pdfa))->resolve($logo)),
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
