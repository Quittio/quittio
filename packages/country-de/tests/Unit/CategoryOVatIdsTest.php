<?php

declare(strict_types=1);

use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Data\Party\Address;
use Quittio\Core\Enums\UnitCode;
use Quittio\Core\Quittio;
use Quittio\Core\Testing\Scenarios;
use Quittio\Country\DE\CountryDE;
use Quittio\Country\DE\Support\Flags;
use Quittio\Country\DE\Tax\VatPreset;

function transfer(?VatPreset $preset = null): InvoiceBuilder
{
    // Seller with VAT identifier and tax number, buyer with VAT identifier (Scenarios::base()).
    return Scenarios::base()->line(fn ($l) => $l->name('Betrieb Musterstraße 1 mit Inventar')->quantity(1, UnitCode::Piece)->netPrice('250000.00')->vat($preset ?? VatPreset::BusinessTransfer));
}

/**
 * @return list<string>
 */
function categoryOCodes(InvoiceBuilder $builder, array $config = []): array
{
    return array_values(array_unique(array_map(static fn ($m): string => $m->code, Quittio::create($config, [new CountryDE])->make($builder)->format('xrechnung-cii')->check()->messages)));
}

it('leaves out the VAT identifiers of an invoice not subject to VAT when the tax number is given (BR-O-02)', function (VatPreset $preset) {
    $result = Quittio::create([], [new CountryDE])->make(transfer($preset))->format('xrechnung-cii')->render();
    $data = $result->data();
    $info = array_values(array_filter($result->validation()->messages, static fn ($m): bool => $m->code === 'DE-CATEGORY-O-01'));

    expect($data->seller->vatId)->toBeNull()
        ->and($data->buyer->vatId)->toBeNull()
        ->and($data->seller->taxNumber)->toBe('12/345/67890')
        ->and($data->meta->hasFlag(Flags::VAT_IDS_OMITTED))->toBeTrue()
        ->and($result->xml())->not->toContain('DE123456789')
        ->and($result->xml())->not->toContain('DE987654321')
        ->and($result->validation()->has('BR-O-02'))->toBeFalse()
        ->and($info)->toHaveCount(1)
        ->and($info[0]->severity->value)->toBe('info')
        // The builder keeps what was given.
        ->and(transfer($preset)->build()->seller->vatId)->toBe('DE123456789');
})->with([
    'business transfer' => [VatPreset::BusinessTransfer],
    'not taxable in Germany' => [VatPreset::NotTaxableInGermany],
]);

it('keeps the VAT identifiers without tax number, with a tax representative or when switched off', function () {
    $withoutTaxNumber = transfer()->seller(fn ($s) => $s->taxNumber(null));
    $withRepresentative = transfer()->taxRepresentative('Vertreter GmbH', 'DE111111125', Address::make('Weg 1', '10115', 'Berlin', 'DE'));

    expect(categoryOCodes($withoutTaxNumber))->toContain('BR-O-02', 'DE-CATEGORY-O-02')
        ->and(categoryOCodes($withRepresentative))->toContain('BR-O-02', 'DE-CATEGORY-O-02')
        ->and(categoryOCodes(transfer(), ['countries' => ['de' => ['omit_vat_ids_for_category_o' => false]]]))->toContain('BR-O-02')
        ->and(categoryOCodes(transfer(), ['countries' => ['de' => ['omit_vat_ids_for_category_o' => false]]]))->not->toContain('DE-CATEGORY-O-01');
});

it('leaves invoices with other VAT categories alone', function () {
    $result = Quittio::create([], [new CountryDE])->make(Scenarios::make('simple'))->format('xrechnung-cii')->render();

    expect($result->data()->seller->vatId)->toBe('DE123456789')
        ->and($result->data()->meta->hasFlag(Flags::VAT_IDS_OMITTED))->toBeFalse();
});
