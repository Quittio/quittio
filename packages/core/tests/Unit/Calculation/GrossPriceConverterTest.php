<?php

declare(strict_types=1);

use Brick\Math\BigDecimal;
use Quittio\Core\Calculation\CalculationException;
use Quittio\Core\Calculation\DeclaredAmounts;
use Quittio\Core\Calculation\GrossPriceConverter;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Tests\Support\Invoices;

/*
 * The gross price mode on its own: the rounding amount G − (N + T) it returns, the cents it
 * distributes over the lines and the data it rejects. En16931CalculatorTest covers the totals.
 */

/**
 * @param  list<array{0: int, 1: string}>  $lines  [quantity, price including 19 % VAT]
 */
function grossInvoice(array $lines): InvoiceData
{
    $builder = Invoices::base();
    foreach ($lines as [$quantity, $price]) {
        $builder->line(fn ($l) => $l->name('Item')->quantity($quantity)->priceInclVat($price)->vat(VatCategory::StandardRate, '19'));
    }

    return $builder->build();
}

/**
 * @return list<string>
 */
function grossNetPrices(array $converted): array
{
    return array_map(fn ($line) => (string) $line->price->netPrice, $converted['lines']);
}

it('returns G − (N + T) as rounding amount: 3 × 9.99', function () {
    // G = 29.97, N = round(29.97 / 1.19) = 25.18, T = round(25.18 × 19 / 100) = 4.78
    $converted = (new GrossPriceConverter)->convert(grossInvoice([[3, '9.99']]));

    expect((string) $converted['rounding'])->toBe('0.01')
        ->and((string) $converted['gross'])->toBe('29.97')
        ->and(grossNetPrices($converted))->toBe(['8.393']);
});

it('uses a declared net amount within 0.01 of its own', function (string $declared, string $rounding) {
    $nets = [DeclaredAmounts::breakdownKey(VatCategory::StandardRate, BigDecimal::of('19')) => BigDecimal::of($declared)];
    $converted = (new GrossPriceConverter)->convert(grossInvoice([[3, '9.99']]), $nets);

    expect((string) $converted['rounding'])->toBe($rounding);
})->with([
    // N = 25.17, T = round(25.17 × 0.19) = 4.78
    'one cent below' => ['25.17', '0.02'],
    // N = 25.19, T = round(25.19 × 0.19) = 4.79
    'one cent above' => ['25.19', '-0.01'],
    'two cents below: its own N' => ['25.16', '0.01'],
]);

it('gives a cent that two lines could take to the first one', function () {
    // G = 0.20, N = 0.17; each line 0.10 / 1.19 = 0.084 → 0.08, so one line needs another cent.
    $converted = (new GrossPriceConverter)->convert(grossInvoice([[1, '0.10'], [1, '0.10']]));

    expect(grossNetPrices($converted))->toBe(['0.09', '0.08']);
});

it('moves a line to zero only if no line can take the cent otherwise', function () {
    // G = −0.06, N = −0.05: every line is −0.01, and a cent would bring each of them to zero.
    $converted = (new GrossPriceConverter)->convert(grossInvoice(array_fill(0, 6, [-1, '0.01'])));

    expect(grossNetPrices($converted))->toBe(['0.00', '0.01', '0.01', '0.01', '0.01', '0.01'])
        ->and((string) $converted['gross'])->toBe('-0.06');
});

it('rejects allowances, charges and sub lines in gross price mode', function (Closure $change, string $message) {
    expect(fn () => (new GrossPriceConverter)->convert($change(grossInvoice([[1, '119.00']]))))->toThrow(CalculationException::class, $message);
})->with([
    'document allowance' => [fn (InvoiceData $i) => $i->with(['allowances' => Invoices::base()->line(fn ($l) => $l->name('A')->quantity(1)->netPrice('10')->vat(VatCategory::StandardRate, '19'))->allowance(VatCategory::StandardRate, '19', amount: '1.00', reason: 'Discount')->build()->allowances]), 'Document allowances and charges'],
    'document charge' => [fn (InvoiceData $i) => $i->with(['charges' => Invoices::base()->line(fn ($l) => $l->name('A')->quantity(1)->netPrice('10')->vat(VatCategory::StandardRate, '19'))->charge(VatCategory::StandardRate, '19', amount: '1.00', reason: 'Freight')->build()->charges]), 'Document allowances and charges'],
    'line allowance' => [fn (InvoiceData $i) => $i->with(['lines' => [$i->lines[0]->with(['allowances' => Invoices::base()->line(fn ($l) => $l->name('A')->quantity(1)->netPrice('10')->allowance('1.00', reason: 'Discount')->vat(VatCategory::StandardRate, '19'))->build()->lines[0]->allowances])]]), 'allowances, charges and sub lines'],
    'line charge' => [fn (InvoiceData $i) => $i->with(['lines' => [$i->lines[0]->with(['charges' => Invoices::base()->line(fn ($l) => $l->name('A')->quantity(1)->netPrice('10')->charge('1.00', reason: 'Freight')->vat(VatCategory::StandardRate, '19'))->build()->lines[0]->charges])]]), 'allowances, charges and sub lines'],
    'sub line' => [fn (InvoiceData $i) => $i->with(['lines' => [$i->lines[0]->with(['subLines' => [$i->lines[0]]])]]), 'allowances, charges and sub lines'],
]);

it('gives a cent to the line rounded down the most', function () {
    // 0.11 / 1.19 = 0.0924 → 0.09 and 0.10 / 1.19 = 0.0840 → 0.08; G = 0.21, N = 0.18
    $converted = (new GrossPriceConverter)->convert(grossInvoice([[1, '0.11'], [1, '0.10']]));

    expect(grossNetPrices($converted))->toBe(['0.09', '0.09']);
});
