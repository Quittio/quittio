<?php

declare(strict_types=1);

use Brick\Money\Money;
use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Countries\CorrectionPolicy;
use Quittio\Core\Countries\ResolvedCountries;
use Quittio\Core\Formats\DefaultFormatResolver;
use Quittio\Core\Quittio;
use Quittio\Core\Support\Date;
use Quittio\Core\Testing\Scenarios;
use Quittio\Core\Validation\Severity;
use Quittio\Core\Validation\ValidationLevel;
use Quittio\Core\Validation\ValidationMessage;
use Quittio\Core\Validation\ValidationResult;
use Quittio\Country\DE\CountryDE;
use Quittio\Country\DE\Dunning\BaseRateTable;
use Quittio\Country\DE\Dunning\DefaultInterest;
use Quittio\Country\DE\Layouts\Din5008;
use Quittio\Country\DE\Rules\BmfErrorClass;
use Quittio\Country\DE\Texts\LegalTexts;

function resolveFormat(InvoiceBuilder $builder): array
{
    $pack = new CountryDE;
    $invoice = $builder->build();
    $choice = (new DefaultFormatResolver)->resolve($invoice, new ResolvedCountries($pack));

    return [$choice->format, $choice->profile];
}

it('chooses the format for domestic invoices', function (Closure $builder, string $format, ?string $profile) {
    expect(resolveFormat($builder()))->toBe([$format, $profile]);
})->with([
    'business' => [fn () => Scenarios::make('simple'), 'zugferd', 'en16931'],
    'public buyer' => [fn () => Scenarios::make('simple')->buyerReference('04011000-12345-03'), 'xrechnung-cii', 'xrechnung'],
    'consumer' => [fn () => Scenarios::make('b2c-gross'), 'pdf', null],
    'small amount' => [fn () => Scenarios::make('simple')->smallAmount(), 'pdf', null],
]);

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

    expect($formB)->toContain('.letterhead td { height: 25mm; }')
        ->and($formB)->toContain('top: 105mm;')
        ->and($formB)->toContain('top: 148.5mm;')
        ->and($formA)->toContain('.letterhead td { height: 7mm; }')
        ->and($formA)->toContain('top: 87mm;');
});

it('lists the German mandatory output', function () {
    $pack = new CountryDE;
    $keys = static fn ($list): array => array_map(static fn ($f): string => $f->accessKey, $list->fields);

    expect($keys($pack->mandatoryOutput(Scenarios::make('simple')->build())))->toBe(['seller.vatId|seller.taxNumber', 'servicePeriod|lineGroups'])
        ->and($keys($pack->mandatoryOutput(Scenarios::make('reverse-charge')->build())))->toContain('buyer.vatId')
        ->and($keys($pack->mandatoryOutput(Scenarios::make('simple')->selfBilled()->build())))->toContain('title');
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

it('splits default interest at base rate changes', function () {
    $charges = (new DefaultInterest)->calculate(Money::of('1000.00', 'EUR'), Date::of(2025, 5, 1), Date::of(2025, 8, 1));

    expect($charges)->toHaveCount(2)
        ->and($charges[0]->ratePercent->toString())->toBe('11.27')
        ->and($charges[0]->days())->toBe(61)
        ->and($charges[0]->total()->getAmount()->toString())->toBe('18.83')
        ->and($charges[1]->ratePercent->toString())->toBe('10.27')
        ->and($charges[1]->days())->toBe(31)
        ->and($charges[1]->total()->getAmount()->toString())->toBe('8.72')
        ->and($charges[0]->description)->toContain('Basiszinssatz 2,27 %');
});

it('uses consumer points and accepts new base rates', function () {
    $rates = (new BaseRateTable)->withRate('2027-01-01', '1.00');
    $charges = (new DefaultInterest($rates))->calculate(Money::of('100.00', 'EUR'), Date::of(2027, 2, 1), Date::of(2027, 3, 1), consumer: true);

    expect($charges[0]->ratePercent->toString())->toBe('6.00')
        ->and($rates->lastChange()->toIsoString())->toBe('2027-01-01')
        ->and((new BaseRateTable)->rateOn(Date::of(2026, 10, 3))->toString())->toBe('1.52')
        ->and((new DefaultInterest)->flatRate()?->getAmount()->toString())->toBe('40.00');
});

it('rejects dates before the known base rates', function () {
    (new BaseRateTable)->rateOn(Date::of(2020, 1, 1));
})->throws(InvalidArgumentException::class);
