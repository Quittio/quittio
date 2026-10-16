<?php

declare(strict_types=1);

use Quittio\Core\Countries\CountryRegistry;
use Quittio\Core\Tax\CountryDefaultVat;
use Quittio\Country\DE\CountryDE;
use Quittio\Country\DE\Tax\VatPreset;

it('uses the standard and the small business preset of the seller country', function () {
    $default = new CountryDefaultVat(new CountryRegistry([new CountryDE]));

    expect($default('DE', false))->toBe(VatPreset::Standard)
        ->and($default('de', true))->toBe(VatPreset::SmallBusiness)
        ->and($default('AT', false))->toBeNull()
        ->and($default(null, false))->toBeNull();
});

it('prefers the configured preset, except for small businesses of a country with a small business preset', function () {
    $default = new CountryDefaultVat(new CountryRegistry([new CountryDE]), VatPreset::Reduced);

    expect($default('DE', false))->toBe(VatPreset::Reduced)
        ->and($default('AT', false))->toBe(VatPreset::Reduced)
        ->and($default('DE', true))->toBe(VatPreset::SmallBusiness)
        ->and($default('AT', true))->toBe(VatPreset::Reduced);
});

it('finds presets of the installed packs by key', function () {
    $countries = new CountryRegistry([new CountryDE]);

    expect($countries->taxPreset('de.reduced'))->toBe(VatPreset::Reduced)
        // The keys of earlier versions stay readable.
        ->and($countries->taxPreset('de.reduced_7'))->toBe(VatPreset::Reduced)
        ->and($countries->taxPreset('de.standard_19'))->toBe(VatPreset::Standard)
        ->and($countries->taxPreset('xx.unknown'))->toBeNull();
});
