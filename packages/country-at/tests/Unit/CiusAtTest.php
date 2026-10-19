<?php

declare(strict_types=1);

use Quittio\Core\Data\Party\Address;
use Quittio\Core\Enums\PaymentMeansCode;
use Quittio\Core\Enums\UnitCode;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Quittio;
use Quittio\Country\AT\CountryAT;
use Quittio\Country\AT\Profiles\CiusAtGov;
use Quittio\Country\AT\Tax\VatPreset;
use Quittio\Country\AT\Tests\Support\Austrian;

it('accepts a complete invoice in CIUS-AT-NAT and CIUS-AT-GOV', function () {
    $federal = Austrian::base()->buyerReference('4700000001')->purchaseOrderReference('4700000001')
        ->buyer(fn ($b) => $b->name('Bundesministerium für Finanzen')->vatId(null)->address('Johannesgasse 5', '1010', 'Wien', 'AT')->electronicAddress('b', '9915'))
        ->line(fn ($l) => $l->name('Softwareentwicklung')->quantity(12, UnitCode::Hour)->netPrice('100.00')->vat(VatPreset::Standard)->orderLineReference('10'));

    expect(Austrian::codes(Austrian::validate(Austrian::invoice(), 'cius-at-nat')))->toBe([])
        ->and(Austrian::codes(Austrian::validate($federal, 'cius-at-gov')))->toBe([]);
});

it('requires the address fields of every party in CIUS-AT-NAT', function () {
    $builder = Austrian::invoice()
        ->buyer(fn ($b) => $b->name('Dublin Trading Ltd')->vatId('IE6388047V')->address('1 Main Street', null, 'Dublin', 'IE'))
        ->deliverTo('Lager', new Address(countryCode: 'AT', postcode: '6020'));

    expect(array_map(fn ($m) => [$m->code, $m->bt], array_values(array_filter(Austrian::validate($builder, 'cius-at-nat')->messages, fn ($m) => str_starts_with($m->code, 'AT-NAT')))))->toBe([
        ['AT-NAT-01', 'BT-75'],
        ['AT-NAT-02', 'BT-77'],
        ['AT-NAT-03', 'BT-53'],
    ]);
});

it('requires a delivery date or invoicing period at document level in CIUS-AT-NAT', function () {
    $linePeriods = Austrian::base(null)->line(fn ($l) => $l->name('Wartung')->quantity(1, UnitCode::Month)->netPrice('500.00')->vat(VatPreset::Standard)->period('2026-09-01', '2026-09-30'));

    expect(Austrian::codes(Austrian::validate($linePeriods, 'cius-at-nat')))->toBe(['AT-NAT-04'])
        ->and(Austrian::codes(Austrian::validate($linePeriods)))->toBe([]);
});

it('requires the VAT rates of allowances, charges and lines except for category O in CIUS-AT-NAT', function () {
    $notTaxable = Austrian::base()->line(fn ($l) => $l->name('Leistung in Japan')->quantity(1, UnitCode::Piece)->netPrice('500.00')->vat(VatPreset::NotTaxableInAustria))
        ->allowance(VatCategory::NotSubjectToVat, null, amount: '10.00', reason: 'Rabatt');

    expect(Austrian::codes(Austrian::validate($notTaxable, 'cius-at-nat')))->not->toContain('AT-NAT-05', 'AT-NAT-08');
});

it('rejects external attachments in CIUS-AT-NAT', function () {
    $builder = Austrian::invoice()->attachment('LS-1', description: 'Lieferschein', externalUri: 'https://example.com/lieferschein.pdf');

    expect(Austrian::codes(Austrian::validate($builder, 'cius-at-nat')))->toBe(['AT-NAT-07']);
});

it('adds the rules of e-Rechnung.gv.at in CIUS-AT-GOV', function () {
    $builder = Austrian::base()->purchaseOrderReference('4700000001')
        ->seller(fn ($s) => $s->contact('Anna Huber', '+43 1 234567', null))
        ->payment(PaymentMeansCode::Cash)
        ->line(fn ($l) => $l->name('Softwareentwicklung')->quantity(12, UnitCode::Hour)->netPrice('100.00')->vat(VatPreset::Standard));

    expect(Austrian::codes(Austrian::validate($builder, 'cius-at-gov')))->toBe(['AT-GOV-01', 'AT-GOV-02', 'AT-GOV-03', 'AT-GOV-09'])
        ->and(Austrian::codes(Austrian::validate($builder, 'cius-at-nat')))->toBe([]);
});

it('writes and reads UBL after CIUS-AT-GOV', function () {
    $quittio = Quittio::create([], [new CountryAT]);
    $federal = Austrian::base()->buyerReference('4700000001')
        ->buyer(fn ($b) => $b->name('Bundesministerium für Finanzen')->vatId(null)->address('Johannesgasse 5', '1010', 'Wien', 'AT')->electronicAddress('b', '9915'))
        ->line(fn ($l) => $l->name('Softwareentwicklung')->quantity(12, UnitCode::Hour)->netPrice('100.00')->vat(VatPreset::Standard));
    $result = $quittio->make($federal->build())->render();
    $read = $quittio->read((string) $result->xml());

    expect($result->format())->toBe('ubl')
        ->and($result->profile()?->key())->toBe('cius-at-gov')
        ->and($result->xml())->toContain('<cbc:CustomizationID>'.CiusAtGov::SPECIFICATION_ID.'</cbc:CustomizationID>')
        ->and($result->validation()->errors())->toBe([])
        ->and($read->profile)->toBe('cius-at-gov');
});
