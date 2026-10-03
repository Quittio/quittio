<?php

declare(strict_types=1);

use Brick\Math\BigDecimal;
use Quittio\Core\Calculation\CalculationException;
use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Enums\UnitCode;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Support\Amount;
use Quittio\Core\Tests\Support\Invoices;

function calc(InvoiceData $invoice): InvoiceData
{
    return (new En16931Calculator)->calculate($invoice);
}

function amount(?Brick\Money\Money $money): ?string
{
    return $money === null ? null : Amount::format($money);
}

// Mandatory test cases from specification 7.11.

it('rounds on line level: 3 × 0.333', function () {
    $invoice = calc(Invoices::base()
        ->line(fn ($l) => $l->name('Item')->quantity(3)->netPrice('0.333')->vat(VatCategory::StandardRate, '19'))
        ->build());

    expect(amount($invoice->lines[0]->netAmount))->toBe('1.00')
        ->and(amount($invoice->totals?->vatTotal))->toBe('0.19')
        ->and(amount($invoice->totals?->amountDue))->toBe('1.19');
});

it('calculates VAT per group, not per line: 100 lines of 0.01', function () {
    $builder = Invoices::base();
    for ($i = 0; $i < 100; $i++) {
        $builder->line(fn ($l) => $l->name('Cent')->quantity(1)->netPrice('0.01')->vat(VatCategory::StandardRate, '19'));
    }
    $invoice = calc($builder->build());

    // Per line the VAT would be round2(0.0019) = 0.00, per group it is 0.19.
    expect(amount($invoice->totals?->lineNetSum))->toBe('1.00')
        ->and(amount($invoice->vatBreakdown[0]->taxAmount))->toBe('0.19')
        ->and(amount($invoice->totals?->grossTotal))->toBe('1.19');
});

it('splits a percentage discount over mixed VAT rates', function () {
    $invoice = calc(Invoices::base()
        ->line(fn ($l) => $l->name('A')->quantity(1)->netPrice('100.00')->vat(VatCategory::StandardRate, '19'))
        ->line(fn ($l) => $l->name('B')->quantity(1)->netPrice('50.00')->vat(VatCategory::StandardRate, '7'))
        ->line(fn ($l) => $l->name('C')->quantity(1)->netPrice('20.00')->vat(VatCategory::ZeroRated, '0'))
        ->discountPercent('10.00', 'Project discount')
        ->build());

    expect($invoice->allowances)->toHaveCount(3)
        ->and(array_map(fn ($a) => amount($a->amount), $invoice->allowances))->toBe(['10.00', '5.00', '2.00'])
        ->and(amount($invoice->totals?->allowanceSum))->toBe('17.00')
        ->and(amount($invoice->totals?->netTotal))->toBe('153.00')
        ->and(array_map(fn ($b) => amount($b->taxableAmount), $invoice->vatBreakdown))->toBe(['90.00', '45.00', '18.00'])
        ->and(array_map(fn ($b) => amount($b->taxAmount), $invoice->vatBreakdown))->toBe(['17.10', '3.15', '0.00'])
        ->and(amount($invoice->totals?->grossTotal))->toBe('173.25');
});

it('distributes rounding differences of a split discount exactly', function () {
    $invoice = calc(Invoices::base()
        ->line(fn ($l) => $l->name('A')->quantity(1)->netPrice('33.33')->vat(VatCategory::StandardRate, '19'))
        ->line(fn ($l) => $l->name('B')->quantity(1)->netPrice('33.33')->vat(VatCategory::StandardRate, '7'))
        ->line(fn ($l) => $l->name('C')->quantity(1)->netPrice('33.34')->vat(VatCategory::ZeroRated, '0'))
        ->discountAmount('10.00')
        ->build());

    $sum = BigDecimal::zero();
    foreach ($invoice->allowances as $allowance) {
        $sum = $sum->plus($allowance->amount?->getAmount() ?? BigDecimal::zero());
    }

    expect($sum->toScale(2)->toString())->toBe('10.00');
});

it('respects the price base quantity: 12.3456 per 100 pieces, quantity 250', function () {
    $invoice = calc(Invoices::base()
        ->line(fn ($l) => $l->name('Screws')->quantity(250, UnitCode::Piece)->netPrice('12.3456', '100')->vat(VatCategory::StandardRate, '19'))
        ->build());

    expect(amount($invoice->lines[0]->netAmount))->toBe('30.86');
});

it('combines percentage and absolute line allowances and charges', function () {
    $invoice = calc(Invoices::base()
        ->line(fn ($l) => $l->name('Item')->quantity(10)->netPrice('10.00')->vat(VatCategory::StandardRate, '19')
            ->allowance(percent: '10', reason: 'Volume')
            ->allowance(amount: '5.00', reason: 'Voucher')
            ->charge(amount: '2.50', reason: 'Packing'))
        ->build());

    $line = $invoice->lines[0];

    expect(amount($line->allowances[0]->amount))->toBe('10.00')
        ->and(amount($line->allowances[0]->baseAmount))->toBe('100.00')
        ->and(amount($line->netAmount))->toBe('87.50');
});

it('converts gross prices so that the customer pays exactly the gross amount: 3 × 9.99', function () {
    $invoice = calc(Invoices::base()
        ->line(fn ($l) => $l->name('Shirt')->quantity(3)->priceInclVat('9.99')->vat(VatCategory::StandardRate, '19'))
        ->build());

    expect(amount($invoice->lines[0]->netAmount))->toBe('25.18')
        ->and($invoice->lines[0]->price->netPrice?->toString())->toBe('8.393')
        ->and(amount($invoice->totals?->vatTotal))->toBe('4.78')
        ->and(amount($invoice->totals?->roundingAmount))->toBe('0.01')
        ->and(amount($invoice->totals?->amountDue))->toBe('29.97');
});

it('keeps gross totals for several lines and rates', function () {
    $invoice = calc(Invoices::base()
        ->line(fn ($l) => $l->name('A')->quantity(1)->priceInclVat('19.99')->vat(VatCategory::StandardRate, '19'))
        ->line(fn ($l) => $l->name('B')->quantity(2)->priceInclVat('4.99')->vat(VatCategory::StandardRate, '19'))
        ->line(fn ($l) => $l->name('C')->quantity(1)->priceInclVat('2.49')->vat(VatCategory::StandardRate, '7'))
        ->build());

    expect(amount($invoice->totals?->amountDue))->toBe('32.46');

    // The line formula must hold for every derived net price.
    foreach ($invoice->lines as $line) {
        $expected = Quittio\Core\Calculation\LineCalculator::baseAmount($line->quantity, $line->price->netPrice ?? BigDecimal::zero(), null);
        expect($expected->isEqualTo($line->netAmount?->getAmount() ?? BigDecimal::zero()))->toBeTrue();
    }
});

it('rejects mixing net and gross prices', function () {
    calc(Invoices::base()
        ->line(fn ($l) => $l->name('A')->quantity(1)->priceInclVat('19.99')->vat(VatCategory::StandardRate, '19'))
        ->line(fn ($l) => $l->name('B')->quantity(1)->netPrice('10')->vat(VatCategory::StandardRate, '19'))
        ->build());
})->throws(CalculationException::class);

it('calculates the VAT amount in the tax currency with a six decimal exchange rate', function () {
    $invoice = calc(Invoices::simple()
        ->currency('USD')
        ->taxCurrency('EUR', '0.912345', '2026-10-02')
        ->build());

    // VAT 190.00 USD × 0.912345 = 173.34555 → 173.35 EUR
    expect(amount($invoice->totals?->vatTotal))->toBe('190.00')
        ->and($invoice->totals?->vatTotalInTaxCurrency?->getCurrency()->getCurrencyCode())->toBe('EUR')
        ->and(amount($invoice->totals?->vatTotalInTaxCurrency))->toBe('173.35');
});

it('handles negative lines and a negative total', function () {
    $invoice = calc(Invoices::base()
        ->line(fn ($l) => $l->name('Return')->quantity(-2)->netPrice('50.00')->vat(VatCategory::StandardRate, '19'))
        ->line(fn ($l) => $l->name('Fee')->quantity(1)->netPrice('10.00')->vat(VatCategory::StandardRate, '19'))
        ->build());

    expect(amount($invoice->totals?->netTotal))->toBe('-90.00')
        ->and(amount($invoice->totals?->vatTotal))->toBe('-17.10')
        ->and(amount($invoice->totals?->amountDue))->toBe('-107.10');
});

it('subtracts prepaid amounts down to an amount due of zero', function () {
    $invoice = calc(Invoices::simple()->prepaid('1190.00')->build());

    expect(amount($invoice->totals?->grossTotal))->toBe('1190.00')
        ->and(amount($invoice->totals?->prepaidAmount))->toBe('1190.00')
        ->and(amount($invoice->totals?->amountDue))->toBe('0.00');
});

it('calculates percentage document charges from the VAT group base', function () {
    $invoice = calc(Invoices::simple()
        ->charge(VatCategory::StandardRate, '19', percent: '2.5', reason: 'Insurance')
        ->build());

    expect(amount($invoice->charges[0]->amount))->toBe('25.00')
        ->and(amount($invoice->charges[0]->baseAmount))->toBe('1000.00')
        ->and(amount($invoice->totals?->chargeSum))->toBe('25.00')
        ->and(amount($invoice->totals?->amountDue))->toBe('1219.75');
});

it('derives the due date from the payment terms', function () {
    $invoice = calc(Invoices::simple()->build());

    expect($invoice->dueDate?->toIsoString())->toBe('2026-10-17');
});

it('carries exemption reasons into the VAT breakdown and omits the rate for category O', function () {
    $invoice = calc(Invoices::base()
        ->line(fn ($l) => $l->name('Service abroad')->quantity(1)->netPrice('500')->vat(VatCategory::NotSubjectToVat, null, 'Not taxable', 'VATEX-EU-O'))
        ->build());

    expect($invoice->vatBreakdown[0]->rate)->toBeNull()
        ->and($invoice->vatBreakdown[0]->exemptionReason)->toBe('Not taxable')
        ->and($invoice->vatBreakdown[0]->exemptionReasonCode?->value)->toBe('VATEX-EU-O')
        ->and(amount($invoice->vatBreakdown[0]->taxAmount))->toBe('0.00');
});

it('sums sub lines into a parent line without own price', function () {
    $invoice = calc(Invoices::base()
        ->line(fn ($l) => $l->name('Bundle')->vat(VatCategory::StandardRate, '19')
            ->subLine(fn ($s) => $s->name('Part A')->quantity(2)->netPrice('10')->vat(VatCategory::StandardRate, '19'))
            ->subLine(fn ($s) => $s->name('Part B')->quantity(1)->netPrice('5.50')->vat(VatCategory::StandardRate, '19')))
        ->build());

    expect(amount($invoice->lines[0]->netAmount))->toBe('25.50')
        ->and($invoice->lines[0]->subLines[0]->id)->toBe('1.1')
        ->and(amount($invoice->totals?->lineNetSum))->toBe('25.50');
});
