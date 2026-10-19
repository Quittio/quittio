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
use Quittio\Core\Testing\Scenarios;
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

it('takes the standard and the reduced rate of the supply date', function (string $date, string $standard, string $reduced) {
    $rate = fn (VatPreset $preset): string => (string) Scenarios::base()->issueDate('2021-03-01')->deliveryDate($date)
        ->line(fn ($l) => $l->name('Leistung')->quantity(1, UnitCode::Piece)->netPrice('100.00')->vat($preset))
        ->build()->lines[0]->vat->rate;

    expect($rate(VatPreset::Standard))->toBe($standard)
        ->and($rate(VatPreset::Reduced))->toBe($reduced)
        // The deprecated cases follow the dates as well and keep their keys.
        ->and($rate(VatPreset::from('de.standard_19')))->toBe($standard)
        ->and($rate(VatPreset::from('de.reduced_7')))->toBe($reduced);
})->with([
    ['2020-06-30', '19', '7'],
    ['2020-07-01', '16', '5'],
    ['2020-12-31', '16', '5'],
    ['2021-01-01', '19', '7'],
    ['2006-12-31', '16', '7'],
]);

it('keeps the keys of the presets and of earlier versions', function () {
    $line = fn (VatPreset $preset) => Scenarios::base()->line(fn ($l) => $l->name('Leistung')->quantity(1, UnitCode::Piece)->netPrice('100.00')->vat($preset))->build()->lines[0]->vat;
    $presets = (new CountryDE)->taxPresets();

    expect($line(VatPreset::Standard)->presetKey)->toBe('de.standard')
        ->and($line(VatPreset::from('de.standard_19'))->presetKey)->toBe('de.standard_19')
        ->and(VatPreset::fromKey('de.reduced_7')?->canonical())->toBe(VatPreset::Reduced)
        ->and(VatPreset::fromKey('xx.unknown'))->toBeNull()
        ->and($presets->get('de.standard_19'))->toBe(VatPreset::Standard)
        ->and($presets->has('de.reduced'))->toBeTrue()
        ->and(array_keys(iterator_to_array($presets)))->not->toContain('de.standard_19', 'de.reduced_7')
        ->and((string) VatPreset::Standard->rate())->toBe('19')
        ->and(VatPreset::Standard->exemptionReason())->toBeNull();
});

it('adds the statement of the special schemes once as invoice note (mapping table of KoSIT and FeRD)', function (VatPreset $preset, string $text) {
    $builder = Scenarios::base()
        ->line(fn ($l) => $l->name('Artikel A')->quantity(1, UnitCode::Piece)->netPrice('100.00')->vat($preset))
        ->line(fn ($l) => $l->name('Artikel B')->quantity(1, UnitCode::Piece)->netPrice('50.00')->vat($preset));
    $invoice = (new En16931Calculator)->calculate($builder->build());
    $notes = array_values(array_filter($invoice->notes, static fn ($note): bool => $note->content === $text));
    $again = InvoiceBuilder::from($invoice)->line(fn ($l) => $l->name('Artikel C')->quantity(1, UnitCode::Piece)->netPrice('10.00')->vat($preset))->build();

    expect($notes)->toHaveCount(1)
        ->and($notes[0]->subjectCode?->value)->toBe('REG')
        ->and(array_filter($again->notes, static fn ($note): bool => $note->content === $text))->toHaveCount(1)
        // The PDF lists the text once although it is BT-120 and BT-22.
        ->and(array_filter(Quittio\Core\View\ViewModelFactory::legalNoteTexts($invoice), static fn (string $note): bool => $note === $text))->toHaveCount(1);
})->with([
    'second-hand goods' => [VatPreset::MarginSecondHand, 'Gebrauchtgegenstände/Sonderregelung'],
    'works of art' => [VatPreset::MarginArt, 'Kunstgegenstände/Sonderregelung'],
    'antiques' => [VatPreset::MarginAntiques, 'Sammlungsstücke und Antiquitäten/Sonderregelung'],
    'travel agencies' => [VatPreset::TravelAgency, 'Sonderregelung für Reisebüros'],
    'photovoltaic systems' => [VatPreset::ZeroRatedPv, 'Steuersatz 0 % nach § 12 Abs. 3 UStG.'],
]);

it('adds the note of the triangular transaction in the invoice language', function () {
    $invoice = Scenarios::base()->locale('en')
        ->buyer(fn ($b) => $b->name('Kunde GmbH')->vatId('ATU12345678')->address('Ring 1', '1010', 'Wien', 'AT'))
        ->line(fn ($l) => $l->name('Maschine')->quantity(1, UnitCode::Piece)->netPrice('5000.00')->vat(VatPreset::TriangularTransaction))
        ->build();

    expect($invoice->notes[array_key_last($invoice->notes)]->content)->toBe('Intra-Community triangular transaction (section 25b UStG); the last customer is liable for the VAT');
});

it('adds no invoice note for presets without special scheme', function () {
    $invoice = Scenarios::base()
        ->line(fn ($l) => $l->name('Beratung')->quantity(1, UnitCode::Hour)->netPrice('100.00')->vat(VatPreset::Standard))
        ->line(fn ($l) => $l->name('Ware')->quantity(1, UnitCode::Piece)->netPrice('100.00')->vat(VatPreset::exempt('§ 4 Nr. 14 UStG')))
        ->build();

    expect($invoice->notes)->toBe([]);
});

it('treats the transfer of a business as a whole as not subject to VAT (section 1 (1a) UStG)', function () {
    $quittio = Quittio\Core\Quittio::create([], [new CountryDE]);
    $builder = Scenarios::base()
        ->seller(fn ($s) => $s->vatId(null))
        ->buyer(fn ($b) => $b->vatId(null)->taxNumber('201/987/65432'))
        ->line(fn ($l) => $l->name('Betrieb Musterstraße 1 mit Inventar')->quantity(1, UnitCode::Piece)->netPrice('250000.00')->vat(VatPreset::BusinessTransfer));
    $invoice = $builder->build();
    $withVatIds = Scenarios::base()
        ->line(fn ($l) => $l->name('Betrieb')->quantity(1, UnitCode::Piece)->netPrice('250000.00')->vat(VatPreset::BusinessTransfer));

    expect($invoice->lines[0]->vat->category)->toBe(VatCategory::NotSubjectToVat)
        ->and($invoice->lines[0]->vat->rate)->toBeNull()
        ->and($invoice->lines[0]->vat->exemptionReasonCode)->toBe(VatExemptionReasonCode::NotSubjectToVat)
        ->and($invoice->lines[0]->vat->exemptionReason)->toBe('Nicht steuerbare Geschäftsveräußerung im Ganzen (§ 1 Abs. 1a UStG).')
        ->and($quittio->make($builder)->format('xrechnung-cii')->check()->errors())->toBe([])
        // With the seller's tax number the VAT identifiers are left out (see CategoryOVatIdsTest).
        ->and($quittio->make($withVatIds)->format('xrechnung-cii')->check()->errors())->toBe([]);
});

it('names the place of supply abroad for supplies not taxable in Germany', function () {
    expect(VatPreset::NotTaxableInGermany->exemptionReason('de'))->toBe('Nicht im Inland steuerbare Leistung (Leistungsort im Ausland)')
        ->and(VatPreset::NotTaxableInGermany->exemptionReason('en'))->toBe('Service not taxable in Germany (place of supply abroad)');
});

it('refers to an agreement on bonuses and rebates (section 14 (4) no. 7 UStG)', function () {
    $invoice = Scenarios::make('simple')->de()->discountAgreement('der Bonusvereinbarung vom 01.01.2026')->build();
    $note = $invoice->notes[array_key_last($invoice->notes)];

    expect($note->content)->toBe('Etwaige Minderungen des Entgelts (Boni, Rabatte) ergeben sich aus der Bonusvereinbarung vom 01.01.2026.')
        ->and($note->subjectCode?->value)->toBe('REG');
});
