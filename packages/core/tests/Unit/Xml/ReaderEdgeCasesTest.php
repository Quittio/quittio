<?php

declare(strict_types=1);

use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Data\Attachments\Attachment;
use Quittio\Core\Enums\MimeType;
use Quittio\Core\Profiles\ProfileRegistry;
use Quittio\Core\Reading\InvoiceReader;
use Quittio\Core\Reading\ReadResult;
use Quittio\Core\Support\Date;
use Quittio\Core\Tests\Support\Invoices;
use Quittio\Core\Validation\Severity;
use Quittio\Core\Validation\ValidationMessage;
use Quittio\Core\Xml\Cii\CiiWriter;
use Quittio\Core\Xml\Ubl\UblWriter;
use Quittio\Core\Xml\UnsafeXmlException;
use Quittio\Core\Xml\XmlSecurity;

function round3Xml(string $syntax, bool $foreignCurrency = false): string
{
    $builder = Invoices::simple();
    if ($foreignCurrency) {
        $builder->currency('USD')->taxCurrency('EUR', '0.857143');
    }
    $invoice = (new En16931Calculator)->calculate($builder->build());
    $profile = ProfileRegistry::withDefaults()->get('en16931');

    return $syntax === 'cii' ? (new CiiWriter)->serialize($invoice, $profile) : (new UblWriter)->serialize($invoice, $profile);
}

/**
 * @return list<string>
 */
function round3Codes(ReadResult $result, ?Severity $severity = null): array
{
    return array_values(array_map(
        fn (ValidationMessage $f): string => $f->code,
        array_filter($result->findings, fn (ValidationMessage $f): bool => $severity === null || $f->severity === $severity),
    ));
}

function round3Replace(string $search, string $replace, string $subject): string
{
    expect(substr_count($subject, $search))->toBeGreaterThan(0, 'Not found: '.$search);

    return str_replace($search, $replace, $subject);
}

describe('XML pre-scan', function () {
    it('rejects a DOCTYPE before libxml processes its default attributes', function () {
        $declarations = '';
        for ($i = 0; $i < 100000; $i++) {
            $declarations .= " a{$i} CDATA \"x\"";
        }
        $start = microtime(true);

        expect(fn () => XmlSecurity::load('<?xml version="1.0"?><!DOCTYPE r [<!ATTLIST r'.$declarations.'>]><r/>'))
            ->toThrow(UnsafeXmlException::class, 'DOCTYPE')
            ->and(microtime(true) - $start)->toBeLessThan(0.5);
    });

    it('rejects encodings that hide the markup from the pre-scan', function (string $xml) {
        XmlSecurity::load($xml);
    })->with([
        'UTF-7' => ['<?xml version="1.0" encoding="UTF-7"?>+ADw-r a+AD0AIg-1+ACIAPgA8-b/+AD4APA-/r+AD4-'],
        'EBCDIC' => [(string) iconv('UTF-8', 'CP037', '<?xml version="1.0" encoding="IBM037"?><r/>')],
        'ISO-2022-JP' => ['<?xml version="1.0" encoding="ISO-2022-JP"?><r/>'],
        'UTF-32BE' => [(string) mb_convert_encoding('<?xml version="1.0" encoding="UCS-4"?><r/>', 'UTF-32BE', 'UTF-8')],
        'UTF-32LE' => [(string) mb_convert_encoding('<r/>', 'UTF-32LE', 'UTF-8')],
    ])->throws(UnsafeXmlException::class);

    it('accepts UTF-8, ISO-8859-1 and UTF-16 documents', function (string $xml) {
        expect(XmlSecurity::load($xml)->documentElement?->getAttribute('a'))->toBe('1');
    })->with([
        'UTF-8 with BOM' => ["\xEF\xBB\xBF<?xml version=\"1.0\" encoding=\"utf-8\"?><r a=\"1\"/>"],
        'ISO-8859-1' => ["<?xml version='1.0' encoding='ISO-8859-1'?><r a=\"1\">\xE4</r>"],
        'UTF-16LE with BOM' => ["\xFF\xFE".mb_convert_encoding('<?xml version="1.0" encoding="UTF-16"?><r a="1"/>', 'UTF-16LE', 'UTF-8')],
        'UTF-16BE without BOM' => [mb_convert_encoding('<?xml version="1.0" encoding="UTF-16"?><r a="1"/>', 'UTF-16BE', 'UTF-8')],
    ]);

    it('rejects a DOCTYPE in UTF-16 before parsing', function () {
        $xml = '<?xml version="1.0" encoding="UTF-16"?><!DOCTYPE r [<!ATTLIST r a CDATA "x">]><r/>';

        XmlSecurity::load("\xFF\xFE".mb_convert_encoding($xml, 'UTF-16LE', 'UTF-8'));
    })->throws(UnsafeXmlException::class, 'DOCTYPE');

    it('limits the namespace declarations in scope', function () {
        $declarations = '';
        for ($k = 0; $k < 250; $k++) {
            $declarations .= " xmlns:p{$k}=\"urn:{$k}\"";
        }
        $start = microtime(true);

        expect(fn () => XmlSecurity::load('<a:r xmlns:a="urn:a"><e'.$declarations.'><f'.$declarations.'>'.str_repeat('<a:z/>', 1000).'</f></e></a:r>'))
            ->toThrow(UnsafeXmlException::class, 'namespace declarations in scope')
            ->and(microtime(true) - $start)->toBeLessThan(0.5);
    });

    it('accepts namespaces redeclared on every element and many declarations on siblings', function () {
        $declarations = '';
        for ($k = 0; $k < 200; $k++) {
            $declarations .= " xmlns:p{$k} = 'urn:{$k}'";
        }
        $xml = '<r xmlns="urn:r">'.str_repeat('<c:a xmlns:c="urn:c" c:x="1">1</c:a>', 5000).str_repeat("<e{$declarations}/><f{$declarations}></f>", 50).'</r>';

        expect(XmlSecurity::load($xml)->documentElement?->childNodes->length)->toBe(5100);
    });
});

describe('VAT totals by currency', function () {
    it('does not put the VAT accounting currency (BT-6) into an XPath expression', function (string $syntax, string $element, string $injected) {
        $xml = round3Replace("<{$element}>EUR</{$element}>", "<{$element}>x\"]|{$injected}|//*[@y=\"</{$element}>", round3Xml($syntax, true));

        $result = (new InvoiceReader)->read($xml);

        expect($result->data->totals?->vatTotalInTaxCurrency)->toBeNull()
            ->and($result->data->totals?->vatTotal?->getAmount()->isZero())->toBeFalse()
            ->and(round3Codes($result, Severity::Error))->toContain('QUITTIO-READER-CURRENCY');
    })->with([
        'CII' => ['cii', 'ram:TaxCurrencyCode', '//ram:DuePayableAmount'],
        'UBL' => ['ubl', 'cbc:TaxCurrencyCode', '//cbc:PayableAmount'],
    ]);

    it('reads BT-110 and BT-111 when the currencyID has surrounding white space', function (string $syntax) {
        $xml = round3Xml($syntax, true);
        $expected = (new InvoiceReader)->read($xml)->data->totals;
        $xml = round3Replace('currencyID="USD"', 'currencyID=" USD "', round3Replace('currencyID="EUR"', 'currencyID="EUR "', $xml));

        $totals = (new InvoiceReader)->read($xml)->data->totals;

        expect($expected?->vatTotal)->not->toBeNull()
            ->and($expected?->vatTotalInTaxCurrency)->not->toBeNull()
            ->and((string) $totals?->vatTotal)->toBe((string) $expected?->vatTotal)
            ->and((string) $totals?->vatTotalInTaxCurrency)->toBe((string) $expected?->vatTotalInTaxCurrency);
    })->with(['cii', 'ubl']);

    it('reads a CII total VAT amount without currencyID in the invoice currency and reports it', function () {
        $xml = round3Xml('cii');
        $expected = (new InvoiceReader)->read($xml)->data->totals?->vatTotal;

        $result = (new InvoiceReader)->read(round3Replace('<ram:TaxTotalAmount currencyID="EUR">', '<ram:TaxTotalAmount>', $xml));

        expect((string) $result->data->totals?->vatTotal)->toBe((string) $expected)
            ->and(round3Codes($result, Severity::Warning))->toContain('QUITTIO-READER-CURRENCY')
            ->and(round3Codes($result))->not->toContain('QUITTIO-DECLARED-TOTALS');
    });
});

it('removes format characters such as the right-to-left override from attachment names', function () {
    $invoice = (new En16931Calculator)->calculate(Invoices::simple()->build());
    $invoice = $invoice->with(['attachments' => [new Attachment('A1', 'Beleg', null, '%PDF-1.4', MimeType::Pdf, "beleg\u{202E}fdp.exe")]]);
    $xml = (new CiiWriter)->serialize($invoice, ProfileRegistry::withDefaults()->get('en16931'));

    expect((new InvoiceReader)->read($xml)->data->attachments[0]->filename)->toBe('belegfdp.exe');
});

it('rejects date strings relative to today', function (string $value) {
    Date::parse($value);
})->with(['+2000 days', '2000 days ago', 'next monday 2026', 'tomorrow 2026'])->throws(InvalidArgumentException::class);

it('still parses explicit calendar dates', function (string $value, string $expected) {
    expect(Date::parse($value)->toIsoString())->toBe($expected);
})->with([
    ['2026-10-03', '2026-10-03'],
    ['20261003', '2026-10-03'],
    ['2026-10-03 14:30:00', '2026-10-03'],
    ['2026-10-03T23:30:00+14:00', '2026-10-03'],
    ['3 October 2026', '2026-10-03'],
    ['03.10.2026', '2026-10-03'],
]);

it('reads cash discount periods in weeks and does not read other units as days', function () {
    $invoice = (new En16931Calculator)->calculate(Invoices::simple()
        ->paymentTerms(fn ($t) => $t->dueInDays(30)->cashDiscount(10, '3')->cashDiscount(14, '2'))
        ->build());
    $xml = (new CiiWriter)->serialize($invoice, ProfileRegistry::withDefaults()->get('extended'));
    $xml = round3Replace('<ram:BasisPeriodMeasure unitCode="DAY">14</ram:BasisPeriodMeasure>', '<ram:BasisPeriodMeasure unitCode="WEE">2</ram:BasisPeriodMeasure>', $xml);
    $xml = round3Replace('<ram:BasisPeriodMeasure unitCode="DAY">10</ram:BasisPeriodMeasure>', '<ram:BasisPeriodMeasure unitCode="MON">1</ram:BasisPeriodMeasure>', $xml);

    $result = (new InvoiceReader)->read($xml);
    $discounts = $result->data->paymentTerms->discounts ?? [];

    expect($discounts)->toHaveCount(2)
        ->and($discounts[0]->days)->toBe(0)
        ->and($discounts[1]->days)->toBe(14)
        ->and(round3Codes($result, Severity::Warning))->toContain('QUITTIO-READER-UNIT');
});
