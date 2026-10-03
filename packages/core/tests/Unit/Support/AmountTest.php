<?php

declare(strict_types=1);

use Brick\Math\BigDecimal;
use Quittio\Core\Support\Amount;
use Quittio\Core\Support\Decimal;

it('creates amounts with two decimals independent of the currency', function () {
    expect(Amount::format(Amount::of('12.5', 'EUR')))->toBe('12.50')
        ->and(Amount::format(Amount::of('100', 'JPY')))->toBe('100.00');
});

it('rejects more than two decimals for amounts', function () {
    Amount::of('1.005', 'EUR');
})->throws(InvalidArgumentException::class);

it('accepts trailing zeros beyond two decimals', function () {
    expect(Amount::format(Amount::of('1.5000', 'EUR')))->toBe('1.50');
});

it('rejects floats', function () {
    Decimal::of(1.5);
})->throws(InvalidArgumentException::class);

it('rounds half up, away from zero', function () {
    expect(Decimal::round(BigDecimal::of('2.345'), 2)->toString())->toBe('2.35')
        ->and(Decimal::round(BigDecimal::of('-2.345'), 2)->toString())->toBe('-2.35')
        ->and(Decimal::round(BigDecimal::of('2.344'), 2)->toString())->toBe('2.34');
});
