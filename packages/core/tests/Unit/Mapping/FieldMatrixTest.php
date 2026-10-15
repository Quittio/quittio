<?php

declare(strict_types=1);

use Quittio\Core\Data\Extensions\ExtendedData;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Data\Party\Address;
use Quittio\Core\Data\Party\Contact;
use Quittio\Core\Mapping\FieldMatrix;
use Quittio\Core\Mapping\FieldReference;
use Quittio\Core\Profiles\ProfileLevel;

it('reads the business term, cardinality and paths of a field', function () {
    $number = FieldMatrix::fields(InvoiceData::class)['number'];

    expect($number->bt)->toBe('BT-1')
        ->and($number->cardinality)->toBe('1')
        ->and($number->isMandatory())->toBeTrue()
        ->and($number->cii)->toBe('rsm:ExchangedDocument/ram:ID')
        ->and($number->ubl)->toBe('cbc:ID')
        ->and(FieldMatrix::fields(InvoiceData::class)['taxCurrency']->isMandatory())->toBeFalse();
});

it('resolves the business terms of shared structures from their parent', function (string $class, string $property, string $parent, string $term) {
    expect(FieldMatrix::businessTerm($class, $property, $parent))->toBe($term);
})->with([
    'seller country' => [Address::class, 'countryCode', 'BG-5', 'BT-40'],
    'buyer country' => [Address::class, 'countryCode', 'BG-8', 'BT-55'],
    'deliver to city' => [Address::class, 'city', 'BG-15', 'BT-77'],
    'buyer contact email' => [Contact::class, 'email', 'BG-9', 'BT-58'],
]);

it('raises the level of a field to the level of its parent', function () {
    // The country code is MINIMUM in the seller address (BG-5, MINIMUM) and BASIC WL in the
    // buyer address (BG-8, BASIC WL).
    expect(FieldMatrix::level(Address::class, 'countryCode', ProfileLevel::Minimum))->toBe(ProfileLevel::Minimum)
        ->and(FieldMatrix::level(Address::class, 'countryCode', ProfileLevel::BasicWl))->toBe(ProfileLevel::BasicWl)
        ->and(FieldMatrix::level(ExtendedData::class, 'deliveryTermsCode'))->toBe(ProfileLevel::Extended)
        ->and(FieldMatrix::level(InvoiceData::class, 'number'))->toBe(ProfileLevel::Minimum);
});

it('lists every field with its business term, level and notes in the field reference', function () {
    $markdown = (new FieldReference)->markdown();

    expect($markdown)->toStartWith('# Field reference')
        ->toContain('| `number` | BT-1 | 1 | MINIMUM | `rsm:ExchangedDocument/ram:ID` | `cbc:ID` |')
        ->toContain('| `buyer.address.countryCode` | BT-55 |')
        ->toContain('EXTENDED only')
        ->toContain('PDF only')
        // Every table row has the seven columns.
        ->and(array_filter(explode("\n", $markdown), static fn (string $row): bool => str_starts_with($row, '| `') && substr_count(str_replace('\\|', '', $row), '|') !== 8))->toBe([]);
});
