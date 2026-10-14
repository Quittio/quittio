<?php

declare(strict_types=1);

use Quittio\Core\Tests\Support\Invoices;
use Quittio\Core\Tests\Support\Validate;
use Quittio\Core\Validation\Plausibility\ViesVerifier;

function viesStub(string $body, ?int &$calls = null, ?array &$urls = null): ViesVerifier
{
    $calls = 0;
    $urls = [];

    return new ViesVerifier(http: function (string $url) use ($body, &$calls, &$urls): string {
        $calls++;
        $urls[] = $url;

        return $body;
    });
}

it('sends only EU and Northern Ireland identifiers to VIES', function (string $vatId, bool $sent) {
    $verifier = viesStub('{"isValid":true,"userError":"VALID"}', $calls);
    $result = $verifier->verify($vatId);

    expect($calls)->toBe($sent ? 1 : 0)
        ->and($result->supported)->toBe($sent)
        ->and($result->valid)->toBe($sent ? true : null);
})->with([
    ['DE123456789', true],
    ['EL123456789', true],
    ['XI123456789', true],
    ['GR123456789', false],
    ['GB980780684', false],
    ['CHE123456789MWST', false],
    ['NO123456789MVA', false],
]);

it('maps the VIES user error to a result', function (string $body, ?bool $valid) {
    expect(viesStub($body)->verify('DE123456789')->valid)->toBe($valid);
})->with([
    'valid' => ['{"isValid":true,"userError":"VALID","name":"Example"}', true],
    'invalid' => ['{"isValid":false,"userError":"INVALID"}', false],
    'invalid input' => ['{"isValid":false,"userError":"INVALID_INPUT"}', null],
    'member state unavailable' => ['{"isValid":false,"userError":"MS_UNAVAILABLE"}', null],
    'timeout' => ['{"isValid":false,"userError":"TIMEOUT"}', null],
    'service unavailable' => ['{"isValid":false,"userError":"SERVICE_UNAVAILABLE"}', null],
    'too many requests' => ['{"isValid":false,"userError":"MS_MAX_CONCURRENT_REQ"}', null],
    'error wrapper' => ['{"actionSucceed":false,"errorWrappers":[{"error":"MS_UNAVAILABLE"}]}', null],
    'no user error' => ['{"isValid":false}', false],
    'not an object' => ['"oops"', null],
    'invalid JSON' => ['<html>', null],
]);

it('does not cache answers without a result', function () {
    $verifier = viesStub('{"isValid":false,"userError":"MS_UNAVAILABLE"}', $calls);

    $first = $verifier->verify('DE123456789');
    $verifier->verify('DE123456789');

    expect($first->valid)->toBeNull()
        ->and($first->error)->toBe('MS_UNAVAILABLE')
        ->and($calls)->toBe(2);
});

it('caches VALID and INVALID answers', function () {
    $verifier = viesStub('{"isValid":false,"userError":"INVALID"}', $calls);

    $verifier->verify('DE123456789');
    $verifier->verify('DE 123 456 789');

    expect($calls)->toBe(1);
});

it('does not report foreign VAT identifiers as invalid or unavailable', function () {
    $invoice = Invoices::simple()->buyer(fn ($b) => $b->name('Buyer Ltd')->vatId('GB980780684')->address('1 High Street', 'SW1A 1AA', 'London', 'GB'))->build();
    $codes = Validate::codes(Validate::data($invoice, verifier: viesStub('{"isValid":true,"userError":"VALID"}', $calls, $urls)));

    expect($codes)->not->toContain('QUITTIO-VIES')
        ->and($codes)->not->toContain('QUITTIO-VIES-UNAVAILABLE')
        ->and($urls)->toHaveCount(1)
        ->and($urls[0])->toContain('/ms/DE/vat/123456789');
});

it('reports VIES errors as unavailable, not as invalid, and asks once per identifier', function () {
    $verifier = viesStub('{"isValid":false,"userError":"MS_UNAVAILABLE"}', $calls);
    $codes = Validate::codes(Validate::data(Invoices::simple()->build(), verifier: $verifier));

    expect($codes)->toContain('QUITTIO-VIES-UNAVAILABLE')
        ->and($codes)->not->toContain('QUITTIO-VIES')
        ->and($calls)->toBe(2); // seller and buyer, once each
});
