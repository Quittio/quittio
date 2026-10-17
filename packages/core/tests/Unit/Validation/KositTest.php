<?php

declare(strict_types=1);

use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Profiles\ProfileRegistry;
use Quittio\Core\Testing\Scenarios;
use Quittio\Core\Tests\Support\Official;
use Quittio\Core\Validation\Drivers\KositReportParser;
use Quittio\Core\Validation\Drivers\KositValidator;
use Quittio\Core\Validation\Severity;
use Quittio\Core\Validation\ValidationLevel;
use Quittio\Core\Xml\Cii\CiiWriter;
use Quittio\Core\Xml\Ubl\UblWriter;

function kositFixture(string $name): string
{
    return (string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/kosit/'.$name);
}

it('parses accepted reports with warnings', function () {
    $result = (new KositReportParser)->parse(kositFixture('accepted-with-warning.xml'));

    expect($result['accepted'])->toBeTrue()
        ->and($result['scenario'])->toBe('EN16931 XRechnung (CII)')
        ->and($result['messages'][0]->code)->toBe('CII-SR-475')
        // The Schematron flags CII-SR-475 as warning, the scenario lowers it to information.
        ->and($result['messages'][0]->severity)->toBe(Severity::Info)
        ->and($result['messages'][0]->level)->toBe(ValidationLevel::L5)
        ->and($result['messages'][0]->message)->toStartWith('Only one AdditionalReferencedDocument')
        ->and($result['messages'][0]->xpath)->not->toBeNull();
});

it('takes the level of a rule from the custom levels of the scenario', function (string $level, Severity $severity) {
    $report = str_replace('<s:customLevel level="information">CII-SR-475</s:customLevel>', "<s:customLevel level=\"{$level}\">CII-SR-475</s:customLevel>", kositFixture('accepted-with-warning.xml'));
    $result = (new KositReportParser)->parse($report);

    expect($result['messages'][0]->code)->toBe('CII-SR-475')
        ->and($result['messages'][0]->severity)->toBe($severity);
})->with([['error', Severity::Error], ['warning', Severity::Warning], ['information', Severity::Info]]);

it('keeps the level of the Schematron for rules without a custom level', function () {
    $report = str_replace('<s:customLevel level="information">CII-SR-475</s:customLevel>', '', kositFixture('accepted-with-warning.xml'));

    expect((new KositReportParser)->parse($report)['messages'][0]->severity)->toBe(Severity::Warning);
});

it('parses rejected reports', function () {
    $result = (new KositReportParser)->parse(kositFixture('rejected.xml'));

    expect($result['accepted'])->toBeFalse()
        ->and(array_map(fn ($m) => $m->code, $result['messages']))->toContain('PEPPOL-EN16931-R010');
});

it('reports documents without a matching scenario', function () {
    $result = (new KositReportParser)->parse(kositFixture('no-scenario.xml'));

    expect(array_map(fn ($m) => $m->code, $result['messages']))->toContain('KOSIT-NO-SCENARIO');
});

it('posts documents to the validator daemon', function () {
    $requests = [];
    $driver = new KositValidator(url: 'http://kosit.test:8080', http: function (string $url, string $body, int $timeout) use (&$requests): array {
        $requests[] = $url;

        return ['status' => 406, 'body' => kositFixture('rejected.xml')];
    });

    $messages = $driver->validateXml('<x/>');

    expect($requests)->toBe(['http://kosit.test:8080/'])
        ->and(array_map(fn ($m) => $m->code, $messages))->toContain('PEPPOL-EN16931-R010')
        ->and($driver->lastReport())->toContain('rep:report');
});

it('reports an unreachable validator without throwing', function () {
    $driver = new KositValidator(http: fn (): array => ['status' => 0, 'body' => null]);

    $messages = $driver->validateXml('<x/>');

    expect($messages[0]->code)->toBe('KOSIT-UNAVAILABLE')
        ->and($messages[0]->severity)->toBe(Severity::Error);
});

it('passes the official validation for all EN 16931 scenarios', function (string $scenario, string $syntax) {
    $invoice = (new En16931Calculator)->calculate(Scenarios::make($scenario)->build());
    $profile = ProfileRegistry::withDefaults()->get($scenario === 'peppol' ? 'peppol' : 'en16931');
    $xml = $syntax === 'cii' ? (new CiiWriter)->serialize($invoice, $profile) : (new UblWriter)->serialize($invoice, $profile);

    $errors = array_filter(Official::kosit()->validateXml($xml), fn ($m) => $m->isError());

    expect(array_map(fn ($m) => $m->code.': '.$m->message, $errors))->toBe([]);
})->with(function () {
    foreach (Scenarios::names() as $name) {
        if ($name === 'sub-lines') {
            continue;
        }
        if ($name !== 'peppol') {
            yield "{$name} (CII)" => [$name, 'cii'];
        }
        yield "{$name} (UBL)" => [$name, 'ubl'];
    }
})->skip(fn () => ! Official::available(), 'KoSIT validator or Java not available (run tools/schemas/download.sh).')->group('official');
