<?php

declare(strict_types=1);

use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Builder\PartyBuilder;
use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Countries\ResolvedCountries;
use Quittio\Core\Enums\PaymentMeansCode;
use Quittio\Core\Enums\UnitCode;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Formats\DefaultFormatResolver;
use Quittio\Core\Profiles\ProfileRegistry;
use Quittio\Core\Quittio;
use Quittio\Core\Testing\Scenarios;
use Quittio\Core\Validation\Drivers\PhpRulesDriver;
use Quittio\Core\Validation\ValidationContext;
use Quittio\Core\Validation\ValidationException;
use Quittio\Core\Validation\ValidationResult;
use Quittio\Core\Validation\ValidationSubject;
use Quittio\Country\DE\CountryDE;
use Quittio\Country\DE\Rules\UstgRules;
use Quittio\Country\DE\Support\Flags;
use Quittio\Country\DE\Support\LeitwegId;
use Quittio\Country\DE\Tax\VatPreset;
use Quittio\Country\DE\Texts\LegalTexts;

function ustg(InvoiceBuilder $builder, string $locale = 'en'): ValidationResult
{
    $pack = new CountryDE;
    $invoice = (new En16931Calculator)->calculate($builder->build());

    return (new PhpRulesDriver)->validate(new ValidationSubject($invoice, new ValidationContext(ProfileRegistry::withDefaults()->get('en16931'), [$pack], $locale)));
}

function resolveGermanFormat(InvoiceBuilder $builder): string
{
    return (new DefaultFormatResolver)->resolve($builder->build(), new ResolvedCountries(new CountryDE))->format;
}

/**
 * @return list<string>
 */
function ustgCodes(ValidationResult $result): array
{
    return array_values(array_unique(array_filter(
        array_map(static fn ($m): string => $m->code, $result->messages),
        static fn (string $code): bool => str_starts_with($code, 'DE-'),
    )));
}

it('accepts a complete German invoice', function () {
    expect(ustgCodes(ustg(Scenarios::make('simple'))))->toBe([]);
});

it('names the party and the missing address fields', function () {
    $seller = fn ($s) => $s->name('Niklas GmbH')->vatId('DE123456789')->address(null, null, null, 'DE');
    $de = ustg(Scenarios::make('simple')->seller($seller), 'de')->errors();
    $en = ustg(Scenarios::make('simple')->buyer(fn ($b) => $b->name('Kunde AG')->address('Weg 1', null, null, 'DE')), 'en')->errors();
    $fr = ustg(Scenarios::make('simple')->seller($seller), 'fr')->errors();

    expect($de[0]->code)->toBe('DE-USTG-01')
        ->and($de[0]->message)->toBe('Die Anschrift des leistenden Unternehmers (Verkäufer, BG-5) ist unvollständig, es fehlt: Straße und Hausnummer, Postleitzahl, Ort (§ 14 Abs. 4 Nr. 1 UStG).')
        ->and($de[0]->hint)->toStartWith('Mit Quittio::invoice() kommt der Verkäufer aus config/quittio.php')
        ->and($en[0]->message)->toBe('The address of the buyer (BG-8) is incomplete; missing: post code, city (section 14 (4) no. 1 UStG).')
        ->and($en[0]->hint)->toContain('->buyer(Party::make(…)->address(')
        ->and($fr[0]->message)->toContain('of the seller (BG-5)');               // other locales: English
});

it('puts the hints into the message of the validation exception', function () {
    $quittio = Quittio::create([], [new CountryDE]);
    $invoice = Scenarios::make('simple')->seller(fn ($s) => $s->name('Niklas GmbH')->vatId('DE123456789')->address(null, null, null, 'DE'));

    expect(fn () => $quittio->make($invoice)->locale('de')->format('zugferd')->render())
        ->toThrow(ValidationException::class, "ist unvollständig, es fehlt: Straße und Hausnummer, Postleitzahl, Ort (§ 14 Abs. 4 Nr. 1 UStG).\n    Mit Quittio::invoice() kommt der Verkäufer aus config/quittio.php");
});

it('requires the full address of both parties', function () {
    $result = ustg(Scenarios::make('simple')->buyer(fn ($b) => $b->name('Kunde AG')->address(null, null, 'Berlin', 'DE')));

    expect(ustgCodes($result))->toBe(['DE-USTG-01'])
        ->and($result->errors()[0]->path)->toBe('buyer.address');
});

it('requires the tax number or VAT identifier of the seller', function () {
    $seller = (new PartyBuilder)->name('Muster GmbH')->address('Hauptstraße 1', '12345', 'Musterstadt', 'DE')->contact('Max', '1', 'a@b.de')->buildSeller();
    $result = ustg(Scenarios::make('simple')->seller($seller));

    expect(ustgCodes($result))->toContain('DE-USTG-02');
});

it('requires a service date', function () {
    $builder = Scenarios::base()->line(fn ($l) => $l->name('Beratung')->quantity(1, UnitCode::Hour)->netPrice('100.00')->vat(VatCategory::StandardRate, '19'));
    $withoutDate = new ReflectionProperty(InvoiceBuilder::class, 'delivery');
    $withoutDate->setValue($builder, null);

    expect(ustgCodes(ustg($builder)))->toBe(['DE-USTG-03'])
        ->and(ustgCodes(ustg($builder->invoicingPeriod('2026-09-01', '2026-09-30'))))->toBe([]);
});

it('accepts line periods as service date', function () {
    $builder = Scenarios::base()->line(fn ($l) => $l->name('Hosting')->quantity(1, UnitCode::Month)->netPrice('10.00')->vat(VatCategory::StandardRate, '19')->period('2026-09-01', '2026-09-30'));
    (new ReflectionProperty(InvoiceBuilder::class, 'delivery'))->setValue($builder, null);

    expect(ustgCodes(ustg($builder)))->toBe([]);
});

it('labels self-billed invoices as Gutschrift', function () {
    $plain = Scenarios::make('simple')->type('389');

    expect(ustgCodes(ustg($plain)))->toBe(['DE-USTG-04'])
        ->and(ustgCodes(ustg(Scenarios::make('simple')->type('261'))))->toBe(['DE-USTG-04'])
        ->and(ustgCodes(ustg(Scenarios::make('simple')->type('527'))))->toBe(['DE-USTG-04'])
        ->and(ustgCodes(ustg(Scenarios::make('simple')->selfBilled())))->toBe([]);
});

it('adds the retention notice for property related services', function () {
    $builder = Scenarios::make('simple')->propertyRelatedService();
    $invoice = $builder->build();

    expect(ustgCodes(ustg($builder)))->toBe([])
        ->and($invoice->notes[array_key_last($invoice->notes)]->content)->toContain('§ 14b');

    $flagOnly = Scenarios::make('simple')->flag('de.property_related_service');
    expect(ustgCodes(ustg($flagOnly)))->toBe(['DE-USTG-05']);
});

it('warns when the reverse charge wording is missing', function () {
    $builder = Scenarios::base()->line(fn ($l) => $l->name('Beratung')->quantity(1, UnitCode::Hour)->netPrice('100.00')->vat(VatCategory::ReverseCharge, '0', 'Reverse charge', 'VATEX-EU-AE'))
        ->buyer(fn ($b) => $b->name('Client SARL')->vatId('FR12345678901')->address('1 Rue', '75002', 'Paris', 'FR'));

    $result = ustg($builder);
    expect(ustgCodes($result))->toBe(['DE-USTG-06'])
        ->and($result->isValid())->toBeTrue();

    $preset = Scenarios::base()->line(fn ($l) => $l->name('Beratung')->quantity(1, UnitCode::Hour)->netPrice('100.00')->vat(VatPreset::ReverseChargeEu))
        ->buyer(fn ($b) => $b->name('Client SARL')->vatId('FR12345678901')->address('1 Rue', '75002', 'Paris', 'FR'));
    expect(ustgCodes(ustg($preset)))->toBe([]);
});

it('requires vehicle details for new vehicles', function () {
    $builder = Scenarios::make('intra-eu')->newVehicle();

    expect(ustgCodes(ustg($builder)))->toBe(['DE-USTG-07']);
});

it('accepts the texts of the reverse charge presets in every language of the pack', function (VatPreset $preset, string $locale) {
    $builder = Scenarios::base()->locale($locale)
        ->line(fn ($l) => $l->name('Beratung')->quantity(1, UnitCode::Hour)->netPrice('100.00')->vat($preset))
        ->buyer(fn ($b) => $b->name('Client SARL')->vatId('FR12345678901')->address('1 Rue', '75002', 'Paris', 'FR'));
    $invoice = (new En16931Calculator)->calculate($builder->build());

    expect($invoice->vatBreakdown[0]->exemptionReason)->toBe($preset->exemptionReason($locale))
        ->and(ustgCodes(ustg($builder)))->not->toContain('DE-USTG-06')
        ->and(ustgCodes(ustg($builder, 'de')))->not->toContain('DE-USTG-06');
})->with([VatPreset::ReverseChargeDomestic, VatPreset::ReverseChargeEu, VatPreset::TriangularTransaction])->with(['de', 'en']);

it('adds the new vehicle notice and expects an intra-community supply (section 14a (4) UStG)', function () {
    $vehicle = fn (VatPreset $preset) => Scenarios::base()
        ->buyer(fn ($b) => $b->name('Cliente S.r.l.')->vatId('IT12345678901')->address('Via Roma 1', '00184', 'Roma', 'IT'))
        ->line(fn ($l) => $l->name('Pkw')->quantity(1, UnitCode::Piece)->netPrice('30000.00')->vat($preset)->attribute('Erstzulassung', '2026-09-01')->attribute('Kilometerstand', '12'));
    $invoice = $vehicle(VatPreset::IntraCommunitySupply)->newVehicle()->build();
    $english = $vehicle(VatPreset::IntraCommunitySupply)->newVehicle('en_GB')->build();
    $standard = ustg($vehicle(VatPreset::Standard19)->newVehicle());

    expect($invoice->meta->hasFlag(Flags::NEW_VEHICLE))->toBeTrue()
        ->and($invoice->notes[array_key_last($invoice->notes)]->content)->toBe(LegalTexts::catalog()->get(LegalTexts::NEW_VEHICLE, 'de'))
        ->and($invoice->notes[array_key_last($invoice->notes)]->subjectCode?->value)->toBe('REG')
        ->and($english->notes[array_key_last($english->notes)]->content)->toStartWith('Intra-Community supply of a new means of transport')
        ->and(ustgCodes(ustg($vehicle(VatPreset::IntraCommunitySupply)->newVehicle())))->toBe([])
        ->and(ustgCodes($standard))->toBe(['DE-USTG-13'])
        ->and($standard->warnings()[0]->path)->toBe('lines')
        ->and($standard->isValid())->toBeTrue();
});

it('limits small amount invoices to 250 EUR gross', function () {
    $small = Scenarios::base()->smallAmount()
        ->buyer(fn ($b) => $b->name('Barkunde')->address(null, null, null, 'DE'))
        ->line(fn ($l) => $l->name('Kaffee')->quantity(2, UnitCode::Piece)->netPrice('3.50')->vat(VatCategory::StandardRate, '19'));
    $large = Scenarios::make('simple')->smallAmount();

    expect(ustgCodes(ustg($small)))->toBe([])
        ->and(ustgCodes(ustg($large)))->toBe(['DE-USTG-08']);
});

it('rejects VAT on invoices of small businesses', function () {
    $builder = Scenarios::base()
        ->line(fn ($l) => $l->name('Leistung A')->quantity(1, UnitCode::Piece)->netPrice('100.00')->vat(VatPreset::SmallBusiness))
        ->line(fn ($l) => $l->name('Leistung B')->quantity(1, UnitCode::Piece)->netPrice('100.00')->vat(VatPreset::Standard19));

    expect(ustgCodes(ustg($builder)))->toContain('DE-USTG-09');
});

it('recommends the VAT amount in EUR for foreign currencies', function () {
    $builder = Scenarios::base()->currency('USD')
        ->payment(PaymentMeansCode::CreditTransfer, iban: 'DE02120300000000202051')
        ->line(fn ($l) => $l->name('Licence')->quantity(1, UnitCode::Unit)->netPrice('100.00')->vat(VatCategory::StandardRate, '19'));

    expect(ustgCodes(ustg($builder)))->toBe(['DE-USTG-10'])
        ->and(ustgCodes(ustg(Scenarios::make('foreign-currency'))))->toBe([]);
});

it('checks the Leitweg-ID check digits', function () {
    expect(LeitwegId::isValid('04011000-12345-03'))->toBeTrue()
        ->and(LeitwegId::isValid('991-33333TEST-33'))->toBeTrue()
        ->and(LeitwegId::isValid('04011000-12345-04'))->toBeFalse()
        ->and(LeitwegId::looksLike('PO-4711'))->toBeFalse()
        ->and(ustgCodes(ustg(Scenarios::make('simple')->buyerReference('04011000-12345-04')->buyer(fn ($b) => $b->electronicAddress('04011000-12345-04', '0204')))))->toBe(['DE-LEITWEG-ID']);
});

it('does not treat order numbers of business buyers as Leitweg-IDs, but notes a possible typo', function () {
    $result = ustg(Scenarios::make('simple')->buyerReference('4711-01'));

    expect(ustgCodes($result))->toBe(['DE-LEITWEG-ID-SUSPECT'])
        ->and($result->messages[0]->severity->value)->toBe('info')
        ->and($result->isValid())->toBeTrue()
        ->and($result->warnings())->toBe([])
        ->and(resolveGermanFormat(Scenarios::make('simple')->buyerReference('4711-01')))->toBe('zugferd');
});

it('reports a mistyped Leitweg-ID without switching the format', function () {
    $builder = Scenarios::make('simple')->buyerReference('04011000-12345-04');
    $result = ustg($builder, 'de');
    $suspect = array_values(array_filter($result->messages, static fn ($m): bool => $m->code === 'DE-LEITWEG-ID-SUSPECT'))[0];

    expect(ustgCodes($result))->toBe(['DE-LEITWEG-ID-SUSPECT'])
        ->and($suspect->severity->value)->toBe('info')
        ->and($suspect->path)->toBe('buyerReference')
        ->and($suspect->message)->toContain('„04011000-12345-04“')
        ->and(resolveGermanFormat($builder))->toBe('zugferd')
        ->and(ustgCodes(ustg(Scenarios::make('simple')->buyerReference('04011000-12345-03'))))->toBe([])
        ->and(ustgCodes(ustg(Scenarios::make('simple')->buyerReference('PO-4711'))))->toBe([])
        // Public buyers get the DE-LEITWEG-ID warning instead; consumers and buyers abroad no note.
        ->and(ustgCodes(ustg($builder->buyer(fn ($b) => $b->electronicAddress('04011000-12345-04', '0204')))))->toBe(['DE-LEITWEG-ID'])
        ->and(ustgCodes(ustg(Scenarios::make('b2c-gross')->buyerReference('04011000-12345-04'))))->not->toContain('DE-LEITWEG-ID-SUSPECT')
        ->and(ustgCodes(ustg(Scenarios::make('intra-eu')->buyerReference('04011000-12345-04'))))->toBe([]);
});

it('has German messages for every rule', function () {
    $result = ustg(Scenarios::make('simple')->type('389'), 'de');

    expect($result->errors()[0]->message)->toContain('„Gutschrift“')
        ->and($result->errors()[0]->hint)->toContain('->selfBilled()');
});

it('accepts small business invoices without service date (section 34a UStDV)', function () {
    $builder = Scenarios::base()->line(fn ($l) => $l->name('Beratung')->quantity(1, UnitCode::Hour)->netPrice('100.00')->vat(VatPreset::SmallBusiness));
    (new ReflectionProperty(InvoiceBuilder::class, 'delivery'))->setValue($builder, null);
    $invoice = $builder->build();

    expect(ustgCodes(ustg($builder)))->toBe([])
        ->and($invoice->lines[0]->vat->exemptionReason)->toBe('Steuerfreie Leistung nach § 19 Abs. 1 UStG (Steuerbefreiung für Kleinunternehmer).');
});

it('requires the VAT identifiers of both parties for reverse charge services in another member state', function () {
    $taxNumberOnly = (new PartyBuilder)->name('Muster GmbH')->taxNumber('248/815/08156')->address('Hauptstraße 1', '12345', 'Musterstadt', 'DE')->contact('Max', '+49 30 123456', 'a@b.de')->buildSeller();
    $line = fn ($l) => $l->name('Beratung')->quantity(1, UnitCode::Hour)->netPrice('100.00')->vat(VatPreset::ReverseChargeEu);
    $withoutBuyerVatId = Scenarios::base()->line($line)
        ->buyer(fn ($b) => $b->name('Client SARL')->vatId(null)->legalRegistration('12345678900012')->address('1 Rue', '75002', 'Paris', 'FR'));
    $withoutSellerVatId = Scenarios::base()->seller($taxNumberOnly)->line($line)
        ->buyer(fn ($b) => $b->name('Client SARL')->vatId('FR12345678901')->address('1 Rue', '75002', 'Paris', 'FR'));
    $complete = Scenarios::base()->line($line)
        ->buyer(fn ($b) => $b->name('Client SARL')->vatId('FR12345678901')->address('1 Rue', '75002', 'Paris', 'FR'));

    expect(ustgCodes(ustg($withoutBuyerVatId)))->toBe(['DE-USTG-11'])
        ->and(ustg($withoutBuyerVatId)->errors()[0]->path)->toBe('buyer.vatId')
        ->and(ustgCodes(ustg($withoutSellerVatId)))->toBe(['DE-USTG-11'])
        ->and(ustgCodes(ustg($complete)))->toBe([]);
});

it('does not require the buyer VAT identifier for domestic reverse charge (section 13b UStG)', function () {
    $builder = Scenarios::base()->line(fn ($l) => $l->name('Bauleistung')->quantity(1, UnitCode::Piece)->netPrice('1000.00')->vat(VatPreset::ReverseChargeDomestic))
        ->buyer(fn ($b) => $b->name('Bau GmbH')->vatId(null)->taxNumber('201/987/65432')->legalRegistration('HRB 12345')->address('Baustraße 1', '10115', 'Berlin', 'DE'));

    expect(ustgCodes(ustg($builder)))->not->toContain('DE-USTG-11');
});

it('marks triangular transactions as reverse charge with the wording of section 14a (7) UStG', function () {
    $builder = Scenarios::base()->line(fn ($l) => $l->name('Maschine')->quantity(1, UnitCode::Piece)->netPrice('5000.00')->vat(VatPreset::TriangularTransaction))
        ->buyer(fn ($b) => $b->name('Kunde GmbH')->vatId('ATU12345678')->address('Ring 1', '1010', 'Wien', 'AT'));
    $invoice = $builder->build();

    expect($invoice->lines[0]->vat->category)->toBe(VatCategory::ReverseCharge)
        ->and($invoice->lines[0]->vat->exemptionReason)->toContain('Dreiecksgeschäft nach § 25b UStG')
        ->and($invoice->lines[0]->vat->exemptionReason)->toContain('Steuerschuldnerschaft des letzten Abnehmers')
        ->and(ustgCodes(ustg($builder)))->toBe([]);
});

it('deducts paid advance invoices with their VAT in the final invoice (section 14 (5) sentence 2 UStG)', function () {
    $advance = (new En16931Calculator)->calculate(Scenarios::base()->number('RE-2026-0040')->issueDate('2026-09-01')->type('386')
        ->line(fn ($l) => $l->name('Anzahlung Website')->quantity(1, UnitCode::Piece)->netPrice('1000.00')->vat(VatPreset::Standard19))
        ->build());
    $builder = Scenarios::base()
        ->line(fn ($l) => $l->name('Website')->quantity(1, UnitCode::Piece)->netPrice('3000.00')->vat(VatPreset::Standard19))
        ->deductAdvance($advance);
    $invoice = (new En16931Calculator)->calculate($builder->build());

    expect($invoice->totals?->grossTotal->getAmount()->toString())->toBe('3570.00')
        ->and($invoice->totals?->vatTotal?->getAmount()->toString())->toBe('570.00')
        ->and($invoice->totals?->prepaidAmount?->getAmount()->toString())->toBe('1190.00')
        ->and($invoice->totals?->amountDue->getAmount()->toString())->toBe('2380.00')
        ->and($invoice->precedingInvoices[0]->number)->toBe('RE-2026-0040')
        ->and($invoice->notes[array_key_last($invoice->notes)]->content)
        ->toBe("Abzüglich Anzahlung gemäß Rechnung RE-2026-0040 vom 01.09.2026: Entgelt 1.000,00\u{00A0}€ zzgl. 19\u{00A0}% Umsatzsteuer 190,00\u{00A0}€ (§ 14 Abs. 5 Satz 2 UStG).")
        ->and(ustg($builder)->isValid())->toBeTrue()
        ->and(ustgCodes(ustg($builder)))->toBe([]);
});

it('adds up several advance payments', function () {
    $advance = fn (string $number) => (new En16931Calculator)->calculate(Scenarios::base()->number($number)->type('386')
        ->line(fn ($l) => $l->name('Abschlag')->quantity(1, UnitCode::Piece)->netPrice('500.00')->vat(VatPreset::Standard19))
        ->build());
    $invoice = (new En16931Calculator)->calculate(Scenarios::make('simple')->deductAdvance($advance('RE-1'))->deductAdvance($advance('RE-2'), 'en')->build());

    expect($invoice->totals?->prepaidAmount?->getAmount()->toString())->toBe('1190.00')
        ->and($invoice->precedingInvoices)->toHaveCount(2)
        ->and($invoice->notes[array_key_last($invoice->notes)]->content)->toStartWith('Less advance payment according to invoice RE-2');
});

it('writes the whole advance deduction note in one language', function () {
    $advance = (new En16931Calculator)->calculate(Scenarios::base()->number('RE-2026-0040')->issueDate('2026-09-01')->type('386')
        ->line(fn ($l) => $l->name('Anzahlung')->quantity(1, UnitCode::Piece)->netPrice('1000.00')->vat(VatPreset::Standard19))
        ->line(fn ($l) => $l->name('Export')->quantity(1, UnitCode::Piece)->netPrice('100.00')->vat(VatPreset::Export))
        ->build());
    $note = static fn (string $locale): string => (static function (array $notes): string {
        return $notes[array_key_last($notes)]->content;
    })(Scenarios::make('simple')->deductAdvance($advance, $locale)->build()->notes);

    expect($note('fr'))->toBe("Abzüglich Anzahlung gemäß Rechnung RE-2026-0040 vom 01.09.2026: Entgelt 1.000,00\u{00A0}€ zzgl. 19\u{00A0}% Umsatzsteuer 190,00\u{00A0}€; Entgelt 100,00\u{00A0}€ ohne Umsatzsteuer (§ 14 Abs. 5 Satz 2 UStG).")
        ->and($note('fr'))->toBe($note('de'))
        ->and($note('en_GB'))->toStartWith('Less advance payment according to invoice RE-2026-0040 of ')
        ->and($note('en_GB'))->toContain('of 01/09/2026: net 1,000.00')
        ->and($note('en_GB'))->toContain('without VAT (section 14 (5) sentence 2 UStG).')
        ->and($note('en_GB'))->not->toContain('Entgelt');
});

it('deducts only documents that can be advance invoices', function (string $type, bool $accepted) {
    $document = (new En16931Calculator)->calculate(Scenarios::base()->number('X-1')->type($type)
        ->line(fn ($l) => $l->name('Anzahlung')->quantity(1, UnitCode::Piece)->netPrice('100.00')->vat(VatPreset::Standard19))
        ->build());
    $deduct = fn () => Scenarios::make('simple')->deductAdvance($document);

    $accepted ? expect($deduct()->build()->precedingInvoices[0]->number)->toBe('X-1') : expect($deduct)->toThrow(InvalidArgumentException::class, 'cannot be deducted');
})->with([
    'commercial invoice' => ['380', true],
    'prepayment invoice' => ['386', true],
    'partial invoice' => ['326', true],
    'credit note' => ['381', false],
    'corrected invoice' => ['384', false],
    'self-billed credit note' => ['261', false],
    'final construction invoice' => ['877', false],
]);

it('does not deduct a cancellation by negative invoice', function () {
    $quittio = Quittio::create([], [new CountryDE]);
    $original = (new En16931Calculator)->calculate(Scenarios::base()->number('RE-1')->type('386')
        ->line(fn ($l) => $l->name('Anzahlung')->quantity(1, UnitCode::Piece)->netPrice('100.00')->vat(VatPreset::Standard19))->build());
    $cancellation = (new En16931Calculator)->calculate($quittio->cancellation($original, 'ST-1', 'Test', 'negative_invoice')->build());

    expect($cancellation->typeCodeValue())->toBe('380')
        ->and(fn () => Scenarios::make('simple')->deductAdvance($cancellation))->toThrow(InvalidArgumentException::class, 'positive gross total');
});

it('rejects VAT for sellers marked as small business', function () {
    $builder = Scenarios::make('simple')->flag(Flags::SMALL_BUSINESS);
    $exempt = Scenarios::base()->flag(Flags::SMALL_BUSINESS)
        ->line(fn ($l) => $l->name('Beratung')->quantity(1, UnitCode::Hour)->netPrice('100.00')->vat(VatPreset::SmallBusiness));

    expect(ustgCodes(ustg($builder)))->toBe(['DE-USTG-09'])
        ->and(ustgCodes(ustg($exempt)))->toBe([]);
});

it('does not apply the small amount simplifications to reverse charge, intra-community supplies and distance sales (section 33 sentence 3 UStDV)', function () {
    $reverseCharge = Scenarios::base()->smallAmount()
        ->buyer(fn ($b) => $b->name('Bau GmbH')->address(null, null, null, 'DE'))
        ->line(fn ($l) => $l->name('Reinigung')->quantity(1, UnitCode::Piece)->netPrice('150.00')->vat(VatPreset::ReverseChargeDomestic));
    $distanceSale = Scenarios::base()->smallAmount()
        ->buyer(fn ($b) => $b->name('Jean Dupont')->vatId(null)->consumer()->address('1 Rue', '75002', 'Paris', 'FR'))
        ->line(fn ($l) => $l->name('Buch')->quantity(1, UnitCode::Piece)->netPrice('20.00')->vat(VatCategory::StandardRate, '5.5'));
    $domestic = Scenarios::base()->smallAmount()
        ->buyer(fn ($b) => $b->name('Barkunde')->vatId(null)->consumer()->address(null, null, null, 'DE'))
        ->line(fn ($l) => $l->name('Kaffee')->quantity(2, UnitCode::Piece)->netPrice('3.50')->vat(VatPreset::Standard19));

    expect(ustgCodes(ustg($reverseCharge)))->toContain('DE-USTG-12')
        ->and(ustgCodes(ustg($reverseCharge)))->toContain('DE-USTG-01')
        ->and(ustgCodes(ustg($distanceSale)))->toContain('DE-USTG-12')
        ->and(ustgCodes(ustg($domestic)))->toBe([])
        ->and(UstgRules::isSmallAmountInvoice($reverseCharge->build()))->toBeFalse()
        ->and(resolveGermanFormat($reverseCharge))->toBe('zugferd');
});

it('rejects VAT on document level charges of small businesses', function () {
    $builder = Scenarios::base()->flag(Flags::SMALL_BUSINESS)
        ->line(fn ($l) => $l->name('Beratung')->quantity(1, UnitCode::Hour)->netPrice('100.00')->vat(VatPreset::SmallBusiness))
        ->charge(VatCategory::StandardRate, '19', amount: '10.00', reason: 'Versand');

    expect(ustgCodes(ustg($builder)))->toContain('DE-USTG-09');
});

it('requires the postcode of German buyers', function () {
    $builder = Scenarios::make('simple')->buyer(fn ($b) => $b->address('Bahnhofstraße 5', null, 'Beispielstadt', 'DE'));

    expect(ustgCodes(ustg($builder)))->toBe(['DE-USTG-01']);
});

it('requires both VAT identifiers for reverse charge to another member state without a preset', function () {
    $builder = Scenarios::base()
        ->line(fn ($l) => $l->name('Beratung')->quantity(1, UnitCode::Hour)->netPrice('100.00')->vat(VatCategory::ReverseCharge, '0', 'Steuerschuldnerschaft des Leistungsempfängers', 'VATEX-EU-AE'))
        ->buyer(fn ($b) => $b->name('Client SARL')->vatId(null)->legalRegistration('12345678900012')->address('1 Rue', '75002', 'Paris', 'FR'));

    expect(ustgCodes(ustg($builder)))->toBe(['DE-USTG-11']);
});

it('deducts only advance invoices in the same currency and the amount actually received', function () {
    $usd = (new En16931Calculator)->calculate(Scenarios::base()->number('RE-1')->type('386')->currency('USD')
        ->payment(PaymentMeansCode::CreditTransfer, iban: 'DE02120300000000202051')
        ->line(fn ($l) => $l->name('Anzahlung')->quantity(1, UnitCode::Piece)->netPrice('1000.00')->vat(VatPreset::Standard19))->build());
    $rounded = (new En16931Calculator)->calculate(Scenarios::base()->number('RE-2')->type('386')->rounding('0.03')
        ->line(fn ($l) => $l->name('Anzahlung')->quantity(1, UnitCode::Piece)->netPrice('99.97')->vat(VatPreset::Standard19))->build());
    $final = (new En16931Calculator)->calculate(Scenarios::make('simple')->deductAdvance($rounded)->build());

    expect(fn () => Scenarios::make('simple')->deductAdvance($usd))->toThrow(InvalidArgumentException::class, 'USD')
        ->and($rounded->totals?->amountDue->getAmount()->toString())->toBe('118.99')
        ->and($final->totals?->prepaidAmount?->getAmount()->toString())->toBe('118.99');
});

it('accepts the self-billing label in another official EU language (UStAE 14.5 Abs. 24)', function () {
    expect(ustgCodes(ustg(Scenarios::make('simple')->type('389')->note('Self-billing', 'REG'))))->toBe([])
        ->and(ustgCodes(ustg(Scenarios::make('simple')->type('389')->note('Autofacturation', 'REG'))))->toBe([]);
});

it('compares small amount invoices in a foreign currency by their EUR equivalent', function () {
    $small = fn (string $rate) => Scenarios::base()->smallAmount()->currency('USD')->taxCurrency('EUR', $rate, '2026-10-01')
        ->payment(PaymentMeansCode::CreditTransfer, iban: 'DE02120300000000202051')
        ->buyer(fn ($b) => $b->name('Barkunde')->vatId(null)->consumer()->address(null, null, null, 'DE'))
        ->line(fn ($l) => $l->name('Lizenz')->quantity(1, UnitCode::Unit)->netPrice('200.00')->vat(VatPreset::Standard19));

    expect(ustgCodes(ustg($small('0.9'))))->not->toContain('DE-USTG-08')
        ->and(ustgCodes(ustg($small('1.2'))))->toContain('DE-USTG-08');
});

it('checks the tax number of a German seller', function () {
    $seller = fn (string $number, string $country = 'DE') => Scenarios::make('simple')->seller(fn ($s) => $s->vatId(null)->taxNumber($number)->address('Hauptstraße 1', '12345', 'Musterstadt', $country));
    $invalid = ustg($seller('11/111/11111'), 'de');
    $warning = array_values(array_filter($invalid->messages, static fn ($m): bool => $m->code === 'DE-TAX-NUMBER'))[0];

    expect($warning->severity->value)->toBe('warning')
        ->and($warning->path)->toBe('seller.taxNumber')
        ->and($warning->message)->toContain('„11/111/11111“')
        ->and(ustgCodes(ustg($seller('198/815/08152'))))->not->toContain('DE-TAX-NUMBER')
        ->and(ustgCodes(ustg($seller('9198081508152'))))->not->toContain('DE-TAX-NUMBER')
        ->and(ustgCodes(ustg($seller('DE123456789'))))->toContain('DE-TAX-NUMBER')
        // Not checkable (Bavarian notation without tax office number) or not a German seller.
        ->and(ustgCodes(ustg($seller('815/08152'))))->not->toContain('DE-TAX-NUMBER')
        ->and(ustgCodes(ustg($seller('11/111/11111', 'AT'))))->not->toContain('DE-TAX-NUMBER');
});

it('rejects intra-community supplies of small businesses except for new vehicles (section 19 (1) sentence 2 and (6) sentence 1 UStG)', function () {
    $buyer = fn ($b) => $b->name('Cliente S.r.l.')->vatId('IT12345678901')->address('Via Roma 1', '00184', 'Roma', 'IT');
    $mixed = Scenarios::base()->buyer($buyer)
        ->line(fn ($l) => $l->name('Beratung')->quantity(1, UnitCode::Hour)->netPrice('100.00')->vat(VatPreset::SmallBusiness))
        ->line(fn ($l) => $l->name('Ware')->quantity(1, UnitCode::Piece)->netPrice('100.00')->vat(VatPreset::IntraCommunitySupply));
    $flagged = Scenarios::base()->buyer($buyer)->flag(Flags::SMALL_BUSINESS)
        ->line(fn ($l) => $l->name('Ware')->quantity(1, UnitCode::Piece)->netPrice('100.00')->vat(VatPreset::IntraCommunitySupply));
    $vehicle = Scenarios::base()->buyer($buyer)->flag(Flags::SMALL_BUSINESS)->newVehicle()
        ->line(fn ($l) => $l->name('Pkw')->quantity(1, UnitCode::Piece)->netPrice('30000.00')->vat(VatPreset::IntraCommunitySupply)->attribute('Erstzulassung', '2026-09-01')->attribute('Kilometerstand', '12'));
    $regular = Scenarios::base()->buyer($buyer)
        ->line(fn ($l) => $l->name('Ware')->quantity(1, UnitCode::Piece)->netPrice('100.00')->vat(VatPreset::IntraCommunitySupply));

    $finding = array_values(array_filter(ustg($mixed)->errors(), static fn ($m): bool => $m->code === 'DE-USTG-14'))[0];

    expect(ustgCodes(ustg($mixed)))->toBe(['DE-USTG-14'])
        ->and($finding->path)->toBe('lines')
        ->and(ustgCodes(ustg($flagged)))->toBe(['DE-USTG-14'])
        ->and(ustgCodes(ustg($vehicle)))->toBe([])
        ->and(ustgCodes(ustg($regular)))->toBe([]);
});

it('warns about intra-community invoices issued after the 15th of the following month (section 14a (1) and (3) UStG)', function () {
    $supply = fn (string $delivered, string $issued) => Scenarios::base()->issueDate($issued)->deliveryDate($delivered)
        ->buyer(fn ($b) => $b->name('Cliente S.r.l.')->vatId('IT12345678901')->address('Via Roma 1', '00184', 'Roma', 'IT'))
        ->line(fn ($l) => $l->name('Ware')->quantity(1, UnitCode::Piece)->netPrice('100.00')->vat(VatPreset::IntraCommunitySupply));
    $service = fn (string $delivered, string $issued) => Scenarios::base()->issueDate($issued)->deliveryDate($delivered)
        ->buyer(fn ($b) => $b->name('Client SARL')->vatId('FR12345678901')->address('1 Rue', '75002', 'Paris', 'FR'))
        ->line(fn ($l) => $l->name('Beratung')->quantity(1, UnitCode::Hour)->netPrice('100.00')->vat(VatPreset::ReverseChargeEu));
    $late = ustg($supply('2026-08-31', '2026-09-16'));
    $warning = array_values(array_filter($late->warnings(), static fn ($m): bool => $m->code === 'DE-USTG-15'))[0];

    expect(ustgCodes($late))->toBe(['DE-USTG-15'])
        ->and($warning->path)->toBe('issueDate')
        ->and($warning->message)->toContain('2026-09-15')
        ->and(array_filter($late->errors(), static fn ($m): bool => str_starts_with($m->code, 'DE-')))->toBe([])
        ->and(ustgCodes(ustg($supply('2026-08-31', '2026-09-15'))))->toBe([])
        ->and(ustgCodes(ustg($supply('2026-12-10', '2027-01-16'))))->toBe(['DE-USTG-15'])
        ->and(ustgCodes(ustg($supply('2026-12-10', '2027-01-15'))))->toBe([])
        ->and(ustgCodes(ustg($service('2026-08-31', '2026-10-03'))))->toBe(['DE-USTG-15'])
        ->and(ustgCodes(ustg($service('2026-08-31', '2026-10-03')->precedingInvoice('RE-2026-0001', '2026-09-01'))))->toBe([])
        ->and(ustgCodes(ustg(Scenarios::make('simple')->issueDate('2026-12-01'))))->toBe([]);
});

it('takes the latest supply date for the issuing deadline', function () {
    $builder = Scenarios::base()->deliveryDate('2026-07-01')->invoicingPeriod('2026-07-01', '2026-08-31')
        ->line(fn ($l) => $l->name('Ware')->quantity(1, UnitCode::Piece)->netPrice('100.00')->vat(VatPreset::IntraCommunitySupply)->period('2026-09-01', '2026-09-30'));

    expect(UstgRules::issuingDeadline($builder->build())?->toIsoString())->toBe('2026-10-15')
        ->and(UstgRules::issuingDeadline(Scenarios::make('simple')->deliveryDate('2026-12-31')->build())?->toIsoString())->toBe('2027-01-15');
});
