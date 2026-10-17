<?php

declare(strict_types=1);

use Brick\Money\Context\CustomContext;
use Brick\Money\Money;
use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Data\Extensions\ThirdPartyPayment;
use Quittio\Core\Data\Extensions\XRechnungExtensionData;
use Quittio\Core\Data\Identifier;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Enums\MimeType;
use Quittio\Core\Profiles\Profile;
use Quittio\Core\Profiles\Syntax;
use Quittio\Core\Support\Amount;
use Quittio\Core\Testing\Scenarios;
use Quittio\Core\Tests\Support\Official;
use Quittio\Core\Validation\Drivers\KositReportParser;
use Quittio\Core\Validation\Drivers\PhpRulesDriver;
use Quittio\Core\Validation\Rule;
use Quittio\Core\Validation\ValidationContext;
use Quittio\Core\Validation\ValidationMessage;
use Quittio\Core\Validation\ValidationResult;
use Quittio\Core\Validation\ValidationSubject;
use Quittio\Core\Xml\Ubl\UblWriter;
use Quittio\Country\DE\CountryDE;
use Quittio\Country\DE\Profiles\XRechnung\XRechnung30;
use Quittio\Country\DE\Profiles\XRechnung\XRechnung30Extension;
use Quittio\Country\DE\Rules\XRechnungRules;

/*
 * Rules of the XRechnung extension (BR-DEX-02 to BR-DEX-14), checked on the data model.
 */

/**
 * An extension invoice with sub lines, a third party payment and the schemes XR01 to XR03.
 */
function dexInvoice(): InvoiceBuilder
{
    return Scenarios::subLines()
        ->buyerReference('04011000-12345-03')
        ->seller(fn ($s) => $s->identifier('DIGA-4711', 'XR01')->legalRegistration('HRB 12345', '0002'))
        ->buyer(fn ($b) => $b->electronicAddress('ap@kunde.de', 'XR02'))
        ->deliverTo('Lager', null, Identifier::of('LAGER-1', 'XR03'))
        ->xrechnungExtension(new XRechnungExtensionData([new ThirdPartyPayment('Porto', Amount::of('4.90', 'EUR'), 'Briefporto')]));
}

function dexCalculated(?InvoiceBuilder $builder = null): InvoiceData
{
    return (new En16931Calculator)->calculate(($builder ?? dexInvoice())->build()->with(['extended' => null]));
}

function dexValidate(InvoiceData $invoice, ?Syntax $syntax = Syntax::Ubl, ?Profile $profile = null): ValidationResult
{
    return (new PhpRulesDriver)->validate(new ValidationSubject(
        $invoice,
        new ValidationContext($profile ?? new XRechnung30Extension, [new CountryDE], 'en', null, ['syntax' => $syntax]),
    ));
}

/**
 * @return list<string>
 */
function dexCodes(ValidationResult $result): array
{
    return array_values(array_unique(array_map(fn (ValidationMessage $m): string => $m->code, $result->messages)));
}

/**
 * @param  list<ThirdPartyPayment>  $payments
 */
function dexWithPayments(array $payments): InvoiceData
{
    return dexCalculated()->with(['xrechnungExtension' => new XRechnungExtensionData($payments)]);
}

it('accepts a valid extension invoice with sub lines, third party payments and the schemes XR01 to XR03', function (?Syntax $syntax) {
    $result = dexValidate(dexCalculated(), $syntax);

    expect($result->errors())->toBe([])
        ->and(array_filter(dexCodes($result), fn (string $code): bool => str_starts_with($code, 'BR-DEX') || str_starts_with($code, 'BR-CL')))->toBe([]);
})->with(['UBL' => [Syntax::Ubl], 'unknown syntax' => [null]]);

it('reports the extension rules with the severity of the official Schematron', function (Closure $invoice, string $code, string $severity, string $path) {
    $findings = array_values(array_filter(dexValidate($invoice())->messages, fn (ValidationMessage $m): bool => $m->code === $code));

    expect($findings)->not->toBe([])
        ->and($findings[0]->severity->value)->toBe($severity)
        ->and($findings[0]->path)->toBe($path);
})->with([
    'BR-DEX-02 line net amount differs from its sub lines' => [
        fn () => dexCalculated()->with(['lines' => [dexCalculated()->lines[0]->with(['netAmount' => Amount::of('1000.00', 'EUR')]), dexCalculated()->lines[1]]]),
        'BR-DEX-02', 'warning', 'line 1.netAmount',
    ],
    'BR-DEX-04 unknown seller identifier scheme' => [fn () => dexCalculated(dexInvoice()->seller(fn ($s) => $s->identifier('X-1', 'XX99'))), 'BR-DEX-04', 'error', 'seller.identifiers[1].scheme'],
    'BR-DEX-04 SEPA for the buyer' => [fn () => dexCalculated()->with(['buyer' => dexCalculated()->buyer->with(['identifier' => Identifier::of('DE98ZZZ09999999999', 'SEPA')])]), 'BR-DEX-04', 'error', 'buyer.identifier.scheme'],
    'BR-DEX-05 unknown legal registration scheme' => [fn () => dexCalculated(dexInvoice()->seller(fn ($s) => $s->legalRegistration('HRB 12345', 'XX99'))), 'BR-DEX-05', 'error', 'seller.legalRegistration.scheme'],
    'BR-DEX-06 unknown item standard identifier scheme' => [
        fn () => dexCalculated(dexInvoice()->line(fn ($l) => $l->name('Kabel')->quantity(1)->netPrice('5.00')->vat(Quittio\Core\Enums\VatCategory::StandardRate, '19')->standardId('4711', 'XX99'))),
        'BR-DEX-06', 'error', 'line 3.item.standardId.scheme',
    ],
    'BR-DEX-07 unknown electronic address scheme' => [fn () => dexCalculated(dexInvoice()->seller(fn ($s) => $s->electronicAddress('rechnung@muster.de', 'XX99'))), 'BR-DEX-07', 'error', 'seller.electronicAddress.scheme'],
    'BR-DEX-08 unknown delivery location scheme' => [fn () => dexCalculated(dexInvoice()->deliverTo('Lager', null, Identifier::of('LAGER-1', 'XX99'))), 'BR-DEX-08', 'error', 'delivery.locationId.scheme'],
    'BR-DEX-09 amount due without the third party payment' => [
        fn () => dexCalculated()->with(['totals' => dexCalculated()->totals?->with(['amountDue' => dexCalculated()->totals->grossTotal])]),
        'BR-DEX-09', 'error', 'totals.amountDue',
    ],
    'BR-DEX-10 third party payment without type' => [fn () => dexWithPayments([new ThirdPartyPayment(' ', Amount::of('4.90', 'EUR'), 'Briefporto')]), 'BR-DEX-10', 'error', 'xrechnungExtension.thirdPartyPayments[0].type'],
    'BR-DEX-12 third party payment without description' => [fn () => dexWithPayments([new ThirdPartyPayment('Porto', Amount::of('4.90', 'EUR'), '')]), 'BR-DEX-12', 'error', 'xrechnungExtension.thirdPartyPayments[0].description'],
    'BR-DEX-14 third party payment in another currency' => [fn () => dexWithPayments([new ThirdPartyPayment('Porto', Amount::of('4.90', 'USD'), 'Briefporto')]), 'BR-DEX-14', 'error', 'xrechnungExtension.thirdPartyPayments[0].amount'],
]);

// Amounts have two decimals (Amount::of()); a Money with more cannot be serialized, so the whole
// run stops at QUITTIO-INVALID-CHARACTERS (InvoiceData::toArray()). The rule itself checks the data.
it('reports BR-DEX-13 for a third party payment amount with more than two decimals', function () {
    $rule = array_values(array_filter(XRechnungRules::all(), fn (Rule $rule): bool => $rule->code() === 'BR-DEX-13'))[0];
    $invoice = dexWithPayments([new ThirdPartyPayment('Porto', Money::of('4.905', 'EUR', new CustomContext(3)), 'Briefporto')]);

    $findings = [...$rule->check($invoice, new ValidationContext(new XRechnung30Extension, [new CountryDE], 'en', null, ['syntax' => Syntax::Ubl]))];

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->severity->value)->toBe('error')
        ->and($findings[0]->path)->toBe('xrechnungExtension.thirdPartyPayments[0].amount')
        ->and($findings[0]->message)->toContain('4.905');
});

it('accepts SEPA as scheme of seller and payee identifiers in UBL only', function () {
    $invoice = dexCalculated(dexInvoice()->seller(fn ($s) => $s->identifier('DE98ZZZ09999999999', 'SEPA')));

    expect(dexCodes(dexValidate($invoice)))->not->toContain('BR-DEX-04')
        ->and(dexCodes(dexValidate($invoice, Syntax::Cii)))->toContain('BR-DEX-04');
});

it('reports the official extension codes instead of the replaced rules', function () {
    $schemes = dexCalculated(dexInvoice()
        ->seller(fn ($s) => $s->identifier('X-1', 'XX99')->legalRegistration('HRB 12345', 'XX99')->electronicAddress('rechnung@muster.de', 'XX99'))
        ->deliverTo('Lager', null, Identifier::of('LAGER-1', 'XX99')));
    $amountDue = dexCalculated()->with(['totals' => dexCalculated()->totals?->with(['amountDue' => dexCalculated()->totals->grossTotal])]);
    $subLines = dexCalculated()->with(['lines' => [dexCalculated()->lines[0]->with(['netAmount' => Amount::of('1000.00', 'EUR')]), dexCalculated()->lines[1]]]);

    expect(dexCodes(dexValidate($schemes)))->toContain('BR-DEX-04', 'BR-DEX-05', 'BR-DEX-07', 'BR-DEX-08')
        ->not->toContain('BR-CL-10', 'BR-CL-11', 'BR-CL-25', 'BR-CL-26')
        ->and(dexCodes(dexValidate($schemes, Syntax::Ubl, new XRechnung30)))->toContain('BR-CL-10', 'BR-CL-11', 'BR-CL-25', 'BR-CL-26')
        ->not->toContain('BR-DEX-04', 'BR-DEX-05', 'BR-DEX-07', 'BR-DEX-08')
        ->and(dexCodes(dexValidate($amountDue)))->toContain('BR-DEX-09')->not->toContain('BR-CO-16')
        ->and(dexCodes(dexValidate($subLines)))->toContain('BR-DEX-02')->not->toContain('QUITTIO-SUBLINES');
});

it('keeps BR-CO-16 and QUITTIO-SUBLINES for the extension in CII, which has no BR-DEX-09 and BR-DEX-02', function () {
    $amountDue = dexCalculated(Scenarios::simple()->buyerReference('04011000-12345-03'));
    $amountDue = $amountDue->with(['totals' => $amountDue->totals?->with(['amountDue' => Amount::of('1.00', 'EUR')])]);
    $subLines = dexCalculated()->with(['xrechnungExtension' => null, 'lines' => [dexCalculated()->lines[0]->with(['netAmount' => Amount::of('1000.00', 'EUR')]), dexCalculated()->lines[1]]]);

    expect(dexCodes(dexValidate($amountDue, Syntax::Cii)))->toContain('BR-CO-16')->not->toContain('BR-DEX-09')
        ->and(dexCodes(dexValidate($subLines, Syntax::Cii)))->toContain('QUITTIO-SUBLINES', 'BR-DEX-15')->not->toContain('BR-DEX-02');
});

it('accepts XML attachments only in the extension (BR-DEX-01), the other XRechnung profiles report BR-CL-24', function (?Syntax $syntax) {
    $invoice = dexCalculated(dexInvoice()->attachment('ATT-1', '<data/>', MimeType::Xml, 'data.xml', 'Data'));

    expect(dexCodes(dexValidate($invoice, $syntax)))->not->toContain('BR-CL-24', 'BR-DEX-01')
        ->and(dexValidate($invoice, $syntax)->errors())->toBe([])
        ->and(dexCodes(dexValidate($invoice, $syntax, new XRechnung30)))->toContain('BR-CL-24');
})->with(['UBL' => [Syntax::Ubl], 'unknown syntax' => [null]]);

it('reports the extension messages in German', function () {
    $result = (new PhpRulesDriver)->validate(new ValidationSubject(
        dexWithPayments([new ThirdPartyPayment('Porto', Amount::of('4.90', 'USD'), 'Briefporto')]),
        new ValidationContext(new XRechnung30Extension, [new CountryDE], 'de', null, ['syntax' => Syntax::Ubl]),
    ));
    $message = array_values(array_filter($result->messages, fn (ValidationMessage $m): bool => $m->code === 'BR-DEX-14'))[0];

    expect($message->message)->toBe('Der Betrag der Zahlung an Dritte (BT-DEX-002) ist in USD angegeben, muss aber in der Rechnungswährung (BT-5) EUR angegeben werden.');
});

it('passes the official validation with the data the extension rules accept', function () {
    $invoice = dexCalculated();
    $kosit = Official::kosit();
    $kosit->validateXml((new UblWriter)->serialize($invoice, new XRechnung30Extension));
    // The report lists BR-CO-16 and BR-CL-10, -25, -26 with their CEN level; the scenario of the
    // extension lowers them to information (customLevel), so the acceptance decides.
    $report = (new KositReportParser)->parse((string) $kosit->lastReport());

    expect(dexValidate($invoice)->errors())->toBe([])
        ->and($report['scenario'])->toBe('EN16931 XRechnung Extension (UBL Invoice)')
        ->and($report['accepted'])->toBeTrue();
})->skip(fn () => ! Official::available(), 'KoSIT validator or Java not available (run tools/schemas/download.sh).')->group('official');

it('passes the official validation of the extension with an XML attachment, which XRechnung reports with BR-CL-24', function () {
    $invoice = dexCalculated(dexInvoice()->attachment('ATT-1', '<data/>', MimeType::Xml, 'data.xml', 'Data'));
    $kosit = Official::kosit();
    $messages = $kosit->validateXml((new UblWriter)->serialize($invoice, new XRechnung30Extension));
    $report = (new KositReportParser)->parse((string) $kosit->lastReport());
    $plain = dexCalculated(Scenarios::simple()->buyerReference('04011000-12345-03')->attachment('ATT-1', '<data/>', MimeType::Xml, 'data.xml', 'Data'));
    $plainMessages = $kosit->validateXml((new UblWriter)->serialize($plain, new XRechnung30));

    expect(array_map(fn ($m) => $m->code, $messages))->not->toContain('BR-DEX-01')
        ->and($report['scenario'])->toBe('EN16931 XRechnung Extension (UBL Invoice)')
        ->and($report['accepted'])->toBeTrue()
        ->and(array_map(fn ($m) => $m->code, $plainMessages))->toContain('BR-CL-24')
        ->and(dexCodes(dexValidate($plain, Syntax::Ubl, new XRechnung30)))->toContain('BR-CL-24');
})->skip(fn () => ! Official::available(), 'KoSIT validator or Java not available (run tools/schemas/download.sh).')->group('official');

it('reports what the official validation reports for the extension', function (Closure $invoice, string $code) {
    $data = $invoice();
    $official = array_map(fn ($m) => $m->code, Official::kosit()->validateXml((new UblWriter)->serialize($data, new XRechnung30Extension)));

    expect($official)->toContain($code)
        ->and(dexCodes(dexValidate($data)))->toContain($code);
})->with([
    'BR-DEX-02' => [fn () => dexCalculated()->with(['lines' => [dexCalculated()->lines[0]->with(['netAmount' => Amount::of('1000.00', 'EUR')]), dexCalculated()->lines[1]]]), 'BR-DEX-02'],
    'BR-DEX-04' => [fn () => dexCalculated(dexInvoice()->seller(fn ($s) => $s->identifier('X-1', 'XX99'))), 'BR-DEX-04'],
    'BR-DEX-07' => [fn () => dexCalculated(dexInvoice()->seller(fn ($s) => $s->electronicAddress('rechnung@muster.de', 'XX99'))), 'BR-DEX-07'],
    'BR-DEX-08' => [fn () => dexCalculated(dexInvoice()->deliverTo('Lager', null, Identifier::of('LAGER-1', 'XX99'))), 'BR-DEX-08'],
    'BR-DEX-09' => [fn () => dexCalculated()->with(['totals' => dexCalculated()->totals?->with(['amountDue' => dexCalculated()->totals->grossTotal])]), 'BR-DEX-09'],
    'BR-DEX-12' => [fn () => dexWithPayments([new ThirdPartyPayment('Porto', Amount::of('4.90', 'EUR'), ' ')]), 'BR-DEX-12'],
    'BR-DEX-14' => [fn () => dexWithPayments([new ThirdPartyPayment('Porto', Amount::of('4.90', 'USD'), 'Briefporto')]), 'BR-DEX-14'],
])->skip(fn () => ! Official::available(), 'KoSIT validator or Java not available (run tools/schemas/download.sh).')->group('official');
