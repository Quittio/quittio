<?php

declare(strict_types=1);

use Brick\Money\Money;
use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Countries\ResolvedCountries;
use Quittio\Core\Dunning\DefaultInterest as CoreDefaultInterest;
use Quittio\Core\Enums\UnitCode;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Exceptions\InvalidArgumentException;
use Quittio\Core\Formats\DefaultFormatResolver;
use Quittio\Core\Quittio;
use Quittio\Core\Support\Date;
use Quittio\Country\AT\CountryAT;
use Quittio\Country\AT\Dunning\BaseRateTable;
use Quittio\Country\AT\Dunning\DefaultInterest;
use Quittio\Country\AT\Tax\VatPreset;
use Quittio\Country\AT\Tests\Support\Austrian;
use Quittio\Country\AT\Texts\LegalTexts;

it('registers the pack with its profiles and defaults', function () {
    $quittio = Quittio::create([], [new CountryAT]);
    $defaults = (new CountryAT)->defaults();

    expect($quittio->countries()->get('at'))->toBeInstanceOf(CountryAT::class)
        ->and($quittio->profiles()->has('cius-at-nat'))->toBeTrue()
        ->and($quittio->profiles()->has('cius-at-gov'))->toBeTrue()
        ->and([$defaults->locale, $defaults->timezone, $defaults->vatPreset, $defaults->smallBusinessVatPreset])->toBe(['de', 'Europe/Vienna', 'at.standard', 'at.small_business'])
        ->and((new CountryAT)->taxPresets()->count())->toBe(count(VatPreset::cases()));
});

it('has the Austrian VAT rates', function (VatPreset $preset, VatCategory $category, ?string $rate) {
    expect($preset->category())->toBe($category)
        ->and($preset->rate() === null ? null : (string) $preset->rate())->toBe($rate);
})->with([
    'standard' => [VatPreset::Standard, VatCategory::StandardRate, '20'],
    'reduced' => [VatPreset::Reduced, VatCategory::StandardRate, '10'],
    'reduced 13 %' => [VatPreset::Reduced13, VatCategory::StandardRate, '13'],
    'small business' => [VatPreset::SmallBusiness, VatCategory::Exempt, '0'],
    'intra-community supply' => [VatPreset::IntraCommunitySupply, VatCategory::IntraCommunitySupply, '0'],
    'not taxable' => [VatPreset::NotTaxableInAustria, VatCategory::NotSubjectToVat, null],
]);

it('takes 4.9 % for basic foods supplied from 1 July 2026, 10 % before', function () {
    $line = fn (string $date) => (new En16931Calculator)->calculate(Austrian::base($date)
        ->line(fn ($l) => $l->name('Milch')->quantity(10, UnitCode::Litre)->netPrice('1.50')->vat(VatPreset::BasicFood))->build())->lines[0]->vat;

    expect((string) $line('2026-06-30')->rate)->toBe('10')
        ->and((string) $line('2026-07-01')->rate)->toBe('4.9')
        ->and($line('2026-07-01')->presetKey)->toBe('at.basic_food');
});

it('states the exemption reasons in German and English', function () {
    expect(VatPreset::SmallBusiness->exemptionReason())->toBe('Umsatzsteuerfrei aufgrund der Kleinunternehmerregelung (§ 6 Abs. 1 Z 27 UStG).')
        ->and(VatPreset::ReverseChargeEu->exemptionReason('en'))->toBe('Reverse charge: the recipient owes the VAT')
        ->and(VatPreset::Standard->exemptionReason())->toBeNull()
        ->and(VatPreset::exempt('§ 6 Abs. 1 Z 19 UStG')->exemptionReason())->toBe('Steuerfrei gemäß § 6 Abs. 1 Z 19 UStG')
        ->and(VatPreset::exempt('§ 6 Abs. 1 Z 19 UStG')->exemptionReason('en'))->toBe('VAT exempt under § 6 Abs. 1 Z 19 UStG')
        ->and(LegalTexts::text(LegalTexts::INTRA_COMMUNITY_SUPPLY))->toBe(VatPreset::IntraCommunitySupply->exemptionReason())
        ->and(LegalTexts::text(LegalTexts::SELF_BILLING, 'de_AT'))->toBe('Gutschrift');
});

it('calculates default interest of section 456 UGB and the fixed 4 % of consumers', function () {
    $interest = new DefaultInterest;
    $business = $interest->calculate(Money::of('1000.00', 'EUR'), Date::of(2024, 12, 1), Date::of(2025, 2, 1));
    $consumer = $interest->calculate(Money::of('1000.00', 'EUR'), Date::of(2024, 12, 1), Date::of(2025, 2, 1), consumer: true);

    expect(array_map(fn ($c) => (string) $c->ratePercent, $business))->toBe(['13.08', '11.73'])
        ->and($business[0]->description)->toBe('Verzugszinsen 13,08 % p. a. (Basiszinssatz 3,88 % + 9,20 Prozentpunkte, § 456 UGB) vom 01.12.2024 bis 31.12.2024')
        ->and(array_map(fn ($c) => (string) $c->ratePercent, $consumer))->toBe(['4.00'])
        ->and($consumer[0]->description)->toBe('Verzugszinsen 4,00 % p. a. (§ 1000 Abs. 1 ABGB) vom 01.12.2024 bis 31.01.2025')
        ->and((string) $interest->flatRate())->toBe('EUR 40.00')
        ->and(CoreDefaultInterest::forCountry(new CountryAT)->calculate(Money::of('1000.00', 'EUR'), Date::of(2026, 1, 1), Date::of(2026, 2, 1))[0]->ratePercent->toString())->toBe('10.73');
});

it('knows the base rates of the OeNB up to the current half-year', function () {
    $table = new BaseRateTable;

    expect((string) $table->rateOn(Date::of(2020, 5, 1)))->toBe('-0.62')
        ->and((string) $table->rateOn(Date::of(2026, 12, 31)))->toBe('1.53')
        ->and(fn () => $table->rateOn(Date::of(2027, 1, 1)))->toThrow(InvalidArgumentException::class)
        ->and((string) $table->withRate('2027-01-01', '1.78')->rateOn(Date::of(2027, 3, 1)))->toBe('1.78');
});

it('keeps invoices for seven years and has Austrian titles', function () {
    $pack = new CountryAT;

    expect($pack->retention()->until(Date::of(2026, 10, 5))->toIsoString())->toBe('2033-12-31')
        ->and($pack->correctionPolicy()->title('correction', 'de'))->toBe('Rechnungsberichtigung')
        ->and($pack->correctionPolicy()->title('self_billed', 'de'))->toBe('Gutschrift');
});

it('sends invoices to Austrian public buyers as UBL after CIUS-AT-GOV', function () {
    $resolve = fn ($builder) => (new DefaultFormatResolver)->resolve($builder->build(), new ResolvedCountries(new CountryAT));
    $public = $resolve(Austrian::invoice()->buyer(fn ($b) => $b->electronicAddress('b', '9915')));

    expect([$public->format, $public->profile])->toBe(['ubl', 'cius-at-gov'])
        ->and($resolve(Austrian::invoice())->format)->toBe('zugferd');
});

it('requires the UID of seller and buyer and the reverse charge note in the PDF', function () {
    $invoice = (new En16931Calculator)->calculate(Austrian::base()
        ->line(fn ($l) => $l->name('Bauleistung')->quantity(1, UnitCode::Piece)->netPrice('20000.00')->vat(VatPreset::ReverseChargeDomestic))->build());
    $fields = array_map(fn ($f) => $f->accessKey, (new CountryAT)->mandatoryOutput($invoice)->fields);

    expect($fields)->toContain('seller.vatId|taxRepresentative.vatId', 'buyer.vatId')
        ->and(implode(' ', $fields))->toContain('vatRows[0].label');
});
