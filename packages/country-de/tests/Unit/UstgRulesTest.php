<?php

declare(strict_types=1);

use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Builder\PartyBuilder;
use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Enums\PaymentMeansCode;
use Quittio\Core\Enums\UnitCode;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Profiles\ProfileRegistry;
use Quittio\Core\Testing\Scenarios;
use Quittio\Core\Validation\Drivers\PhpRulesDriver;
use Quittio\Core\Validation\ValidationContext;
use Quittio\Core\Validation\ValidationResult;
use Quittio\Core\Validation\ValidationSubject;
use Quittio\Country\DE\CountryDE;
use Quittio\Country\DE\Support\LeitwegId;
use Quittio\Country\DE\Tax\VatPreset;

function ustg(InvoiceBuilder $builder, string $locale = 'en'): ValidationResult
{
    $pack = new CountryDE;
    $invoice = (new En16931Calculator)->calculate($builder->build());

    return (new PhpRulesDriver)->validate(new ValidationSubject($invoice, new ValidationContext(ProfileRegistry::withDefaults()->get('en16931'), [$pack], $locale)));
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
        ->and(ustgCodes(ustg(Scenarios::make('simple')->buyerReference('04011000-12345-04'))))->toBe(['DE-LEITWEG-ID']);
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
