<?php

declare(strict_types=1);

use Brick\Money\Money;
use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Countries\CorrectionPolicy;
use Quittio\Core\Countries\CountryGeneric;
use Quittio\Core\Countries\CountryPack;
use Quittio\Core\Countries\DunningLaw;
use Quittio\Core\Countries\ResolvedCountries;
use Quittio\Core\Data\Party\BuyerParty;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Formats\DefaultFormatResolver;
use Quittio\Core\Formats\FormatChoice;
use Quittio\Core\Quittio;
use Quittio\Core\Support\Amount;
use Quittio\Core\Support\Date;
use Quittio\Core\Testing\Scenarios;
use Quittio\Core\Validation\Severity;
use Quittio\Core\Validation\ValidationLevel;
use Quittio\Core\Validation\ValidationMessage;
use Quittio\Core\Validation\ValidationResult;
use Quittio\Country\DE\CountryDE;
use Quittio\Country\DE\Dunning\BaseRateTable;
use Quittio\Country\DE\Dunning\DefaultInterest;
use Quittio\Country\DE\Formats\GermanFormatRule;
use Quittio\Country\DE\Formats\XRechnungFormats;
use Quittio\Country\DE\Layouts\Din5008;
use Quittio\Country\DE\Rules\BmfErrorClass;
use Quittio\Country\DE\Tax\VatPreset;
use Quittio\Country\DE\Texts\LegalTexts;

function germanFormatChoice(InvoiceBuilder $builder): array
{
    $pack = new CountryDE;
    $invoice = $builder->build();
    $choice = (new DefaultFormatResolver)->resolve($invoice, new ResolvedCountries($pack));

    return [$choice->format, $choice->profile];
}

it('chooses the format for domestic invoices', function (Closure $builder, string $format, ?string $profile) {
    expect(germanFormatChoice($builder()))->toBe([$format, $profile]);
})->with([
    'business' => [fn () => Scenarios::make('simple'), 'zugferd', 'en16931'],
    'public buyer' => [fn () => Scenarios::make('simple')->buyerReference('04011000-12345-03'), 'xrechnung-cii', 'xrechnung'],
    'order number shaped like a Leitweg-ID' => [fn () => Scenarios::make('simple')->buyerReference('4711-01'), 'zugferd', 'en16931'],
    'public buyer by endpoint' => [fn () => Scenarios::make('simple')->buyer(fn ($b) => $b->electronicAddress('04011000-12345-03', '0204')), 'xrechnung-cii', 'xrechnung'],
    'consumer' => [fn () => Scenarios::make('b2c-gross'), 'pdf', null],
    'small amount' => [fn () => Scenarios::make('simple')->de()->smallAmount(), 'pdf', null],
]);

it('leaves everything but German requirements to the format resolver', function () {
    $rule = new GermanFormatRule;
    $usSeller = fn () => Scenarios::make('simple')->seller(fn ($s) => $s->vatId(null)->address('1 Main Street', '10001', 'New York', 'US'));
    $buesingen = fn () => Scenarios::make('simple')->buyer(fn ($b) => $b->address('Junkerstraße 1', '78266', 'Büsingen am Hochrhein', 'DE'));
    $resolve = static fn (InvoiceBuilder $builder, ?CountryPack $leading = null): FormatChoice => (new DefaultFormatResolver)->resolve($builder->build(), new ResolvedCountries($leading ?? new CountryDE, $leading === null ? null : new CountryDE));

    expect($rule->resolve(Scenarios::make('simple')->build()))->toBeNull()
        ->and($rule->resolve(Scenarios::make('b2c-gross')->build()))->toBeNull()
        ->and($rule->resolve($usSeller()->build()))->toBeNull()
        ->and($resolve(Scenarios::make('simple'))->reason)->toBe('Domestic invoice without a country specific rule.')
        // Section 14 (2) UStG covers businesses established in Germany only: the resolver decides.
        ->and([$resolve($usSeller(), new CountryGeneric('US'))->format, $resolve($usSeller(), new CountryGeneric('US'))->reason])->toBe(['pdf', 'Invoice from a seller outside the EU VAT territory.'])
        ->and([$resolve($buesingen())->format, $resolve($buesingen())->reason])->toBe(['pdf', 'Invoice to a buyer outside the EU VAT territory.'])
        // German public buyers receive XRechnung whatever the seller's country.
        ->and($resolve($usSeller()->buyerReference('04011000-12345-03'), new CountryGeneric('US'))->format)->toBe('xrechnung-cii')
        // Small amount invoices are PDF only between parties in the German Inland.
        ->and($resolve(Scenarios::make('simple')->de()->smallAmount()->seller(fn ($s) => $s->vatId('FR12345678901')->address('1 Rue', '75002', 'Paris', 'FR')), new CountryGeneric('FR'))->format)->toBe('zugferd')
        ->and($resolve($buesingen()->de()->smallAmount())->format)->toBe('pdf');
});

it('asks the Peppol lookup for domestic German business invoices', function () {
    $lookup = static fn (BuyerParty $buyer): bool => true;
    $choose = static fn (InvoiceBuilder $builder): FormatChoice => (new DefaultFormatResolver('en16931', true, $lookup))->resolve($builder->build(), new ResolvedCountries(new CountryDE));

    expect($choose(Scenarios::make('simple'))->format)->toBe('peppol')
        ->and($choose(Scenarios::make('simple')->buyerReference('04011000-12345-03'))->format)->toBe('xrechnung-cii')
        ->and($choose(Scenarios::make('b2c-gross'))->format)->toBe('pdf')
        ->and((new DefaultFormatResolver('basic'))->resolve(Scenarios::make('simple')->build(), new ResolvedCountries(new CountryDE))->profile)->toBe('basic');
});

it('renders XRechnung automatically for public buyers', function () {
    $result = Quittio::create([], [new CountryDE])->make(Scenarios::make('simple')->buyerReference('04011000-12345-03'))->render();

    expect($result->format())->toBe('xrechnung-cii')
        ->and($result->profile()?->key())->toBe('xrechnung');
});

it('provides DIN 5008 letter layouts', function () {
    $b = Din5008::formB();
    $a = Din5008::formA();

    expect($b->addressTop)->toBe(45.0)
        ->and($a->addressTop)->toBe(27.0)
        ->and($b->foldMarks)->toBe([105.0, 210.0])
        ->and($a->foldMarks)->toBe([87.0, 192.0])
        ->and($b->punchMark)->toBe(148.5)
        ->and($b->letterheadHeight(20.0))->toBe(25.0)
        ->and((new CountryDE)->letterLayouts())->toHaveKeys(['din5008a', 'din5008b', 'free']);
});

it('places the address field and marks according to DIN 5008', function () {
    $quittio = Quittio::create(['options' => ['fold_marks' => true]], [new CountryDE]);
    $formB = $quittio->make(Scenarios::make('simple'))->format('html')->render()->html();
    $formA = $quittio->make(Scenarios::make('simple')->presentation(fn ($p) => $p->with(['letterLayout' => 'din5008a'])))->format('html')->render()->html();

    // The letterhead starts at the top margin of 15 mm and ends at the address field.
    expect($formB)->toContain('.letterhead td { height: 30mm; }')
        ->and($formB)->toContain('.address { position: absolute; top: 45mm; left: 20mm; width: 85mm;')
        ->and($formB)->toContain('top: 105mm;')
        ->and($formB)->toContain('top: 148.5mm;')
        ->and($formA)->toContain('.letterhead td { height: 12mm; }')
        ->and($formA)->toContain('.address { position: absolute; top: 27mm;')
        ->and($formA)->toContain('top: 87mm;');
});

it('lists the German mandatory output', function () {
    $pack = new CountryDE;
    $keys = static fn ($list): array => array_map(static fn ($f): string => $f->accessKey, $list->fields);

    expect($keys($pack->mandatoryOutput(Scenarios::make('simple')->build())))->toBe(['seller.vatId|seller.taxNumber|taxRepresentative.vatId', 'servicePeriod|deliveryDate|delivery.date'])
        ->and($keys($pack->mandatoryOutput((new En16931Calculator)->calculate(Scenarios::make('reverse-charge')->build()))))->toBe([
            'seller.vatId|seller.taxNumber|taxRepresentative.vatId',
            'servicePeriod|deliveryDate|delivery.date',
            'vatRows[0].label|legalNotes[0]',
            'buyer.vatId',
            'seller.vatId|taxRepresentative.vatId',
        ])
        ->and($keys($pack->mandatoryOutput(Scenarios::make('simple')->de()->selfBilled()->build())))->toContain('title')
        ->and($keys($pack->mandatoryOutput(Scenarios::make('simple')->de()->selfBilled()->build())))->toContain('legalNotes[0]')
        ->and($keys($pack->mandatoryOutput(Scenarios::make('simple')->de()->propertyRelatedService()->build())))->toContain('legalNotes[0]')
        ->and($keys($pack->mandatoryOutput(Scenarios::make('simple')->note('Gutschrift', 'AAI')->type('389')->build())))->toContain('notes[0].content|notes[0].html');
});

it('passes the mandatory field guard with the default templates for German special cases', function (Closure $builder) {
    $quittio = Quittio::create([], [new CountryDE]);

    expect($quittio->make($builder())->format('html')->render()->html())->toContain('</html>');
})->with([
    'self-billed' => [fn () => Scenarios::make('simple')->de()->selfBilled()],
    'property related service' => [fn () => Scenarios::make('simple')->de()->propertyRelatedService()],
    'reverse charge' => [fn () => Scenarios::make('reverse-charge')],
    'line periods only' => [function () {
        $builder = Scenarios::base()->line(fn ($l) => $l->name('Hosting')->quantity(1, 'MON')->netPrice('10.00')->vat(VatCategory::StandardRate, '19')->period('2026-09-01', '2026-09-30'));
        (new ReflectionProperty(InvoiceBuilder::class, 'delivery'))->setValue($builder, null);

        return $builder;
    }],
]);

it('accepts the delivery date as date of supply in custom templates', function () {
    $dir = sys_get_temp_dir().'/quittio-de-template-'.bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents($dir.'/delivery.php', <<<'PHP'
        <?php
        echo $e($invoice->number), $e($invoice->issueDate), $e($invoice->seller->vatId), $e($invoice->delivery->date), $e($invoice->totals->amountDue);
        foreach (['seller', 'buyer'] as $party) { foreach ($invoice->{$party}->address->lines as $line) { echo $e($line); } }
        foreach ($invoice->lines as $line) { echo $e($line->name), $e($line->quantity), $e($line->unit->label); }
        foreach ($invoice->vatRows as $row) { echo $e($row->label), $e($row->amount); }
        foreach ($invoice->paymentTerms->lines as $line) { echo $e($line); }
        PHP);
    $quittio = Quittio::create(['template_paths' => [$dir]], [new CountryDE]);

    expect($quittio->make(Scenarios::make('simple'))->format('html')->template('delivery')->render()->html())->toContain('30.09.2026');
});

it('classifies findings by BMF error class', function () {
    $message = static fn (string $code, ValidationLevel $level): ValidationMessage => new ValidationMessage(Severity::Error, $code, 'x', $level);
    $classes = (new CountryDE)->errorClassification(new ValidationResult([
        $message('XSD', ValidationLevel::L4),
        $message('BR-CO-10', ValidationLevel::L3),
        $message('DE-USTG-03', ValidationLevel::L3),
        $message('IBAN', ValidationLevel::L7),
    ]));

    expect(array_keys($classes))->toBe(['format', 'business_rule', 'content'])
        ->and($classes['content'])->toHaveCount(2)
        ->and(BmfErrorClass::Format->voidsEInvoice())->toBeTrue()
        ->and(BmfErrorClass::Content->label())->toBe('Inhaltsfehler (Rz. 35a)');
});

it('provides legal texts, numbering, retention, dunning and correction rules', function () {
    $pack = new CountryDE;

    expect($pack->legalTexts()->get(LegalTexts::SMALL_BUSINESS))->toBe('Steuerfreie Leistung nach § 19 Abs. 1 UStG (Steuerbefreiung für Kleinunternehmer).')
        ->and($pack->legalTexts()->get(LegalTexts::PREPAYMENT, 'de', ['date' => '01.11.2026']))->toContain('01.11.2026')
        ->and($pack->numberingRules()->defaultPattern)->toBe('RE-{Y}-{seq:4}')
        ->and($pack->retention()->until(Date::of(2026, 10, 3))->toIsoString())->toBe('2034-12-31')
        ->and($pack->dunningLaw()->b2bInterestPoints)->toBe('9.00')
        ->and($pack->dunningLaw()->b2bFlatRate)->toBe('40.00')
        ->and($pack->correctionPolicy()->cancellationStrategy)->toBe(CorrectionPolicy::CANCELLATION_CREDIT_NOTE)
        ->and($pack->correctionPolicy()->title('self_billed', 'de'))->toBe('Gutschrift');
});

it('words a notice that a preset states like the preset', function () {
    $catalog = LegalTexts::catalog();
    $notices = [
        LegalTexts::REVERSE_CHARGE => VatPreset::ReverseChargeEu,
        LegalTexts::INTRA_COMMUNITY_SUPPLY => VatPreset::IntraCommunitySupply,
        LegalTexts::EXPORT => VatPreset::Export,
        LegalTexts::SMALL_BUSINESS => VatPreset::SmallBusiness,
    ];
    foreach ($notices as $key => $preset) {
        foreach (CountryDE::locales() as $locale) {
            expect($catalog->get($key, $locale))->toBe($preset->exemptionReason($locale));
        }
    }

    expect(CountryDE::locales())->toBe(['de', 'en'])
        ->and($catalog->get(LegalTexts::INTRA_COMMUNITY_SUPPLY, 'en'))->toBe('VAT-exempt intra-Community supply (section 4 no. 1b in conjunction with section 6a UStG)')
        ->and($catalog->get(LegalTexts::EXPORT, 'en'))->toBe('VAT-exempt export supply (section 4 no. 1a in conjunction with section 6 UStG)')
        ->and($catalog->get(LegalTexts::REVERSE_CHARGE, 'en'))->toBe('Reverse charge, the recipient is liable for VAT')
        ->and(LegalTexts::text(LegalTexts::SELF_BILLING, 'en_GB'))->toBe('Gutschrift (self-billed invoice)')
        ->and(LegalTexts::localeOf(LegalTexts::SELF_BILLING, 'de-AT'))->toBe('de')
        ->and(LegalTexts::localeOf(LegalTexts::SELF_BILLING, 'fr'))->toBe('de');
});

it('splits default interest at base rate changes', function () {
    $charges = (new DefaultInterest)->calculate(Money::of('1000.00', 'EUR'), Date::of(2025, 5, 1), Date::of(2025, 8, 1));

    expect($charges)->toHaveCount(2)
        ->and($charges[0]->ratePercent->toString())->toBe('11.27')
        ->and($charges[0]->days())->toBe(61)
        ->and($charges[0]->total()->getAmount()->toString())->toBe('18.83')
        ->and($charges[1]->ratePercent->toString())->toBe('10.27')
        ->and($charges[1]->days())->toBe(31)
        ->and($charges[1]->total()->getAmount()->toString())->toBe('8.72')
        ->and($charges[0]->description)->toContain('Basiszinssatz 2,27 %')
        ->and($charges[0]->description)->toContain('vom 01.05.2025 bis 30.06.2025')
        ->and($charges[1]->description)->toContain('vom 01.07.2025 bis 31.07.2025');
});

it('uses consumer points and accepts new base rates', function () {
    $rates = (new BaseRateTable)->withRate('2027-01-01', '1.00');
    $charges = (new DefaultInterest($rates))->calculate(Money::of('100.00', 'EUR'), Date::of(2027, 2, 1), Date::of(2027, 3, 1), consumer: true);

    expect($charges[0]->ratePercent->toString())->toBe('6.00')
        ->and($rates->lastChange()->toIsoString())->toBe('2027-01-01')
        ->and((new BaseRateTable)->rateOn(Date::of(2026, 10, 3))->toString())->toBe('1.52')
        ->and((new DefaultInterest)->flatRate()?->getAmount()->toString())->toBe('40.00');
});

it('counts default interest with the day count of the dunning law', function () {
    $rates = (new BaseRateTable)->withRate('2027-01-01', '1.00')->withRate('2027-07-01', '1.00')->withRate('2028-01-01', '1.00')->withRate('2028-07-01', '1.00');
    $leapYear = (new DefaultInterest($rates))->calculate(Money::of('1000.00', 'EUR'), Date::of(2028, 1, 1), Date::of(2029, 1, 1));
    $basis360 = (new DefaultInterest($rates, new DunningLaw('9.00', '5.00', '40.00', 360)))->calculate(Money::of('1000.00', 'EUR'), Date::of(2028, 1, 1), Date::of(2028, 2, 1));

    expect($leapYear)->toHaveCount(1)
        ->and($leapYear[0]->dayCount)->toBe((new CountryDE)->dunningLaw()->dayCount)
        ->and($leapYear[0]->days())->toBe(366)
        // 1000 × 10 % × 366 / 365
        ->and($leapYear[0]->total()->getAmount()->toString())->toBe('100.27')
        ->and($basis360[0]->dayCount)->toBe(360);
});

it('merges base rate periods with the same rate', function () {
    $periods = (new BaseRateTable)->periods(Date::of(2025, 12, 1), Date::of(2026, 8, 1));
    $charges = (new DefaultInterest)->calculate(Money::of('1000.00', 'EUR'), Date::of(2025, 12, 1), Date::of(2026, 3, 1));

    expect($periods)->toHaveCount(2)
        ->and($periods[0]['from']->toIsoString())->toBe('2025-12-01')
        ->and($periods[0]['until']->toIsoString())->toBe('2026-07-01')
        ->and($periods[0]['rate']->toString())->toBe('1.27')
        ->and($periods[1]['rate']->toString())->toBe('1.52')
        ->and($charges)->toHaveCount(1)
        ->and($charges[0]->description)->toContain('vom 01.12.2025 bis 28.02.2026');
});

it('rejects dates before the known base rates', function () {
    (new BaseRateTable)->rateOn(Date::of(2020, 1, 1));
})->throws(InvalidArgumentException::class);

it('rejects dates after the next scheduled change of the base rate', function () {
    $table = new BaseRateTable;

    expect($table->validUntil()->toIsoString())->toBe('2027-01-01')
        ->and($table->rateOn(Date::of(2026, 12, 31))->toString())->toBe('1.52')
        ->and(fn () => $table->rateOn(Date::of(2027, 1, 1)))->toThrow(InvalidArgumentException::class)
        ->and(fn () => (new DefaultInterest)->calculate(Money::of('100.00', 'EUR'), Date::of(2026, 12, 1), Date::of(2027, 2, 1)))->toThrow(InvalidArgumentException::class)
        ->and((new DefaultInterest)->calculate(Money::of('100.00', 'EUR'), Date::of(2026, 12, 1), Date::of(2027, 1, 1)))->toHaveCount(1);
});

it('adds the business flat rate in the money context of the documents', function () {
    $flat = (new DefaultInterest)->flatRate();

    expect($flat)->not->toBeNull()
        ->and($flat?->plus(Amount::of('5.00', 'EUR'))->getAmount()->toString())->toBe('45.00');
});

it('provides the XRechnung formats', function () {
    $pack = new CountryDE;

    expect(array_map(fn ($format) => $format->key, $pack->formats()))->toBe([XRechnungFormats::CII, XRechnungFormats::UBL])
        ->and($pack->formatAliases())->toBe(['xrechnung' => XRechnungFormats::CII])
        ->and(Quittio::create([], [$pack])->formats()->get('xrechnung')->defaultProfile)->toBe('xrechnung');
});
