<?php

declare(strict_types=1);

use Quittio\Core\Support\Date;

it('parses ISO dates and format 102', function () {
    expect(Date::parse('2026-10-03')->toIsoString())->toBe('2026-10-03')
        ->and(Date::parse('20261003')->toIsoString())->toBe('2026-10-03')
        ->and(Date::parse('2026-10-03')->toFormat102())->toBe('20261003');
});

it('keeps the local calendar date of a date time in the issuer time zone', function () {
    // 23:30 UTC on 2 October is already 3 October in Berlin.
    $instant = new DateTimeImmutable('2026-10-02T23:30:00+00:00');

    expect(Date::parse($instant, 'Europe/Berlin')->toIsoString())->toBe('2026-10-03')
        ->and(Date::parse($instant)->toIsoString())->toBe('2026-10-02');
});

it('rejects invalid dates', function () {
    Date::of(2026, 2, 30);
})->throws(InvalidArgumentException::class);

it('compares and adds days', function () {
    $date = Date::parse('2026-12-25');

    expect($date->addDays(14)->toIsoString())->toBe('2027-01-08')
        ->and($date->isBefore(Date::parse('2026-12-26')))->toBeTrue()
        ->and($date->diffInDays(Date::parse('2027-01-08')))->toBe(14);
});
