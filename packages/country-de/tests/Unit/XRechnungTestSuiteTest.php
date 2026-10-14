<?php

declare(strict_types=1);

use Quittio\Core\Profiles\Syntax;
use Quittio\Core\Tests\Support\RoundTrip;
use Quittio\Core\Validation\Drivers\PhpRulesDriver;
use Quittio\Core\Validation\ValidationContext;
use Quittio\Core\Validation\ValidationSubject;
use Quittio\Core\Xml\Cii\CiiWriter;
use Quittio\Core\Xml\Ubl\UblWriter;
use Quittio\Country\DE\CountryDE;
use Quittio\Country\DE\Tests\Support\German;

/*
 * Golden files: the official XRechnung test suite of the KoSIT (tools/fixtures/download.sh).
 */

$files = German::testSuiteFiles();
// PHPUnit refuses an empty data set even for skipped or excluded tests.
$cases = $files === [] ? ['test suite not downloaded' => ['']] : $files;

it('reads every official test file', function (string $file) {
    $result = German::reader()->readFile($file);

    expect($result->profile)->toBeIn(['xrechnung', 'xrechnung-extension'])
        ->and(array_filter($result->findings, fn ($f) => $f->isError()))->toBe([]);
})->with($cases)->group('official')->skip($files === [], 'XRechnung test suite not downloaded (tools/fixtures/download.sh).');

it('finds no rule violations in the official test files', function (string $file) {
    $result = German::reader()->readFile($file);
    $profile = German::profiles()->get($result->profile ?? 'xrechnung');
    $context = new ValidationContext($profile, [new CountryDE], 'en', null, ['syntax' => $result->syntax === 'cii' ? Syntax::Cii : Syntax::Ubl]);

    // The official files are technical samples of the CIUS; they do not have to be complete
    // invoices in the sense of the UStG (service date, "Gutschrift" label), so the
    // content rules DE-USTG-* are not part of this conformance check.
    $errors = array_filter(
        (new PhpRulesDriver)->validate(new ValidationSubject($result->data, $context))->errors(),
        fn ($e) => ! str_starts_with($e->code, 'DE-USTG-'),
    );

    expect(array_map(fn ($e) => $e->code.': '.$e->message.' ['.$e->path.']', array_values($errors)))->toBe([]);
})->with($cases)->group('official')->skip($files === [], 'XRechnung test suite not downloaded (tools/fixtures/download.sh).');

it('writes the official test files back without loss', function (string $file) {
    $reader = German::reader();
    $first = $reader->readFile($file);
    $profile = German::profiles()->get($first->profile ?? 'xrechnung');
    $writer = $first->syntax === 'cii' ? new CiiWriter : new UblWriter;

    $second = $reader->read($writer->serialize($first->data, $profile));

    expect(RoundTrip::comparable($second->data))->toEqual(RoundTrip::comparable($first->data))
        ->and(RoundTrip::numbers($second->data->paymentTerms?->toArray() ?? []))->toEqual(RoundTrip::numbers($first->data->paymentTerms?->toArray() ?? []));
})->with($cases)->group('official')->skip($files === [], 'XRechnung test suite not downloaded (tools/fixtures/download.sh).');

it('converts the official test files to schema-valid JSON and back', function (string $file) {
    $json = German::reader()->readFile($file)->data->toJson();

    expect(Quittio\Core\Data\InvoiceData::fromJson($json)->toJson())->toBe($json);
    if (Quittio\Core\Serialization\JsonSchemaValidator::available()) {
        expect((new Quittio\Core\Serialization\JsonSchemaValidator)->validate($json))->toBe([]);
    }
})->with($cases)->group('official')->skip($files === [], 'XRechnung test suite not downloaded (tools/fixtures/download.sh).');
