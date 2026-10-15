<?php

declare(strict_types=1);

use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Data\Lines\InvoiceLine;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Tests\Support\Invoices;

it('keeps line identifiers unique when lines are added after removeLine()', function () {
    $line = fn (string $name, string $price) => fn ($l) => $l->name($name)->quantity(1)->netPrice($price)->vat(VatCategory::StandardRate, '19');
    $original = (new En16931Calculator)->calculate(Invoices::base()->line($line('A', '10'))->line($line('B', '20'))->line($line('C', '30'))->build());

    $changed = InvoiceBuilder::from($original)->removeLine('1')->line($line('D', '40'))->build();

    expect(array_map(fn (InvoiceLine $l): string => $l->id, $changed->lines))->toBe(['2', '3', '4']);
});

it('numbers built lines by position as long as no identifier is taken', function () {
    $line = fn (string $name) => fn ($l) => $l->name($name)->quantity(1)->netPrice('10')->vat(VatCategory::StandardRate, '19');
    $invoice = Invoices::base()->line($line('A'))->line($line('B'))->line($line('C'))->build();

    expect(array_map(fn (InvoiceLine $l): string => $l->id, $invoice->lines))->toBe(['1', '2', '3']);
});

it('accepts the same amount notation in addPrepaid() as in prepaid()', function () {
    $invoice = (new En16931Calculator)->calculate(Invoices::simple()->prepaid(' 100.00 ')->addPrepaid(' 50 ')->build());

    expect((string) $invoice->totals?->prepaidAmount?->getAmount())->toBe('150.00');
});

it('recalculates a percentage allowance of a VAT group that had no lines when lines are added', function () {
    $calculator = new En16931Calculator;
    $draft = $calculator->calculate(Invoices::base()->allowance(VatCategory::StandardRate, '19', percent: '3', reason: 'Discount')->buildDraft());

    $invoice = $calculator->calculate(InvoiceBuilder::from($draft)
        ->line(fn ($l) => $l->name('A')->quantity(1)->netPrice('200.00')->vat(VatCategory::StandardRate, '19'))
        ->build());

    expect((string) $draft->allowances[0]->baseAmount?->getAmount())->toBe('0.00')
        ->and((string) $invoice->allowances[0]->amount?->getAmount())->toBe('6.00')
        ->and((string) $invoice->allowances[0]->baseAmount?->getAmount())->toBe('200.00');
});
