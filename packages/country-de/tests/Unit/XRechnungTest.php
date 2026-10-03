<?php

declare(strict_types=1);

use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Data\Payment\PaymentInstructions;
use Quittio\Core\Enums\PaymentMeansCode;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Profiles\Syntax;
use Quittio\Core\Testing\Scenarios;
use Quittio\Core\Tests\Support\Official;
use Quittio\Core\Validation\Drivers\PhpRulesDriver;
use Quittio\Core\Validation\ValidationContext;
use Quittio\Core\Validation\ValidationResult;
use Quittio\Core\Validation\ValidationSubject;
use Quittio\Core\Xml\Cii\CiiWriter;
use Quittio\Core\Xml\SerializationOptions;
use Quittio\Core\Xml\Ubl\UblWriter;
use Quittio\Country\DE\CountryDE;
use Quittio\Country\DE\Profiles\XRechnung\XRechnung30;
use Quittio\Country\DE\Profiles\XRechnung\XRechnung30Extension;

function xrechnung(InvoiceData $invoice, string $locale = 'en', ?Syntax $syntax = null): ValidationResult
{
    $invoice = (new En16931Calculator)->calculate($invoice);

    return (new PhpRulesDriver)->validate(new ValidationSubject(
        $invoice,
        new ValidationContext(new XRechnung30, [new CountryDE], $locale, null, ['syntax' => $syntax]),
    ));
}

function xrCodes(ValidationResult $result): array
{
    return array_values(array_unique(array_map(fn ($m) => $m->code, $result->messages)));
}

it('accepts the example scenarios with a Leitweg-ID', function () {
    $result = xrechnung(Scenarios::simple()->buyerReference('04011000-12345-03')->build());

    expect($result->errors())->toBe([]);
});

it('detects violations of the XRechnung rules', function (Closure $build, string $code) {
    expect(xrCodes(xrechnung($build())))->toContain($code);
})->with([
    'BR-DE-15 missing buyer reference' => [fn () => Scenarios::simple()->buyerReference(null)->build(), 'BR-DE-15'],
    'BR-DE-2 missing seller contact' => [fn () => Scenarios::simple()->build()->with(['seller' => Scenarios::simple()->build()->seller->with(['contact' => null])]), 'BR-DE-2'],
    'BR-DE-6 missing seller phone' => [fn () => Scenarios::simple()->seller(fn ($s) => $s->contact('Max', null, 'a@b.de'))->build(), 'BR-DE-6'],
    'BR-DE-9 missing buyer post code' => [fn () => Scenarios::simple()->buyer(fn ($b) => $b->address('Weg 1', null, 'Ort', 'DE'))->build(), 'BR-DE-9'],
    'BR-DE-1 missing payment instructions' => [fn () => Scenarios::simple()->build()->with(['paymentInstructions' => null]), 'BR-DE-1'],
    'BR-DE-23-a credit transfer without account' => [fn () => Scenarios::simple()->build()->with(['paymentInstructions' => new PaymentInstructions(PaymentMeansCode::SepaCreditTransfer)]), 'BR-DE-23-a'],
    'BR-DE-25-a direct debit without mandate data' => [fn () => Scenarios::simple()->build()->with(['paymentInstructions' => new PaymentInstructions(PaymentMeansCode::SepaDirectDebit)]), 'BR-DE-25-a'],
    'BR-DE-30 direct debit without creditor id' => [fn () => Scenarios::base()->line(fn ($l) => $l->name('A')->quantity(1)->netPrice('10')->vat(VatCategory::StandardRate, '19'))->build()
        ->with(['paymentInstructions' => (new PaymentInstructions(PaymentMeansCode::SepaDirectDebit))->with(['directDebit' => new Quittio\Core\Data\Payment\DirectDebit('M-1', null, 'DE02120300000000202051')])]), 'BR-DE-30'],
    'BR-DE-17 unusual type code' => [fn () => Scenarios::simple()->type('383')->build(), 'BR-DE-17'],
    'BR-DE-26 corrected invoice without reference' => [fn () => Scenarios::simple()->type('384')->build(), 'BR-DE-26'],
    'BR-DE-27 short phone number' => [fn () => Scenarios::simple()->seller(fn ($s) => $s->contact('Max', '12', 'max@muster.de'))->build(), 'BR-DE-27'],
    'BR-DE-18 malformed cash discount line' => [fn () => Scenarios::simple()->paymentTerms(fn ($t) => $t->text("#SKONTO#TAGE=7#PROZENT=2#\n"))->build(), 'BR-DE-18'],
    'BR-DE-TMP-32 no service date' => [fn () => Scenarios::simple()->build()->with(['delivery' => null, 'lines' => array_map(fn ($l) => $l->with(['period' => null]), Scenarios::simple()->build()->lines)]), 'BR-DE-TMP-32'],
]);

it('rejects sub lines in XRechnung CII', function () {
    $result = (new PhpRulesDriver)->validate(new ValidationSubject(
        (new En16931Calculator)->calculate(Scenarios::subLines()->buyerReference('04011000-12345-03')->build()->with(['extended' => null])),
        new ValidationContext(new XRechnung30Extension, [new CountryDE], 'en', null, ['syntax' => Syntax::Cii]),
    ));

    expect(xrCodes($result))->toContain('BR-DEX-15');
});

it('reports XRechnung messages in German', function () {
    $message = array_values(array_filter(xrechnung(Scenarios::simple()->buyerReference(null)->build(), 'de')->messages, fn ($m) => $m->code === 'BR-DE-15'))[0];

    expect($message->message)->toStartWith('Die Käuferreferenz (BT-10) muss übermittelt werden')
        ->and($message->hint)->toContain('buyerReference');
});

it('writes structured cash discounts and the XRechnung identifiers', function () {
    $invoice = (new En16931Calculator)->calculate(Scenarios::simple()->buyerReference('04011000-12345-03')->build());

    $cii = (new CiiWriter)->serialize($invoice, new XRechnung30, new SerializationOptions('de'));

    expect($cii)->toContain('urn:cen.eu:en16931:2017#compliant#urn:xeinkauf.de:kosit:xrechnung_3.0')
        ->and($cii)->toContain("#SKONTO#TAGE=7#PROZENT=2.00#\n")
        ->and($cii)->toContain('<ram:ID>urn:fdc:peppol.eu:2017:poacc:billing:01:1.0</ram:ID>');
});

it('writes a VAT rate of zero for category O in XRechnung', function () {
    $invoice = (new En16931Calculator)->calculate(Scenarios::base()->buyerReference('04011000-12345-03')
        ->seller(fn ($s) => $s->vatId(null))->buyer(fn ($b) => $b->vatId(null))
        ->line(fn ($l) => $l->name('Leistung im Ausland')->quantity(1)->netPrice('100')->vat(VatCategory::NotSubjectToVat, null, 'Nicht im Inland steuerbar', 'VATEX-EU-O'))
        ->build());

    $ubl = (new UblWriter)->serialize($invoice, new XRechnung30);

    expect($ubl)->toMatch('#<cac:TaxSubtotal>.*<cbc:ID>O</cbc:ID>\s*<cbc:Percent>0.00</cbc:Percent>#s');
});

it('passes the official XRechnung validation', function (string $scenario, string $syntax) {
    $builder = Scenarios::make($scenario)->buyerReference('04011000-12345-03');
    $invoice = (new En16931Calculator)->calculate($builder->build());
    $profile = $scenario === 'sub-lines' ? new XRechnung30Extension : new XRechnung30;
    if ($scenario === 'sub-lines') {
        $invoice = $invoice->with(['extended' => null]);
    }
    $xml = $syntax === 'cii' ? (new CiiWriter)->serialize($invoice, $profile) : (new UblWriter)->serialize($invoice, $profile);

    $errors = array_filter(Official::kosit()->validateXml($xml), fn ($m) => $m->isError());

    expect(array_map(fn ($m) => $m->code.': '.$m->message, $errors))->toBe([]);
})->with(function () {
    foreach (Scenarios::names() as $name) {
        if ($name === 'peppol') {
            continue;
        }
        if ($name !== 'sub-lines') {
            yield "{$name} (CII)" => [$name, 'cii'];
        }
        yield "{$name} (UBL)" => [$name, 'ubl'];
    }
})->skip(fn () => ! Official::available(), 'KoSIT validator or Java not available (run tools/schemas/download.sh).')->group('official');
