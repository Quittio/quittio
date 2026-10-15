<?php

declare(strict_types=1);

use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Tests\Support\DocumentedPaths;
use Quittio\Country\DE\Tests\Support\German;

/*
 * The documented paths against the fields of the official XRechnung test suite, which use far
 * more business terms than the sample invoices (tools/fixtures/download.sh).
 */

$files = German::testSuiteFiles();

it('finds every documented CII and UBL path in the XML written from the official test files', function () use ($files) {
    $missing = [];
    foreach ($files as [$file]) {
        $data = (new En16931Calculator)->calculate(German::reader()->readFile($file)->data);
        foreach (DocumentedPaths::missing($data, German::profiles()->get('extended'), German::profiles()->get('en16931')) as $field => $path) {
            $missing[$field][$path][] = basename($file);
        }
    }
    ksort($missing);

    expect(array_map(static fn (array $paths): array => array_map(static fn (array $found): string => $found[0].(count($found) > 1 ? ' (+'.(count($found) - 1).')' : ''), $paths), $missing))->toBe([]);
})->group('official')->skip($files === [], 'XRechnung test suite not downloaded (tools/fixtures/download.sh).');
