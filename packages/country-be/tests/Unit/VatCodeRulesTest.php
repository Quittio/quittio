<?php

declare(strict_types=1);

use Quittio\Core\Data\Meta;
use Quittio\Core\Enums\InvoiceTypeCode;
use Quittio\Core\Enums\UnitCode;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Profiles\Syntax;
use Quittio\Country\BE\Tax\VatPreset;
use Quittio\Country\BE\Tests\Support\Belgian;

it('accepts a complete Belgian invoice', function () {
    expect(Belgian::codes(Belgian::validate(Belgian::invoice())))->toBe([]);
});

it('requires the VAT number and the addresses', function () {
    $result = Belgian::validate(Belgian::invoice()
        ->seller(fn ($s) => $s->vatId(null)->address('Markt 1', '8000', null, 'BE'))
        ->buyer(fn ($b) => $b->address(null, '9000', 'Gent', 'BE')));

    expect(array_map(fn ($m) => [$m->code, $m->path], array_values(array_filter($result->messages, fn ($m) => str_starts_with($m->code, 'BE-VAT')))))->toBe([
        ['BE-VAT-01', 'seller.vatId'],
        ['BE-VAT-02', 'seller.address.city'],
        ['BE-VAT-02', 'buyer.address.line1'],
    ]);
});

it('warns about a Belgian business buyer without VAT number', function () {
    expect(Belgian::codes(Belgian::validate(Belgian::invoice()->buyer(fn ($b) => $b->vatId(null)))))->toBe(['BE-VAT-03'])
        ->and(Belgian::codes(Belgian::validate(Belgian::invoice()->buyer(fn ($b) => $b->vatId(null)->consumer()))))->toBe([]);
});

it('requires the reverse charge mention and exemption texts', function () {
    $own = Belgian::base()->line(fn ($l) => $l->name('Renovatie')->quantity(1, UnitCode::Piece)->netPrice('5000.00')->vat(VatCategory::ReverseCharge, '0', 'Zonder btw'));
    $preset = Belgian::base()->line(fn ($l) => $l->name('Renovatie')->quantity(1, UnitCode::Piece)->netPrice('5000.00')->vat(VatPreset::ReverseChargeDomestic));
    $codeOnly = Belgian::base()->buyer(fn ($b) => $b->name('Lille SARL')->vatId('FR40303265045')->address('Rue Nationale 1', '59000', 'Lille', 'FR'))
        ->line(fn ($l) => $l->name('Machine')->quantity(1, UnitCode::Piece)->netPrice('2000.00')->vat(VatCategory::IntraCommunitySupply, '0', null, 'VATEX-EU-IC'));

    expect(Belgian::codes(Belgian::validate($own)))->toBe(['BE-VAT-04'])
        ->and(Belgian::codes(Belgian::validate($preset)))->toBe([])
        ->and(Belgian::codes(Belgian::validate($codeOnly)))->toBe(['BE-VAT-05']);
});

it('forbids VAT under the small business scheme', function () {
    expect(Belgian::codes(Belgian::validate(Belgian::invoice()->flag(Meta::FLAG_SMALL_BUSINESS))))->toBe(['BE-VAT-06'])
        ->and(Belgian::codes(Belgian::validate(Belgian::base()->line(fn ($l) => $l->name('Grafisch ontwerp')->quantity(1, UnitCode::Piece)->netPrice('400.00')->vat(VatPreset::SmallBusiness)))))->toBe([]);
});

it('requires the self-billing mention, as a warning in XML', function () {
    $selfBilled = Belgian::invoice()->type(InvoiceTypeCode::SelfBilledInvoice);

    expect(Belgian::validate($selfBilled)->errors()[0]->code ?? null)->toBe('BE-VAT-07')
        ->and(Belgian::validate($selfBilled, syntax: Syntax::Ubl)->warnings()[0]->code ?? null)->toBe('BE-VAT-07')
        ->and(Belgian::codes(Belgian::validate($selfBilled->note('Zelffacturering'))))->toBe([]);
});

it('warns about invoices issued after the 15th of the following month', function (string $issueDate, array $codes) {
    expect(Belgian::codes(Belgian::validate(Belgian::invoice()->issueDate($issueDate))))->toBe($codes);
})->with([
    'on the 15th' => ['2026-10-15', []],
    'on the 16th' => ['2026-10-16', ['BE-VAT-08']],
]);

it('warns when a business in a language region invoices in another language', function (string $postcode, string $locale, array $codes) {
    $builder = Belgian::invoice()->locale($locale)->seller(fn ($s) => $s->address('Rue 1', $postcode, 'Stad', 'BE'));

    expect(Belgian::codes(Belgian::validate($builder, locale: $locale)))->toBe($codes);
})->with([
    'Flanders in Dutch' => ['8000', 'nl_BE', []],
    'Flanders in French' => ['8000', 'fr_BE', ['BE-LANG-01']],
    'Wallonia in French' => ['5000', 'fr_BE', []],
    'Wallonia in Dutch' => ['5000', 'nl', ['BE-LANG-01']],
    'Eupen in German' => ['4700', 'de_BE', []],
    'Brussels in English' => ['1000', 'en', []],
]);
