<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Quittio\Core\Support\Date;
use Quittio\Country\DE\Dunning\BaseRates;
use Quittio\Country\DE\Dunning\BundesbankBaseRates;
use Quittio\Country\DE\Laravel\GermanyServiceProvider;

beforeEach(function () {
    File::delete((string) config('quittio-de.base_rate_file'));
});

afterEach(function () {
    File::delete((string) config('quittio-de.base_rate_file'));
});

/**
 * @param  array<string, string>  $months  YYYY-MM => rate with decimal comma
 */
function fakeBundesbank(array $months): void
{
    $lines = ['"";BBIN1.M.DE.BBK.BBKBAS2.EUR.ME;BBIN1.M.DE.BBK.BBKBAS2.EUR.ME_FLAGS'];
    foreach ($months as $month => $rate) {
        $lines[] = $month.';'.$rate.';';
    }
    app()->instance(BundesbankBaseRates::class, new BundesbankBaseRates(fn (): string => implode("\n", $lines)));
}

it('registers the rates of the file and of the configuration', function () {
    $file = (string) config('quittio-de.base_rate_file');
    file_put_contents($file, json_encode(['2027-01-01' => '1.77', '2027-07-01' => '1.90']));
    config()->set('quittio-de.base_rates', ['2027-07-01' => '2.00']);

    GermanyServiceProvider::registerBaseRates(config());

    expect(BaseRates::table()->rateOn(Date::of(2027, 3, 1))->toString())->toBe('1.77')
        ->and(BaseRates::table()->rateOn(Date::of(2027, 8, 1))->toString())->toBe('2.00');
});

it('stores new base rates from the Bundesbank', function () {
    $file = (string) config('quittio-de.base_rate_file');
    fakeBundesbank(['2013-01' => '-0,13', '2026-07' => '1,52', '2027-01' => '1,77', '2027-02' => '1,77']);

    $this->artisan('quittio:base-rate:update')
        ->expectsOutputToContain('Latest base rate: 1.77 % from 2027-01-01')
        ->expectsOutputToContain('Plus 1 earlier rate(s)')
        ->assertSuccessful();

    expect(json_decode((string) file_get_contents($file), true))->toBe(['2013-01-01' => '-0.13', '2027-01-01' => '1.77'])
        ->and(BaseRates::table()->rateOn(Date::of(2027, 1, 15))->toString())->toBe('1.77');

    $this->artisan('quittio:base-rate:update')->expectsOutputToContain('No new rates')->assertSuccessful();
});

it('writes nothing in a dry run', function () {
    fakeBundesbank(['2027-01' => '1,77']);

    $this->artisan('quittio:base-rate:update', ['--dry-run' => true])->expectsOutputToContain('2027-01-01')->assertSuccessful();

    expect((string) config('quittio-de.base_rate_file'))->not->toBeFile();
});

it('reports differences to the shipped table and fetch errors', function () {
    fakeBundesbank(['2026-07' => '1,60']);
    $this->artisan('quittio:base-rate:update')->expectsOutputToContain('the Bundesbank value is used')->assertSuccessful();
    expect(json_decode((string) file_get_contents((string) config('quittio-de.base_rate_file')), true))->toBe(['2026-07-01' => '1.60']);

    app()->instance(BundesbankBaseRates::class, new BundesbankBaseRates(fn (): string => throw new RuntimeException('The Bundesbank statistics API could not be reached or returned an error.')));
    $this->artisan('quittio:base-rate:update')->expectsOutputToContain('could not be reached')->assertFailed();
});
