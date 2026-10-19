<?php

declare(strict_types=1);

use Brick\Math\BigDecimal;
use Quittio\Core\Calculation\LineCalculator;
use Quittio\Core\Data\Lines\InvoiceLine;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Tests\Support\Invoices;

/*
 * The line formula of LineCalculator: net price from gross price and discount, the unrounded
 * amount of PEPPOL-EN16931-R120 and the net price that reproduces a line amount.
 */

function calculatorLine(Closure $line): InvoiceLine
{
    return Invoices::base()->line(fn ($l) => $line($l->name('Item')->vat(VatCategory::StandardRate, '19')))->build()->lines[0];
}

it('derives the net price from the gross price and the discount', function (?string $discount, string $net) {
    $line = calculatorLine(fn ($l) => $l->quantity(2)->netPrice('1.00'));
    $line = $line->with(['price' => $line->price->with(['netPrice' => null, 'grossPrice' => BigDecimal::of('100.00'), 'discount' => $discount === null ? null : BigDecimal::of($discount)])]);
    $calculated = (new LineCalculator)->calculate($line, 'EUR');

    expect((string) $calculated->price->netPrice)->toBe($net);
})->with([
    'without discount' => [null, '100.00'],
    'with discount' => ['5.00', '95.00'],
]);

it('rounds a percentage line allowance to cents', function () {
    // 7.5 % of 10.05 = 0.75375
    $calculated = (new LineCalculator)->calculate(calculatorLine(fn ($l) => $l->quantity(1)->netPrice('10.05')->allowance(percent: '7.5', reason: 'Discount')), 'EUR');

    expect((string) $calculated->allowances[0]->amount?->getAmount())->toBe('0.75')
        ->and((string) $calculated->netAmount?->getAmount())->toBe('9.30');
});

it('has no exact amount without net price and counts allowances and charges without amount as zero', function () {
    $line = calculatorLine(fn ($l) => $l->quantity(2)->netPrice('10.00')->allowance(percent: '10', reason: 'Discount')->charge(percent: '5', reason: 'Freight'));
    $uncalculated = $line->with([
        'allowances' => [$line->allowances[0]->with(['amount' => null])],
        'charges' => [$line->charges[0]->with(['amount' => null])],
    ]);

    expect(LineCalculator::exactAmount($line->with(['price' => $line->price->with(['netPrice' => null])])))->toBeNull()
        ->and((string) LineCalculator::exactAmount($uncalculated))->toBe('20.00');
});

it('finds the net price that reproduces a line amount', function (string $amount, string $quantity, ?string $baseQuantity, string $price) {
    expect((string) LineCalculator::priceFor(BigDecimal::of($amount), BigDecimal::of($quantity), $baseQuantity === null ? null : BigDecimal::of($baseQuantity)))->toBe($price);
})->with([
    'two decimals' => ['10.00', '1', null, '10.00'],
    'more decimals' => ['25.18', '3', null, '8.393'],
    'per base quantity' => ['25.00', '1', '10', '250.00'],
    // No price of up to ten decimals reproduces the amount.
    'ten decimals at most' => ['0.01', '1000000000000', null, '0.0000000000'],
]);
