<?php

declare(strict_types=1);

namespace Quittio\Core\Rendering;

use Quittio\Core\Config;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Data\Presentation\Presentation;
use Quittio\Core\Profiles\Profile;
use Quittio\Core\Quittio;
use Quittio\Core\Support\Macroable;
use Quittio\Core\Validation\ValidationResult;

/**
 * Fluent render call: Quittio::make($data)->profile()->format()->locale()->render().
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
        protected readonly Quittio $quittio,
        protected readonly InvoiceData $data,
    ) {}

    public function profile(Profile|string $profile): static
    {
        $this->profile = $profile;

        return $this;
    }

    public function format(string $format): static
    {
        $this->formats = [$format];

        return $this;
    }

    /**
     * Several formats from one calculation, e.g. ['zugferd', 'xrechnung-cii'].
     *
     * @param  list<string>  $formats
     */
    public function formats(array $formats): static
    {
        $this->formats = array_values(array_unique($formats));

        return $this;
    }

    public function template(string $template): static
    {
        $this->template = $template;

        return $this;
    }

    public function locale(string $locale): static
    {
        $this->locale = $locale;

        return $this;
    }

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

    public function option(string $key, mixed $value): static
    {
        $this->options[$key] = $value;

        return $this;
    }

    public function data(): InvoiceData
    {
        return $this->data;
    }

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

    public function render(): RenderedInvoice
    {
        return $this->quittio->render($this->data, $this->options());
    }

    /**
     * Validates without rendering a PDF (XML levels run if the format writes or embeds XML).
     * An explicit validation request: validate() and withoutValidation() do not apply, the
     * result is returned and never thrown; see {@see Quittio::prepare()}.
     */
    public function check(): ValidationResult
    {
        return $this->quittio->check($this->data, $this->options());
    }

    /**
     * Calculates and validates without rendering, whatever the validation mode; see
     * {@see PreparedInvoice} and {@see Quittio::prepare()}.
     */
    public function prepare(): PreparedInvoice
    {
        return $this->quittio->prepare($this->data, $this->options());
    }
}
