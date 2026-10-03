<?php

declare(strict_types=1);

namespace Quittio\Laravel\Rendering;

use Illuminate\Support\Traits\Macroable;
use Quittio\Core\Config;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Data\Presentation\Presentation;
use Quittio\Core\Profiles\Profile;
use Quittio\Core\Rendering\RenderOptions;
use Quittio\Core\Validation\ValidationResult;
use Quittio\Laravel\QuittioManager;

/**
 * Fluent render call (specification 14.1):
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
        return $this->manager->renderInvoice($this->data, $this->options());
    }

    public function check(): ValidationResult
    {
        return $this->manager->check($this->data, $this->options());
    }

    /**
     * Renders in a queued job: Quittio::make($order)->queue()->save('invoices', $path).
     */
    public function queue(?string $connection = null, ?string $queue = null): QueuedInvoice
    {
        return new QueuedInvoice($this->data, $this->options(), $connection, $queue);
    }
}
