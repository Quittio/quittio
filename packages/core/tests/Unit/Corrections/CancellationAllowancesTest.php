<?php

declare(strict_types=1);

use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Corrections\Cancellation;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Tests\Support\Invoices;

it('recalculates a percentage document allowance when a line is removed from a cancellation', function (string $strategy, string $allowance, string $net) {
    $calculator = new En16931Calculator;
    $original = $calculator->calculate(Invoices::base()
        ->line(fn ($l) => $l->name('A')->quantity(1)->netPrice('100')->vat(VatCategory::StandardRate, '19'))
        ->line(fn ($l) => $l->name('B')->quantity(1)->netPrice('300')->vat(VatCategory::StandardRate, '19'))
        ->allowance(VatCategory::StandardRate, '19', percent: '10', reason: 'Discount')
        ->build());

    $cancellation = $calculator->calculate(Cancellation::of($original, 'ST-1', $strategy)->removeLine('2')->build());

    expect((string) $cancellation->allowances[0]->amount?->getAmount())->toBe($allowance)
        ->and((string) $cancellation->totals?->netTotal->getAmount())->toBe($net);
})->with([
    'credit note' => ['credit_note', '10.00', '90.00'],
    'negative invoice' => ['negative_invoice', '-10.00', '-90.00'],
]);

it('keeps the allowances of an unchanged full cancellation', function () {
    $calculator = new En16931Calculator;
    $original = $calculator->calculate(Invoices::base()
        ->line(fn ($l) => $l->name('A')->quantity(3)->netPrice('33.33')->vat(VatCategory::StandardRate, '19'))
        ->allowance(VatCategory::StandardRate, '19', percent: '7.5', reason: 'Discount')
        ->build());

    $cancellation = $calculator->calculate(Cancellation::of($original, 'ST-1', 'negative_invoice')->build());

    expect((string) $cancellation->allowances[0]->amount?->getAmount())->toBe('-7.50')
        ->and((string) $cancellation->totals?->grossTotal->getAmount())->toBe((string) $original->totals?->grossTotal->getAmount()->negated());
});
