<?php

declare(strict_types=1);

use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Data\Party\Address;
use Quittio\Core\Enums\UnitCode;
use Quittio\Core\Pdf\PdfEmbeddedFileExtractor;
use Quittio\Core\Quittio;
use Quittio\Core\Testing\Scenarios;
use Quittio\Core\Tests\Support\Official;
use Quittio\Core\Validation\ValidationLevel;
use Quittio\Country\DE\CountryDE;
use Quittio\Country\DE\Tax\VatPreset;

function germany(array $config = []): Quittio
{
    return Quittio::create($config, [new CountryDE]);
}

it('renders ZUGFeRD and XRechnung from one calculation with German defaults', function () {
    $result = germany()->make(Scenarios::make('simple')->buyerReference('04011000-12345-03'))
        ->formats(['zugferd', 'xrechnung-cii', 'xrechnung-ubl'])
        ->render();

    expect($result->filename())->toBe('Rechnung_RE-2026-0042.pdf')
        ->and($result->validation()->isValid())->toBeTrue()
        ->and($result->as('xrechnung-cii')->profile()?->key())->toBe('xrechnung')
        ->and($result->as('xrechnung-cii')->xml())->toContain('urn:xeinkauf.de:kosit:xrechnung_3.0')
        ->and($result->as('xrechnung-ubl')->xml())->toContain('<cbc:CustomizationID>urn:cen.eu:en16931:2017#compliant#urn:xeinkauf.de:kosit:xrechnung_3.0</cbc:CustomizationID>')
        ->and($result->validation()->has('QUITTIO-GENERIC-COUNTRY'))->toBeFalse();
});

it('embeds XRechnung into a PDF as xrechnung.xml', function () {
    $result = germany()->make(Scenarios::make('simple')->buyerReference('04011000-12345-03'))->format('zugferd')->profile('xrechnung')->render();

    expect((new PdfEmbeddedFileExtractor)->extract($result->pdf())[0] ?? null)->toBe('xrechnung.xml');
});

it('reports German rules while rendering', function () {
    $result = germany()->make(Scenarios::make('simple')->buyerReference(null))->format('xrechnung-cii')->validate(throwOnError: false)->render();

    expect($result->validation()->has('BR-DE-15'))->toBeTrue();
});

it('passes the official KoSIT validation for rendered XRechnung', function (string $format) {
    $quittio = germany()->addValidator(Official::kosit());
    $result = $quittio->make(Scenarios::make('mixed-vat')->buyerReference('04011000-12345-03'))->format($format)->render();

    expect($result->validation()->levels)->toContain(ValidationLevel::L5)
        ->and($result->validation()->errors())->toBe([]);
})->with(['xrechnung-cii', 'xrechnung-ubl', 'zugferd'])->group('official')->skip(fn () => ! Official::available(), 'KoSIT validator is not available.');

it('titles cancellations as Stornorechnung', function () {
    $quittio = germany();
    $original = $quittio->make(Scenarios::make('simple'))->format('cii')->render()->data();
    $html = $quittio->make($quittio->cancellation($original, 'ST-2026-0001', 'Doppelt berechnet'))->format('html')->render()->html();

    expect($html)->toContain('<h1>Stornorechnung</h1>')
        ->and($html)->toContain('Stornierung der Rechnung RE-2026-0042 vom 03.10.2026. Grund: Doppelt berechnet');
});

it('passes the official KoSIT validation for both cancellation strategies', function (string $strategy, string $format) {
    $quittio = germany()->addValidator(Official::kosit());
    $original = $quittio->make(Scenarios::make('mixed-vat')->buyerReference('04011000-12345-03'))->format('cii')->render()->data();
    $result = $quittio->make($quittio->cancellation($original, 'ST-2026-0002', 'Test', $strategy))->format($format)->render();

    expect($result->validation()->levels)->toContain(ValidationLevel::L5)
        ->and($result->validation()->errors())->toBe([]);
})->with(['credit_note', 'negative_invoice'])->with(['xrechnung-cii', 'xrechnung-ubl', 'zugferd'])
    ->group('official')->skip(fn () => ! Official::available(), 'KoSIT validator is not available.');

it('issues photovoltaic invoices at the zero rate without an exemption reason (section 12 (3) UStG)', function () {
    $builder = Scenarios::base()->buyerReference('04011000-12345-03')
        ->line(fn ($l) => $l->name('Photovoltaikanlage 9,8 kWp')->quantity(1, UnitCode::Piece)->netPrice('14500.00')->vat(VatPreset::ZeroRatedPv));
    $result = germany()->make($builder)->formats(['zugferd', 'xrechnung-cii', 'xrechnung-ubl'])->render();

    expect($result->validation()->isValid())->toBeTrue()
        ->and($result->as('xrechnung-cii')->xml())->toContain('<ram:CategoryCode>Z</ram:CategoryCode>')
        ->and($result->as('xrechnung-cii')->xml())->not->toContain('ExemptionReason')
        ->and($result->as('xrechnung-ubl')->xml())->not->toContain('TaxExemptionReason');
});

it('passes the official KoSIT validation for every German tax preset', function (Closure $preset, string $format) {
    $builder = Scenarios::base()->buyerReference('04011000-12345-03');
    $preset($builder);
    $result = germany()->addValidator(Official::kosit())->make($builder)->format($format)->render();

    expect($result->validation()->levels)->toContain(ValidationLevel::L5)
        ->and($result->validation()->errors())->toBe([]);
})->with([
    'reduced 7' => [fn (InvoiceBuilder $b) => $b->line(fn ($l) => $l->name('Buch')->quantity(2, UnitCode::Piece)->netPrice('19.90')->vat(VatPreset::Reduced))],
    'photovoltaics' => [fn (InvoiceBuilder $b) => $b->line(fn ($l) => $l->name('PV-Anlage')->quantity(1, UnitCode::Piece)->netPrice('14500.00')->vat(VatPreset::ZeroRatedPv))],
    'small business' => [fn (InvoiceBuilder $b) => $b->line(fn ($l) => $l->name('Beratung')->quantity(1, UnitCode::Hour)->netPrice('80.00')->vat(VatPreset::SmallBusiness))],
    'reverse charge domestic' => [fn (InvoiceBuilder $b) => $b->line(fn ($l) => $l->name('Bauleistung')->quantity(1, UnitCode::Piece)->netPrice('5000.00')->vat(VatPreset::ReverseChargeDomestic))],
    'reverse charge EU' => [fn (InvoiceBuilder $b) => $b->buyer(fn ($p) => $p->name('Client SARL')->vatId('FR12345678901')->address('1 Rue de la Paix', '75002', 'Paris', 'FR'))
        ->line(fn ($l) => $l->name('Beratung')->quantity(8, UnitCode::Hour)->netPrice('120.00')->vat(VatPreset::ReverseChargeEu))],
    'triangular transaction' => [fn (InvoiceBuilder $b) => $b->buyer(fn ($p) => $p->name('Kunde GmbH')->vatId('ATU12345678')->address('Ring 1', '1010', 'Wien', 'AT'))
        ->line(fn ($l) => $l->name('Maschine')->quantity(1, UnitCode::Piece)->netPrice('5000.00')->vat(VatPreset::TriangularTransaction))],
    'intra-community supply' => [fn (InvoiceBuilder $b) => $b->buyer(fn ($p) => $p->name('Cliente S.r.l.')->vatId('IT12345678901')->address('Via Roma 1', '00184', 'Roma', 'IT'))
        ->deliverTo('Cliente S.r.l.', Address::make('Via Roma 1', '00184', 'Roma', 'IT'))
        ->line(fn ($l) => $l->name('Industriepumpe')->quantity(2, UnitCode::Piece)->netPrice('1450.00')->vat(VatPreset::IntraCommunitySupply))],
    'export' => [fn (InvoiceBuilder $b) => $b->buyer(fn ($p) => $p->name('Customer Inc.')->vatId(null)->address('1 Main Street', '10001', 'New York', 'US'))
        ->line(fn ($l) => $l->name('Messgerät')->quantity(1, UnitCode::Piece)->netPrice('2400.00')->vat(VatPreset::Export))],
    'not taxable in Germany' => [fn (InvoiceBuilder $b) => $b->seller(fn ($s) => $s->vatId(null))->buyer(fn ($p) => $p->name('Customer Inc.')->vatId(null)->address('1 Main Street', '10001', 'New York', 'US'))
        ->line(fn ($l) => $l->name('Beratung')->quantity(4, UnitCode::Hour)->netPrice('150.00')->vat(VatPreset::NotTaxableInGermany))],
    'margin second hand' => [fn (InvoiceBuilder $b) => $b->line(fn ($l) => $l->name('Gebrauchtwagen')->quantity(1, UnitCode::Piece)->netPrice('8900.00')->vat(VatPreset::MarginSecondHand))],
    'margin art' => [fn (InvoiceBuilder $b) => $b->line(fn ($l) => $l->name('Gemälde')->quantity(1, UnitCode::Piece)->netPrice('3200.00')->vat(VatPreset::MarginArt))],
    'margin antiques' => [fn (InvoiceBuilder $b) => $b->line(fn ($l) => $l->name('Kommode')->quantity(1, UnitCode::Piece)->netPrice('1800.00')->vat(VatPreset::MarginAntiques))],
    'travel agency' => [fn (InvoiceBuilder $b) => $b->line(fn ($l) => $l->name('Pauschalreise')->quantity(2, UnitCode::Piece)->netPrice('1250.00')->vat(VatPreset::TravelAgency))],
    'exempt § 4 Nr. 14' => [fn (InvoiceBuilder $b) => $b->line(fn ($l) => $l->name('Heilbehandlung')->quantity(1, UnitCode::Piece)->netPrice('90.00')->vat(VatPreset::exempt('§ 4 Nr. 14 UStG', 'VATEX-EU-132-1C')))],
])->with(['xrechnung-cii', 'xrechnung-ubl'])->group('official')->skip(fn () => ! Official::available(), 'KoSIT validator is not available.');

it('applies the configured profile to the format chosen by the German rule', function () {
    $result = germany(['profile' => 'extended'])->make(Scenarios::simple()->build())->render();

    expect($result->format())->toBe('zugferd')
        ->and($result->profile()?->key())->toBe('extended');
});

it('reports sub lines in XRechnung CII in the validation instead of switching the profile', function () {
    $result = germany(['validation' => ['mode' => 'warn']])->make(Scenarios::subLines()->buyerReference('04011000-12345-03')->build())->format('xrechnung-cii')->render();
    $codes = array_map(fn ($m) => $m->code, $result->validation()->errors());

    expect($result->profile()?->key())->toBe('xrechnung')
        ->and($codes)->not->toBeEmpty();
});
