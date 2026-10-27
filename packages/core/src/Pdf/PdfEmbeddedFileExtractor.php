<?php

declare(strict_types=1);

namespace Quittio\Core\Pdf;

use Quittio\Core\Pdf\Parsing\ArrayValue;
use Quittio\Core\Pdf\Parsing\ByteString;
use Quittio\Core\Pdf\Parsing\Dictionary;
use Quittio\Core\Pdf\Parsing\Document;
use Quittio\Core\Pdf\Parsing\Name;
use Quittio\Core\Pdf\Parsing\PdfValue;
use Quittio\Core\Pdf\Parsing\Reference;
use Quittio\Core\Pdf\Parsing\Stream;
use Quittio\Core\Reading\DiagnosingXmlExtractor;
use Quittio\Core\Reading\ExtractedXml;
use Quittio\Core\Validation\Severity;
use Quittio\Core\Validation\ValidationLevel;
use Quittio\Core\Validation\ValidationMessage;
use Throwable;

/**
 * Extracts the embedded invoice XML from PDF files (ZUGFeRD 2.x, Factur-X, XRechnung in PDF).
 *
 * Embedded files are searched in the associated files of the document (/AF, PDF/A-3) and in
 * the name tree (/Names /EmbeddedFiles). Classic cross-reference tables, cross-reference
 * streams and object streams are supported. All structures are limited (tree size, number
 * of file specifications, decompressed size) as protection against malicious files, and
 * file names are never used as paths.
 */
final class PdfEmbeddedFileExtractor implements DiagnosingXmlExtractor
{
    /** Known file names in order of preference (ZUGFeRD-invoice.xml is the ZUGFeRD 1.0 name). */
    public const array KNOWN_FILENAMES = ['factur-x.xml', 'xrechnung.xml', 'zugferd-invoice.xml', 'ZUGFeRD-invoice.xml'];

    private const int MAX_TREE_DEPTH = 10;

    private const int MAX_TREE_NODES = 1000;

    private const int MAX_FILE_SPECS = 1000;

    /** Further candidates whose content is compared with the chosen file. */
    private const int MAX_COMPARED_CANDIDATES = 3;

    public function __construct(
        private readonly int $maxPdfBytes = 25 * 1024 * 1024,
        private readonly int $maxDecompressedBytes = 50 * 1024 * 1024,
    ) {}

    /**
     * Largest PDF in bytes the extractor accepts; larger input throws a PdfException.
     * InvoiceReader::readFile() accepts files up to this size as well.
     */
    public function maxPdfBytes(): int
    {
        return $this->maxPdfBytes;
    }

    public function extract(string $pdf): ?array
    {
        $file = $this->extractFile($pdf);

        return $file === null ? null : [$file->filename, $file->xml];
    }

    /**
     * The embedded invoice XML with its file name, /AFRelationship and findings (several candidates
     * with different content, a file that is no associated file), or null if the PDF embeds no XML
     * file. Known file names win over other XML files, associated files (/AF) over name tree
     * entries. Throws a PdfException when the PDF is too large, malformed or exceeds a limit.
     */
    public function extractFile(string $pdf): ?ExtractedXml
    {
        [$document, $candidates] = $this->candidates($pdf);

        $chosen = $this->choose($candidates);
        if ($chosen === null) {
            return null;
        }

        $xml = $this->content($document, $chosen['spec']);

        return new ExtractedXml(
            filename: $chosen['name'],
            xml: $xml,
            relationship: $chosen['relationship'],
            findings: $this->findings($document, $candidates, $chosen, $xml),
        );
    }

    /**
     * Names of all embedded files.
     *
     * @return list<string>
     */
    public function names(string $pdf): array
    {
        [, $candidates] = $this->candidates($pdf);

        return array_values(array_unique(array_map(static fn (array $candidate): string => $candidate['name'], $candidates)));
    }

    /**
     * @return array{0: Document, 1: list<array{key: string, name: string, spec: Dictionary, af: bool, relationship: ?string, order: int}>}
     */
    private function candidates(string $pdf): array
    {
        if (strlen($pdf) > $this->maxPdfBytes) {
            throw new PdfException(sprintf('The PDF exceeds the size limit of %d bytes.', $this->maxPdfBytes));
        }

        try {
            $document = Document::parse($pdf, $this->maxDecompressedBytes);
            $catalog = $document->catalog();
        } catch (PdfException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new PdfException('The PDF could not be parsed: '.$e->getMessage(), 0, $e);
        }

        /** @var array<string, array{spec: Dictionary, af: bool}> $specs */
        $specs = [];
        try {
            $af = $document->get($catalog, 'AF');
            if ($af instanceof ArrayValue) {
                foreach ($af->items as $entry) {
                    $this->addSpec($document, $entry, true, $specs);
                }
            }

            $names = $document->get($catalog, 'Names');
            if ($names instanceof Dictionary) {
                $visited = [];
                $nodes = 0;
                $this->walkNameTree($document, $names->get('EmbeddedFiles'), $specs, $visited, $nodes, 0);
            }
        } catch (PdfException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new PdfException('The embedded files of the PDF could not be read: '.$e->getMessage(), 0, $e);
        }

        $candidates = [];
        $order = 0;
        foreach ($specs as $key => $spec) {
            try {
                $name = $this->filename($document, $spec['spec']);
                $relationship = $document->get($spec['spec'], 'AFRelationship');
            } catch (PdfException $e) {
                throw $e;
            } catch (Throwable $e) {
                throw new PdfException('The embedded files of the PDF could not be read: '.$e->getMessage(), 0, $e);
            }
            if ($name === null) {
                continue;
            }
            $candidates[] = [
                'key' => $key,
                'name' => $name,
                'spec' => $spec['spec'],
                'af' => $spec['af'],
                'relationship' => $relationship instanceof Name ? $relationship->value : null,
                'order' => $order++,
            ];
        }

        return [$document, $candidates];
    }

    /**
     * Collects a file specification once, however often it is referenced.
     *
     * @param  array<string, array{spec: Dictionary, af: bool}>  $specs
     */
    private function addSpec(Document $document, ?PdfValue $entry, bool $af, array &$specs): void
    {
        $key = $entry instanceof Reference ? 'r'.$entry->number : null;
        $spec = $document->resolve($entry);
        if (! $spec instanceof Dictionary) {
            return;
        }
        $key ??= 'd'.spl_object_id($spec);

        if (isset($specs[$key])) {
            $specs[$key]['af'] = $specs[$key]['af'] || $af;

            return;
        }
        if (count($specs) >= self::MAX_FILE_SPECS) {
            throw new PdfException(sprintf('The PDF contains more than %d embedded file specifications.', self::MAX_FILE_SPECS));
        }
        $specs[$key] = ['spec' => $spec, 'af' => $af];
    }

    /**
     * Walks the embedded files name tree; every node is visited once.
     *
     * @param  array<string, array{spec: Dictionary, af: bool}>  $specs
     * @param  array<int, true>  $visited  object numbers of visited nodes
     */
    private function walkNameTree(Document $document, ?PdfValue $value, array &$specs, array &$visited, int &$nodes, int $depth): void
    {
        if ($depth > self::MAX_TREE_DEPTH) {
            return;
        }
        if ($value instanceof Reference) {
            if (isset($visited[$value->number])) {
                return;
            }
            $visited[$value->number] = true;
        }

        $node = $document->resolve($value);
        if (! $node instanceof Dictionary) {
            return;
        }
        if (++$nodes > self::MAX_TREE_NODES) {
            throw new PdfException(sprintf('The embedded files name tree of the PDF has more than %d nodes.', self::MAX_TREE_NODES));
        }

        $names = $document->get($node, 'Names');
        if ($names instanceof ArrayValue) {
            foreach (array_chunk($names->items, 2) as $pair) {
                $this->addSpec($document, $pair[1] ?? null, false, $specs);
            }
        }

        $kids = $document->get($node, 'Kids');
        if ($kids instanceof ArrayValue) {
            foreach ($kids->items as $kid) {
                $this->walkNameTree($document, $kid, $specs, $visited, $nodes, $depth + 1);
            }
        }
    }

    /**
     * Known file names first, then any XML file; associated files (/AF) before name tree entries.
     *
     * @param  list<array{key: string, name: string, spec: Dictionary, af: bool, relationship: ?string, order: int}>  $candidates
     * @return array{key: string, name: string, spec: Dictionary, af: bool, relationship: ?string, order: int}|null
     */
    private function choose(array $candidates): ?array
    {
        $ranked = [];
        foreach ($candidates as $candidate) {
            $rank = $this->rank($candidate['name']);
            if ($rank !== null) {
                $ranked[] = [[$rank >= count(self::KNOWN_FILENAMES) ? 1 : 0, $candidate['af'] ? 0 : 1, $rank, $candidate['order']], $candidate];
            }
        }
        if ($ranked === []) {
            return null;
        }

        usort($ranked, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return $ranked[0][1];
    }

    /**
     * Position in the known file names, the number of known names for other XML files, null otherwise.
     */
    private function rank(string $name): ?int
    {
        foreach (self::KNOWN_FILENAMES as $index => $known) {
            if (strcasecmp($name, $known) === 0) {
                return $index;
            }
        }

        return str_ends_with(strtolower($name), '.xml') ? count(self::KNOWN_FILENAMES) : null;
    }

    /**
     * @param  list<array{key: string, name: string, spec: Dictionary, af: bool, relationship: ?string, order: int}>  $candidates
     * @param  array{key: string, name: string, spec: Dictionary, af: bool, relationship: ?string, order: int}  $chosen
     * @return list<ValidationMessage>
     */
    private function findings(Document $document, array $candidates, array $chosen, string $xml): array
    {
        $findings = [];
        $known = array_values(array_filter($candidates, fn (array $c): bool => ($this->rank($c['name']) ?? PHP_INT_MAX) < count(self::KNOWN_FILENAMES)));
        $others = array_values(array_filter($candidates, fn (array $c): bool => $this->rank($c['name']) === count(self::KNOWN_FILENAMES)));

        if (count($known) > 1) {
            $different = [];
            $compared = 0;
            $chosenStream = $this->streamKey($document, $chosen['spec']);
            foreach ($known as $candidate) {
                if ($candidate['key'] === $chosen['key'] || $compared >= self::MAX_COMPARED_CANDIDATES) {
                    continue;
                }
                $compared++;
                $stream = $this->streamKey($document, $candidate['spec']);
                if ($stream !== null && $stream === $chosenStream) {
                    continue;
                }
                try {
                    $same = $this->content($document, $candidate['spec']) === $xml;
                } catch (PdfException) {
                    $same = false;
                }
                if (! $same) {
                    $different[] = $candidate['name'];
                }
            }
            if ($different !== []) {
                $findings[] = $this->warning('QUITTIO-READER-PDF-CANDIDATES', sprintf(
                    'The PDF contains %d embedded invoice files with different content (%s); "%s" was read.',
                    count($different) + 1,
                    implode(', ', array_unique([$chosen['name'], ...$different])),
                    $chosen['name'],
                ));
            }
        } elseif ($known === [] && count($others) > 1) {
            $findings[] = $this->warning('QUITTIO-READER-PDF-CANDIDATES', sprintf(
                'The PDF contains several XML files (%s) and none has a known e-invoice file name; "%s" was read.',
                implode(', ', array_map(static fn (array $c): string => $c['name'], $others)),
                $chosen['name'],
            ));
        }

        if (! $chosen['af']) {
            $findings[] = $this->warning('QUITTIO-READER-PDF-AF', sprintf(
                'The embedded file "%s" is not an associated file (/AF) of the document, as PDF/A-3 requires for ZUGFeRD and Factur-X.',
                $chosen['name'],
            ));
        }

        return $findings;
    }

    private function warning(string $code, string $message): ValidationMessage
    {
        return new ValidationMessage(Severity::Warning, $code, $message, ValidationLevel::L1);
    }

    private function filename(Document $document, Dictionary $spec): ?string
    {
        foreach (['UF', 'F'] as $key) {
            $value = $document->get($spec, $key);
            if (! $value instanceof ByteString) {
                continue;
            }
            $name = $value->text();
            if ($name === '') {
                continue;
            }

            // Never use names from the file as paths.
            $name = basename(str_replace('\\', '/', $name));

            return $name === '' ? null : $name;
        }

        return null;
    }

    /**
     * Identity of the embedded file stream of a file specification, if it is an indirect object.
     */
    private function streamKey(Document $document, Dictionary $spec): ?int
    {
        try {
            $ef = $document->get($spec, 'EF');
        } catch (PdfException) {
            return null;
        }
        if (! $ef instanceof Dictionary) {
            return null;
        }
        $stream = $ef->get('UF') ?? $ef->get('F');

        return $stream instanceof Reference ? $stream->number : null;
    }

    private function content(Document $document, Dictionary $spec): string
    {
        try {
            $ef = $document->get($spec, 'EF');
            if (! $ef instanceof Dictionary) {
                throw new PdfException('An embedded file has no stream.');
            }

            $stream = $document->get($ef, 'UF');
            if (! $stream instanceof Stream) {
                $stream = $document->get($ef, 'F');
            }
            if (! $stream instanceof Stream) {
                throw new PdfException('An embedded file has no stream.');
            }

            return $document->streamData($stream, $this->maxDecompressedBytes);
        } catch (PdfException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new PdfException('An embedded file could not be read: '.$e->getMessage(), 0, $e);
        }
    }
}
