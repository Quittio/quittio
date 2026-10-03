<?php

declare(strict_types=1);

use Quittio\Core\Reading\InvoiceReader;
use Quittio\Core\Reading\UnreadableInvoiceException;
use Quittio\Core\Xml\UnsafeXmlException;
use Quittio\Core\Xml\XmlSecurity;

it('rejects external entities (XXE)', function () {
    $xml = '<?xml version="1.0"?><!DOCTYPE foo [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><rsm:CrossIndustryInvoice xmlns:rsm="urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100">&xxe;</rsm:CrossIndustryInvoice>';

    (new InvoiceReader)->read($xml);
})->throws(UnsafeXmlException::class, 'DOCTYPE');

it('rejects entity expansion (billion laughs)', function () {
    $xml = '<?xml version="1.0"?><!DOCTYPE lolz [<!ENTITY lol "lol"><!ENTITY lol2 "&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;">]><lolz>&lol2;</lolz>';

    XmlSecurity::load($xml);
})->throws(UnsafeXmlException::class);

it('rejects documents above the size limit', function () {
    XmlSecurity::load('<a>'.str_repeat('x', 2048).'</a>', 1024);
})->throws(UnsafeXmlException::class, 'size limit');

it('rejects malformed XML', function () {
    XmlSecurity::load('<a><b></a>');
})->throws(UnsafeXmlException::class, 'not well-formed');

it('rejects unknown document types', function () {
    (new InvoiceReader)->read('<Order xmlns="urn:example"/>');
})->throws(UnreadableInvoiceException::class, 'Unknown invoice format');

it('reports deviations between declared and recalculated totals', function () {
    $invoice = (new Quittio\Core\Calculation\En16931Calculator)->calculate(Quittio\Core\Testing\Scenarios::simple()->build());
    $xml = (new Quittio\Core\Xml\Cii\CiiWriter)->serialize($invoice, Quittio\Core\Profiles\ProfileRegistry::withDefaults()->get('en16931'));
    $tampered = str_replace('<ram:DuePayableAmount>2747.71</ram:DuePayableAmount>', '<ram:DuePayableAmount>2700.00</ram:DuePayableAmount>', $xml);

    $result = (new InvoiceReader)->read($tampered);
    $codes = array_map(fn ($f) => $f->code, $result->findings);

    expect($codes)->toContain('QUITTIO-DECLARED-TOTALS')
        ->and(Quittio\Core\Support\Amount::format($result->declaredTotals->amountDue))->toBe('2700.00');
});

it('keeps unknown codes as raw values', function () {
    $invoice = (new Quittio\Core\Calculation\En16931Calculator)->calculate(Quittio\Core\Testing\Scenarios::simple()->build());
    $xml = (new Quittio\Core\Xml\Cii\CiiWriter)->serialize($invoice, Quittio\Core\Profiles\ProfileRegistry::withDefaults()->get('en16931'));
    $xml = str_replace('unitCode="HUR"', 'unitCode="ZZQ"', $xml);

    $result = (new InvoiceReader)->read($xml);

    expect($result->data->lines[0]->unit)->toBeInstanceOf(Quittio\Core\Codes\RawCode::class)
        ->and(array_map(fn ($f) => $f->code, $result->findings))->toContain('QUITTIO-READER-UNKNOWN-CODE');
});
