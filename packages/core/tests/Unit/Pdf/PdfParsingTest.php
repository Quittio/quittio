<?php

declare(strict_types=1);

use Quittio\Core\Pdf\Parsing\ArrayValue;
use Quittio\Core\Pdf\Parsing\BooleanValue;
use Quittio\Core\Pdf\Parsing\ByteString;
use Quittio\Core\Pdf\Parsing\Dictionary;
use Quittio\Core\Pdf\Parsing\Document;
use Quittio\Core\Pdf\Parsing\Lexer;
use Quittio\Core\Pdf\Parsing\Name;
use Quittio\Core\Pdf\Parsing\NullValue;
use Quittio\Core\Pdf\Parsing\NumberValue;
use Quittio\Core\Pdf\Parsing\Reference;
use Quittio\Core\Pdf\Parsing\Stream;
use Quittio\Core\Pdf\PdfException;
use Quittio\Core\Tests\Support\MiniPdf;

it('reads names with escaped characters', function () {
    expect((new Lexer('/text#2Fxml'))->readValue())->toEqual(new Name('text/xml'))
        ->and((new Lexer('/A#20B '))->readValue())->toEqual(new Name('A B'));
});

it('reads literal strings with escapes, octal codes and balanced parentheses', function (string $input, string $bytes) {
    $value = (new Lexer($input))->readValue();

    expect($value)->toBeInstanceOf(ByteString::class)
        ->and($value->bytes)->toBe($bytes);
})->with([
    'escapes' => ['(a\\nb\\tc\\\\d\\)e)', "a\nb\tc\\d)e"],
    'octal' => ['(\\101\\7\\0537)', "A\x07+7"],
    'nested parentheses' => ['(a (b) c)', 'a (b) c'],
    'line continuation' => ["(ab\\\ncd)", 'abcd'],
    'carriage return' => ["(a\r\nb)", "a\nb"],
]);

it('reads hexadecimal strings with white space and an odd number of digits', function () {
    expect((new Lexer('<48 65 6C6C 6F>'))->readValue())->toEqual(new ByteString('Hello'))
        ->and((new Lexer('<414>'))->readValue())->toEqual(new ByteString("A\x40"));
});

it('decodes text strings in UTF-16 and UTF-8 with byte order mark', function () {
    expect((new ByteString("\xFE\xFF\x00\xC4\x00r"))->text())->toBe('Är')
        ->and((new ByteString("\xFF\xFE\xC4\x00r\x00"))->text())->toBe('Är')
        ->and((new ByteString("\xEF\xBB\xBFÄr"))->text())->toBe('Är')
        ->and((new ByteString('plain'))->text())->toBe('plain');
});

it('reads numbers, references, booleans, null, arrays and dictionaries', function () {
    $value = (new Lexer('<< /Size 12 /Rate -1.5 /Root 1 0 R /Kids [2 0 R 3 0 R] /Open true /Empty null /Pair [1 2] >>'))->readValue();

    expect($value)->toBeInstanceOf(Dictionary::class)
        ->and($value->get('Size'))->toEqual(new NumberValue(12))
        ->and($value->get('Rate'))->toEqual(new NumberValue(-1.5))
        ->and($value->get('Root'))->toEqual(new Reference(1, 0))
        ->and($value->get('Kids'))->toEqual(new ArrayValue([new Reference(2, 0), new Reference(3, 0)]))
        ->and($value->get('Open'))->toEqual(new BooleanValue(true))
        ->and($value->get('Empty'))->toEqual(new NullValue)
        // Two integers without "R" stay numbers.
        ->and($value->get('Pair'))->toEqual(new ArrayValue([new NumberValue(1), new NumberValue(2)]));
});

it('refuses malformed values with a PDF exception', function (string $input, string $message) {
    expect(fn () => (new Lexer($input))->readValue())->toThrow(PdfException::class, $message);
})->with([
    'unterminated string' => ['(abc', 'unterminated string'],
    'unterminated hex string' => ['<4142', 'unterminated hexadecimal string'],
    'unterminated array' => ['[1 2', 'unterminated array'],
    'unterminated dictionary' => ['<< /A 1', 'unterminated dictionary'],
    'key that is no name' => ['<< 1 2 >>', 'dictionary key expected'],
    'unknown keyword' => ['foo', 'unexpected token'],
    'empty input' => ['   ', 'unexpected end of data'],
    'too deep' => [str_repeat('[', 200), 'nested too deeply'],
]);

it('resolves the catalog and indirect objects of a file with and without cross-reference table', function (bool $xref) {
    $document = Document::parse(MiniPdf::withEmbeddedFile('<x/>', xref: $xref), 1024);
    $catalog = $document->catalog();
    $pages = $document->get($catalog, 'Pages');

    expect($catalog->name('Type'))->toBe('Catalog')
        ->and($pages)->toBeInstanceOf(Dictionary::class)
        ->and($pages->get('Count'))->toEqual(new NumberValue(1))
        // Missing objects are null (ISO 32000-1, 7.3.10).
        ->and($document->resolve(new Reference(99)))->toEqual(new NullValue);
})->with(['cross-reference table' => [true], 'rebuilt index' => [false]]);

it('decodes Flate streams with PNG and TIFF predictors', function (string $parameters, string $encoded, string $decoded) {
    $pdf = MiniPdf::build([
        1 => '<< /Type /Catalog >>',
        2 => MiniPdf::stream($encoded, '/DecodeParms << '.$parameters.' >>', compress: true),
    ]);
    $document = Document::parse($pdf, 1024);
    $stream = $document->object(2);

    expect($stream)->toBeInstanceOf(Stream::class)
        ->and($document->streamData($stream))->toBe($decoded);
})->with([
    // Row 1 type 0 (none), row 2 type 2 (up): 1 1 1 + 1 2 3.
    'PNG up' => ['/Predictor 12 /Columns 3', "\x00\x01\x02\x03\x02\x01\x01\x01", "\x01\x02\x03\x02\x03\x04"],
    // Type 1 (sub): each byte adds the byte to its left.
    'PNG sub' => ['/Predictor 11 /Columns 3', "\x01\x01\x01\x01", "\x01\x02\x03"],
    'TIFF' => ['/Predictor 2 /Columns 3', "\x01\x01\x01\x05\x01\x01", "\x01\x02\x03\x05\x06\x07"],
]);

it('stops decoding at the decompression limit', function () {
    $document = Document::parse(MiniPdf::build([1 => '<< /Type /Catalog >>', 2 => MiniPdf::stream(str_repeat('A', 5000), compress: true)]), 1024);

    $document->streamData($document->object(2));
})->throws(PdfException::class, 'decompression limit');

it('refuses files without PDF header', function () {
    Document::parse('<?xml version="1.0"?><a/>', 1024);
})->throws(PdfException::class, 'no PDF header');
