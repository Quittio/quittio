<?php

declare(strict_types=1);

use Brick\Math\BigDecimal;
use Quittio\Core\Documents\InterestCharge;
use Quittio\Core\Support\Amount;
use Quittio\Core\Support\Date;

it('rejects an interest period that ends before it starts', function () {
    $charge = fn (string $from, string $until) => new InterestCharge(Amount::of('1000.00', 'EUR'), BigDecimal::of('10.27'), Date::parse($from), Date::parse($until));

    expect(fn () => $charge('2026-03-01', '2026-02-01'))->toThrow(InvalidArgumentException::class, 'must not end (2026-02-01) before it starts (2026-03-01)')
        ->and($charge('2026-03-01', '2026-03-01')->total()->getAmount()->toString())->toBe('0.00');
});

it('computes default interest on concrete examples (sections 187, 188 and 288 BGB)', function (string $from, string $until, string $rate, int $days, string $lastDay, string $expected) {
    $charge = new InterestCharge(Amount::of('1000.00', 'EUR'), BigDecimal::of($rate), Date::parse($from), Date::parse($until));

    expect($charge->days())->toBe($days)
        ->and($charge->lastDay()->toIsoString())->toBe($lastDay)
        ->and($charge->total()->getAmount()->toString())->toBe($expected);
})->with([
    // Due 15 Jan, default from 16 Jan, paid on 16 Feb: 31 days at 9 points + 1.27 % base rate.
    'one month' => ['2026-01-16', '2026-02-16', '10.27', 31, '2026-02-15', '8.72'],
    // 17 days of 2027 (365) and 14 days of 2028 (366).
    'turn of a leap year' => ['2027-12-15', '2028-01-15', '10', 31, '2028-01-14', '8.48'],
    '29 February' => ['2028-02-29', '2028-03-01', '10', 1, '2028-02-29', '0.27'],
]);
