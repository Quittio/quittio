<?php

declare(strict_types=1);

namespace Quittio\Core\Pdf;

use Quittio\Core\Reading\EmbeddedXmlExtractor;
use setasign\Fpdi\PdfParser\PdfParser;
use setasign\Fpdi\PdfParser\StreamReader;
use setasign\Fpdi\PdfParser\Type\PdfArray;
use setasign\Fpdi\PdfParser\Type\PdfDictionary;
use setasign\Fpdi\PdfParser\Type\PdfHexString;
use setasign\Fpdi\PdfParser\Type\PdfName;
use setasign\Fpdi\PdfParser\Type\PdfStream;
use setasign\Fpdi\PdfParser\Type\PdfString;
use setasign\Fpdi\PdfParser\Type\PdfType;
use Throwable;

/**
 * Extracts the embedded invoice XML from PDF files (ZUGFeRD 1.0 to 2.x, Factur-X, XRechnung).
 *
 * Embedded files are searched in the name tree (/Names /EmbeddedFiles) and in /AF.
 * Streams are decompressed with a size limit as protection against zip bombs, and
 * file names are never used as paths.
 */
final class PdfEmbeddedFileExtractor implements EmbeddedXmlExtractor
{
    /** Known file names in order of preference; ZUGFeRD 1.0 is read only. */
    public const array KNOWN_FILENAMES = ['factur-x.xml', 'xrechnung.xml', 'zugferd-invoice.xml', 'ZUGFeRD-invoice.xml'];

    private const int MAX_TREE_DEPTH = 10;

    public function __construct(
        private readonly int $maxPdfBytes = 25 * 1024 * 1024,
        private readonly int $maxDecompressedBytes = 50 * 1024 * 1024,
    ) {}

    public function extract(string $pdf): ?array
    {
        $files = $this->embeddedFiles($pdf);

        foreach (self::KNOWN_FILENAMES as $known) {
            foreach ($files as $name => $content) {
                if (strcasecmp($name, $known) === 0) {
                    return [$name, $content()];
                }
            }
        }

        foreach ($files as $name => $content) {
            if (str_ends_with(strtolower($name), '.xml')) {
                return [$name, $content()];
            }
        }

        return null;
    }

    /**
     * Names of all embedded files.
     *
     * @return list<string>
     */
    public function names(string $pdf): array
    {
        return array_map(strval(...), array_keys($this->embeddedFiles($pdf)));
    }

    /**
     * @return array<string, callable(): string> file name => lazy content loader
     */
    private function embeddedFiles(string $pdf): array
    {
        if (strlen($pdf) > $this->maxPdfBytes) {
            throw new PdfException(sprintf('The PDF exceeds the size limit of %d bytes.', $this->maxPdfBytes));
        }

        try {
            $parser = new PdfParser(StreamReader::createByString($pdf));
            $catalog = $parser->getCatalog();
        } catch (Throwable $e) {
            throw new PdfException('The PDF could not be parsed: '.$e->getMessage(), 0, $e);
        }

        $specs = [];
        try {
            $names = $this->resolve(PdfDictionary::get($catalog, 'Names'), $parser);
            if ($names instanceof PdfDictionary) {
                $tree = $this->resolve(PdfDictionary::get($names, 'EmbeddedFiles'), $parser);
                $this->walkNameTree($tree, $parser, $specs, 0);
            }

            $af = $this->resolve(PdfDictionary::get($catalog, 'AF'), $parser);
            if ($af instanceof PdfArray) {
                foreach ($af->value as $entry) {
                    $spec = $entry instanceof PdfType ? $this->resolve($entry, $parser) : null;
                    if ($spec instanceof PdfDictionary) {
                        $specs[] = $spec;
                    }
                }
            }
        } catch (Throwable $e) {
            throw new PdfException('The embedded files of the PDF could not be read: '.$e->getMessage(), 0, $e);
        }

        $files = [];
        foreach ($specs as $spec) {
            $name = $this->filename($spec, $parser);
            if ($name === null || isset($files[$name])) {
                continue;
            }
            $files[$name] = fn (): string => $this->content($spec, $parser);
        }

        return $files;
    }

    /**
     * @param  list<PdfDictionary>  $specs
     */
    private function walkNameTree(PdfType $node, PdfParser $parser, array &$specs, int $depth): void
    {
        if (! $node instanceof PdfDictionary || $depth > self::MAX_TREE_DEPTH) {
            return;
        }

        $names = $this->resolve(PdfDictionary::get($node, 'Names'), $parser);
        if ($names instanceof PdfArray) {
            foreach (array_chunk($names->value, 2) as $pair) {
                $spec = ($pair[1] ?? null) instanceof PdfType ? $this->resolve($pair[1], $parser) : null;
                if ($spec instanceof PdfDictionary) {
                    $specs[] = $spec;
                }
            }
        }

        $kids = $this->resolve(PdfDictionary::get($node, 'Kids'), $parser);
        if ($kids instanceof PdfArray) {
            foreach ($kids->value as $kid) {
                if ($kid instanceof PdfType) {
                    $this->walkNameTree($this->resolve($kid, $parser), $parser, $specs, $depth + 1);
                }
            }
        }
    }

    private function filename(PdfDictionary $spec, PdfParser $parser): ?string
    {
        foreach (['UF', 'F'] as $key) {
            $value = $this->resolve(PdfDictionary::get($spec, $key), $parser);
            $string = match (true) {
                $value instanceof PdfString && is_string($value->value) => PdfString::unescape($value->value),
                $value instanceof PdfHexString && is_string($value->value) => (string) hex2bin($value->value),
                default => null,
            };
            if ($string === null || $string === '') {
                continue;
            }
            if (str_starts_with($string, "\xFE\xFF")) {
                $string = (string) mb_convert_encoding(substr($string, 2), 'UTF-8', 'UTF-16BE');
            }

            // Never use names from the file as paths.
            return basename(str_replace('\\', '/', $string));
        }

        return null;
    }

    private function content(PdfDictionary $spec, PdfParser $parser): string
    {
        $ef = $this->resolve(PdfDictionary::get($spec, 'EF'), $parser);
        if (! $ef instanceof PdfDictionary) {
            throw new PdfException('An embedded file has no stream.');
        }

        $stream = $this->resolve(PdfDictionary::get($ef, 'UF'), $parser);
        if (! $stream instanceof PdfStream) {
            $stream = $this->resolve(PdfDictionary::get($ef, 'F'), $parser);
        }
        if (! $stream instanceof PdfStream) {
            throw new PdfException('An embedded file has no stream.');
        }

        $data = $stream->getStream();
        if (! is_string($data)) {
            throw new PdfException('An embedded file stream could not be read.');
        }
        foreach ($stream->getFilters() as $filter) {
            if (! $filter instanceof PdfName || $filter->value !== 'FlateDecode') {
                throw new PdfException('Unsupported filter for embedded files.');
            }
            $data = $this->inflate($data);
        }

        if (strlen($data) > $this->maxDecompressedBytes) {
            throw new PdfException('An embedded file exceeds the size limit.');
        }

        return $data;
    }

    /**
     * Decompresses a FlateDecode stream chunk by chunk and stops at the size limit.
     */
    private function inflate(string $data): string
    {
        $context = inflate_init(ZLIB_ENCODING_DEFLATE);
        if ($context === false) {
            throw new PdfException('Unable to initialise decompression.');
        }

        $output = '';
        foreach (str_split($data, 8192) as $chunk) {
            $part = @inflate_add($context, $chunk, ZLIB_SYNC_FLUSH);
            if ($part === false) {
                throw new PdfException('An embedded file could not be decompressed.');
            }
            $output .= $part;
            if (strlen($output) > $this->maxDecompressedBytes) {
                throw new PdfException(sprintf('An embedded file exceeds the decompression limit of %d bytes.', $this->maxDecompressedBytes));
            }
        }

        $rest = @inflate_add($context, '', ZLIB_FINISH);
        if ($rest !== false) {
            $output .= $rest;
        }

        return $output;
    }

    private function resolve(PdfType $value, PdfParser $parser): PdfType
    {
        return PdfType::resolve($value, $parser);
    }
}
