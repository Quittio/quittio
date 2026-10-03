<?php

declare(strict_types=1);

use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Enums\InvoiceTypeCode;
use Quittio\Core\Enums\UnitCode;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Exceptions\InvalidInvoiceData;
use Quittio\Core\Tests\Support\Invoices;

it('builds invoice data from the fluent API', function () {
    $invoice = Invoices::simple()->buyerReference('04011000-12345-03')->note('Thank you')->build();

    expect($invoice)->toBeInstanceOf(InvoiceData::class)
        ->and($invoice->number)->toBe('INV-2026-0001')
        ->and($invoice->issueDate->toIsoString())->toBe('2026-10-03')
        ->and($invoice->typeCode)->toBe(InvoiceTypeCode::CommercialInvoice)
        ->and($invoice->seller->vatId)->toBe('DE123456789')
        ->and($invoice->buyer->address->city)->toBe('Example Town')
        ->and($invoice->delivery?->actualDeliveryDate?->toIsoString())->toBe('2026-09-30')
        ->and($invoice->buyerReference)->toBe('04011000-12345-03')
        ->and($invoice->notes[0]->content)->toBe('Thank you')
        ->and($invoice->lines)->toHaveCount(1)
        ->and($invoice->lines[0]->id)->toBe('1')
        ->and($invoice->lines[0]->unit)->toBe(UnitCode::Hour)
        ->and($invoice->lines[0]->price->netPrice?->toString())->toBe('100.00')
        ->and($invoice->paymentInstructions?->creditTransfers[0]->accountId)->toBe('DE02120300000000202051')
        ->and($invoice->isCalculated())->toBeFalse();
});

it('numbers lines automatically and keeps explicit line ids', function () {
    $invoice = Invoices::base()
        ->line(fn ($l) => $l->name('A')->quantity(1)->netPrice('1')->vat(VatCategory::StandardRate, '19'))
        ->line(fn ($l) => $l->id('X-7')->name('B')->quantity(1)->netPrice('1')->vat(VatCategory::StandardRate, '19'))
        ->line(fn ($l) => $l->name('C')->quantity(1)->netPrice('1')->vat(VatCategory::StandardRate, '19'))
        ->build();

    expect(array_map(fn ($l) => $l->id, $invoice->lines))->toBe(['1', 'X-7', '3']);
});

it('requires lines, seller and buyer', function () {
    InvoiceBuilder::make()->build();
})->throws(InvalidInvoiceData::class);

it('derives the net price from list price and price discount', function () {
    $invoice = Invoices::base()
        ->line(fn ($l) => $l->name('A')->quantity(2)->listPrice('120.00', '20.00')->vat(VatCategory::StandardRate, '19'))
        ->build();

    expect($invoice->lines[0]->price->netPrice?->toString())->toBe('100.00')
        ->and($invoice->lines[0]->price->grossPrice?->toString())->toBe('120.00');
});

it('starts a builder from existing data', function () {
    $original = Invoices::simple()->build();
    $changed = InvoiceBuilder::from($original)->number('INV-2')->build();

    expect($changed->number)->toBe('INV-2')
        ->and($changed->lines)->toEqual($original->lines)
        ->and($changed->seller)->toEqual($original->seller);
});

it('supports macros', function () {
    InvoiceBuilder::macro('internalNote', fn (string $text) => $this->note('[internal] '.$text));

    $invoice = Invoices::simple()->internalNote('check')->build();

    expect($invoice->notes[0]->content)->toBe('[internal] check');
    InvoiceBuilder::flushMacros();
});
