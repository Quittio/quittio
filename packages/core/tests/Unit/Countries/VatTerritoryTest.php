<?php

declare(strict_types=1);

use Quittio\Core\Countries\VatTerritory;

it('lists the 27 EU member states with ISO codes', function () {
    expect(VatTerritory::EU_MEMBER_STATES)->toHaveCount(27)
        ->toContain('HR', 'GR', 'CY')
        ->not->toContain('GB', 'EL', 'XI', 'CH');
});

it('maps countries to the VAT territory they belong to', function (string $country, string $expected) {
    expect(VatTerritory::vatCountry($country))->toBe($expected);
})->with([
    ['MC', 'FR'],
    ['IM', 'GB'],
    ['EL', 'GR'],
    ['LI', 'CH'],
    ['xi', 'XI'],
    ['de', 'DE'],
]);

it('tells whether an address lies in the EU VAT territory', function (string $country, ?string $postcode, bool $goods, bool $expected) {
    expect(VatTerritory::isEuVatTerritory($country, $postcode, $goods))->toBe($expected);
})->with([
    'Germany' => ['DE', '10115', true, true],
    'Büsingen' => ['DE', '78266', true, false],
    'Büsingen with prefix' => ['DE', 'D-78266', true, false],
    'Heligoland' => ['DE', '27498', true, false],
    'Monaco as France' => ['MC', '98000', true, true],
    'Isle of Man as UK' => ['IM', 'IM1 1AA', true, false],
    'Northern Ireland goods' => ['XI', 'BT1 1AA', true, true],
    'Northern Ireland services' => ['XI', 'BT1 1AA', false, false],
    'United Kingdom' => ['GB', 'SW1A 1AA', true, false],
    'Belfast with GB code goods' => ['GB', 'BT1 1AA', true, true],
    'Belfast with GB code services' => ['GB', 'BT1 1AA', false, false],
    'Switzerland' => ['CH', '8001', true, false],
    'Canary Islands' => ['ES', '35001', true, false],
    'Madrid' => ['ES', '28001', true, true],
    'Ceuta' => ['ES', '51001', true, false],
    'Melilla' => ['ES', '52001', true, false],
    'Livigno' => ['IT', '23041', true, false],
    "Campione d'Italia" => ['IT', '22061', true, false],
    'Åland' => ['FI', '22100', true, false],
    'Helsinki' => ['FI', '00100', true, true],
    'Mount Athos' => ['GR', '630 86', true, false],
    'Réunion' => ['FR', '97400', true, false],
    'Paris' => ['FR', '75001', true, true],
    'Monaco postcode with FR' => ['FR', '98000', true, true],
    'New Caledonia' => ['FR', '98800', true, false],
    'no postcode' => ['DE', null, true, true],
]);

it('compares VAT territories', function () {
    expect(VatTerritory::sameVatTerritory('FR', '75001', 'MC', '98000'))->toBeTrue()
        ->and(VatTerritory::sameVatTerritory('DE', '10115', 'DE', '78266'))->toBeFalse()
        ->and(VatTerritory::sameVatTerritory('DE', '10115', 'DE', null))->toBeTrue()
        ->and(VatTerritory::sameVatTerritory('GB', null, 'XI', null))->toBeFalse()
        ->and(VatTerritory::sameVatTerritory('GB', 'BT1 1AA', 'XI', null))->toBeTrue()
        ->and(VatTerritory::sameVatTerritory('GB', 'BT1 1AA', 'GB', 'SW1A 1AA'))->toBeFalse();
});

it('reads the member state of a VAT identifier', function (string $vatId, ?string $expected) {
    expect(VatTerritory::memberStateOfVatId($vatId))->toBe($expected);
})->with([
    ['DE123456789', 'DE'],
    ['EL123456789', 'GR'],
    ['GR123456789', null],
    ['XI123456789', 'XI'],
    ['GB123456789', null],
    ['CHE123456789MWST', null],
]);
