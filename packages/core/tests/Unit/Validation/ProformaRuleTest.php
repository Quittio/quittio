<?php

declare(strict_types=1);

use Quittio\Core\Codes\RawCode;
use Quittio\Core\Enums\InvoiceTypeCode;
use Quittio\Core\Tests\Support\Invoices;
use Quittio\Core\Tests\Support\Validate;
use Quittio\Core\Validation\Severity;

it('rejects a proforma (type code 325) in the invoice pipeline', function (InvoiceTypeCode|RawCode|string $type) {
    $result = Validate::data(Invoices::simple()->type($type)->build());
    $finding = array_values(array_filter($result->messages, fn ($m) => $m->code === 'QUITTIO-PROFORMA'))[0] ?? null;

    expect($result->isValid())->toBeFalse()
        ->and($finding?->severity)->toBe(Severity::Error)
        ->and($finding?->bt)->toBe('BT-3');
})->with([
    'enum' => [InvoiceTypeCode::ProformaInvoice],
    'string' => ['325'],
]);

it('accepts commercial invoices', function () {
    expect(Validate::codes(Validate::data(Invoices::simple()->build())))->not->toContain('QUITTIO-PROFORMA');
});
