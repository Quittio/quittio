<?php

declare(strict_types=1);

use Quittio\Core\Data\Party\Address;
use Quittio\Core\Enums\UnitCode;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Tests\Support\Invoices;
use Quittio\Core\Tests\Support\Validate;

function destinationCodes(VatCategory $category, string $buyerCountry, ?string $deliverTo = null, ?string $postcode = '1000'): array
{
    $invoice = Invoices::base()
        ->buyer(fn ($b) => $b->name('Buyer')->vatId($buyerCountry === 'FR' ? 'FR12345678901' : null)->address('Station Road 5', $postcode, 'Town', $buyerCountry))
        ->deliverTo('Warehouse', $deliverTo === null ? null : Address::make('Dock 1', $postcode, 'Port', $deliverTo))
        ->line(fn ($l) => $l->name('Machine')->quantity(1, UnitCode::Piece)->netPrice('1000.00')
            ->vat($category, '0', $category === VatCategory::Export ? 'Export' : 'Intra-community supply'))
        ->build();

    $codes = Validate::codes(Validate::data($invoice));

    return array_values(array_filter($codes, static fn (string $c): bool => str_starts_with($c, 'QUITTIO-VAT-K-') || str_starts_with($c, 'QUITTIO-VAT-G-')));
}

it('checks the destination of intra-community supplies', function (string $buyer, ?string $deliverTo, ?string $postcode, array $expected) {
    expect(destinationCodes(VatCategory::IntraCommunitySupply, $buyer, $deliverTo, $postcode))->toBe($expected);
})->with([
    'France' => ['FR', 'FR', '75001', []],
    'Monaco' => ['FR', 'MC', '98000', []],
    'Northern Ireland goods' => ['FR', 'XI', null, []],
    'Belfast with GB code' => ['GB', 'GB', 'BT1 1AA', []],
    'domestic' => ['DE', 'DE', '10115', ['QUITTIO-VAT-K-DOMESTIC']],
    'buyer country as fallback' => ['DE', null, '10115', ['QUITTIO-VAT-K-DOMESTIC']],
    'Switzerland' => ['CH', 'CH', '8001', ['QUITTIO-VAT-K-OUTSIDE-EU']],
    'delivered to Büsingen' => ['FR', 'DE', '78266', ['QUITTIO-VAT-K-OUTSIDE-EU']],
]);

it('checks the destination of exports', function (string $buyer, ?string $deliverTo, ?string $postcode, array $expected) {
    expect(destinationCodes(VatCategory::Export, $buyer, $deliverTo, $postcode))->toBe($expected);
})->with([
    'Switzerland' => ['CH', 'CH', '8001', []],
    'United Kingdom' => ['GB', null, 'SW1A 1AA', []],
    'Isle of Man' => ['IM', null, 'IM1 1AA', []],
    'Heligoland' => ['DE', 'DE', '27498', []],
    'France' => ['FR', null, '75001', ['QUITTIO-VAT-G-INSIDE-EU']],
    'Northern Ireland goods' => ['GB', 'XI', null, ['QUITTIO-VAT-G-INSIDE-EU']],
    'domestic' => ['DE', 'DE', '10115', ['QUITTIO-VAT-G-INSIDE-EU']],
]);
