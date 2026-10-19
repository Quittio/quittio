<?php

declare(strict_types=1);

use Brick\Money\Money;
use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Countries\ResolvedCountries;
use Quittio\Core\Dunning\DefaultInterest;
use Quittio\Core\Enums\UnitCode;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Formats\DefaultFormatResolver;
use Quittio\Core\Quittio;
use Quittio\Core\Support\Date;
use Quittio\Core\Tests\Support\Official;
use Quittio\Country\BE\CountryBE;
use Quittio\Country\BE\Dunning\ReferenceRateTable;
use Quittio\Country\BE\Support\EnterpriseNumber;
use Quittio\Country\BE\Support\LanguageRegion;
use Quittio\Country\BE\Tax\VatPreset;
use Quittio\Country\BE\Tests\Support\Belgian;

it('has the Belgian rates, with 12 % for accommodation from March 2026', function () {
    $rate = fn (VatPreset $preset, string $date) => (string) (new En16931Calculator)->calculate(Belgian::base()->deliveryDate($date)
        ->line(fn ($l) => $l->name('A')->quantity(1, UnitCode::Piece)->netPrice('100.00')->vat($preset))->build())->lines[0]->vat->rate;

    expect([(string) VatPreset::Standard->rate(), (string) VatPreset::Intermediate->rate(), (string) VatPreset::Reduced->rate()])->toBe(['21', '12', '6'])
        ->and($rate(VatPreset::Accommodation, '2026-02-28'))->toBe('6')
        ->and($rate(VatPreset::Accommodation, '2026-03-01'))->toBe('12')
        ->and(VatPreset::ZeroRated->category())->toBe(VatCategory::ZeroRated);
});

it('states the mentions in the languages of Belgium', function () {
    expect(VatPreset::ReverseChargeDomestic->exemptionReason())->toBe('Verlegging van heffing – KB nr. 1, art. 20')
        ->and(VatPreset::ReverseChargeDomestic->exemptionReason('fr'))->toBe('Autoliquidation – A.R. n° 1, art. 20')
        ->and(VatPreset::SmallBusiness->exemptionReason('fr_BE'))->toBe('Régime particulier de franchise des petites entreprises')
        ->and(VatPreset::exempt('artikel 44, § 2, 1° Wetboek btw')->exemptionReason())->toBe('Vrijgesteld van btw – artikel 44, § 2, 1° Wetboek btw');
});

it('checks and formats enterprise numbers', function () {
    expect(EnterpriseNumber::isValid('0477.472.701'))->toBeTrue()
        ->and(EnterpriseNumber::isValid('0477472702'))->toBeFalse()
        ->and(EnterpriseNumber::fromVatId('BE 0477 472 701'))->toBe('0477472701')
        ->and(EnterpriseNumber::fromVatId('DE123456789'))->toBeNull()
        ->and(EnterpriseNumber::format('477472701'))->toBe('0477.472.701');
});

it('knows the language region of a post code', function (string $postcode, ?string $region) {
    expect(LanguageRegion::of($postcode))->toBe($region);
})->with([
    ['1000', LanguageRegion::BILINGUAL], ['1300', LanguageRegion::FRENCH], ['1500', LanguageRegion::DUTCH],
    ['2000', LanguageRegion::DUTCH], ['4000', LanguageRegion::FRENCH], ['4700', LanguageRegion::GERMAN],
    ['4791', LanguageRegion::GERMAN], ['4800', LanguageRegion::FRENCH], ['9000', LanguageRegion::DUTCH], ['75001', null],
]);

it('sends domestic business invoices via Peppol', function () {
    $resolve = fn ($builder) => (new DefaultFormatResolver)->resolve($builder->build(), new ResolvedCountries(new CountryBE));

    expect($resolve(Belgian::invoice())->format)->toBe('peppol')
        ->and($resolve(Belgian::invoice()->buyer(fn ($b) => $b->consumer()))->format)->not->toBe('peppol')
        ->and($resolve(Belgian::base()->line(fn ($l) => $l->name('Arts')->quantity(1, UnitCode::Piece)->netPrice('100.00')->vat(VatPreset::exempt('artikel 44 Wetboek btw'))))->format)->not->toBe('peppol');
});

it('adds the enterprise numbers as Peppol endpoints', function () {
    $invoice = (new CountryBE)->complete(Belgian::invoice()->build(), []);

    expect($invoice->seller->electronicAddress?->value)->toBe('0477472701')
        ->and((string) $invoice->buyer->electronicAddress?->scheme->value)->toBe('0208')
        ->and((new CountryBE)->complete(Belgian::invoice()->build(), ['peppol_endpoints' => false])->seller->electronicAddress)->toBeNull();
});

it('renders a Peppol BIS invoice that passes the PHP rules', function () {
    $result = Quittio::create([], [new CountryBE])->make(Belgian::invoice()->buyerReference('PO-4711')->build())->render();

    expect($result->format())->toBe('peppol')
        ->and($result->xml())->toContain('<cbc:EndpointID schemeID="0208">0477472701</cbc:EndpointID>')
        ->and(array_map(fn ($m) => $m->code, $result->validation()->errors()))->toBe([]);
});

it('calculates default interest of the law of 2 August 2002', function () {
    $interest = DefaultInterest::forCountry(new CountryBE);
    $business = $interest->calculate(Money::of('1000.00', 'EUR'), Date::of(2025, 6, 1), Date::of(2025, 8, 1));

    expect(array_map(fn ($c) => (string) $c->ratePercent, $business))->toBe(['11.50', '10.50'])
        ->and((string) $interest->calculate(Money::of('1000.00', 'EUR'), Date::of(2026, 1, 1), Date::of(2026, 2, 1), consumer: true)[0]->ratePercent)->toBe('4.50')
        ->and((string) $interest->flatRate())->toBe('EUR 40.00')
        ->and((new ReferenceRateTable)->validUntil()->toIsoString())->toBe('2027-01-01');
});

it('keeps invoices for seven years', function () {
    expect((new CountryBE)->retention()->until(Date::of(2026, 10, 5))->toIsoString())->toBe('2033-12-31')
        ->and((new CountryBE)->correctionPolicy()->title('cancellation', 'nl'))->toBe('Creditnota');
});

it('passes the official Peppol rules with the Belgian presets', function (VatPreset $preset) {
    $result = Quittio::create([], [new CountryBE])->make(Belgian::base()->buyerReference('PO-4711')
        ->line(fn ($l) => $l->name('Dienst')->quantity(2, UnitCode::Hour)->netPrice('80.00')->vat($preset))->build())->render();
    $official = array_filter(Official::kosit()->validateXml((string) $result->xml()), fn ($m) => $m->isError());

    expect(array_map(fn ($m) => $m->code.' '.$m->message, $official))->toBe([]);
})->with([VatPreset::Standard, VatPreset::Reduced, VatPreset::ReverseChargeDomestic, VatPreset::SmallBusiness])
    ->group('official')->skip(fn () => ! Official::available() || ! is_file(Official::root().'/peppol/PEPPOL-EN16931-UBL.xsl'), 'KoSIT validator or Peppol artifacts are not available (run tools/schemas/download.sh).');
