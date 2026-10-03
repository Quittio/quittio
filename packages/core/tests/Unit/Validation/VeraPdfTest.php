<?php

declare(strict_types=1);

use Quittio\Core\Quittio;
use Quittio\Core\Tests\Support\Invoices;
use Quittio\Core\Validation\Drivers\VeraPdfValidator;
use Quittio\Core\Validation\Severity;
use Quittio\Core\Validation\ValidationLevel;

function veraPdfReport(string $name): string
{
    return (string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/verapdf/'.$name.'.xml');
}

function dockerAvailable(): bool
{
    exec('docker image inspect verapdf/cli 2>&1', $output, $code);

    return $code === 0;
}

it('turns failed rules into L6 messages', function () {
    $messages = VeraPdfValidator::parse(veraPdfReport('non-compliant'));

    expect($messages)->not->toBeEmpty()
        ->and($messages[0]->code)->toStartWith('PDFA-6.')
        ->and($messages[0]->severity)->toBe(Severity::Error)
        ->and($messages[0]->hint)->toContain('ISO 19005-3');
});

it('reports nothing for compliant files', function () {
    expect(VeraPdfValidator::parse(veraPdfReport('compliant')))->toBe([]);
});

it('passes the PDF via stdin to the docker image', function () {
    $seen = [];
    $validator = new VeraPdfValidator(runner: function (array $command, string $input) use (&$seen): array {
        $seen = [$command, $input];

        return ['exitCode' => 0, 'stdout' => veraPdfReport('compliant'), 'stderr' => ''];
    });

    expect($validator->validatePdf('%PDF-1.7 test'))->toBe([])
        ->and($seen[0])->toBe(['docker', 'run', '--rm', '-i', 'verapdf/cli', '--flavour', '3b', '--format', 'mrr', '--maxfailuresdisplayed', '5'])
        ->and($seen[1])->toBe('%PDF-1.7 test');
});

it('reports an unavailable validator', function (bool $fail, Severity $severity) {
    $validator = new VeraPdfValidator(failOnUnavailable: $fail, runner: fn (): array => ['exitCode' => 127, 'stdout' => '', 'stderr' => 'not found']);
    $messages = $validator->validatePdf('%PDF');

    expect($messages)->toHaveCount(1)
        ->and($messages[0]->code)->toBe('VERAPDF-UNAVAILABLE')
        ->and($messages[0]->severity)->toBe($severity);
})->with([[true, Severity::Error], [false, Severity::Warning]]);

it('confirms PDF/A-3b for rendered invoices with veraPDF', function (string $format) {
    $quittio = Quittio::create(['validation' => ['verapdf' => ['enabled' => true]]]);
    $result = $quittio->make(Invoices::simple())->format($format)->render();

    expect($result->validation()->levels)->toContain(ValidationLevel::L6)
        ->and($result->validation()->byLevel(ValidationLevel::L6))->toBe([]);
})->with(['zugferd', 'pdf'])->group('official')->skip(fn () => ! dockerAvailable(), 'Docker image verapdf/cli is not available.');
