<?php

declare(strict_types=1);

use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Codes\RawCode;
use Quittio\Core\Profiles\ProfileRegistry;
use Quittio\Core\Quittio;
use Quittio\Core\Reading\InvoiceReader;
use Quittio\Core\Reading\ReadResult;
use Quittio\Core\Reading\UnreadableInvoiceException;
use Quittio\Core\Support\Amount;
use Quittio\Core\Tests\Support\Invoices;
use Quittio\Core\Validation\Rules\CallbackRule;
use Quittio\Core\Validation\Rules\CodeListRules;
use Quittio\Core\Validation\Severity;
use Quittio\Core\Validation\ValidationMessage;
use Quittio\Core\Xml\Cii\CiiWriter;
use Quittio\Core\Xml\Ubl\UblWriter;
use Quittio\Core\Xml\UnsafeXmlException;
use Quittio\Core\Xml\XmlSecurity;

function hardeningCii(string $profile = 'en16931'): string
{
    $invoice = (new En16931Calculator)->calculate(Invoices::simple()->build());

    return (new CiiWriter)->serialize($invoice, ProfileRegistry::withDefaults()->get($profile));
}

function hardeningUbl(): string
{
    $invoice = (new En16931Calculator)->calculate(Invoices::simple()->build());

    return (new UblWriter)->serialize($invoice, ProfileRegistry::withDefaults()->get('en16931'));
}

/**
 * @return list<string>
 */
function hardeningCodes(ReadResult $result, ?Severity $severity = null): array
{
    return array_values(array_map(
        fn (ValidationMessage $f): string => $f->code,
        array_filter($result->findings, fn (ValidationMessage $f): bool => $severity === null || $f->severity === $severity),
    ));
}

function hardeningReplace(string $pattern, string $replacement, string $xml): string
{
    $result = preg_replace($pattern, $replacement, $xml, 1, $count);
    expect($count)->toBe(1, 'Pattern not found: '.$pattern);

    return (string) $result;
}

describe('XML parsing limits', function () {
    it('rejects elements with very many attributes before parsing', function () {
        $attributes = '';
        for ($i = 0; $i < 40000; $i++) {
            $attributes .= " a{$i}=\">\"";
        }
        $start = microtime(true);

        expect(fn () => XmlSecurity::load("<r{$attributes}/>"))->toThrow(UnsafeXmlException::class, 'attributes')
            ->and(microtime(true) - $start)->toBeLessThan(1.0);
    });

    it('rejects overlong start tags', function () {
        XmlSecurity::load('<r a="'.str_repeat('x', XmlSecurity::MAX_TAG_BYTES).'"/>');
    })->throws(UnsafeXmlException::class, 'start tag');

    it('accepts the text <!DOCTYPE in CDATA sections and comments', function () {
        expect(XmlSecurity::load('<r><![CDATA[<!DOCTYPE html> a < b]]><!-- <!DOCTYPE --></r>')->documentElement?->nodeName)->toBe('r');
    });

    it('still rejects DOCTYPE declarations in UTF-16 documents', function () {
        $xml = '<?xml version="1.0" encoding="UTF-16"?><!DOCTYPE r [<!ENTITY x SYSTEM "file:///etc/passwd">]><r>&x;</r>';

        XmlSecurity::load("\xFF\xFE".mb_convert_encoding($xml, 'UTF-16LE', 'UTF-8'));
    })->throws(UnsafeXmlException::class, 'DOCTYPE');
});

describe('numbers', function () {
    it('does not expand exponents and reports them', function (string $value) {
        $xml = hardeningReplace('#(<ram:DuePayableAmount>)[^<]+#', '${1}'.$value, hardeningCii());
        $before = memory_get_usage();

        $result = (new InvoiceReader)->read($xml);

        expect(hardeningCodes($result, Severity::Error))->toContain('QUITTIO-READER-NUMBER')
            ->and(memory_get_usage() - $before)->toBeLessThan(10 * 1024 * 1024);
    })->with(['1e2000000000', '1E3', '1,000.00', str_repeat('9', 41)]);

    it('reports amounts with more than two decimals as BR-DEC errors', function () {
        $xml = hardeningReplace('#(<ram:SpecifiedTradeSettlementLineMonetarySummation>\s*<ram:LineTotalAmount>)1000.00#', '${1}1000.004', hardeningCii());

        $result = (new InvoiceReader)->read($xml);

        expect(hardeningCodes($result, Severity::Error))->toContain('BR-DEC-23')
            ->and(Amount::format($result->data->lines[0]->netAmount))->toBe('1000.00');
    });

    it('reports a cash discount base amount with more than two decimals', function () {
        $xml = hardeningReplace('#(<ram:SpecifiedTradePaymentTerms>)#', '${1}<ram:Description>#SKONTO#TAGE=10#PROZENT=2.00#BASISBETRAG=100.005#</ram:Description>', hardeningCii());

        expect(hardeningCodes((new InvoiceReader)->read($xml)))->toContain('QUITTIO-READER-DECIMALS');
    });
});

describe('dates', function () {
    it('accepts only format 102 in CII and never uses today', function (string $element) {
        $xml = hardeningReplace('#<ram:IssueDateTime>.*?</ram:IssueDateTime>#s', $element, hardeningCii());

        $result = (new InvoiceReader)->read($xml);

        expect($result->data->issueDate->toIsoString())->toBe('1970-01-01')
            ->and(hardeningCodes($result, Severity::Error))->toContain('BR-03');
    })->with([
        'format 610' => '<ram:IssueDateTime><udt:DateTimeString format="610">202609</udt:DateTimeString></ram:IssueDateTime>',
        'relative' => '<ram:IssueDateTime><udt:DateTimeString format="102">tomorrow</udt:DateTimeString></ram:IssueDateTime>',
        'German' => '<ram:IssueDateTime><udt:DateTimeString format="102">03.10.2026</udt:DateTimeString></ram:IssueDateTime>',
        'invalid' => '<ram:IssueDateTime><udt:DateTimeString format="102">20261340</udt:DateTimeString></ram:IssueDateTime>',
        'missing' => '',
    ]);

    it('accepts only ISO dates in UBL', function () {
        $xml = hardeningReplace('#<cbc:IssueDate>[^<]+</cbc:IssueDate>#', '<cbc:IssueDate>10/03/2026</cbc:IssueDate>', hardeningUbl());

        $result = (new InvoiceReader)->read($xml);

        expect($result->data->issueDate->toIsoString())->toBe('1970-01-01')
            ->and(hardeningCodes($result, Severity::Error))->toContain('QUITTIO-READER-DATE', 'BR-03');
    });

    it('reads UBL dates with a time zone', function () {
        $xml = hardeningReplace('#<cbc:IssueDate>[^<]+</cbc:IssueDate>#', '<cbc:IssueDate>2026-10-03+02:00</cbc:IssueDate>', hardeningUbl());

        expect((new InvoiceReader)->read($xml)->data->issueDate->toIsoString())->toBe('2026-10-03');
    });

    it('does not fill in an unreadable delivery date', function () {
        $xml = hardeningReplace('#(<ram:OccurrenceDateTime>\s*<udt:DateTimeString format="102">)\d+#', '${1}20.09.2026', hardeningCii());

        $result = (new InvoiceReader)->read($xml);

        expect($result->data->delivery?->actualDeliveryDate)->toBeNull()
            ->and(hardeningCodes($result, Severity::Error))->toContain('QUITTIO-READER-DATE');
    });
});

describe('currency', function () {
    it('does not default a missing BT-5 to EUR', function () {
        $xml = str_replace('currencyID="EUR"', 'currencyID="USD"', hardeningUbl());
        $xml = hardeningReplace('#<cbc:DocumentCurrencyCode>EUR</cbc:DocumentCurrencyCode>#', '', $xml);

        $result = (new InvoiceReader)->read($xml);

        expect($result->data->currency)->toBe('USD')
            ->and($result->declaredTotals?->grossTotal->getCurrency()->getCurrencyCode())->toBe('USD')
            ->and(hardeningCodes($result, Severity::Error))->toContain('BR-05');
    });

    it('uses XXX when neither BT-5 nor a currency of the amounts is given', function () {
        $xml = hardeningReplace('#<ram:InvoiceCurrencyCode>EUR</ram:InvoiceCurrencyCode>#', '', hardeningCii());
        $xml = str_replace(' currencyID="EUR"', '', $xml);

        $result = (new InvoiceReader)->read($xml);

        expect($result->data->currency)->toBe('XXX')
            ->and(hardeningCodes($result, Severity::Error))->toContain('BR-05');
    });

    it('reports amounts in another currency and reads BT-110 only in the invoice currency', function () {
        $xml = str_replace('currencyID="EUR"', 'currencyID="USD"', hardeningUbl());

        $result = (new InvoiceReader)->read($xml);

        expect(hardeningCodes($result, Severity::Error))->toContain('QUITTIO-READER-CURRENCY')
            ->and($result->declaredTotals?->vatTotal)->toBeNull();
    });
});

describe('lines and prices', function () {
    it('reads the price base quantity from the gross price', function () {
        $xml = hardeningReplace(
            '#<ram:NetPriceProductTradePrice>\s*<ram:ChargeAmount>100.00</ram:ChargeAmount>\s*</ram:NetPriceProductTradePrice>#',
            '<ram:GrossPriceProductTradePrice><ram:ChargeAmount>10000.00</ram:ChargeAmount><ram:BasisQuantity unitCode="HUR">100</ram:BasisQuantity></ram:GrossPriceProductTradePrice>'
            .'<ram:NetPriceProductTradePrice><ram:ChargeAmount>10000.00</ram:ChargeAmount></ram:NetPriceProductTradePrice>',
            hardeningCii(),
        );

        $result = (new InvoiceReader)->read($xml);
        $price = $result->data->lines[0]->price;

        expect($price->baseQuantity?->toString())->toBe('100')
            ->and($price->baseQuantityUnit?->value)->toBe('HUR')
            ->and(hardeningCodes($result))->not->toContain('QUITTIO-DECLARED-TOTALS');
    });

    it('converts a gross price with another base quantity', function () {
        $xml = hardeningReplace(
            '#<ram:NetPriceProductTradePrice>\s*<ram:ChargeAmount>100.00</ram:ChargeAmount>\s*</ram:NetPriceProductTradePrice>#',
            '<ram:GrossPriceProductTradePrice><ram:ChargeAmount>1000.00</ram:ChargeAmount><ram:BasisQuantity unitCode="HUR">10</ram:BasisQuantity></ram:GrossPriceProductTradePrice>'
            .'<ram:NetPriceProductTradePrice><ram:ChargeAmount>100.00</ram:ChargeAmount><ram:BasisQuantity unitCode="HUR">1</ram:BasisQuantity></ram:NetPriceProductTradePrice>',
            hardeningCii(),
        );

        $result = (new InvoiceReader)->read($xml);

        expect($result->data->lines[0]->price->grossPrice?->toString())->toBe('100')
            ->and(hardeningCodes($result))->toContain('QUITTIO-READER-BASE-QUANTITY');
    });

    it('keeps lines with cyclic parent references as top-level lines', function (string $parents) {
        $xml = hardeningCii('extended');
        $line = (string) preg_replace('#^.*?(<ram:IncludedSupplyChainTradeLineItem>.*?</ram:IncludedSupplyChainTradeLineItem>).*$#s', '$1', $xml);
        [$first, $second] = explode(',', $parents);
        $lineOne = str_replace('<ram:LineID>1</ram:LineID>', '<ram:LineID>1</ram:LineID><ram:ParentLineID>'.$first.'</ram:ParentLineID>', $line);
        $lineTwo = str_replace('<ram:LineID>1</ram:LineID>', '<ram:LineID>2</ram:LineID>'.($second === '-' ? '' : '<ram:ParentLineID>'.$second.'</ram:ParentLineID>'), $line);
        $xml = str_replace($line, $lineOne.$lineTwo, $xml);

        $result = (new InvoiceReader)->read($xml);

        expect($result->data->lines)->toHaveCount(2)
            ->and(hardeningCodes($result, Severity::Error))->toContain('QUITTIO-READER-PARENT-LINE');
    })->with(['self' => '1,-', 'cycle' => '2,1']);

    it('reports a missing quantity of a line and of a sub line differently', function () {
        $xml = hardeningUbl();
        $subLine = '<cac:SubInvoiceLine><cbc:ID>1.1</cbc:ID><cbc:LineExtensionAmount currencyID="EUR">0.00</cbc:LineExtensionAmount>'
            .'<cac:Item><cbc:Name>Part</cbc:Name><cac:ClassifiedTaxCategory><cbc:ID>S</cbc:ID><cbc:Percent>19.00</cbc:Percent><cac:TaxScheme><cbc:ID>VAT</cbc:ID></cac:TaxScheme></cac:ClassifiedTaxCategory></cac:Item>'
            .'<cac:Price><cbc:PriceAmount currencyID="EUR">0.00</cbc:PriceAmount></cac:Price></cac:SubInvoiceLine>';
        $xml = hardeningReplace('#(</cac:Price>\s*)(</cac:InvoiceLine>)#', '$1'.$subLine.'$2', $xml);
        $withSubLine = (new InvoiceReader)->read($xml);
        $withoutQuantity = (new InvoiceReader)->read(hardeningReplace('#<cbc:InvoicedQuantity unitCode="HUR">10</cbc:InvoicedQuantity>#', '', hardeningUbl()));

        expect(hardeningCodes($withSubLine))->toContain('QUITTIO-READER-SUBLINE-QUANTITY')
            ->and(hardeningCodes($withSubLine))->not->toContain('QUITTIO-READER-UNKNOWN-CODE')
            ->and(hardeningCodes($withSubLine, Severity::Error))->toBe([])
            ->and(hardeningCodes($withoutQuantity, Severity::Error))->toContain('BR-22');
    });

    it('reports an unknown VAT point date code and keeps it raw', function () {
        $xml = hardeningReplace('#(<ram:ApplicableTradeTax>\s*<ram:CalculatedAmount>[^<]+</ram:CalculatedAmount>\s*<ram:TypeCode>VAT</ram:TypeCode>)#', '$1', hardeningCii());
        $xml = hardeningReplace('#(<ram:ApplicableHeaderTradeSettlement>.*?<ram:ApplicableTradeTax>.*?)(<ram:CategoryCode>)#s', '$1<ram:DueDateTypeCode>99</ram:DueDateTypeCode>$2', $xml);

        $result = (new InvoiceReader)->read($xml);
        $rule = array_values(array_filter(CodeListRules::all(), fn ($r) => $r instanceof CallbackRule && $r->code() === 'BR-CL-06'));

        expect($result->data->taxPointDateCode)->toBeInstanceOf(RawCode::class)
            ->and(hardeningCodes($result))->toContain('QUITTIO-READER-UNKNOWN-CODE')
            ->and($rule)->toHaveCount(1);
    });
});

describe('profiles without lines', function () {
    it('does not report recalculation differences for MINIMUM and derives BT-106', function () {
        $result = (new InvoiceReader)->read(hardeningCii('minimum'));

        expect($result->data->lines)->toBe([])
            ->and(hardeningCodes($result))->not->toContain('QUITTIO-DECLARED-TOTALS')
            ->and(Amount::format($result->declaredTotals?->lineNetSum))->toBe('1000.00')
            ->and(hardeningCodes($result, Severity::Error))->toBe([]);
    });

    it('does not report recalculation differences for BASIC WL', function () {
        $result = (new InvoiceReader)->read(hardeningCii('basic-wl'));

        expect(hardeningCodes($result))->not->toContain('QUITTIO-DECLARED-TOTALS')
            ->and(hardeningCodes($result, Severity::Error))->toBe([]);
    });
});

describe('attachments', function () {
    it('sanitises attachment file names instead of failing', function (string $filename, ?string $expected) {
        $document = '<ram:AdditionalReferencedDocument><ram:IssuerAssignedID>A1</ram:IssuerAssignedID><ram:TypeCode>916</ram:TypeCode>'
            .'<ram:AttachmentBinaryObject mimeCode="application/pdf" filename="'.htmlspecialchars($filename, ENT_XML1 | ENT_QUOTES).'">'.base64_encode('%PDF-1.4').'</ram:AttachmentBinaryObject>'
            .'</ram:AdditionalReferencedDocument>';
        $xml = hardeningReplace('#(</ram:ApplicableHeaderTradeAgreement>)#', str_replace('$', '\$', $document).'$1', hardeningCii());

        $result = (new InvoiceReader)->read($xml);

        expect($result->data->attachments[0]->filename)->toBe($expected);
        if ($expected === null) {
            expect(hardeningCodes($result))->toContain('QUITTIO-READER-ATTACHMENT');
        }
    })->with([
        ['/', null],
        ['..', null],
        ['..\\..\\Windows\\evil.pdf', 'evil.pdf'],
        ['../../etc/passwd.pdf', 'passwd.pdf'],
        ['report.pdf', 'report.pdf'],
    ]);
});

describe('reading API', function () {
    it('never treats content as a file name', function () {
        $path = sys_get_temp_dir().'/quittio-reader-'.bin2hex(random_bytes(4)).'.xml';
        file_put_contents($path, hardeningCii());

        try {
            expect(fn () => (new InvoiceReader)->read($path))->toThrow(UnsafeXmlException::class)
                ->and((new InvoiceReader)->readFile($path)->data->number)->toBe('INV-2026-0001');
        } finally {
            @unlink($path);
        }
    });

    it('reports missing files', function () {
        (new InvoiceReader)->readFile('/does/not/exist.xml');
    })->throws(UnreadableInvoiceException::class, 'does not exist');

    it('explains that ZUGFeRD 1.0 is not supported', function () {
        (new InvoiceReader)->read('<rsm:CrossIndustryDocument xmlns:rsm="urn:ferd:CrossIndustryDocument:invoice:1p0"/>');
    })->throws(UnreadableInvoiceException::class, 'ZUGFeRD 1.0');
});

describe('EXTENDED payment terms and exchange rate', function () {
    it('reads all cash discount tiers back and no spurious installment', function () {
        $invoice = (new En16931Calculator)->calculate(Invoices::simple()
            ->paymentTerms(fn ($t) => $t->dueInDays(30)->cashDiscount(10, '3')->cashDiscount(14, '2', '500.00'))
            ->build());
        $xml = (new CiiWriter)->serialize($invoice, ProfileRegistry::withDefaults()->get('extended'));

        $read = (new InvoiceReader)->read($xml)->data;
        $discounts = $read->paymentTerms->discounts ?? [];

        expect($xml)->toContain('ApplicableTradePaymentDiscountTerms')
            ->and($discounts)->toHaveCount(2)
            ->and($discounts[0]->days)->toBe(10)
            ->and($discounts[0]->percent->toString())->toBe('3.00')
            ->and($discounts[1]->days)->toBe(14)
            ->and(Amount::format($discounts[1]->baseAmount))->toBe('500.00')
            ->and($read->extended->installments ?? [])->toBe([])
            ->and($read->dueDate?->toIsoString())->toBe($invoice->dueDate?->toIsoString());
    });

    it('reads installments only from groups with a partial payment amount', function () {
        $xml = hardeningCii('extended');
        $groups = '<ram:SpecifiedTradePaymentTerms><ram:DueDateDateTime><udt:DateTimeString format="102">20261101</udt:DateTimeString></ram:DueDateDateTime><ram:PartialPaymentAmount>595.00</ram:PartialPaymentAmount></ram:SpecifiedTradePaymentTerms>'
            .'<ram:SpecifiedTradePaymentTerms><ram:DueDateDateTime><udt:DateTimeString format="102">20261201</udt:DateTimeString></ram:DueDateDateTime><ram:PartialPaymentAmount>595.00</ram:PartialPaymentAmount></ram:SpecifiedTradePaymentTerms>';
        $xml = hardeningReplace('#<ram:SpecifiedTradePaymentTerms>.*?</ram:SpecifiedTradePaymentTerms>#s', $groups, $xml);

        $installments = (new InvoiceReader)->read($xml)->data->extended->installments ?? [];

        expect($installments)->toHaveCount(2)
            ->and($installments[1]->dueDate->toIsoString())->toBe('2026-12-01');
    });

    it('reads the exchange rate of EXTENDED', function () {
        $invoice = (new En16931Calculator)->calculate(Invoices::simple()->taxCurrency('CHF', '0.9312', '2026-10-01')->build());
        $xml = (new CiiWriter)->serialize($invoice, ProfileRegistry::withDefaults()->get('extended'));

        $result = (new InvoiceReader)->read($xml);

        expect($xml)->toContain('TaxApplicableTradeCurrencyExchange')
            ->and($result->data->meta->exchangeRate?->toString())->toBe('0.9312')
            ->and($result->data->meta->exchangeRateDate?->toIsoString())->toBe('2026-10-01')
            ->and(hardeningCodes($result))->not->toContain('QUITTIO-READER-EXCHANGE-RATE');
    });
});

describe('missing document totals', function () {
    it('reports the missing totals with BR-12 to BR-15 instead of inventing them', function (string $syntax) {
        $xml = $syntax === 'cii'
            ? hardeningReplace('#<ram:SpecifiedTradeSettlementHeaderMonetarySummation>.*?</ram:SpecifiedTradeSettlementHeaderMonetarySummation>#s', '', hardeningCii())
            : hardeningReplace('#<cac:LegalMonetaryTotal>.*?</cac:LegalMonetaryTotal>#s', '', hardeningUbl());

        $read = (new InvoiceReader)->read($xml);
        $validation = Quittio::create()->validateFile($xml);
        $missing = array_values(array_filter($validation->errors(), fn (ValidationMessage $m): bool => str_starts_with($m->code, 'BR-1')));

        expect($read->data->totals)->toBeNull()
            ->and($read->declaredTotals)->toBeNull()
            ->and(hardeningCodes($read, Severity::Error))->toBe(['BR-12', 'BR-13', 'BR-14', 'BR-15'])
            ->and(array_map(fn (ValidationMessage $m): ?string => $m->bt, $missing))->toBe(['BT-106', 'BT-109', 'BT-112', 'BT-115'])
            ->and($validation->isValid())->toBeFalse()
            ->and($validation->has('QUITTIO-NOT-CALCULATED'))->toBeFalse();
    })->with(['cii', 'ubl']);

    it('does not require the sum of line net amounts without lines', function () {
        $xml = hardeningReplace('#<ram:SpecifiedTradeSettlementHeaderMonetarySummation>.*?</ram:SpecifiedTradeSettlementHeaderMonetarySummation>#s', '', hardeningCii('minimum'));

        expect(hardeningCodes((new InvoiceReader)->read($xml), Severity::Error))->toBe(['BR-13', 'BR-14', 'BR-15']);
    });
});
