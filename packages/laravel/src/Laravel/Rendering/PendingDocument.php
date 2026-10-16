<?php

declare(strict_types=1);

namespace Quittio\Laravel\Rendering;

use Illuminate\Support\Traits\Macroable;
use Quittio\Core\Calculation\CalculationException;
use Quittio\Core\Data\Presentation\Presentation;
use Quittio\Core\Documents\DocumentData;
use Quittio\Core\Pdf\PdfException;
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

    /**
     * Template of this call; the same selection rules as for invoices.
     */
    public function template(string $template): static
    {
        $this->template = $template;

        return $this;
    }

    /**
     * Locale of this call; wins over the document locale and the configuration.
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
     * Design defaults of this call; the document's own presentation wins.
     */
    public function presentation(Presentation $presentation): static
    {
        $this->presentation = $presentation;

        return $this;
    }

    /**
     * Renders the template only, without PDF (format "html").
     */
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

    /**
     * The document of this call, as passed (not calculated).
     */
    public function data(): DocumentData
    {
        return $this->data;
    }

    /**
     * The options collected so far, as passed to the render run.
     */
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

    /**
     * Calculates and renders the document with the events DocumentRendering and
     * DocumentRendered, see Quittio::renderDocument().
     *
     * @throws CalculationException when the document cannot be calculated
     * @throws PdfException when the PDF cannot be rendered
     */
    public function render(): RenderedDocument
    {
        return $this->manager->renderDocument($this->data, $this->options());
    }

    /**
     * Renders in a queued job: Quittio::document($quote)->queue()->save('documents', $path).
     */
    public function queue(?string $connection = null, ?string $queue = null): QueuedDocument
    {
        return new QueuedDocument($this->manager, $this->data, $this->options(), $connection, $queue);
    }
}
