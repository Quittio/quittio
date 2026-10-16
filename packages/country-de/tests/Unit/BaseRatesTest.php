<?php

declare(strict_types=1);

use Brick\Money\Money;
use Quittio\Core\Dunning\DefaultInterest as CoreDefaultInterest;
use Quittio\Core\Support\Date;
use Quittio\Country\DE\CountryDE;
use Quittio\Country\DE\Dunning\BaseRates;
use Quittio\Country\DE\Dunning\BaseRateTable;
use Quittio\Country\DE\Dunning\BundesbankBaseRates;
use Quittio\Country\DE\Dunning\DefaultInterest;

afterEach(fn () => BaseRates::reset());

/**
 * Excerpt of the CSV download of the Bundesbank (lang=de), with header lines and a month
 * that is not a change date.
 */
function bundesbankCsv(): string
{
    return implode("\n", [
        '"";BBIN1.M.DE.BBK.BBKBAS2.EUR.ME;BBIN1.M.DE.BBK.BBKBAS2.EUR.ME_FLAGS',
        '"";Basiszinssatz gemäß BGB / Stand am Monatsende / SU0115;',
        'Einheit;% p.a.;',
        '2013-01;-0,13;Bemerkung',
        '2013-02;-0,13;',
        '2026-07;1,52;Bemerkung',
        '2026-08;1,52;',
        '2027-01;1,77;Bemerkung',
    ]);
}

it('reads the January and July values of the Bundesbank download', function () {
    expect(BundesbankBaseRates::parse(bundesbankCsv()))->toBe([
        '2013-01-01' => '-0.13',
        '2026-07-01' => '1.52',
        '2027-01-01' => '1.77',
    ]);
});

it('fetches the series from the statistics API', function () {
    $requested = [];
    $bundesbank = new BundesbankBaseRates(function (string $url, int $timeout) use (&$requested): string {
        $requested[] = [$url, $timeout];

        return bundesbankCsv();
    }, 7);

    expect($bundesbank->fetch('2026-01'))->toHaveKey('2027-01-01', '1.77')
        ->and($requested)->toBe([['https://api.statistiken.bundesbank.de/rest/data/BBIN1/M.DE.BBK.BBKBAS2.EUR.ME?format=csv&lang=de&startPeriod=2026-01', 7]]);
});

it('fails when the Bundesbank returns nothing useful', function () {
    (new BundesbankBaseRates(fn (): string => 'Die Zeitreihe ist nicht vorhanden.'))->fetch();
})->throws(RuntimeException::class, 'no base rates');

it('extends the shipped table with registered rates for default interest', function () {
    expect(fn () => (new DefaultInterest)->calculate(Money::of('1000.00', 'EUR'), Date::of(2027, 1, 1), Date::of(2027, 2, 1)))
        ->toThrow(InvalidArgumentException::class, 'quittio:base-rate:update');

    BaseRates::register(['2027-01-01' => '1.77']);
    $charges = (new DefaultInterest)->calculate(Money::of('1000.00', 'EUR'), Date::of(2027, 1, 1), Date::of(2027, 2, 1));

    expect(BaseRates::table()->rateOn(Date::of(2027, 3, 1))->toString())->toBe('1.77')
        ->and(BaseRates::table()->validUntil()->toIsoString())->toBe('2027-07-01')
        ->and($charges)->toHaveCount(1)
        ->and($charges[0]->ratePercent->toString())->toBe('10.77')
        ->and((new BaseRateTable)->validUntil()->toIsoString())->toBe('2027-01-01');
});

it('accepts base rates only for 1 January and 1 July as decimal percentages', function (array $rates, string $message) {
    expect(fn () => BaseRates::register($rates))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'other date' => [['2027-03-01' => '1.00'], '1 January and 1 July'],
    'float' => [['2027-01-01' => 1.5], 'percentage'],
    'text' => [['2027-01-01' => 'eins'], 'percentage'],
]);

it('gives the generic default interest the base rates and the law of the pack', function () {
    $german = (new DefaultInterest)->calculate(Money::of('1000.00', 'EUR'), Date::of(2026, 5, 1), Date::of(2026, 8, 1));
    $generic = CoreDefaultInterest::forCountry(new CountryDE)->calculate(Money::of('1000.00', 'EUR'), Date::of(2026, 5, 1), Date::of(2026, 8, 1));

    expect(array_map(fn ($c) => [(string) $c->ratePercent, (string) $c->from, (string) $c->until, $c->dayCount], $generic))
        ->toBe(array_map(fn ($c) => [(string) $c->ratePercent, (string) $c->from, (string) $c->until, $c->dayCount], $german))
        ->and($generic[0]->ratePercent->toString())->toBe('10.27')
        ->and((new CountryDE)->referenceRates())->toBeInstanceOf(BaseRateTable::class);
});
