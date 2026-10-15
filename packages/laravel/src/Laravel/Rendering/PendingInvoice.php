<?php

declare(strict_types=1);

namespace Quittio\Laravel\Rendering;

use Illuminate\Support\Traits\Macroable;
use Quittio\Core\Calculation\CalculationException;
use Quittio\Core\Config;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Data\Presentation\Presentation;
use Quittio\Core\Exceptions\ConfigurationException;
use Quittio\Core\Pdf\PdfException;
use Quittio\Core\Profiles\Profile;
use Quittio\Core\Rendering\PreparedInvoice;
use Quittio\Core\Rendering\RenderOptions;
use Quittio\Core\Validation\ValidationException;
use Quittio\Core\Validation\ValidationResult;
use Quittio\Core\View\MandatoryFieldMissing;
use Quittio\Laravel\QuittioManager;

/**
 * Fluent render call:
 * Quittio::make($order)->profile('en16931')->format('zugferd')->locale('de')->render().
 */
class PendingInvoice
{
    use Macroable;

    /** @var list<string> */
    private array $formats = [];

    private Profile|string|null $profile = null;

    private ?string $template = null;

    private ?string $locale = null;

    private ?string $country = null;

    private ?string $validation = null;

    private ?Presentation $presentation = null;

    /** @var array<string, mixed> */
    private array $options = [];

    final public function __construct(
        protected readonly QuittioManager $manager,
        protected readonly InvoiceData $data,
    ) {}

    /**
     * Profile of the XML for this call, as key, alias or Profile object, e.g. "xrechnung"; the
     * format must support it. Wins over the configured profile.
     */
    public function profile(Profile|string $profile): static
    {
        $this->profile = $profile;

        return $this;
    }

    /**
     * Output format of this call, e.g. "zugferd", "xrechnung-cii", "pdf"; replaces earlier formats.
     */
    public function format(string $format): static
    {
        $this->formats = [$format];

        return $this;
    }

    /**
     * @param  list<string>  $formats
     */
    public function formats(array $formats): static
    {
        $this->formats = array_values(array_unique($formats));

        return $this;
    }

    /**
     * Template of this call (a shipped or a configured template); wins over the invoice
     * presentation and the configuration.
     */
    public function template(string $template): static
    {
        $this->template = $template;

        return $this;
    }

    /**
     * Locale of this call; wins over the invoice locale and the configuration.
     */
    public function locale(string $locale): static
    {
        $this->locale = $locale;

        return $this;
    }

    /**
     * Leading country pack of this call (ISO 3166-1 alpha-2), instead of the seller's country.
     */
    public function country(string $country): static
    {
        $this->country = strtoupper($country);

        return $this;
    }

    /**
     * Switches validation on (errors throw) or to warnings only.
     */
    public function validate(bool $throwOnError = true): static
    {
        $this->validation = $throwOnError ? Config::VALIDATION_ERROR : Config::VALIDATION_WARN;

        return $this;
    }

    /**
     * Validation mode "off" for this call: nothing is validated, no ValidationException.
     */
    public function withoutValidation(): static
    {
        $this->validation = Config::VALIDATION_OFF;

        return $this;
    }

    /**
     * Presentation defaults (design, texts) for this call; the invoice's own values win.
     */
    public function presentation(Presentation $presentation): static
    {
        $this->presentation = $presentation;

        return $this;
    }

    /**
     * Output option of this call, e.g. option('fold_marks', false); see the output configuration.
     */
    public function option(string $key, mixed $value): static
    {
        $this->options[$key] = $value;

        return $this;
    }

    /**
     * The invoice data of this call, as passed (not calculated).
     */
    public function data(): InvoiceData
    {
        return $this->data;
    }

    /**
     * The options collected so far, as passed to the render run.
     */
    public function options(): RenderOptions
    {
        return new RenderOptions(
            formats: $this->formats,
            profile: $this->profile,
            template: $this->template,
            locale: $this->locale,
            country: $this->country,
            validation: $this->validation,
            presentation: $this->presentation,
            options: $this->options,
        );
    }

    /**
     * Renders the formats of this call with the events of the run (InvoiceRendering,
     * InvoiceValidated or InvoiceValidationFailed, InvoiceRendered); see Quittio::render().
     *
     * @throws ValidationException when validation finds errors in the mode "error"
     * @throws ConfigurationException for an unknown format, a profile the format does not support or a
     *         watermark with e-invoice XML
     * @throws MandatoryFieldMissing when the template omits mandatory values in the guard mode "exception"
     * @throws CalculationException when the data cannot be calculated
     * @throws PdfException when the PDF cannot be rendered
     */
    public function render(): RenderedInvoice
    {
        return $this->manager->renderInvoice($this->data, $this->options());
    }

    /**
     * Validates like a render run without rendering. An explicit validation request: it
     * validates whatever validation.mode or withoutValidation() say and never throws a
     * ValidationException.
     */
    public function check(): ValidationResult
    {
        return $this->manager->check($this->data, $this->options());
    }

    /**
     * Calculates and validates without rendering, whatever validation.mode or
     * withoutValidation() say; see {@see PreparedInvoice}.
     */
    public function prepare(): PreparedInvoice
    {
        return $this->manager->prepare($this->data, $this->options());
    }

    /**
     * Renders in a queued job: Quittio::make($order)->queue()->save('invoices', $path).
     */
    public function queue(?string $connection = null, ?string $queue = null): QueuedInvoice
    {
        return new QueuedInvoice($this->manager, $this->data, $this->options(), $connection, $queue);
    }
}
