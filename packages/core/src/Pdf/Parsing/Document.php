<?php

declare(strict_types=1);

namespace Quittio\Core\Pdf\Parsing;

use Quittio\Core\Pdf\PdfException;

/**
 * Minimal PDF document reader (ISO 32000-1, 7.5): classic cross-reference tables,
 * cross-reference streams, hybrid files, incremental updates and object streams.
 *
 * Only what is needed to find embedded files is supported. Every structure is limited:
 * the number of objects and cross-reference sections, the nesting of references and the
 * decompressed size of cross-reference and object streams.
 */
final class Document
{
    public const int MAX_OBJECTS = 1_000_000;

    private const int MAX_XREF_SECTIONS = 100;

    private const int MAX_REFERENCE_CHAIN = 32;

    private const int MAX_OBJECTS_PER_STREAM = 100_000;

    /** Damaged files are rebuilt by scanning; e-invoices have far fewer objects, and two full indexes near MAX_OBJECTS would exceed common memory limits. */
    private const int MAX_RECONSTRUCTED_OBJECTS = 100_000;

    /** @var array<int, array{0: int, 1: int}> object number => [1, offset] or [2, object stream number] */
    private array $entries = [];

    /** @var array<int, int>|null object number => offset, found by scanning the file */
    private ?array $scanned = null;

    /** @var array<int, PdfValue> */
    private array $cache = [];

    /** @var array<int, true> objects currently being loaded */
    private array $loading = [];

    /** @var array<int, array{0: string, 1: array<int, int>}> object stream number => [data, object number => offset] */
    private array $objectStreams = [];

    private Dictionary $trailer;

    /** Remaining decompression budget for cross-reference and object streams. */
    private int $budget;

    private function __construct(private readonly string $data, private readonly int $maxDecompressedBytes)
    {
        $this->budget = $maxDecompressedBytes;
        $this->trailer = new Dictionary([]);
    }

    public static function parse(string $data, int $maxDecompressedBytes): self
    {
        $header = strpos(substr($data, 0, 1024), '%PDF-');
        if ($header === false) {
            throw new PdfException('The PDF could not be parsed: the file has no PDF header.');
        }

        $document = new self($data, $maxDecompressedBytes);
        try {
            $document->readCrossReferences();
        } catch (PdfException $e) {
            // Damaged cross-reference data: rebuild the object index from the file content.
            $document->rebuild($e);
        }

        if ($document->trailer->get('Encrypt') !== null) {
            throw new PdfException('The PDF is encrypted and cannot be read.');
        }

        return $document;
    }

    public function catalog(): Dictionary
    {
        $catalog = $this->resolve($this->trailer->get('Root'));
        if (! $catalog instanceof Dictionary) {
            throw new PdfException('The PDF could not be parsed: the document catalog is missing.');
        }

        return $catalog;
    }

    /**
     * Follows indirect references. Missing objects are null (ISO 32000-1, 7.3.10).
     */
    public function resolve(?PdfValue $value): PdfValue
    {
        $chain = 0;
        while ($value instanceof Reference) {
            if (++$chain > self::MAX_REFERENCE_CHAIN) {
                throw new PdfException('Malformed PDF: indirect references are nested too deeply.');
            }
            $value = $this->object($value->number);
        }

        return $value ?? new NullValue;
    }

    /**
     * The resolved value of a dictionary entry.
     */
    public function get(Dictionary $dictionary, string $key): PdfValue
    {
        return $this->resolve($dictionary->get($key));
    }

    /**
     * The decoded data of a stream.
     */
    public function streamData(Stream $stream, ?int $limit = null): string
    {
        return StreamDecoder::decode($stream->data, $this->filters($stream->dictionary), $limit ?? $this->maxDecompressedBytes);
    }

    public function object(int $number): PdfValue
    {
        if (isset($this->cache[$number])) {
            return $this->cache[$number];
        }
        if (isset($this->loading[$number])) {
            throw new PdfException(sprintf('Malformed PDF: object %d refers to itself.', $number));
        }

        $this->loading[$number] = true;
        try {
            $value = $this->load($number);
        } finally {
            unset($this->loading[$number]);
        }

        return $this->cache[$number] = $value;
    }

    private function load(int $number): PdfValue
    {
        $entry = $this->entries[$number] ?? null;
        if ($entry !== null && $entry[0] === 2) {
            return $this->fromObjectStream($entry[1], $number);
        }

        if ($entry !== null) {
            $value = $this->indirectObjectAt($entry[1], $number);
            if ($value !== null) {
                return $value;
            }
        }

        // Wrong offsets are common in damaged files: look the object up by scanning.
        $offset = $this->scan()[$number] ?? null;
        if ($offset !== null && $offset !== ($entry[1] ?? null)) {
            return $this->indirectObjectAt($offset, $number) ?? new NullValue;
        }

        return new NullValue;
    }

    /**
     * Reads "n g obj … endobj" at an offset; null if another object (or none) is there.
     */
    private function indirectObjectAt(int $offset, ?int $expected): ?PdfValue
    {
        if ($offset < 0 || $offset >= strlen($this->data)) {
            return null;
        }

        $lexer = new Lexer($this->data, $offset);
        $number = $lexer->readInteger();
        $generation = $lexer->readInteger();
        if ($number === null || $generation === null || $lexer->readKeyword() !== 'obj' || ($expected !== null && $number !== $expected)) {
            return null;
        }

        $value = $lexer->readValue();
        if ($value instanceof Dictionary && $lexer->peekKeyword() === 'stream') {
            $lexer->readKeyword();

            return new Stream($value, $this->streamBytes($value, $lexer->position()));
        }

        return $value;
    }

    /**
     * The raw stream data after the "stream" keyword; /Length is used when it is plausible.
     */
    private function streamBytes(Dictionary $dictionary, int $position): string
    {
        if (substr($this->data, $position, 2) === "\r\n") {
            $position += 2;
        } elseif (in_array($this->data[$position] ?? '', ["\n", "\r"], true)) {
            $position++;
        }

        $length = null;
        try {
            $value = $this->resolve($dictionary->get('Length'));
            $length = $value instanceof NumberValue ? $value->int() : null;
        } catch (PdfException) {
            // The length is checked against the "endstream" keyword below.
        }

        if ($length !== null && $length >= 0 && $position + $length <= strlen($this->data)) {
            $after = new Lexer($this->data, $position + $length);
            if ($after->peekKeyword() === 'endstream') {
                return substr($this->data, $position, $length);
            }
        }

        $end = strpos($this->data, 'endstream', $position);
        if ($end === false) {
            throw new PdfException('Malformed PDF: a stream has no end.');
        }
        $bytes = substr($this->data, $position, $end - $position);

        return str_ends_with($bytes, "\r\n") ? substr($bytes, 0, -2) : rtrim($bytes, "\r\n");
    }

    private function fromObjectStream(int $streamNumber, int $number): PdfValue
    {
        if (! isset($this->objectStreams[$streamNumber])) {
            if (($this->entries[$streamNumber][0] ?? 1) === 2) {
                throw new PdfException('Malformed PDF: an object stream is stored in an object stream.');
            }
            $stream = $this->object($streamNumber);
            if (! $stream instanceof Stream || $stream->dictionary->name('Type') !== 'ObjStm') {
                return new NullValue;
            }
            $this->objectStreams[$streamNumber] = $this->readObjectStream($stream);
        }

        [$data, $offsets] = $this->objectStreams[$streamNumber];
        if (! isset($offsets[$number])) {
            return new NullValue;
        }

        return (new Lexer($data, $offsets[$number]))->readValue();
    }

    /**
     * @return array{0: string, 1: array<int, int>}
     */
    private function readObjectStream(Stream $stream): array
    {
        $count = $this->resolve($stream->dictionary->get('N'));
        $first = $this->resolve($stream->dictionary->get('First'));
        $count = $count instanceof NumberValue ? $count->int() : null;
        $first = $first instanceof NumberValue ? $first->int() : null;
        if ($count === null || $first === null || $count < 0 || $first < 0 || $count > self::MAX_OBJECTS_PER_STREAM) {
            throw new PdfException('Malformed PDF: invalid object stream.');
        }

        $data = $this->decodeStructural($stream);
        $lexer = new Lexer($data);
        $offsets = [];
        for ($i = 0; $i < $count; $i++) {
            $number = $lexer->readInteger();
            $offset = $lexer->readInteger();
            if ($number === null || $offset === null) {
                break;
            }
            $offsets[$number] ??= $first + $offset;
        }

        return [$data, $offsets];
    }

    /**
     * Decodes a cross-reference or object stream within the shared decompression budget.
     */
    private function decodeStructural(Stream $stream): string
    {
        $data = StreamDecoder::decode($stream->data, $this->filters($stream->dictionary), $this->budget);
        $this->budget -= strlen($data);

        return $data;
    }

    /**
     * @return list<array{0: string, 1: ?Dictionary}>
     */
    private function filters(Dictionary $dictionary): array
    {
        $filter = $this->resolve($dictionary->get('Filter'));
        $parameters = $this->resolve($dictionary->get('DecodeParms'));
        $names = $filter instanceof ArrayValue ? $filter->items : [$filter];
        $parameterList = $parameters instanceof ArrayValue ? $parameters->items : [$parameters];

        $filters = [];
        foreach ($names as $index => $name) {
            $name = $this->resolve($name);
            if ($name instanceof NullValue) {
                continue;
            }
            if (! $name instanceof Name) {
                throw new PdfException('Malformed PDF: invalid stream filter.');
            }
            $parameter = $this->resolve($parameterList[$index] ?? null);
            $filters[] = [$name->value, $parameter instanceof Dictionary ? $parameter : null];
        }

        return $filters;
    }

    private function readCrossReferences(): void
    {
        $start = strrpos($this->data, 'startxref');
        if ($start === false) {
            throw new PdfException('Unable to find the cross-reference table.');
        }
        $offset = (new Lexer($this->data, $start + 9))->readInteger();
        if ($offset === null) {
            throw new PdfException('Invalid data after the startxref keyword.');
        }

        $visited = [];
        $trailers = [];
        while ($offset !== null) {
            if (isset($visited[$offset])) {
                throw new PdfException('Malformed PDF: the cross-reference sections form a loop.');
            }
            if (count($visited) >= self::MAX_XREF_SECTIONS) {
                throw new PdfException('Malformed PDF: too many cross-reference sections.');
            }
            $visited[$offset] = true;

            $trailer = $this->readSection($offset);
            $trailers[] = $trailer;

            // Hybrid files (7.5.8.4): the hidden cross-reference stream belongs to this section.
            $hidden = $this->offset($trailer->get('XRefStm'));
            if ($hidden !== null && ! isset($visited[$hidden])) {
                $visited[$hidden] = true;
                $this->readSection($hidden);
            }

            $offset = $this->offset($trailer->get('Prev'));
        }

        $entries = [];
        foreach (array_reverse($trailers) as $section) {
            $entries = [...$entries, ...$section->entries];
        }
        unset($entries['Prev'], $entries['XRefStm']);
        $this->trailer = new Dictionary($entries);

        $size = $this->trailer->get('Size');
        if ($size instanceof NumberValue && $size->value > self::MAX_OBJECTS) {
            throw new PdfException('The PDF declares too many objects.');
        }
    }

    private function offset(?PdfValue $value): ?int
    {
        return $value instanceof NumberValue && $value->int() !== null && $value->int() >= 0 ? $value->int() : null;
    }

    /**
     * Reads one cross-reference section (table or stream) and returns its trailer dictionary.
     */
    private function readSection(int $offset): Dictionary
    {
        $lexer = new Lexer($this->data, $offset);
        if ($lexer->peekKeyword() === 'xref') {
            $lexer->readKeyword();

            return $this->readTable($lexer);
        }

        $stream = $this->indirectObjectAt($offset, null);
        if (! $stream instanceof Stream || $stream->dictionary->name('Type') !== 'XRef') {
            throw new PdfException('The cross-reference offset points to an incorrect object.');
        }

        $this->readStream($stream);

        return $stream->dictionary;
    }

    private function readTable(Lexer $lexer): Dictionary
    {
        while (true) {
            if ($lexer->peekKeyword() === 'trailer') {
                $lexer->readKeyword();
                $trailer = $lexer->readValue();
                if (! $trailer instanceof Dictionary) {
                    throw new PdfException('Malformed PDF: invalid trailer.');
                }

                return $trailer;
            }

            $first = $lexer->readInteger();
            $count = $lexer->readInteger();
            if ($first === null || $count === null || $first + $count > self::MAX_OBJECTS) {
                throw new PdfException('Malformed PDF: invalid cross-reference table.');
            }

            for ($i = 0; $i < $count; $i++) {
                $position = $lexer->position();
                $entryOffset = $lexer->readInteger();
                $generation = $lexer->readInteger();
                $type = $lexer->readKeyword();
                if ($entryOffset === null || $generation === null || ($type !== 'n' && $type !== 'f')) {
                    throw new PdfException(sprintf('Malformed PDF: invalid cross-reference entry at offset %d.', $position));
                }
                if ($type === 'n' && $entryOffset > 0) {
                    $this->entries[$first + $i] ??= [1, $entryOffset];
                }
            }
        }
    }

    private function readStream(Stream $stream): void
    {
        $widths = $this->resolve($stream->dictionary->get('W'));
        $size = $this->resolve($stream->dictionary->get('Size'));
        $size = $size instanceof NumberValue ? $size->int() : null;
        if (! $widths instanceof ArrayValue || count($widths->items) < 3 || $size === null || $size < 0 || $size > self::MAX_OBJECTS) {
            throw new PdfException('Malformed PDF: invalid cross-reference stream.');
        }

        $w = [];
        foreach (array_slice($widths->items, 0, 3) as $width) {
            $width = $this->resolve($width);
            $value = $width instanceof NumberValue ? $width->int() : null;
            if ($value === null || $value < 0 || $value > 8) {
                throw new PdfException('Malformed PDF: invalid cross-reference stream field widths.');
            }
            $w[] = $value;
        }
        $rowLength = $w[0] + $w[1] + $w[2];
        if ($rowLength === 0) {
            throw new PdfException('Malformed PDF: invalid cross-reference stream field widths.');
        }

        $index = $this->resolve($stream->dictionary->get('Index'));
        $ranges = [];
        if ($index instanceof ArrayValue) {
            foreach (array_chunk($index->items, 2) as $pair) {
                $first = $this->resolve($pair[0]);
                $count = $this->resolve($pair[1] ?? null);
                $first = $first instanceof NumberValue ? $first->int() : null;
                $count = $count instanceof NumberValue ? $count->int() : null;
                if ($first === null || $count === null || $first < 0 || $count < 0 || $first + $count > self::MAX_OBJECTS) {
                    throw new PdfException('Malformed PDF: invalid cross-reference stream index.');
                }
                $ranges[] = [$first, $count];
            }
        } else {
            $ranges[] = [0, $size];
        }

        $data = $this->decodeStructural($stream);
        $position = 0;
        $length = strlen($data);
        foreach ($ranges as [$first, $count]) {
            for ($i = 0; $i < $count && $position + $rowLength <= $length; $i++, $position += $rowLength) {
                $type = $w[0] === 0 ? 1 : self::field($data, $position, $w[0]);
                $field2 = self::field($data, $position + $w[0], $w[1]);
                if (($type === 1 && $field2 > 0) || $type === 2) {
                    $this->entries[$first + $i] ??= [$type, $field2];
                }
            }
        }
    }

    private static function field(string $data, int $position, int $width): int
    {
        $value = 0;
        for ($i = 0; $i < $width; $i++) {
            $value = ($value << 8) | ord($data[$position + $i]);
        }

        return $value;
    }

    /**
     * Rebuilds the object index by scanning for "n g obj" when the cross-reference data is damaged.
     */
    private function rebuild(PdfException $cause): void
    {
        $this->entries = [];
        $objects = $this->scan();
        if ($objects === []) {
            throw new PdfException('The PDF could not be parsed: '.$cause->getMessage(), 0, $cause);
        }
        foreach ($objects as $number => $offset) {
            $this->entries[$number] = [1, $offset];
        }

        $trailer = null;
        $position = strrpos($this->data, 'trailer');
        if ($position !== false) {
            try {
                $value = (new Lexer($this->data, $position + 7))->readValue();
                $trailer = $value instanceof Dictionary ? $value : null;
            } catch (PdfException) {
                $trailer = null;
            }
        }

        // Cross-reference streams carry the trailer entries and list the compressed objects.
        foreach ($objects as $number => $offset) {
            if (! $this->looksLike($offset, '/XRef') && ! $this->looksLike($offset, '/ObjStm')) {
                continue;
            }
            try {
                $value = $this->object($number);
            } catch (PdfException) {
                continue;
            }
            if ($value instanceof Stream && $value->dictionary->name('Type') === 'XRef') {
                $trailer ??= $value->dictionary;
            }
            if ($value instanceof Stream && $value->dictionary->name('Type') === 'ObjStm') {
                try {
                    $this->objectStreams[$number] ??= $this->readObjectStream($value);
                } catch (PdfException) {
                    continue;
                }
                foreach (array_keys($this->objectStreams[$number][1]) as $compressed) {
                    $this->entries[$compressed] ??= [2, $number];
                }
            }
        }

        $this->cache = [];
        $this->trailer = $trailer ?? new Dictionary([]);
        if ($this->trailer->get('Root') === null) {
            $root = $this->findCatalog();
            if ($root === null) {
                throw new PdfException('The PDF could not be parsed: '.$cause->getMessage(), 0, $cause);
            }
            $this->trailer = new Dictionary([...$this->trailer->entries, 'Root' => new Reference($root)]);
        }
    }

    private function looksLike(int $offset, string $type): bool
    {
        return str_contains(substr($this->data, $offset, 512), $type);
    }

    private function findCatalog(): ?int
    {
        foreach ($this->entries as $number => $entry) {
            try {
                $value = $this->object($number);
            } catch (PdfException) {
                continue;
            }
            if ($value instanceof Dictionary && $value->name('Type') === 'Catalog') {
                return $number;
            }
            // Probing every object of a damaged file would otherwise keep each parsed
            // object cached and exhaust memory; only the catalog is needed again.
            unset($this->cache[$number]);
        }

        return null;
    }

    /**
     * @return array<int, int> object number => offset of its last definition
     */
    private function scan(): array
    {
        if ($this->scanned !== null) {
            return $this->scanned;
        }

        // Matched one at a time rather than with preg_match_all: capturing the offsets of
        // every object in a large file at once would exhaust memory before the cap applies.
        $this->scanned = [];
        $pattern = '/(?<![0-9])(\d{1,10})[\x00\x09\x0A\x0C\x0D\x20]+\d{1,5}[\x00\x09\x0A\x0C\x0D\x20]+obj(?![^\x00\x09\x0A\x0C\x0D\x20()<>\[\]{}\/%])/';
        $position = 0;
        while (preg_match($pattern, $this->data, $match, PREG_OFFSET_CAPTURE, $position) === 1) {
            $this->scanned[(int) $match[1][0]] = $match[1][1];
            $position = $match[0][1] + strlen($match[0][0]);
            if (count($this->scanned) > self::MAX_RECONSTRUCTED_OBJECTS) {
                return $this->scanned = [];
            }
        }

        return $this->scanned;
    }
}
