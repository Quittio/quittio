<?php

declare(strict_types=1);

use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Enums\UnitCode;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Exceptions\HydrationException;
use Quittio\Core\Tests\Support\Invoices;

it('round-trips through arrays and JSON', function () {
    $invoice = (new En16931Calculator)->calculate(
        Invoices::simple()
            ->taxCurrency('EUR')
            ->line(fn ($l) => $l->name('Book')->quantity('3')->unit('XYZ')->netPrice('12.3456', '1')->vat(VatCategory::StandardRate, '7')->allowance(percent: '5', reason: 'Volume'))
            ->discountAmount('10.00', 'Loyalty')
            ->build()
    );

    $restored = InvoiceData::fromJson($invoice->toJson());

    expect($restored->toArray())->toBe($invoice->toArray())
        ->and($restored->lines[1]->unit->value)->toBe('XYZ');
});

it('omits default values in the normalised form', function () {
    $array = Invoices::simple()->build()->toArray();

    expect($array)->not->toHaveKey('notes')
        ->and($array)->not->toHaveKey('totals')
        ->and($array['lines'][0])->not->toHaveKey('subLines');
});

it('reports the path of invalid input', function () {
    $data = Invoices::simple()->build()->toArray();
    $data['lines'][0]['quantity'] = 1.5;

    InvoiceData::fromArray($data);
})->throws(HydrationException::class, 'lines[0].quantity');

it('rejects unknown fields', function () {
    $data = Invoices::simple()->build()->toArray();
    $data['seller']['nickname'] = 'x';

    InvoiceData::fromArray($data);
})->throws(HydrationException::class, 'seller.nickname');

it('hydrates codes that are not enum cases as raw codes', function () {
    $data = Invoices::simple()->build()->toArray();
    $data['lines'][0]['unit'] = 'KTM';

    $invoice = InvoiceData::fromArray($data);

    expect($invoice->lines[0]->unit)->toBeInstanceOf(Quittio\Core\Codes\RawCode::class)
        ->and($invoice->lines[0]->unit->value)->toBe('KTM')
        ->and(UnitCode::tryFrom('HUR'))->toBe(UnitCode::Hour);
});

it('creates copies with with()', function () {
    $invoice = Invoices::simple()->build();
    $copy = $invoice->with(['number' => 'X']);

    expect($copy->number)->toBe('X')
        ->and($invoice->number)->toBe('INV-2026-0001');
});
