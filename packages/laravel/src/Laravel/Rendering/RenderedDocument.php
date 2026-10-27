<?php

declare(strict_types=1);

namespace Quittio\Laravel\Rendering;

use Illuminate\Contracts\Mail\Attachable;
use Illuminate\Contracts\Support\Responsable;
use Quittio\Core\Documents\DocumentData;
use Quittio\Core\Rendering\RenderedDocument as CoreRenderedDocument;

/**
 * Rendered quote, delivery note, reminder or statement for Laravel.
 */
final class RenderedDocument implements Attachable, Responsable
{
    use DeliversFile;

    public function __construct(
        private readonly CoreRenderedDocument $result,
    ) {}

    /**
     * The result of the core, e.g. for save() to a local path.
     */
    public function core(): CoreRenderedDocument
    {
        return $this->result;
    }

    /**
     * The calculated document data the output was rendered from.
     */
    public function data(): DocumentData
    {
        return $this->result->data();
    }

    /**
     * Plausibility hints on the document, e.g. a reminder that claims reminder fees besides the
     * flat rate (see Quittio\Core\Documents\DocumentData::notices()).
     *
     * @return list<\Quittio\Core\Validation\ValidationMessage>
     */
    public function notices(): array
    {
        return $this->result->notices();
    }

    /**
     * Whether a PDF was rendered (false after htmlOnly()).
     */
    public function hasPdf(): bool
    {
        return $this->result->hasPdf();
    }

    /**
     * The PDF. Throws a LogicException when the document was rendered as HTML only (see hasPdf()).
     */
    public function pdf(): string
    {
        return $this->result->pdf();
    }

    /**
     * The rendered template HTML, also when a PDF was rendered.
     */
    public function html(): string
    {
        return $this->result->html();
    }

    /**
     * The main output: the PDF, otherwise the HTML.
     */
    public function contents(): string
    {
        return $this->result->contents();
    }

    /**
     * MIME type of the main output (contents()): application/pdf, or text/html without a PDF.
     */
    public function mimeType(): string
    {
        return $this->result->mimeType();
    }

    /**
     * File name of the main output from the configured pattern, with the extension pdf or html;
     * $extension replaces it.
     */
    public function filename(?string $extension = null): string
    {
        return $this->result->filename($extension);
    }
}
