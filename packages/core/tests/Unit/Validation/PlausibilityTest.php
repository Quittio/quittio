<?php

declare(strict_types=1);

use Quittio\Core\Tests\Support\Invoices;
use Quittio\Core\Tests\Support\Validate;
use Quittio\Core\Validation\Plausibility\Iban;
use Quittio\Core\Validation\Plausibility\VatIdFormat;
use Quittio\Core\Validation\Plausibility\ViesVerifier;

it('validates IBAN check digits', function (string $iban, bool $valid) {
    expect(Iban::isValid($iban))->toBe($valid);
})->with([
    ['DE02120300000000202051', true],
    ['DE02 1203 0000 0000 2020 51', true],
    ['DE02120300000000202052', false],
    ['GB82WEST12345698765432', true],
    ['FR1420041010050500013M02606', true],
    ['XX', false],
]);

it('validates VAT identifier formats', function (string $vatId, ?bool $valid) {
    expect(VatIdFormat::isValid($vatId))->toBe($valid);
})->with([
    ['DE123456789', true],
    ['DE12345678', false],
    ['ATU12345678', true],
    ['NL123456789B01', true],
    ['FR12345678901', true],
    ['CHE123456789MWST', true],
    ['ZZ123', null],
]);

it('checks VAT identifiers via VIES without blocking on outages', function () {
    $valid = new ViesVerifier(http: fn (string $url): string => json_encode(['isValid' => ! str_contains($url, '987654321'), 'name' => 'Example']));
    $offline = new ViesVerifier(http: fn (): ?string => null);

    $codes = Validate::codes(Validate::data(Invoices::simple()->build(), verifier: $valid));
    $offlineCodes = Validate::codes(Validate::data(Invoices::simple()->build(), verifier: $offline));

    expect($codes)->toContain('QUITTIO-VIES')
        ->and($offlineCodes)->toContain('QUITTIO-VIES-UNAVAILABLE')
        ->and($offlineCodes)->not->toContain('QUITTIO-VIES');
});

it('caches VIES results', function () {
    $calls = 0;
    $verifier = new ViesVerifier(http: function () use (&$calls): string {
        $calls++;

        return '{"isValid":true}';
    });

    $verifier->verify('DE123456789');
    $verifier->verify('DE 123 456 789');

    expect($calls)->toBe(1);
});
