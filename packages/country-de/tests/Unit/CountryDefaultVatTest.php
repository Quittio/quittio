<?php

declare(strict_types=1);

use Quittio\Core\Countries\CountryRegistry;
use Quittio\Core\Tax\CountryDefaultVat;
use Quittio\Country\DE\CountryDE;
use Quittio\Country\DE\Tax\VatPreset;

it('uses the standard and the small business preset of the seller country', function () {
    $default = new CountryDefaultVat(new CountryRegistry([new CountryDE]));

    expect($default('DE', false))->toBe(VatPreset::Standard19)
        ->and($default('de', true))->toBe(VatPreset::SmallBusiness)
        ->and($default('AT', false))->toBeNull()
        ->and($default(null, false))->toBeNull();
});

it('prefers the configured preset, except for small businesses of a country with a small business preset', function () {
    $default = new CountryDefaultVat(new CountryRegistry([new CountryDE]), VatPreset::Reduced7);

    expect($default('DE', false))->toBe(VatPreset::Reduced7)
        ->and($default('AT', false))->toBe(VatPreset::Reduced7)
        ->and($default('DE', true))->toBe(VatPreset::SmallBusiness)
        ->and($default('AT', true))->toBe(VatPreset::Reduced7);
});

it('finds presets of the installed packs by key', function () {
    $countries = new CountryRegistry([new CountryDE]);

    expect($countries->taxPreset('de.reduced_7'))->toBe(VatPreset::Reduced7)
        ->and($countries->taxPreset('xx.unknown'))->toBeNull();
});
