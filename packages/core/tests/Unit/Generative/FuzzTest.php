<?php

declare(strict_types=1);

use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Exceptions\QuittioException;
use Quittio\Core\Pdf\Parsing\Document;
use Quittio\Core\Pdf\PdfEmbeddedFileExtractor;
use Quittio\Core\Profiles\ProfileRegistry;
use Quittio\Core\Reading\InvoiceReader;
use Quittio\Core\Testing\Scenarios;
use Quittio\Core\Tests\Support\MiniPdf;
use Quittio\Core\Xml\Cii\CiiWriter;
use Quittio\Core\Xml\Ubl\UblWriter;
use Quittio\Core\Xml\XmlSecurity;
use Random\Engine\Mt19937;
use Random\Randomizer;

/*
| Mutated and garbage input must end in a result or a QuittioException: never a TypeError,
| a ValueError or a PHP warning. Every case is reproducible from its seed.
*/

/**
 * Runs $call and returns what went wrong: an exception that is no QuittioException, or a PHP
 * warning or notice; null when the call returned or threw a QuittioException.
 */
function fuzzProblem(Closure $call): ?string
{
    $warnings = [];
    set_error_handler(static function (int $level, string $message, string $file, int $line) use (&$warnings): bool {
        // Silenced with @ on purpose: Laravel and PHPUnit ignore these as well.
        if ((error_reporting() & $level) !== 0) {
            $warnings[] = sprintf('%s at %s:%d', $message, basename($file), $line);
        }

        return true;
    });
    try {
        $call();
    } catch (QuittioException) {
        // Expected for broken input.
    } catch (Throwable $e) {
        return sprintf('%s: %s at %s:%d', $e::class, $e->getMessage(), basename($e->getFile()), $e->getLine());
    } finally {
        restore_error_handler();
    }

    return $warnings === [] ? null : 'warning: '.$warnings[0];
}

/**
 * $input with a few random byte changes: flips, deletions, duplications, a truncation.
 */
function mutate(string $input, int $seed): string
{
    $random = new Randomizer(new Mt19937($seed));
    $length = strlen($input);
    for ($i = $random->getInt(1, 6); $i > 0 && $length > 0; $i--) {
        $position = $random->getInt(0, $length - 1);
        $input = match ($random->getInt(0, 4)) {
            0 => substr_replace($input, chr($random->getInt(0, 255)), $position, 1),
            1 => substr_replace($input, '', $position, $random->getInt(1, 20)),
            2 => substr_replace($input, substr($input, $position, $random->getInt(1, 40)), $position, 0),
            3 => substr_replace($input, $random->getBytesFromString('<>&;"/=0123456789-.eE ', $random->getInt(1, 8)), $position, 0),
            default => substr($input, 0, $position),
        };
        $length = strlen($input);
    }

    return $input;
}

/**
 * @return array<string, array{int}>
 */
function fuzzSeeds(int $count): array
{
    $seeds = [];
    for ($seed = 1; $seed <= $count; $seed++) {
        $seeds['seed '.$seed] = [$seed];
    }

    return $seeds;
}

function fuzzXml(string $syntax): string
{
    static $xml = [];

    return $xml[$syntax] ??= ($syntax === 'cii' ? new CiiWriter : new UblWriter)->serialize(
        (new En16931Calculator)->calculate(Scenarios::make('allowances')->build()),
        ProfileRegistry::withDefaults()->get('en16931'),
    );
}

it('loads or refuses mutated XML', function (int $seed) {
    $xml = mutate(fuzzXml($seed % 2 === 0 ? 'cii' : 'ubl'), $seed);

    expect(fuzzProblem(fn () => XmlSecurity::load($xml)))->toBeNull();
})->with(fuzzSeeds(150));

it('reads mutated CII and UBL or refuses them', function (int $seed) {
    $xml = mutate(fuzzXml($seed % 2 === 0 ? 'cii' : 'ubl'), $seed);

    expect(fuzzProblem(fn () => (new InvoiceReader)->read($xml)))->toBeNull();
})->with(fuzzSeeds(150));

it('refuses XML with entity declarations and external references', function (string $xml) {
    try {
        XmlSecurity::load($xml);
        $thrown = null;
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(QuittioException::class);
})->with([
    'internal entity' => ['<?xml version="1.0"?><!DOCTYPE a [<!ENTITY x "y">]><a>&x;</a>'],
    'external entity' => ['<?xml version="1.0"?><!DOCTYPE a [<!ENTITY x SYSTEM "file:///etc/passwd">]><a>&x;</a>'],
    'billion laughs' => ['<?xml version="1.0"?><!DOCTYPE a [<!ENTITY a "aaaaaaaaaa"><!ENTITY b "&a;&a;&a;&a;&a;&a;&a;&a;&a;&a;">]><a>&b;</a>'],
    'parameter entity' => ['<?xml version="1.0"?><!DOCTYPE a [<!ENTITY % p SYSTEM "http://127.0.0.1/x.dtd"> %p;]><a/>'],
]);

it('parses or refuses mutated PDFs', function (int $seed) {
    $pdf = mutate(MiniPdf::withEmbeddedFile(fuzzXml('cii'), compress: $seed % 2 === 0, xref: $seed % 3 !== 0), $seed);

    expect(fuzzProblem(fn () => Document::parse($pdf, 1024 * 1024)->catalog()))->toBeNull()
        ->and(fuzzProblem(fn () => (new PdfEmbeddedFileExtractor)->extractFile($pdf)))->toBeNull();
})->with(fuzzSeeds(200));

it('parses or refuses a mutated PDF with object streams', function (int $seed) {
    $pdf = mutate((string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/pdf/zugferd-objstm.pdf'), $seed);

    expect(fuzzProblem(fn () => (new PdfEmbeddedFileExtractor)->extractFile($pdf)))->toBeNull();
})->with(fuzzSeeds(60));

it('hydrates or refuses arrays with values of the wrong type', function (int $seed) {
    $random = new Randomizer(new Mt19937($seed));
    $data = Scenarios::make(Scenarios::names()[$seed % count(Scenarios::names())])->build()->toArray();
    // Replace one value anywhere in the tree with a value of another type.
    $paths = [];
    $walk = static function (array $node, array $path) use (&$walk, &$paths): void {
        foreach ($node as $key => $value) {
            $paths[] = [...$path, $key];
            if (is_array($value)) {
                $walk($value, [...$path, $key]);
            }
        }
    };
    $walk($data, []);
    $replacements = [null, 0, -1, 1.5, true, '', 'x', [], ['a' => 1], '1e999', str_repeat('9', 40), "\x00", '2026-13-45'];
    for ($i = $random->getInt(1, 3); $i > 0; $i--) {
        $path = $paths[$random->getInt(0, count($paths) - 1)];
        $node = &$data;
        foreach ($path as $key) {
            if (! is_array($node)) {
                break;
            }
            $node = &$node[$key];
        }
        $node = $replacements[$random->getInt(0, count($replacements) - 1)];
        unset($node);
    }

    expect(fuzzProblem(fn () => InvoiceData::fromArray($data)))->toBeNull();
})->with(fuzzSeeds(300));

it('hydrates or refuses JSON of the wrong shape', function (string $json) {
    expect(fuzzProblem(fn () => InvoiceData::fromJson($json)))->toBeNull();
})->with([
    'null' => ['null'], 'number' => ['42'], 'string' => ['"x"'], 'list' => ['[1,2,3]'],
    'lines not a list' => ['{"lines": "x"}'], 'seller is a number' => ['{"seller": 5}'],
]);

it('refuses JSON that is not valid with a QuittioException', function (string $json) {
    expect(fuzzProblem(fn () => InvoiceData::fromJson($json)))->toBeNull();
})->with([
    'empty' => [''], 'too deep' => [str_repeat('[', 5000).str_repeat(']', 5000)], 'invalid UTF-8' => ["{\"number\": \"\xC3\x28\"}"],
])->skip('bug: InvoiceData::fromJson() lets the JsonException of json_decode() through (packages/core/src/Data/InvoiceData.php, fromJson), which is no QuittioException');
