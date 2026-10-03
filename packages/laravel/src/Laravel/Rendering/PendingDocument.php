<?php

declare(strict_types=1);

namespace Quittio\Laravel\Rendering;

use Quittio\Core\Data\Presentation\Presentation;
use Quittio\Core\Documents\DocumentData;
use Quittio\Core\Rendering\RenderOptions;
use Quittio\Laravel\QuittioManager;

/**
 * Fluent render call for documents: Quittio::document($quote)->locale('de')->render().
 */
final class PendingDocument
{
    private ?string $template = null;

    private ?string $locale = null;

    private ?string $country = null;

    private bool $pdf = true;

    private ?Presentation $presentation = null;

    public function __construct(
        private readonly QuittioManager $manager,
        private readonly DocumentData $data,
    ) {}

    public function template(string $template): self
    {
        $this->template = $template;

        return $this;
    }

    public function locale(string $locale): self
    {
        $this->locale = $locale;

        return $this;
    }

    public function country(string $country): self
    {
        $this->country = strtoupper($country);

        return $this;
    }

    public function presentation(Presentation $presentation): self
    {
        $this->presentation = $presentation;

        return $this;
    }

    public function htmlOnly(): self
    {
        $this->pdf = false;

        return $this;
    }

    public function render(): RenderedDocument
    {
        return $this->manager->renderDocument($this->data, new RenderOptions(
            formats: [$this->pdf ? 'pdf' : 'html'],
            template: $this->template,
            locale: $this->locale,
            country: $this->country,
            presentation: $this->presentation,
        ));
    }
}
