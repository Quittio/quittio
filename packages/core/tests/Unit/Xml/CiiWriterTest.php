<?php

declare(strict_types=1);

use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Profiles\ProfileRegistry;
use Quittio\Core\Reading\InvoiceReader;
use Quittio\Core\Testing\Scenarios;
use Quittio\Core\Tests\Support\RoundTrip;
use Quittio\Core\Validation\Drivers\XsdValidator;
use Quittio\Core\Xml\Cii\CiiWriter;

function ciiFor(string $scenario, ?string $profile = null): string
{
    $invoice = (new En16931Calculator)->calculate(Scenarios::make($scenario)->build());

    return (new CiiWriter)->serialize($invoice, ProfileRegistry::withDefaults()->get($profile ?? Scenarios::profile($scenario)));
}

$scenarios = array_values(array_filter(Scenarios::names(), fn (string $n) => $n !== 'peppol'));

it('writes schema valid CII', function (string $scenario) {
    expect((new XsdValidator)->validateXml(ciiFor($scenario)))->toBe([]);
})->with($scenarios);

it('matches the CII snapshot', function (string $scenario) {
    expect(ciiFor($scenario))->toMatchSnapshot();
})->with($scenarios);

it('writes deterministic output', function () {
    expect(hash('sha256', ciiFor('mixed-vat')))->toBe(hash('sha256', ciiFor('mixed-vat')));
});

it('writes the specification identifier of the profile', function (string $profile, string $id) {
    expect(ciiFor('simple', $profile))->toContain('<ram:ID>'.$id.'</ram:ID>');
})->with([
    ['en16931', 'urn:cen.eu:en16931:2017'],
    ['basic', 'urn:cen.eu:en16931:2017#compliant#urn:factur-x.eu:1p0:basic'],
    ['extended', 'urn:cen.eu:en16931:2017#conformant#urn:factur-x.eu:1p0:extended'],
]);

it('omits lines and details in BASIC WL and MINIMUM', function () {
    $basicWl = ciiFor('simple', 'basic-wl');
    $minimum = ciiFor('simple', 'minimum');

    expect($basicWl)->not->toContain('IncludedSupplyChainTradeLineItem')
        ->and($basicWl)->toContain('ApplicableTradeTax')
        ->and($minimum)->not->toContain('ApplicableTradeTax')
        ->and($minimum)->toContain('<ram:DuePayableAmount>')
        ->and((new XsdValidator)->validateXml($minimum))->toBe([]);
});

it('writes sub lines with parent references only in EXTENDED', function () {
    $xml = ciiFor('sub-lines', 'extended');

    expect($xml)->toContain('<ram:ParentLineID>1</ram:ParentLineID>')
        ->and($xml)->toContain('<ram:LineStatusReasonCode>GROUP</ram:LineStatusReasonCode>')
        ->and($xml)->toContain('<ram:DeliveryTypeCode>DAP</ram:DeliveryTypeCode>');
});

it('never writes empty elements', function (string $scenario) {
    expect(preg_match('#<([a-z]+:[A-Za-z]+)[^>]*/>|<([a-z]+:[A-Za-z]+)[^>]*>\s*</\2>#', ciiFor($scenario)))->toBe(0);
})->with(['simple', 'allowances', 'export']);

it('reads its own output back without loss', function (string $scenario) {
    $invoice = (new En16931Calculator)->calculate(Scenarios::make($scenario)->build());
    $profile = ProfileRegistry::withDefaults()->get(Scenarios::profile($scenario));
    $xml = (new CiiWriter)->serialize($invoice, $profile);

    $result = (new InvoiceReader)->read($xml);

    expect(RoundTrip::comparable($result->data))->toEqual(RoundTrip::comparable($invoice))
        ->and($result->profile)->toBe($profile->key())
        ->and(array_filter($result->findings, fn ($f) => $f->code === 'QUITTIO-DECLARED-TOTALS'))->toBe([]);
})->with($scenarios);

it('parses structured cash discounts from the payment terms text', function () {
    $invoice = (new En16931Calculator)->calculate(Scenarios::simple()->build());
    $xml = (new CiiWriter)->serialize($invoice, ProfileRegistry::withDefaults()->get('extended'));

    $read = (new InvoiceReader)->read($xml)->data;

    expect($read->paymentTerms?->discounts[0]->days)->toBe(7)
        ->and($read->paymentTerms?->discounts[0]->percent->toString())->toBe('2.00');
});
