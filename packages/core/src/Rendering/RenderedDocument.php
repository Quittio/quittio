<?php

declare(strict_types=1);

namespace Quittio\Core\Rendering;

use LogicException;
use Quittio\Core\Documents\DocumentData;
use RuntimeException;

/**
 * Rendered quote, delivery note, reminder or other document (PDF and HTML, never XML).
 */
final readonly class RenderedDocument
{
    public function __construct(
        private DocumentData $data,
        private string $html,
        private string $filenamePattern,
        private ?string $pdf = null,
    ) {}

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

    public function save(string $path): string
    {
        if (is_dir($path)) {
            $path = rtrim($path, '/').'/'.$this->filename();
        }
        if (file_put_contents($path, $this->contents()) === false) {
            throw new RuntimeException(sprintf('Unable to write "%s".', $path));
        }

        return $path;
    }
}
