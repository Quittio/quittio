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

    public function data(): DocumentData
    {
        return $this->data;
    }

    public function html(): string
    {
        return $this->html;
    }

    public function hasPdf(): bool
    {
        return $this->pdf !== null;
    }

    public function pdf(): string
    {
        return $this->pdf ?? throw new LogicException('The document was rendered as HTML only.');
    }

    public function contents(): string
    {
        return $this->pdf ?? $this->html;
    }

    public function mimeType(): string
    {
        return $this->pdf === null ? 'text/html' : 'application/pdf';
    }

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
