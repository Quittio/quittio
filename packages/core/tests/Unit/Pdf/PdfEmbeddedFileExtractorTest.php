<?php

declare(strict_types=1);

use Quittio\Core\Pdf\PdfEmbeddedFileExtractor;
use Quittio\Core\Pdf\PdfException;
use Quittio\Core\Reading\InvoiceReader;

const PDF_EXTRACTOR_XML = '<?xml version="1.0" encoding="UTF-8"?><invoice>original</invoice>';

/**
 * Builds a small PDF from object bodies.
 *
 * Modes: "classic" (cross-reference table), "stream" (cross-reference stream with PNG predictor),
 * "objstm" (non-stream objects in an object stream plus cross-reference stream) and "hybrid"
 * (classic table plus /XRefStm for the compressed objects).
 *
 * @param  array<int, string>  $objects
 */
function pdfExtractorTestPdf(array $objects, string $mode = 'classic', string $trailerExtra = ''): string
{
    ksort($objects);
    $out = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n";
    $offsets = [];
    $compressed = [];
    $max = max(array_keys($objects));

    $direct = $objects;
    if ($mode === 'objstm' || $mode === 'hybrid') {
        $header = '';
        $body = '';
        $index = 0;
        $streamNumber = ++$max;
        foreach ($objects as $number => $object) {
            if (str_contains($object, 'stream')) {
                continue;
            }
            $header .= $number.' '.strlen($body).' ';
            $body .= $object."\n";
            $compressed[$number] = [$streamNumber, $index++];
            unset($direct[$number]);
        }
        $data = gzcompress($header.$body);
        $direct[$streamNumber] = '<< /Type /ObjStm /N '.$index.' /First '.strlen($header).' /Length '.strlen($data)." /Filter /FlateDecode >>\nstream\n".$data."\nendstream";
    }

    ksort($direct);
    foreach ($direct as $number => $object) {
        $offsets[$number] = strlen($out);
        $out .= "{$number} 0 obj\n{$object}\nendobj\n";
    }

    if ($mode === 'classic') {
        $xref = strlen($out);
        $out .= "xref\n0 ".($max + 1)."\n0000000000 65535 f \n";
        for ($i = 1; $i <= $max; $i++) {
            $out .= isset($offsets[$i]) ? sprintf("%010d 00000 n \n", $offsets[$i]) : "0000000000 65535 f \n";
        }
        $out .= 'trailer << /Size '.($max + 1).' /Root 1 0 R '.$trailerExtra." >>\nstartxref\n{$xref}\n%%EOF\n";

        return $out;
    }

    // Cross-reference stream with the fields 1, 4 and 2 bytes wide, encoded with the PNG "Up" predictor.
    $xrefNumber = $max + 1;
    $xrefOffset = strlen($out);
    $offsets[$xrefNumber] = $xrefOffset;
    $rows = [];
    for ($i = 0; $i <= $xrefNumber; $i++) {
        $rows[] = match (true) {
            isset($compressed[$i]) => pack('CNn', 2, $compressed[$i][0], $compressed[$i][1]),
            isset($offsets[$i]) && $mode !== 'hybrid' => pack('CNn', 1, $offsets[$i], 0),
            default => pack('CNn', 0, 0, 0),
        };
    }
    $encoded = '';
    $previous = str_repeat("\0", 7);
    foreach ($rows as $row) {
        $encoded .= "\x02";
        for ($i = 0; $i < 7; $i++) {
            $encoded .= chr((ord($row[$i]) - ord($previous[$i])) & 0xFF);
        }
        $previous = $row;
    }
    $data = gzcompress($encoded);
    $out .= "{$xrefNumber} 0 obj\n<< /Type /XRef /Size ".($xrefNumber + 1).' /W [1 4 2] /Root 1 0 R '.($mode === 'hybrid' ? '' : $trailerExtra).' /Filter /FlateDecode /DecodeParms << /Predictor 12 /Columns 7 >> /Length '.strlen($data)." >>\nstream\n{$data}\nendstream\nendobj\n";

    if ($mode === 'hybrid') {
        $xref = strlen($out);
        $out .= "xref\n0 ".($xrefNumber + 1)."\n0000000000 65535 f \n";
        for ($i = 1; $i <= $xrefNumber; $i++) {
            $out .= isset($offsets[$i]) ? sprintf("%010d 00000 n \n", $offsets[$i]) : "0000000000 65535 f \n";
        }
        $out .= 'trailer << /Size '.($xrefNumber + 1)." /Root 1 0 R /XRefStm {$xrefOffset} {$trailerExtra} >>\nstartxref\n{$xref}\n%%EOF\n";

        return $out;
    }

    return $out."startxref\n{$xrefOffset}\n%%EOF\n";
}

function pdfExtractorTestStream(string $data, string $dictionary = '/Type /EmbeddedFile /Subtype /text#2Fxml'): string
{
    $compressed = gzcompress($data);

    return '<< '.$dictionary.' /Filter /FlateDecode /Length '.strlen($compressed)." >>\nstream\n".$compressed."\nendstream";
}

/**
 * A ZUGFeRD-like PDF: catalog (1), pages (2, 3), name tree (4), file specification (5) and file (6).
 *
 * @param  array<int, string>  $override
 * @return array<int, string>
 */
function pdfExtractorTestObjects(array $override = []): array
{
    return array_replace([
        1 => '<< /Type /Catalog /Pages 2 0 R /Names << /EmbeddedFiles 4 0 R >> /AF [5 0 R] >>',
        2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] >>',
        4 => '<< /Names [(factur-x.xml) 5 0 R] >>',
        5 => '<< /Type /Filespec /F (factur-x.xml) /UF (factur-x.xml) /AFRelationship /Alternative /EF << /F 6 0 R /UF 6 0 R >> >>',
        6 => pdfExtractorTestStream(PDF_EXTRACTOR_XML),
    ], $override);
}

it('reads PDFs with cross-reference tables, cross-reference streams, object streams and hybrid files', function (string $mode) {
    $file = (new PdfEmbeddedFileExtractor)->extractFile(pdfExtractorTestPdf(pdfExtractorTestObjects(), $mode));

    expect($file?->filename)->toBe('factur-x.xml')
        ->and($file?->xml)->toBe(PDF_EXTRACTOR_XML)
        ->and($file?->relationship)->toBe('Alternative')
        ->and($file?->findings)->toBe([]);
})->with(['classic', 'stream', 'objstm', 'hybrid']);

it('reads a ZUGFeRD PDF with object streams written by qpdf', function () {
    $pdf = (string) file_get_contents(__DIR__.'/../../Fixtures/pdf/zugferd-objstm.pdf');

    $file = (new PdfEmbeddedFileExtractor)->extractFile($pdf);
    expect($pdf)->toContain('/ObjStm')
        ->and($file?->filename)->toBe('factur-x.xml')
        ->and($file?->relationship)->toBe('Alternative');

    $result = (new InvoiceReader)->read($pdf);
    expect($result->data->number)->toBe('INV-2026-0001')
        ->and($result->embeddedFilename)->toBe('factur-x.xml')
        ->and($result->profile)->toBe('en16931');
});

it('visits every node of the embedded files name tree only once', function () {
    $objects = pdfExtractorTestObjects([4 => '<< /Kids ['.str_repeat('4 0 R ', 16).'] /Names [(factur-x.xml) 5 0 R] >>']);

    $start = microtime(true);
    $file = (new PdfEmbeddedFileExtractor)->extractFile(pdfExtractorTestPdf($objects));

    expect($file?->xml)->toBe(PDF_EXTRACTOR_XML)
        ->and(microtime(true) - $start)->toBeLessThan(1.0);
});

it('limits the size of the embedded files name tree', function () {
    $objects = pdfExtractorTestObjects();
    $kids = [];
    for ($i = 0; $i < 1001; $i++) {
        $objects[100 + $i] = '<< /Names [] >>';
        $kids[] = (100 + $i).' 0 R';
    }
    $objects[4] = '<< /Kids ['.implode(' ', $kids).'] >>';

    (new PdfEmbeddedFileExtractor)->extractFile(pdfExtractorTestPdf($objects));
})->throws(PdfException::class, 'more than 1000 nodes');

it('limits the number of file specifications', function () {
    $names = str_repeat('(a.xml) << /Type /Filespec /F (a.xml) >> ', 1001);

    (new PdfEmbeddedFileExtractor)->extractFile(pdfExtractorTestPdf(pdfExtractorTestObjects([4 => '<< /Names ['.$names.'] >>'])));
})->throws(PdfException::class, 'more than 1000 embedded file specifications');

it('stops decompressing embedded files and object streams at the size limit', function (string $mode) {
    $objects = pdfExtractorTestObjects([6 => pdfExtractorTestStream(str_repeat("\0", 300_000))]);

    (new PdfEmbeddedFileExtractor(maxDecompressedBytes: 100_000))->extractFile(pdfExtractorTestPdf($objects, $mode));
})->with(['classic', 'objstm'])->throws(PdfException::class, 'decompression limit');

it('rejects object streams above the decompression limit', function () {
    $objects = pdfExtractorTestObjects([7 => '<< /Padding ('.str_repeat('x', 5000).') >>']);

    (new PdfEmbeddedFileExtractor(maxDecompressedBytes: 2000))->extractFile(pdfExtractorTestPdf($objects, 'objstm'));
})->throws(PdfException::class, 'decompression limit');

it('prefers the associated file (/AF) and reports several invoice files with different content', function () {
    $objects = pdfExtractorTestObjects([
        4 => '<< /Names [(factur-x.xml) 7 0 R (factur-x.xml) 5 0 R] >>',
        7 => '<< /Type /Filespec /F (factur-x.xml) /AFRelationship /Source /EF << /F 8 0 R >> >>',
        8 => pdfExtractorTestStream('<invoice>other</invoice>'),
    ]);

    $file = (new PdfEmbeddedFileExtractor)->extractFile(pdfExtractorTestPdf($objects));

    expect($file?->xml)->toBe(PDF_EXTRACTOR_XML)
        ->and($file?->relationship)->toBe('Alternative')
        ->and(array_map(fn ($f) => $f->code, $file->findings ?? []))->toBe(['QUITTIO-READER-PDF-CANDIDATES']);
});

it('does not report the same file referenced from /AF and the name tree twice', function () {
    $file = (new PdfEmbeddedFileExtractor)->extractFile(pdfExtractorTestPdf(pdfExtractorTestObjects([
        4 => '<< /Names [(factur-x.xml) 5 0 R (factur-x.xml) 5 0 R] >>',
    ])));

    expect($file?->findings)->toBe([]);
});

it('reports an invoice file that is not an associated file and several unknown XML files', function () {
    $objects = pdfExtractorTestObjects([
        1 => '<< /Type /Catalog /Pages 2 0 R /Names << /EmbeddedFiles 4 0 R >> >>',
        4 => '<< /Names [(order.xml) 5 0 R (other.xml) 7 0 R] >>',
        5 => '<< /Type /Filespec /F (order.xml) /EF << /F 6 0 R >> >>',
        7 => '<< /Type /Filespec /F (other.xml) /EF << /F 6 0 R >> >>',
    ]);

    $file = (new PdfEmbeddedFileExtractor)->extractFile(pdfExtractorTestPdf($objects));

    expect($file?->filename)->toBe('order.xml')
        ->and($file?->relationship)->toBeNull()
        ->and(array_map(fn ($f) => $f->code, $file->findings ?? []))->toBe(['QUITTIO-READER-PDF-CANDIDATES', 'QUITTIO-READER-PDF-AF']);
});

it('reads file names from hexadecimal strings with white space, odd length and UTF-16', function (string $name, string $expected) {
    set_error_handler(static fn (int $level, string $message): never => throw new ErrorException($message, 0, $level));
    try {
        $objects = pdfExtractorTestObjects([5 => '<< /Type /Filespec /UF '.$name.' /EF << /F 6 0 R >> >>']);
        $names = (new PdfEmbeddedFileExtractor)->names(pdfExtractorTestPdf($objects));
    } finally {
        restore_error_handler();
    }

    expect($names)->toBe([$expected]);
})->with([
    'white space' => ['<66616374 75722D78 2E786D6C>', 'factur-x.xml'],
    'odd length' => ['<612E78 6D6C3>', 'a.xml0'],
    'UTF-16BE' => ['<FEFF'.bin2hex(mb_convert_encoding('fäctur-x.xml', 'UTF-16BE', 'UTF-8')).'>', 'fäctur-x.xml'],
    'UTF-16LE' => ['<FFFE'.bin2hex(mb_convert_encoding('factur-x.xml', 'UTF-16LE', 'UTF-8')).'>', 'factur-x.xml'],
    'path' => ['(..\\\\..\\\\factur-x.xml)', 'factur-x.xml'],
]);

it('reports a missing embedded file stream as PDF error', function () {
    (new PdfEmbeddedFileExtractor)->extractFile(pdfExtractorTestPdf(pdfExtractorTestObjects([
        5 => '<< /Type /Filespec /F (factur-x.xml) /EF << /F 99 0 R >> >>',
        6 => '<< >>',
    ])));
})->throws(PdfException::class, 'no stream');

it('rejects unsupported filters', function () {
    (new PdfEmbeddedFileExtractor)->extractFile(pdfExtractorTestPdf(pdfExtractorTestObjects([
        6 => "<< /Filter /ASCIIHexDecode /Length 5 >>\nstream\n3C3E>\nendstream",
    ])));
})->throws(PdfException::class, 'Unsupported stream filter');

it('rejects encrypted PDFs', function (string $mode) {
    (new PdfEmbeddedFileExtractor)->extractFile(pdfExtractorTestPdf(pdfExtractorTestObjects([7 => '<< /Filter /Standard >>']), $mode, '/Encrypt 7 0 R'));
})->with(['classic', 'stream'])->throws(PdfException::class, 'encrypted');

it('detects loops of indirect references', function () {
    (new PdfEmbeddedFileExtractor)->extractFile(pdfExtractorTestPdf(pdfExtractorTestObjects([
        4 => '7 0 R',
        7 => '8 0 R',
        8 => '7 0 R',
    ])));
})->throws(PdfException::class, 'nested too deeply');

it('repairs cross-reference sections that form a loop', function () {
    $pdf = pdfExtractorTestPdf(pdfExtractorTestObjects());
    $xref = (int) substr($pdf, (int) strrpos($pdf, 'startxref') + 10);
    $pdf = str_replace('/Root 1 0 R', '/Root 1 0 R /Prev '.$xref, $pdf);

    $start = microtime(true);
    $file = (new PdfEmbeddedFileExtractor)->extractFile($pdf);

    expect($file?->xml)->toBe(PDF_EXTRACTOR_XML)
        ->and(microtime(true) - $start)->toBeLessThan(1.0);
});

it('repairs wrong offsets in the cross-reference table', function () {
    $pdf = pdfExtractorTestPdf(pdfExtractorTestObjects());
    $pdf = str_replace("%\xE2\xE3\xCF\xD3\n", "%\xE2\xE3\xCF\xD3\n% inserted comment that shifts all offsets\n", $pdf);

    expect((new PdfEmbeddedFileExtractor)->extractFile($pdf)?->xml)->toBe(PDF_EXTRACTOR_XML);
});

it('fails with a PDF error when the document catalog cannot be found', function () {
    $pdf = pdfExtractorTestPdf(pdfExtractorTestObjects([1 => '<< /Type /NotACatalog >>']));
    $pdf = str_replace('/Root 1 0 R', '/Root 99 0 R', $pdf);

    (new PdfEmbeddedFileExtractor)->extractFile($pdf);
})->throws(PdfException::class, 'catalog');

it('fails with a PDF error for truncated files', function () {
    $pdf = pdfExtractorTestPdf(pdfExtractorTestObjects());

    (new PdfEmbeddedFileExtractor)->extractFile(substr($pdf, 0, 30));
})->throws(PdfException::class);

it('bounds the PNG predictor to the data present and does not amplify tiny streams', function () {
    // A tiny Flate stream with maximal /Columns, /Colors and /BitsPerComponent must not be
    // expanded into a multi-megabyte row: the work stays proportional to the data present.
    $compressed = gzcompress("\x00".PDF_EXTRACTOR_XML);
    $stream = '<< /Type /EmbeddedFile /Subtype /text#2Fxml /Filter /FlateDecode '
        .'/DecodeParms << /Predictor 12 /Colors 32 /BitsPerComponent 16 /Columns 1000000 >> '
        .'/Length '.strlen($compressed)." >>\nstream\n".$compressed."\nendstream";

    $start = microtime(true);
    $file = (new PdfEmbeddedFileExtractor)->extractFile(pdfExtractorTestPdf(pdfExtractorTestObjects([6 => $stream])));

    expect($file?->xml)->toBe(PDF_EXTRACTOR_XML)
        ->and(microtime(true) - $start)->toBeLessThan(1.0);
});

it('uses the last definition of an object when reconstructing a damaged file', function () {
    $pdf = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n";
    foreach ([
        1 => '<< /Type /Catalog /Names << /EmbeddedFiles 4 0 R >> /AF [5 0 R] >>',
        4 => '<< /Names [(factur-x.xml) 5 0 R] >>',
        5 => '<< /Type /Filespec /F (factur-x.xml) /UF (factur-x.xml) /AFRelationship /Alternative /EF << /F 6 0 R /UF 6 0 R >> >>',
    ] as $number => $object) {
        $pdf .= "{$number} 0 obj\n{$object}\nendobj\n";
    }
    $pdf .= "6 0 obj\n".pdfExtractorTestStream('<invoice>stale</invoice>')."\nendobj\n";
    $pdf .= "6 0 obj\n".pdfExtractorTestStream(PDF_EXTRACTOR_XML)."\nendobj\n";
    $pdf .= "trailer << /Root 1 0 R >>\nstartxref\n999999999\n%%EOF\n";

    expect((new PdfEmbeddedFileExtractor)->extractFile($pdf)?->xml)->toBe(PDF_EXTRACTOR_XML);
});

it('reconstructs a damaged file with many objects and no trailer root', function () {
    $pdf = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n";
    for ($i = 100; $i < 3100; $i++) {
        $pdf .= "{$i} 0 obj\n<< /Decoy {$i} >>\nendobj\n";
    }
    foreach ([
        1 => '<< /Type /Catalog /Names << /EmbeddedFiles 4 0 R >> /AF [5 0 R] >>',
        4 => '<< /Names [(factur-x.xml) 5 0 R] >>',
        5 => '<< /Type /Filespec /F (factur-x.xml) /UF (factur-x.xml) /AFRelationship /Alternative /EF << /F 6 0 R /UF 6 0 R >> >>',
        6 => pdfExtractorTestStream(PDF_EXTRACTOR_XML),
    ] as $number => $object) {
        $pdf .= "{$number} 0 obj\n{$object}\nendobj\n";
    }
    $pdf .= "trailer << /Size 1 >>\nstartxref\n999999999\n%%EOF\n";

    $start = microtime(true);
    $file = (new PdfEmbeddedFileExtractor)->extractFile($pdf);

    expect($file?->xml)->toBe(PDF_EXTRACTOR_XML)
        ->and(microtime(true) - $start)->toBeLessThan(2.0);
});

it('refuses to reconstruct damaged files with more objects than an e-invoice can have', function () {
    $pdf = "%PDF-1.7\n";
    for ($i = 1; $i <= 100_001; $i++) {
        $pdf .= "{$i} 0 obj null endobj\n";
    }
    $pdf .= "trailer << /Size 1 >>\nstartxref\n999999999\n%%EOF\n";

    $before = memory_get_usage();
    expect(fn () => (new PdfEmbeddedFileExtractor)->extractFile($pdf))->toThrow(PdfException::class)
        ->and(memory_get_peak_usage() - $before)->toBeLessThan(128 * 1024 * 1024);
});
