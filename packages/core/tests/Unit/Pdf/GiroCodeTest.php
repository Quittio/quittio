<?php

declare(strict_types=1);

use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Pdf\Qr\EpcGiroCodeGenerator;
use Quittio\Core\Pdf\Qr\QrImage;
use Quittio\Core\Tests\Support\Invoices;

it('builds the EPC069-12 payload version 002', function () {
    $invoice = (new En16931Calculator)->calculate(Invoices::simple()->build());

    expect((new EpcGiroCodeGenerator)->payload($invoice))->toBe(implode("\n", [
        'BCD',
        '002',
        '1',
        'SCT',
        'BYLADEM1001',
        'Seller Ltd',
        'DE02120300000000202051',
        'EUR1190.00',
        '',
        '',
        'INV-2026-0001',
    ]));
});

it('uses the discounted amount on request', function () {
    $invoice = (new En16931Calculator)->calculate(Invoices::simple()->paymentTerms(fn ($t) => $t->dueInDays(30)->cashDiscount(10, '2'))->build());
    $generator = new EpcGiroCodeGenerator;

    expect($generator->amount($invoice)?->toString())->toBe('1190.00')
        ->and($generator->amount($invoice, discounted: true)?->toString())->toBe('1166.20')
        ->and($generator->payload($invoice, ['amount' => EpcGiroCodeGenerator::AMOUNT_DISCOUNTED]))->toContain("EUR1166.20\n");
});

it('supports only EUR credit transfers', function () {
    $calculator = new En16931Calculator;
    $generator = new EpcGiroCodeGenerator;

    expect($generator->supports($calculator->calculate(Invoices::simple()->currency('USD')->build())))->toBeFalse()
        ->and($generator->payload($calculator->calculate(Invoices::simple()->currency('USD')->build())))->toBeNull();
});

it('encodes the payload as PNG data URI', function () {
    expect(QrImage::dataUri("BCD\n002"))->toStartWith('data:image/png;base64,');
})->skip(! extension_loaded('gd'), 'GD is not available.');
