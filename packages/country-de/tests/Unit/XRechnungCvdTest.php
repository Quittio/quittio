<?php

declare(strict_types=1);

use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Enums\UnitCode;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Profiles\Profile;
use Quittio\Core\Profiles\Syntax;
use Quittio\Core\Quittio;
use Quittio\Core\Testing\Scenarios;
use Quittio\Core\Tests\Support\Official;
use Quittio\Core\Validation\Drivers\PhpRulesDriver;
use Quittio\Core\Validation\ValidationContext;
use Quittio\Core\Validation\ValidationLevel;
use Quittio\Core\Validation\ValidationResult;
use Quittio\Core\Validation\ValidationSubject;
use Quittio\Core\Xml\Cii\CiiWriter;
use Quittio\Core\Xml\Ubl\UblWriter;
use Quittio\Country\DE\CountryDE;
use Quittio\Country\DE\Profiles\XRechnung\XRechnung30;
use Quittio\Country\DE\Profiles\XRechnung\XRechnung30Cvd;
use Quittio\Country\DE\Tests\Support\German;

/*
 * XRechnung CVD (Clean Vehicles Directive): the profile xrechnung-cvd and the rules BR-DE-CVD-01 to 06-b.
 */

const CVD_RULES = ['BR-DE-CVD-01', 'BR-DE-CVD-02', 'BR-DE-CVD-03', 'BR-DE-CVD-04', 'BR-DE-CVD-05', 'BR-DE-CVD-06-a', 'BR-DE-CVD-06-b'];

/**
 * A complete XRechnung CVD invoice: contract and tender reference, one vehicle line, one service line.
 */
function cvdInvoice(): InvoiceBuilder
{
    return Scenarios::base()
        ->buyerReference('04011000-12345-03')
        ->contractReference('V-2026-0017')
        ->tenderReference('LOS-3')
        ->line(fn ($l) => $l->name('Elektro-Kleinbus')->quantity(1, UnitCode::Piece)->netPrice('68000.00')->vat(VatCategory::StandardRate, '19')
            ->classification('M2', 'CVD')
            ->attribute('cva', 'zero-emission'))
        ->line(fn ($l) => $l->name('Einweisung')->quantity(2, UnitCode::Hour)->netPrice('95.00')->vat(VatCategory::StandardRate, '19'));
}

function cvdValidate(InvoiceData $invoice, string $locale = 'en', ?Profile $profile = null): ValidationResult
{
    return (new PhpRulesDriver)->validate(new ValidationSubject(
        (new En16931Calculator)->calculate($invoice),
        new ValidationContext($profile ?? new XRechnung30Cvd, [new CountryDE], $locale, null, ['syntax' => Syntax::Ubl]),
    ));
}

/**
 * @return list<string>
 */
function cvdCodes(ValidationResult $result): array
{
    return array_values(array_unique(array_map(fn ($m) => $m->code, $result->messages)));
}

it('registers the profile xrechnung-cvd for both XRechnung formats', function () {
    $quittio = Quittio::create([], [new CountryDE]);
    $profile = $quittio->profiles()->get('xrechnung-cvd');

    expect($profile)->toBeInstanceOf(XRechnung30Cvd::class)
        ->and($profile->name())->toBe('XRechnung 3.0 CVD')
        ->and($profile->specificationId())->toBe('urn:cen.eu:en16931:2017#compliant#urn:xeinkauf.de:kosit:xrechnung_3.0#compliant#urn:xeinkauf.de:kosit:xrechnung:cvd_0.9')
        ->and($profile->syntaxes())->toBe([Syntax::Cii, Syntax::Ubl])
        ->and($quittio->profiles()->bySpecificationId(XRechnung30Cvd::SPECIFICATION_ID))->toBe($profile)
        ->and($quittio->formats()->get('xrechnung-cii')->allowsProfile('xrechnung-cvd'))->toBeTrue()
        ->and($quittio->formats()->get('xrechnung-ubl')->allowsProfile('xrechnung-cvd'))->toBeTrue();
});

it('accepts a complete XRechnung CVD invoice', function () {
    $result = cvdValidate(cvdInvoice()->build());

    expect($result->errors())->toBe([])
        ->and(array_intersect(cvdCodes($result), [...CVD_RULES, 'BR-CL-13']))->toBe([]);
});

it('detects violations of the XRechnung CVD rules', function (Closure $build, string $code) {
    $result = cvdValidate($build());
    $finding = array_values(array_filter($result->messages, fn ($m) => $m->code === $code))[0] ?? null;

    expect($finding)->not->toBeNull()
        ->and($finding?->severity->value)->toBe('error');
})->with([
    'BR-DE-CVD-01 missing contract reference' => [fn () => cvdInvoice()->contractReference(null)->build(), 'BR-DE-CVD-01'],
    'BR-DE-CVD-02 missing tender reference' => [fn () => cvdInvoice()->tenderReference(null)->build(), 'BR-DE-CVD-02'],
    'BR-DE-CVD-03 no vehicle line' => [fn () => Scenarios::simple()->buyerReference('04011000-12345-03')->contractReference('V-1')->tenderReference('LOS-1')->build(), 'BR-DE-CVD-03'],
    'BR-DE-CVD-03 category and attribute on different lines' => [fn () => Scenarios::base()->buyerReference('04011000-12345-03')->contractReference('V-1')->tenderReference('LOS-1')
        ->line(fn ($l) => $l->name('Bus')->quantity(1)->netPrice('1000')->vat(VatCategory::StandardRate, '19')->classification('M3', 'CVD'))
        ->line(fn ($l) => $l->name('Lkw')->quantity(1)->netPrice('1000')->vat(VatCategory::StandardRate, '19')->attribute('cva', 'clean'))
        ->build(), 'BR-DE-CVD-03'],
    'BR-DE-CVD-04 unknown vehicle category' => [fn () => cvdInvoice()->line(fn ($l) => $l->name('Motorrad')->quantity(1)->netPrice('1000')->vat(VatCategory::StandardRate, '19')
        ->classification('L3', 'CVD')->attribute('cva', 'other'))->build(), 'BR-DE-CVD-04'],
    'BR-DE-CVD-05 unknown vehicle type' => [fn () => cvdInvoice()->line(fn ($l) => $l->name('Lkw')->quantity(1)->netPrice('1000')->vat(VatCategory::StandardRate, '19')
        ->classification('N3', 'CVD')->attribute('cva', 'diesel'))->build(), 'BR-DE-CVD-05'],
    'BR-DE-CVD-06-a two cva attributes' => [fn () => cvdInvoice()->line(fn ($l) => $l->name('Lkw')->quantity(1)->netPrice('1000')->vat(VatCategory::StandardRate, '19')
        ->classification('N2', 'CVD')->attribute('cva', 'clean')->attribute('cva', 'other'))->build(), 'BR-DE-CVD-06-a'],
    'BR-DE-CVD-06-a category without cva attribute' => [fn () => cvdInvoice()->line(fn ($l) => $l->name('Lkw')->quantity(1)->netPrice('1000')->vat(VatCategory::StandardRate, '19')
        ->classification('N2', 'CVD'))->build(), 'BR-DE-CVD-06-a'],
    'BR-DE-CVD-06-b two vehicle categories' => [fn () => cvdInvoice()->line(fn ($l) => $l->name('Lkw')->quantity(1)->netPrice('1000')->vat(VatCategory::StandardRate, '19')
        ->classification('N1', 'CVD')->classification('N2', 'CVD')->attribute('cva', 'clean'))->build(), 'BR-DE-CVD-06-b'],
    'BR-DE-CVD-06-b cva attribute without category' => [fn () => cvdInvoice()->line(fn ($l) => $l->name('Lkw')->quantity(1)->netPrice('1000')->vat(VatCategory::StandardRate, '19')
        ->attribute('cva', 'clean'))->build(), 'BR-DE-CVD-06-b'],
]);

it('points the CVD findings to the line and the value', function () {
    $result = cvdValidate(cvdInvoice()->line(fn ($l) => $l->name('Lkw')->quantity(1)->netPrice('1000')->vat(VatCategory::StandardRate, '19')
        ->classification('X9', 'CVD')->attribute('cva', 'diesel'))->build());
    $byCode = [];
    foreach ($result->messages as $message) {
        $byCode[$message->code] = $message;
    }

    expect($byCode['BR-DE-CVD-04']->path)->toBe('line 3.item.classifications[0].code')
        ->and($byCode['BR-DE-CVD-04']->bt)->toBe('BT-158')
        ->and($byCode['BR-DE-CVD-04']->message)->toContain('"X9"')->toEndWith('Line 3.')
        ->and($byCode['BR-DE-CVD-05']->path)->toBe('line 3.item.attributes[0].value')
        ->and($byCode['BR-DE-CVD-05']->message)->toContain('"diesel"');
});

it('applies the CVD rules only with the CVD specification identifier', function () {
    $invoice = Scenarios::simple()->buyerReference('04011000-12345-03')->build();

    expect(array_intersect(cvdCodes(cvdValidate($invoice, profile: new XRechnung30)), CVD_RULES))->toBe([])
        ->and(array_values(array_intersect(cvdCodes(cvdValidate($invoice)), CVD_RULES)))->toBe(['BR-DE-CVD-01', 'BR-DE-CVD-02', 'BR-DE-CVD-03']);
});

it('reports XRechnung CVD messages in German', function () {
    $message = array_values(array_filter(cvdValidate(cvdInvoice()->contractReference(null)->build(), 'de')->messages, fn ($m) => $m->code === 'BR-DE-CVD-01'))[0];

    expect($message->message)->toBe('Die Vertragsreferenz (BT-12) muss übermittelt werden.')
        ->and($message->hint)->toContain('contractReference');
});

it('writes and reads the vehicle category and type in CII and UBL', function (string $syntax) {
    $invoice = (new En16931Calculator)->calculate(cvdInvoice()->build());
    $xml = $syntax === 'cii' ? (new CiiWriter)->serialize($invoice, new XRechnung30Cvd) : (new UblWriter)->serialize($invoice, new XRechnung30Cvd);
    $read = German::reader()->read($xml);

    expect($xml)->toContain(XRechnung30Cvd::SPECIFICATION_ID)
        ->and($xml)->toContain($syntax === 'cii' ? '<ram:ClassCode listID="CVD">M2</ram:ClassCode>' : '<cbc:ItemClassificationCode listID="CVD">M2</cbc:ItemClassificationCode>')
        ->and($read->profile)->toBe('xrechnung-cvd')
        ->and($read->data->contractReference)->toBe('V-2026-0017')
        ->and($read->data->tenderReference)->toBe('LOS-3')
        ->and($read->data->lines[0]->item->classifications[0]->listId->value)->toBe('CVD')
        ->and($read->data->lines[0]->item->attributes[0]->name)->toBe('cva')
        ->and($read->data->lines[0]->item->attributes[0]->value)->toBe('zero-emission');
})->with(['cii', 'ubl']);

it('passes the official KoSIT validation for rendered XRechnung CVD', function (string $format) {
    $quittio = Quittio::create([], [new CountryDE])->addValidator(Official::kosit());
    $result = $quittio->make(cvdInvoice())->format($format)->profile('xrechnung-cvd')->render();

    expect($result->profile()?->key())->toBe('xrechnung-cvd')
        ->and($result->validation()->levels)->toContain(ValidationLevel::L5)
        ->and($result->validation()->errors())->toBe([]);
})->with(['xrechnung-cii', 'xrechnung-ubl', 'zugferd'])->group('official')->skip(fn () => ! Official::available(), 'KoSIT validator is not available.');

it('passes the official KoSIT validation for cancelled XRechnung CVD invoices', function (string $strategy, string $format) {
    $quittio = Quittio::create([], [new CountryDE])->addValidator(Official::kosit());
    $original = $quittio->make(cvdInvoice())->format('xrechnung-cii')->profile('xrechnung-cvd')->render()->data();
    $result = $quittio->make($quittio->cancellation($original, 'ST-2026-0003', 'Test', $strategy))->format($format)->profile('xrechnung-cvd')->render();

    expect($result->validation()->levels)->toContain(ValidationLevel::L5)
        ->and($result->validation()->errors())->toBe([]);
})->with(['credit_note', 'negative_invoice'])->with(['xrechnung-cii', 'xrechnung-ubl'])
    ->group('official')->skip(fn () => ! Official::available(), 'KoSIT validator is not available.');

it('finds the same CVD violations as the official KoSIT validation', function (Closure $build, array $codes, string $syntax) {
    $invoice = (new En16931Calculator)->calculate($build());
    $xml = $syntax === 'cii' ? (new CiiWriter)->serialize($invoice, new XRechnung30Cvd) : (new UblWriter)->serialize($invoice, new XRechnung30Cvd);
    $official = array_values(array_unique(array_map(fn ($m) => $m->code, array_filter(Official::kosit()->validateXml($xml), fn ($m) => $m->isError()))));
    $php = array_values(array_intersect(cvdCodes(cvdValidate($invoice)), CVD_RULES));

    sort($official);
    expect($official)->toBe($codes)
        ->and($php)->toBe($codes);
})->with([
    'missing references' => [fn () => cvdInvoice()->contractReference(null)->tenderReference(null)->build(), ['BR-DE-CVD-01', 'BR-DE-CVD-02']],
    'no vehicle line' => [fn () => Scenarios::simple()->buyerReference('04011000-12345-03')->contractReference('V-1')->tenderReference('LOS-1')->build(), ['BR-DE-CVD-03']],
    'wrong category and type' => [fn () => cvdInvoice()->line(fn ($l) => $l->name('Lkw')->quantity(1)->netPrice('1000')->vat(VatCategory::StandardRate, '19')
        ->classification('L3', 'CVD')->attribute('cva', 'diesel'))->build(), ['BR-DE-CVD-04', 'BR-DE-CVD-05']],
    'cardinality on the line' => [fn () => cvdInvoice()
        ->line(fn ($l) => $l->name('Lkw')->quantity(1)->netPrice('1000')->vat(VatCategory::StandardRate, '19')->classification('N2', 'CVD'))
        ->line(fn ($l) => $l->name('Bus')->quantity(1)->netPrice('1000')->vat(VatCategory::StandardRate, '19')->attribute('cva', 'clean'))
        ->build(), ['BR-DE-CVD-06-a', 'BR-DE-CVD-06-b']],
])->with(['cii', 'ubl'])->group('official')->skip(fn () => ! Official::available(), 'KoSIT validator is not available.');
