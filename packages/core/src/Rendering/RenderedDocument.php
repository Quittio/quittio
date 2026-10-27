<?php

declare(strict_types=1);

namespace Quittio\Core\Rendering;

use Quittio\Core\Documents\DocumentData;
use Quittio\Core\Exceptions\LogicException;
use Quittio\Core\Support\LocalFile;
use Quittio\Core\Validation\ValidationMessage;

/**
 * Rendered quote, delivery note, reminder or other document (PDF and HTML, never XML).
 */
final readonly class RenderedDocument
{
    /**
     * @param  list<ValidationMessage>  $notices  plausibility hints on the document (DocumentData::notices())
     */
    public function __construct(
        private DocumentData $data,
        private string $html,
        private string $filenamePattern,
        private ?string $pdf = null,
        private array $notices = [],
    ) {}

    /**
     * Plausibility hints on the document, in the language of the run, e.g. a reminder that
     * claims reminder fees besides the flat rate (DocumentData::notices()).
     *
     * @return list<ValidationMessage>
     */
    public function notices(): array
    {
        return $this->notices;
    }

    /**
     * The calculated document data the output was rendered from.
     */
    public function data(): DocumentData
    {
        return $this->data;
    }

    /**
     * The rendered template HTML, also when a PDF was rendered.
     */
    public function html(): string
    {
        return $this->html;
    }

    /**
     * Whether a PDF was rendered; false for an HTML-only run.
     */
    public function hasPdf(): bool
    {
        return $this->pdf !== null;
    }

    /**
     * The PDF.
     *
     * @throws LogicException when the document was rendered as HTML only (see hasPdf())
     */
    public function pdf(): string
    {
        return $this->pdf ?? throw new LogicException('The document was rendered as HTML only.');
    }

    /**
     * The main output: the PDF, otherwise the HTML.
     */
    public function contents(): string
    {
        return $this->pdf ?? $this->html;
    }

    /**
     * MIME type of the main output (contents()): application/pdf, or text/html without a PDF.
     */
    public function mimeType(): string
    {
        return $this->pdf === null ? 'text/html' : 'application/pdf';
    }

    /**
     * File name of the main output from the configured pattern, with the extension pdf or html;
     * $extension replaces it.
     */
    public function filename(?string $extension = null): string
    {
        return str_replace('{ext}', $extension ?? ($this->pdf === null ? 'html' : 'pdf'), $this->filenamePattern);
    }

    /**
     * Writes the output to a file, or into a directory (an existing one or a path ending in "/")
     * with filename(); creates missing directories.
     *
     * @return string the written path
     */
    public function save(string $path): string
    {
        return LocalFile::save($path, $this->filename(), $this->contents());
    }
}
