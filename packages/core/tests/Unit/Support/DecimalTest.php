<?php

declare(strict_types=1);

use Brick\Math\Exception\NumberFormatException;
use Quittio\Core\Builder\LineBuilder;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Data\Meta;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Exceptions\HydrationException;
use Quittio\Core\Support\Decimal;
use Quittio\Core\Tests\Support\Invoices;

it('accepts plain decimal notation', function (string $value, string $expected): void {
    expect((string) Decimal::of($value))->toBe($expected);
})->with([
    ['19.99', '19.99'],
    [' 19.99 ', '19.99'],
    ['-0.5', '-0.5'],
    ['+3', '3'],
    ['.5', '0.5'],
    ['10.', '10'],
]);

it('rejects other notations without expanding exponents', function (string $value): void {
    $start = hrtime(true);

    expect(fn () => Decimal::of($value))->toThrow(NumberFormatException::class, 'plain notation')
        ->and(hrtime(true) - $start)->toBeLessThan(100_000_000);
})->with([
    'exponent' => ['1e999999999'],
    'negative exponent' => ['1E-999999999'],
    'thousands separator' => ['1,000.00'],
    'decimal comma' => ['19,99'],
    'fraction' => ['1/3'],
    'empty' => [''],
]);

it('rejects exponents in builder input', function (): void {
    Invoices::base()
        ->line(fn (LineBuilder $l): LineBuilder => $l->name('A')->quantity(1)->netPrice('1e999999999')->vat(VatCategory::StandardRate, '19'))
        ->build();
})->throws(NumberFormatException::class);

it('rejects exponents in JSON input', function (): void {
    $data = Invoices::simple()->build()->toArray();
    $data['lines'][0]['quantity'] = '1e999999999';

    InvoiceData::fromArray($data);
})->throws(HydrationException::class, 'plain notation');

it('ignores a declared VAT amount in exponent notation in the metadata', function (): void {
    $meta = new Meta(flags: ['declared_vat_in_tax_currency:1e999999999']);

    expect($meta->declaredVatInTaxCurrency())->toBeNull()
        ->and((new Meta(flags: ['declared_vat_in_tax_currency:12.34']))->declaredVatInTaxCurrency()?->toString())->toBe('12.34');
});
