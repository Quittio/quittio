<?php

declare(strict_types=1);

use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Enums\InvoiceTypeCode;
use Quittio\Core\Enums\PaymentMeansCode;
use Quittio\Core\Enums\UnitCode;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Enums\VatExemptionReasonCode;
use Quittio\Core\Support\Amount;
use Quittio\Country\DE\CountryDE;
use Quittio\Country\DE\Tax\VatPreset;

it('builds and calculates the builder example', function () {
    $invoice = InvoiceBuilder::make()
        ->number('RE-2026-0042')
        ->issueDate('2026-10-03')
        ->type(InvoiceTypeCode::CommercialInvoice)
        ->currency('EUR')
        ->seller(fn ($s) => $s
            ->name('Muster GmbH')
            ->vatId('DE123456789')
            ->legalRegistration('HRB 12345')
            ->additionalLegalInfo('Amtsgericht Musterstadt · Geschäftsführer: Max Muster')
            ->address('Hauptstraße 1', '12345', 'Musterstadt', 'DE')
            ->contact('Max Muster', '+49 123 456789', 'rechnung@muster.de')
            ->electronicAddress('rechnung@muster.de', 'EM'))
        ->buyer(fn ($b) => $b
            ->name('Kunde AG')
            ->vatId('DE987654321')
            ->address('Bahnhofstraße 5', '54321', 'Beispielstadt', 'DE')
            ->electronicAddress('ap@kunde.de', 'EM'))
        ->deliveryDate('2026-09-30')
        ->line(fn ($l) => $l
            ->name('Webentwicklung')
            ->description('Umsetzung Kundenportal, Sprint 3')
            ->quantity(24, UnitCode::Hour)
            ->netPrice('95.00')
            ->vat(VatPreset::Standard19))
        ->line(fn ($l) => $l
            ->name('Hosting Oktober')
            ->quantity(1, UnitCode::Month)
            ->netPrice('29.00')
            ->vat(VatPreset::Standard19)
            ->period('2026-10-01', '2026-10-31'))
        ->paymentTerms(fn ($t) => $t->dueInDays(14)->cashDiscount(days: 7, percent: '2.00'))
        ->payment(PaymentMeansCode::SepaCreditTransfer, iban: 'DE02120300000000202051', bic: 'BYLADEM1001')
        ->build();

    $calculated = (new En16931Calculator)->calculate($invoice);

    expect(Amount::format($calculated->totals->lineNetSum))->toBe('2309.00')
        ->and(Amount::format($calculated->totals->vatTotal))->toBe('438.71')
        ->and(Amount::format($calculated->totals->amountDue))->toBe('2747.71')
        ->and($calculated->dueDate?->toIsoString())->toBe('2026-10-17')
        ->and($calculated->paymentTerms?->discounts[0]->percent->toString())->toBe('2.00')
        ->and($calculated->lines[0]->vat->presetKey)->toBe('de.standard_19');
});

it('maps presets to category, rate and VATEX code', function (VatPreset $preset, VatCategory $category, ?string $rate, ?VatExemptionReasonCode $code) {
    expect($preset->category())->toBe($category)
        ->and($preset->rate()?->toString())->toBe($rate)
        ->and($preset->exemptionReasonCode())->toBe($code);
})->with([
    [VatPreset::Standard19, VatCategory::StandardRate, '19', null],
    [VatPreset::Reduced7, VatCategory::StandardRate, '7', null],
    [VatPreset::SmallBusiness, VatCategory::Exempt, '0', null],
    [VatPreset::ReverseChargeDomestic, VatCategory::ReverseCharge, '0', VatExemptionReasonCode::ReverseCharge],
    [VatPreset::IntraCommunitySupply, VatCategory::IntraCommunitySupply, '0', VatExemptionReasonCode::IntraCommunitySupply],
    [VatPreset::Export, VatCategory::Export, '0', VatExemptionReasonCode::Export],
    [VatPreset::NotTaxableInGermany, VatCategory::NotSubjectToVat, null, VatExemptionReasonCode::NotSubjectToVat],
    [VatPreset::MarginArt, VatCategory::Exempt, '0', VatExemptionReasonCode::WorksOfArt],
    [VatPreset::ZeroRatedPv, VatCategory::ZeroRated, '0', null],
    [VatPreset::ReverseChargeEu, VatCategory::ReverseCharge, '0', VatExemptionReasonCode::ReverseCharge],
    [VatPreset::TriangularTransaction, VatCategory::ReverseCharge, '0', VatExemptionReasonCode::ReverseCharge],
    [VatPreset::MarginSecondHand, VatCategory::Exempt, '0', VatExemptionReasonCode::SecondHandGoods],
    [VatPreset::MarginAntiques, VatCategory::Exempt, '0', VatExemptionReasonCode::CollectorsItems],
    [VatPreset::TravelAgency, VatCategory::Exempt, '0', VatExemptionReasonCode::TravelAgents],
]);

it('provides German and English note texts for every exempt preset', function () {
    foreach (VatPreset::cases() as $preset) {
        if ($preset->category() === VatCategory::StandardRate || $preset->category() === VatCategory::ZeroRated) {
            expect($preset->exemptionReason())->toBeNull();

            continue;
        }

        expect($preset->exemptionReason('de'))->not->toBeNull()
            ->and($preset->exemptionReason('en'))->not->toBeNull()
            ->and($preset->exemptionReason('de'))->not->toBe($preset->exemptionReason('en'));
    }

    expect(VatPreset::SmallBusiness->exemptionReason())->toBe('Steuerfreie Leistung nach § 19 Abs. 1 UStG (Steuerbefreiung für Kleinunternehmer).');
});

it('creates exemption presets with a legal reference', function () {
    $preset = VatPreset::exempt('§ 4 Nr. 14 UStG');

    expect($preset->category())->toBe(VatCategory::Exempt)
        ->and($preset->key())->toBe('de.exempt')
        ->and($preset->exemptionReason('de'))->toBe('Steuerfrei nach § 4 Nr. 14 UStG')
        ->and($preset->exemptionReason('en'))->toBe('Exempt from VAT according to § 4 Nr. 14 UStG');
});

it('defaults the text of exemption presets to German like the enum presets', function () {
    $preset = VatPreset::exempt('§ 4 Nr. 14 UStG', 'VATEX-EU-132-1C');
    $line = fn ($l) => $l->name('Heilbehandlung')->quantity(1, UnitCode::Piece)->netPrice('90.00')->vat($preset);
    $withoutLocale = InvoiceBuilder::make()->number('RE-1')->issueDate('2026-10-03')->currency('EUR')
        ->seller(fn ($s) => $s->name('Praxis')->vatId('DE123456789')->address('Hauptstraße 1', '12345', 'Musterstadt', 'DE'))
        ->buyer(fn ($b) => $b->name('Kunde')->address('Weg 1', '54321', 'Beispielstadt', 'DE'))
        ->line($line)
        ->build();

    expect($preset->exemptionReason())->toBe('Steuerfrei nach § 4 Nr. 14 UStG')
        ->and(VatPreset::SmallBusiness->exemptionReason())->toBe(VatPreset::SmallBusiness->exemptionReason('de'))
        ->and($preset->exemptionReason('fr'))->toBe('Exempt from VAT according to § 4 Nr. 14 UStG')
        ->and($preset->exemptionReasonCode()?->value)->toBe('VATEX-EU-132-1C')
        ->and($withoutLocale->lines[0]->vat->exemptionReason)->toBe('Steuerfrei nach § 4 Nr. 14 UStG');
});

it('exposes German defaults and retention rules', function () {
    $pack = new CountryDE;

    expect($pack->code())->toBe('DE')
        ->and($pack->defaults()->timezone)->toBe('Europe/Berlin')
        ->and($pack->defaults()->letterLayout)->toBe('din5008b')
        ->and($pack->taxPresets()->has('de.small_business'))->toBeTrue()
        ->and($pack->retention()->until(Quittio\Core\Support\Date::parse('2026-10-03'))->toIsoString())->toBe('2034-12-31');
});
