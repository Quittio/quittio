<?php

declare(strict_types=1);

use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Profiles\ProfileRegistry;
use Quittio\Core\Reading\InvoiceReader;
use Quittio\Core\Testing\Scenarios;
use Quittio\Core\Tests\Support\RoundTrip;
use Quittio\Core\Validation\Drivers\XsdValidator;
use Quittio\Core\Xml\Ubl\UblWriter;

function ublFor(string $scenario): string
{
    $invoice = (new En16931Calculator)->calculate(Scenarios::make($scenario)->build());

    return (new UblWriter)->serialize($invoice, ProfileRegistry::withDefaults()->get($scenario === 'peppol' ? 'peppol' : 'en16931'));
}

$ublScenarios = array_values(array_filter(Scenarios::names(), fn (string $n) => $n !== 'sub-lines'));

it('writes schema valid UBL', function (string $scenario) {
    expect((new XsdValidator)->validateXml(ublFor($scenario)))->toBe([]);
})->with($ublScenarios);

it('matches the UBL snapshot', function (string $scenario) {
    expect(ublFor($scenario))->toMatchSnapshot();
})->with($ublScenarios);

it('writes credit notes as CreditNote documents', function () {
    $xml = ublFor('cancellation');

    expect($xml)->toContain('<CreditNote xmlns="urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2"')
        ->and($xml)->toContain('<cbc:CreditNoteTypeCode>381</cbc:CreditNoteTypeCode>')
        ->and($xml)->toContain('<cac:CreditNoteLine>')
        ->and($xml)->toContain('<cbc:CreditedQuantity');
});

it('writes the Peppol customization and process identifiers', function () {
    $xml = ublFor('peppol');

    expect($xml)->toContain('<cbc:CustomizationID>urn:cen.eu:en16931:2017#compliant#urn:fdc:peppol.eu:2017:poacc:billing:3.0</cbc:CustomizationID>')
        ->and($xml)->toContain('<cbc:ProfileID>urn:fdc:peppol.eu:2017:poacc:billing:01:1.0</cbc:ProfileID>')
        ->and($xml)->toContain('<cbc:EndpointID schemeID="0208">0123456749</cbc:EndpointID>');
});

it('refuses profiles without UBL syntax', function () {
    $invoice = (new En16931Calculator)->calculate(Scenarios::simple()->build());

    (new UblWriter)->serialize($invoice, ProfileRegistry::withDefaults()->get('extended'));
})->throws(InvalidArgumentException::class);

it('reads its own UBL output back without loss', function (string $scenario) {
    $invoice = (new En16931Calculator)->calculate(Scenarios::make($scenario)->build());
    $profile = ProfileRegistry::withDefaults()->get($scenario === 'peppol' ? 'peppol' : 'en16931');

    $result = (new InvoiceReader)->read((new UblWriter)->serialize($invoice, $profile));

    expect(RoundTrip::comparable($result->data))->toEqual(RoundTrip::comparable($invoice->with(['businessProcess' => $invoice->businessProcess ?? $profile->defaultBusinessProcess()])))
        ->and($result->syntax)->toBe('ubl')
        ->and($result->profile)->toBe($profile->key())
        ->and(array_filter($result->findings, fn ($f) => $f->code === 'QUITTIO-DECLARED-TOTALS'))->toBe([]);
})->with($ublScenarios);

it('converts between CII and UBL without loss', function (string $scenario) {
    $invoice = (new En16931Calculator)->calculate(Scenarios::make($scenario)->build());
    $profile = ProfileRegistry::withDefaults()->get('en16931');

    $fromCii = (new InvoiceReader)->read((new Quittio\Core\Xml\Cii\CiiWriter)->serialize($invoice, $profile))->data;
    $fromUbl = (new InvoiceReader)->read((new UblWriter)->serialize($fromCii, $profile))->data;

    expect(RoundTrip::comparable($fromUbl))->toEqual(RoundTrip::comparable($fromCii));
})->with(['simple', 'mixed-vat', 'allowances', 'intra-eu', 'foreign-currency', 'attachments']);

it('writes the payment means text and the credit note due date only once for several accounts (UBL-SR-45, UBL-SR-46)', function (string $type) {
    $invoice = (new En16931Calculator)->calculate(Scenarios::make('simple')->type($type)
        ->payment('58', iban: 'DE02120300000000202051', meansText: 'SEPA-Überweisung', remittanceInformation: 'RE-2026-0042')
        ->creditTransfer('DE89370400440532013000')
        ->build());
    $xml = (new UblWriter)->serialize($invoice, ProfileRegistry::withDefaults()->get('en16931'));
    $dom = new DOMDocument;
    $dom->loadXML($xml);
    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
    $xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');

    expect($xpath->query('//cac:PaymentMeans')?->length)->toBe(2)
        ->and($xpath->query('//cac:PaymentMeans/cbc:PaymentMeansCode/@name')?->length)->toBe(1)
        ->and($xpath->query('//cac:PaymentMeans/cbc:PaymentDueDate')?->length)->toBeLessThanOrEqual(1)
        ->and((new XsdValidator)->validateXml($xml))->toBe([]);
})->with(['380', '381']);
