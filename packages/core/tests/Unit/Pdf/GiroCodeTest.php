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

it('calculates the amount after every cash discount tier', function () {
    $invoice = (new En16931Calculator)->calculate(Invoices::simple()->paymentTerms(fn ($t) => $t->dueInDays(30)->cashDiscount(7, '3')->cashDiscount(14, '2'))->build());
    $generator = new EpcGiroCodeGenerator;
    [$first, $second] = $invoice->paymentTerms->discounts ?? [];

    expect($generator->discountedAmount($invoice, $first)?->toString())->toBe('1154.30')
        ->and($generator->discountedAmount($invoice, $second)?->toString())->toBe('1166.20')
        ->and($generator->amount($invoice, discounted: true)?->toString())->toBe('1154.30');
});

it('keeps the payload within 331 bytes for multi-byte texts', function () {
    $invoice = (new En16931Calculator)->calculate(Invoices::simple()
        ->payment(Quittio\Core\Enums\PaymentMeansCode::SepaCreditTransfer, iban: 'DE02120300000000202051', accountName: str_repeat('Ä', 80), remittanceInformation: str_repeat('Ü', 150))
        ->build());
    $payload = (string) (new EpcGiroCodeGenerator)->payload($invoice);
    $lines = explode("\n", $payload);

    expect(strlen($payload))->toBeLessThanOrEqual(EpcGiroCodeGenerator::MAX_BYTES)
        ->and(mb_check_encoding($payload, 'UTF-8'))->toBeTrue()
        ->and(mb_strlen($lines[5]))->toBeLessThanOrEqual(70)
        ->and($lines[10])->toStartWith('ÜÜÜ')
        ->and($payload)->not->toEndWith("\n");
});

it('keeps the EPC payload within 331 bytes, also for long multibyte names', function () {
    $invoice = (new En16931Calculator)->calculate(Invoices::simple()
        ->payment(Quittio\Core\Enums\PaymentMeansCode::SepaCreditTransfer, iban: 'DE02120300000000202051', bic: 'BYLADEM1001', accountName: str_repeat('😀', 80), remittanceInformation: str_repeat('Ä', 140))
        ->build());
    $payload = (string) (new EpcGiroCodeGenerator)->payload($invoice);

    expect(strlen($payload))->toBeLessThanOrEqual(EpcGiroCodeGenerator::MAX_BYTES)
        ->and(mb_check_encoding($payload, 'UTF-8'))->toBeTrue()
        ->and(explode("\n", $payload)[5])->not->toBe('');
});

it('falls back to the invoice number for an empty remittance and ends without separators', function () {
    $invoice = (new En16931Calculator)->calculate(Invoices::simple()
        ->payment(Quittio\Core\Enums\PaymentMeansCode::SepaCreditTransfer, iban: 'DE02120300000000202051', remittanceInformation: '')
        ->build());
    $payload = (string) (new EpcGiroCodeGenerator)->payload($invoice);

    expect($payload)->toEndWith("\nINV-2026-0001")
        ->and((new EpcGiroCodeGenerator)->payload($invoice->with(['number' => ''])))->not->toEndWith("\n");
});

it('creates no GiroCode for self-billed invoices', function () {
    $invoice = (new En16931Calculator)->calculate(Invoices::simple()->type(Quittio\Core\Enums\InvoiceTypeCode::SelfBilledInvoice)->build());

    expect((new EpcGiroCodeGenerator)->supports($invoice))->toBeFalse()
        ->and((new EpcGiroCodeGenerator)->payload($invoice))->toBeNull();
});

it('writes compressed QR code images', function () {
    expect(strlen(QrImage::png(str_repeat('A', 200))))->toBeLessThan(20_000);
});

it('names the seller as beneficiary when the account name is blank', function (?string $accountName) {
    $invoice = (new En16931Calculator)->calculate(Invoices::simple()
        ->payment(Quittio\Core\Enums\PaymentMeansCode::SepaCreditTransfer, iban: 'DE02120300000000202051', accountName: $accountName)
        ->build());

    expect(explode("\n", (string) (new EpcGiroCodeGenerator)->payload($invoice))[5])->toBe('Seller Ltd');
})->with(['empty' => [''], 'blank' => ['   '], 'missing' => [null]]);
