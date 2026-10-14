<?php

declare(strict_types=1);

namespace Quittio\Core\Rendering;

use LogicException;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Profiles\Profile;
use Quittio\Core\Validation\ValidationResult;
use RuntimeException;

/**
 * Result of a render run. With several formats, every format is
 * reachable via {@see self::as()}; all share the same calculation.
 */
final class RenderedInvoice
{
    /** @var array<string, self> */
    private array $siblings = [];

    /**
     * @param  string  $filenamePattern  file name with the placeholder {ext}
     */
    public function __construct(
        private readonly InvoiceData $data,
        private readonly string $format,
        private readonly ?Profile $profile,
        private readonly ValidationResult $validation,
        private readonly string $filenamePattern,
        private readonly ?string $xml = null,
        private readonly ?string $pdf = null,
        private readonly ?string $html = null,
        private readonly ?string $json = null,
    ) {}

    /**
     * @param  array<string, self>  $all  all results of the run, keyed by format
     *
     * @internal
     */
    public function linkSiblings(array $all): void
    {
        $this->siblings = $all;
    }

    public function data(): InvoiceData
    {
        return $this->data;
    }

    public function format(): string
    {
        return $this->format;
    }

    public function profile(): ?Profile
    {
        return $this->profile;
    }

    public function validation(): ValidationResult
    {
        return $this->validation;
    }

    /**
     * The result for another format of the same run.
     */
    public function as(string $format): self
    {
        if ($format === $this->format) {
            return $this;
        }

        return $this->siblings[$format] ?? throw new LogicException(sprintf('The format "%s" was not rendered. Rendered formats: %s.', $format, implode(', ', $this->formats())));
    }

    /**
     * @return list<string>
     */
    public function formats(): array
    {
        return $this->siblings === [] ? [$this->format] : array_keys($this->siblings);
    }

    public function hasPdf(): bool
    {
        return $this->pdf !== null;
    }

    public function hasXml(): bool
    {
        return $this->xml !== null;
    }

    public function pdf(): string
    {
        return $this->pdf ?? throw new LogicException(sprintf('The format "%s" produces no PDF.', $this->format));
    }

    public function xml(): string
    {
        return $this->xml ?? throw new LogicException(sprintf('The format "%s" produces no XML.', $this->format));
    }

    public function html(): string
    {
        return $this->html ?? throw new LogicException(sprintf('The format "%s" produces no HTML.', $this->format));
    }

    public function json(): string
    {
        return $this->json ?? $this->data->toJson(JSON_PRETTY_PRINT);
    }

    /**
     * The main output: PDF, otherwise XML, HTML or JSON.
     */
    public function contents(): string
    {
        return $this->pdf ?? $this->xml ?? $this->html ?? $this->json();
    }

    public function extension(): string
    {
        return match (true) {
            $this->pdf !== null => 'pdf',
            $this->xml !== null => 'xml',
            $this->html !== null => 'html',
            default => 'json',
        };
    }

    public function mimeType(): string
    {
        return match ($this->extension()) {
            'pdf' => 'application/pdf',
            'xml' => 'application/xml',
            'html' => 'text/html',
            default => 'application/json',
        };
    }

    public function filename(?string $extension = null): string
    {
        return str_replace('{ext}', $extension ?? $this->extension(), $this->filenamePattern);
    }

    /**
     * SHA-256 hashes of the outputs, e.g. for archiving.
     *
     * @return array<string, string>
     */
    public function hashes(): array
    {
        $hashes = [];
        foreach (['pdf' => $this->pdf, 'xml' => $this->xml, 'html' => $this->html] as $key => $content) {
            if ($content !== null) {
                $hashes[$key] = hash('sha256', $content);
            }
        }

        return $hashes;
    }

    /**
     * Writes the main output. A directory as target uses {@see self::filename()}.
     *
     * @return string the written path
     */
    public function save(string $path): string
    {
        if (is_dir($path)) {
            $path = rtrim($path, '/').'/'.$this->filename();
        }

        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create the directory "%s".', $directory));
        }
        if (file_put_contents($path, $this->contents()) === false) {
            throw new RuntimeException(sprintf('Unable to write "%s".', $path));
        }

        return $path;
    }
}
