<?php

declare(strict_types=1);

use Quittio\Core\Pdf\PdfEmbeddedFileExtractor;
use Quittio\Core\Quittio;
use Quittio\Core\Testing\Scenarios;
use Quittio\Core\Tests\Support\Official;
use Quittio\Core\Validation\ValidationLevel;
use Quittio\Country\DE\CountryDE;

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
    $quittio = germany()->withValidator(Official::kosit());
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
    $quittio = germany()->withValidator(Official::kosit());
    $original = $quittio->make(Scenarios::make('mixed-vat')->buyerReference('04011000-12345-03'))->format('cii')->render()->data();
    $result = $quittio->make($quittio->cancellation($original, 'ST-2026-0002', 'Test', $strategy))->format($format)->render();

    expect($result->validation()->levels)->toContain(ValidationLevel::L5)
        ->and($result->validation()->errors())->toBe([]);
})->with(['credit_note', 'negative_invoice'])->with(['xrechnung-cii', 'xrechnung-ubl', 'zugferd'])
    ->group('official')->skip(fn () => ! Official::available(), 'KoSIT validator is not available.');
