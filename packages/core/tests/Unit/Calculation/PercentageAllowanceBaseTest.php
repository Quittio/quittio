<?php

declare(strict_types=1);

use Brick\Math\BigDecimal;
use Quittio\Core\Calculation\CalculationException;
use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Data\AllowanceCharge\DocumentAllowance;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Support\Amount;
use Quittio\Core\Tests\Support\Invoices;

function percentageAllowance(string $rate, ?string $base = null): DocumentAllowance
{
    return new DocumentAllowance(
        amount: null,
        vatCategory: VatCategory::StandardRate,
        vatRate: BigDecimal::of($rate),
        baseAmount: $base === null ? null : Amount::of($base, 'EUR'),
        percent: BigDecimal::of('10'),
        reason: 'Discount',
    );
}

it('takes the line sum of the VAT group as base of a percentage allowance', function (): void {
    $invoice = Invoices::simple()->build();

    $calculated = (new En16931Calculator)->calculate($invoice->with(['allowances' => [percentageAllowance('19')]]));

    expect((string) $calculated->allowances[0]->baseAmount?->getAmount())->toBe('1000.00')
        ->and((string) $calculated->allowances[0]->amount?->getAmount())->toBe('100.00');
});

it('uses an explicit base amount for a VAT group without lines', function (): void {
    $invoice = Invoices::simple()->build();

    $calculated = (new En16931Calculator)->calculate($invoice->with(['allowances' => [percentageAllowance('7', '200.00')]]));

    expect((string) $calculated->allowances[0]->amount?->getAmount())->toBe('20.00');
});

it('refuses a percentage allowance without base amount and without lines of its VAT group', function (): void {
    $invoice = Invoices::simple()->build();

    (new En16931Calculator)->calculate($invoice->with(['allowances' => [percentageAllowance('7')]]));
})->throws(CalculationException::class, 'VAT category S and rate 7 %');

it('calculates a percentage allowance of a draft without lines with a zero base', function (): void {
    $draft = Invoices::base()->buildDraft();

    $calculated = (new En16931Calculator)->calculate($draft->with(['allowances' => [percentageAllowance('7')]]));

    expect((string) $calculated->allowances[0]->amount?->getAmount())->toBe('0.00');
});
