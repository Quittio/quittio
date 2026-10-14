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

it('omits the tax currency (BT-6) when it equals the invoice currency', function () {
    $invoice = (new En16931Calculator)->calculate(Quittio\Core\Tests\Support\Invoices::simple()->taxCurrency('EUR', '1')->build());
    $profile = ProfileRegistry::withDefaults()->get('en16931');

    $cii = (new CiiWriter)->serialize($invoice, $profile);
    $ubl = (new Quittio\Core\Xml\Ubl\UblWriter)->serialize($invoice, $profile);

    expect($cii)->not->toContain('TaxCurrencyCode')
        ->and(substr_count($cii, '<ram:TaxTotalAmount'))->toBe(1)
        ->and($ubl)->not->toContain('TaxCurrencyCode')
        ->and(substr_count($ubl, '<cac:TaxTotal>'))->toBe(1);
});

it('writes only the seller country of the postal address in MINIMUM', function () {
    $xml = ciiFor('simple', 'minimum');
    preg_match('#<ram:SellerTradeParty>.*?</ram:SellerTradeParty>#s', $xml, $seller);

    expect($seller[0] ?? '')->toContain('<ram:CountryID>DE</ram:CountryID>')
        ->and($seller[0] ?? '')->not->toContain('ram:PostcodeCode')
        ->and($seller[0] ?? '')->not->toContain('ram:LineOne')
        ->and($seller[0] ?? '')->not->toContain('ram:CityName')
        ->and(ciiFor('simple', 'basic-wl'))->toContain('ram:CityName');
});

it('writes free payment terms text before structured cash discount lines', function () {
    $profile = new class extends Quittio\Core\Profiles\AbstractProfile implements Quittio\Core\Profiles\Capabilities\StructuredCashDiscountText
    {
        public function key(): string
        {
            return 'structured-test';
        }

        public function name(): string
        {
            return 'Structured test';
        }

        public function level(): Quittio\Core\Profiles\ProfileLevel
        {
            return Quittio\Core\Profiles\ProfileLevel::En16931;
        }

        public function specificationId(Quittio\Core\Standards\StandardVersion $version = Quittio\Core\Standards\StandardVersion::En16931_2017): string
        {
            return 'urn:cen.eu:en16931:2017';
        }

        public function xmpConformanceLevel(): ?string
        {
            return null;
        }
    };
    $invoice = (new En16931Calculator)->calculate(Quittio\Core\Tests\Support\Invoices::simple()
        ->paymentTerms(fn ($t) => $t->dueInDays(30)->cashDiscount(14, '2')->cashDiscount(7, '3.125', '500.00')->lateFee(text: 'Late payment interest: 9 percentage points above the base rate.'))
        ->build());

    expect(Quittio\Core\Xml\PaymentTermsText::render($invoice, $profile, 'en'))->toBe(
        "Payable within 30 days without deduction.\n"
        ."Late payment interest: 9 percentage points above the base rate.\n"
        ."#SKONTO#TAGE=14#PROZENT=2.00#\n"
        ."#SKONTO#TAGE=7#PROZENT=3.13#BASISBETRAG=500.00#\n"
    );
});

it('repeats the exemption reason of the VAT breakdown on lines and allowances in EXTENDED', function () {
    $invoice = (new En16931Calculator)->calculate(Scenarios::make('reverse-charge')
        ->allowance(Quittio\Core\Enums\VatCategory::ReverseCharge, '0', amount: '20.00', reason: 'Treuerabatt')
        ->build());
    $registry = ProfileRegistry::withDefaults();
    $extended = (new CiiWriter)->serialize($invoice, $registry->get('extended'));
    $en16931 = (new CiiWriter)->serialize($invoice, $registry->get('en16931'));
    preg_match('#<ram:SpecifiedLineTradeSettlement>\s*<ram:ApplicableTradeTax>(.*?)</ram:ApplicableTradeTax>#s', $extended, $lineTax);
    preg_match('#<ram:CategoryTradeTax>(.*?)</ram:CategoryTradeTax>#s', $extended, $allowanceTax);
    $expected = '<ram:TypeCode>VAT</ram:TypeCode><ram:ExemptionReason>Steuerschuldnerschaft des Leistungsempfängers</ram:ExemptionReason>'
        .'<ram:CategoryCode>AE</ram:CategoryCode><ram:ExemptionReasonCode>VATEX-EU-AE</ram:ExemptionReasonCode><ram:RateApplicablePercent>0.00</ram:RateApplicablePercent>';

    expect(preg_replace('/>\s+</', '><', trim($lineTax[1] ?? '')))->toBe($expected)
        ->and(preg_replace('/>\s+</', '><', trim($allowanceTax[1] ?? '')))->toBe($expected)
        ->and(substr_count($extended, '<ram:ExemptionReasonCode>'))->toBe(3)
        ->and(substr_count($en16931, '<ram:ExemptionReasonCode>'))->toBe(1)
        ->and((new XsdValidator)->validateXml($extended))->toBe([]);
});

it('writes no exemption reason on lines of taxed categories in EXTENDED', function () {
    $xml = ciiFor('mixed-vat', 'extended');

    expect($xml)->not->toContain('ExemptionReason');
});

it('writes one payment terms group per cash discount tier in EXTENDED', function () {
    $invoice = (new En16931Calculator)->calculate(Quittio\Core\Tests\Support\Invoices::simple()
        ->paymentTerms(fn ($t) => $t->dueInDays(30)->cashDiscount(10, '3')->cashDiscount(14, '2', '500.00'))
        ->build());
    $registry = ProfileRegistry::withDefaults();
    $extended = (new CiiWriter)->serialize($invoice, $registry->get('extended'));
    preg_match_all('#<ram:SpecifiedTradePaymentTerms>(.*?)</ram:SpecifiedTradePaymentTerms>#s', $extended, $groups);

    expect($groups[1])->toHaveCount(2)
        ->and($groups[1][0])->toContain('<ram:Description>')
        ->and($groups[1][0])->toContain('<ram:DueDateDateTime>')
        ->and($groups[1][0])->toContain('<ram:BasisPeriodMeasure unitCode="DAY">10</ram:BasisPeriodMeasure>')
        ->and($groups[1][1])->not->toContain('<ram:Description>')
        ->and($groups[1][1])->toContain('<ram:BasisPeriodMeasure unitCode="DAY">14</ram:BasisPeriodMeasure>')
        ->and($groups[1][1])->toContain('<ram:BasisAmount>500.00</ram:BasisAmount>')
        ->and((new XsdValidator)->validateXml($extended))->toBe([])
        ->and(substr_count((new CiiWriter)->serialize($invoice, $registry->get('en16931')), '<ram:SpecifiedTradePaymentTerms>'))->toBe(1);
});

it('states the exchange rate of the tax currency in EXTENDED only', function () {
    $invoice = (new En16931Calculator)->calculate(Scenarios::make('foreign-currency')->build());
    $registry = ProfileRegistry::withDefaults();
    $extended = (new CiiWriter)->serialize($invoice, $registry->get('extended'));

    expect(preg_replace('/>\s+</', '><', $extended))->toContain('<ram:TaxApplicableTradeCurrencyExchange><ram:SourceCurrencyCode>USD</ram:SourceCurrencyCode>'
        .'<ram:TargetCurrencyCode>EUR</ram:TargetCurrencyCode><ram:ConversionRate>0.912345</ram:ConversionRate>'
        .'<ram:ConversionRateDateTime><udt:DateTimeString format="102">20261002</udt:DateTimeString></ram:ConversionRateDateTime>'
        .'</ram:TaxApplicableTradeCurrencyExchange>')
        ->and((new XsdValidator)->validateXml($extended))->toBe([])
        ->and((new CiiWriter)->serialize($invoice, $registry->get('en16931')))->not->toContain('TaxApplicableTradeCurrencyExchange')
        ->and(ciiFor('simple', 'extended'))->not->toContain('TaxApplicableTradeCurrencyExchange');
});

it('formats cash discount texts for German invoices with German numbers and dates', function () {
    $invoice = (new En16931Calculator)->calculate(Quittio\Core\Tests\Support\Invoices::simple()
        ->issueDate('2026-10-03')
        ->paymentTerms(fn ($t) => $t->dueInDays(30)->cashDiscount(10, '3')->cashDiscount(14, '2.5'))
        ->build());
    $profile = ProfileRegistry::withDefaults()->get('en16931');

    expect(Quittio\Core\Xml\PaymentTermsText::render($invoice, $profile, 'de'))->toBe(
        "Zahlbar innerhalb von 30 Tagen ohne Abzug.\n"
        ."3 % Skonto bei Zahlung innerhalb von 10 Tagen (bis 13.10.2026).\n"
        .'2,5 % Skonto bei Zahlung innerhalb von 14 Tagen (bis 17.10.2026).'
    )->and(Quittio\Core\Xml\PaymentTermsText::render($invoice, $profile, 'en'))->toBe(
        "Payable within 30 days without deduction.\n"
        ."3.00 % cash discount for payment within 10 days (until 2026-10-13).\n"
        .'2.50 % cash discount for payment within 14 days (until 2026-10-17).'
    )->and((new CiiWriter)->serialize($invoice, $profile, new Quittio\Core\Xml\SerializationOptions(locale: 'de')))
        ->toContain('2,5 % Skonto bei Zahlung innerhalb von 14 Tagen (bis 17.10.2026).');
});
