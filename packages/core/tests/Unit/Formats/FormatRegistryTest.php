<?php

declare(strict_types=1);

use Quittio\Core\Data\Extensions\ExtendedData;
use Quittio\Core\Data\Extensions\ExtendedParty;
use Quittio\Core\Data\Party\Address;
use Quittio\Core\Data\Presentation\Presentation;
use Quittio\Core\Exceptions\ConfigurationException;
use Quittio\Core\Formats\ExtendedFeatures;
use Quittio\Core\Formats\FormatRegistry;
use Quittio\Core\Formats\OutputFormat;
use Quittio\Core\Profiles\Syntax;
use Quittio\Core\Testing\Scenarios;

it('resolves format keys and aliases case-insensitively', function (string $key, string $format) {
    expect(FormatRegistry::withDefaults()->get($key)->key)->toBe($format)
        ->and(FormatRegistry::withDefaults()->has($key))->toBeTrue();
})->with([
    ['ZUGFeRD', 'zugferd'],
    ['factur-x', 'zugferd'],
    ['FacturX', 'zugferd'],
    ['peppol', 'peppol'],
]);

it('names the known formats for an unknown key', function () {
    expect(FormatRegistry::withDefaults()->has('edifact'))->toBeFalse();

    FormatRegistry::withDefaults()->get('edifact');
})->throws(ConfigurationException::class, 'Unknown format "edifact". Known formats: zugferd, cii, ubl');

it('describes what each default format produces', function (string $key, ?Syntax $syntax, bool $pdf, bool $embeds, bool $eInvoice) {
    $format = FormatRegistry::withDefaults()->get($key);

    expect($format->syntax)->toBe($syntax)
        ->and($format->pdf)->toBe($pdf)
        ->and($format->embedsXml)->toBe($embeds)
        ->and($format->hasXml())->toBe($syntax !== null)
        ->and($format->isEInvoice())->toBe($eInvoice);
})->with([
    'zugferd' => ['zugferd', Syntax::Cii, true, true, true],
    'cii' => ['cii', Syntax::Cii, false, false, true],
    'ubl' => ['ubl', Syntax::Ubl, false, false, true],
    'pdf' => ['pdf', null, true, false, false],
    'html' => ['html', null, false, false, false],
    'json' => ['json', null, false, false, false],
]);

it('restricts profiles only where the format names them', function () {
    $registry = FormatRegistry::withDefaults();

    expect($registry->get('peppol')->allowsProfile('peppol'))->toBeTrue()
        ->and($registry->get('peppol')->allowsProfile('en16931'))->toBeFalse()
        ->and($registry->get('cii')->allowsProfile('extended'))->toBeTrue();
});

it('registers national formats and aliases next to the defaults', function () {
    $registry = FormatRegistry::withDefaults()
        ->register(new OutputFormat('fatturapa', Syntax::Ubl, defaultProfile: 'en16931'))
        ->alias('FatturaPA-XML', 'fatturapa');

    expect($registry->get('fatturapa-xml')->key)->toBe('fatturapa')
        ->and(array_keys($registry->all()))->toContain('zugferd', 'fatturapa');
});

it('detects data that only EXTENDED can carry', function () {
    $simple = Scenarios::simple()->build();

    expect(ExtendedFeatures::used($simple))->toBeFalse()
        ->and(ExtendedFeatures::used(Scenarios::make('sub-lines')->build()))->toBeTrue()
        ->and(ExtendedFeatures::used($simple->with(['extended' => new ExtendedData(deliveryTermsCode: 'DAP')])))->toBeTrue()
        ->and(ExtendedFeatures::used($simple->with(['extended' => new ExtendedData(invoicee: new ExtendedParty('Buchhaltung', Address::make('Weg 1', '12345', 'Stadt', 'DE')))])))->toBeTrue()
        // Data for the PDF only never needs EXTENDED.
        ->and(ExtendedFeatures::used($simple->with(['presentation' => new Presentation(title: 'Rechnung', showItemNumbers: true)])))->toBeFalse();
});
