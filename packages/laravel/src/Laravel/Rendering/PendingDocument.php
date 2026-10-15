<?php

declare(strict_types=1);

namespace Quittio\Laravel\Rendering;

use Illuminate\Support\Traits\Macroable;
use Quittio\Core\Data\Presentation\Presentation;
use Quittio\Core\Documents\DocumentData;
use Quittio\Core\Rendering\RenderOptions;
use Quittio\Laravel\QuittioManager;

/**
 * Fluent render call for documents: Quittio::document($quote)->locale('de')->render().
 */
final class PendingDocument
{
    use Macroable;

    private ?string $template = null;

    private ?string $locale = null;

    private ?string $country = null;

    private bool $pdf = true;

    private ?Presentation $presentation = null;

    /** @var array<string, mixed> */
    private array $options = [];

    public function __construct(
        private readonly QuittioManager $manager,
        private readonly DocumentData $data,
    ) {}

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

    public function presentation(Presentation $presentation): static
    {
        $this->presentation = $presentation;

        return $this;
    }

    public function htmlOnly(): static
    {
        $this->pdf = false;

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

    public function data(): DocumentData
    {
        return $this->data;
    }

    public function options(): RenderOptions
    {
        return new RenderOptions(
            formats: [$this->pdf ? 'pdf' : 'html'],
            template: $this->template,
            locale: $this->locale,
            country: $this->country,
            presentation: $this->presentation,
            options: $this->options,
        );
    }

    public function render(): RenderedDocument
    {
        return $this->manager->renderDocument($this->data, $this->options());
    }
}
