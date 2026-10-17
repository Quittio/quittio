<?php

declare(strict_types=1);

use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Countries\CountryGeneric;
use Quittio\Core\Data\Party\Address;
use Quittio\Core\Enums\InvoiceTypeCode;
use Quittio\Core\Enums\PaymentMeansCode;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Tests\Support\Invoices;
use Quittio\Core\Tests\Support\Validate;
use Quittio\Core\Validation\Severity;
use Quittio\Core\Validation\ValidationLevel;

it('accepts a complete invoice without errors', function () {
    $result = Validate::data(Invoices::simple()->build());

    expect($result->errors())->toBe([])
        ->and($result->isValid())->toBeTrue();
});

it('reports a missing country pack as warning only', function () {
    $result = Validate::data(Invoices::simple()->build(), packs: [new CountryGeneric('DE')]);

    expect($result->isValid())->toBeTrue()
        ->and(Validate::codes($result))->toContain('QUITTIO-GENERIC-COUNTRY');
});

it('detects rule violations', function (Closure $build, string $code) {
    $result = Validate::data($build());

    expect(Validate::codes($result))->toContain($code);
})->with([
    'BR-02 missing number' => [fn () => Invoices::simple()->number('')->build(), 'BR-02'],
    'BR-CO-25 no due date or terms' => [fn () => Invoices::simple()->paymentTerms(new Quittio\Core\Data\Payment\PaymentTerms)->build(), 'BR-CO-25'],
    'BR-CO-26 no seller identification' => [fn () => Invoices::simple()->seller(fn ($s) => $s->vatId(null))->build(), 'BR-CO-26'],
    'BR-CO-09 VAT id without prefix' => [fn () => Invoices::simple()->buyer(fn ($b) => $b->vatId('123456789'))->build(), 'BR-CO-09'],
    'BR-CO-03 tax point date and code' => [fn () => Invoices::simple()->taxPointDate('2026-10-01')->taxPointDateCode(Quittio\Core\Enums\TaxPointDateCode::DeliveryDate)->build(), 'BR-CO-03'],
    'BR-29 period end before start' => [fn () => Invoices::simple()->invoicingPeriod('2026-10-31', '2026-10-01')->build(), 'BR-29'],
    'BR-33 allowance without reason' => [fn () => Invoices::simple()->allowance(VatCategory::StandardRate, '19', amount: '5.00')->build(), 'BR-33'],
    'BR-42 line allowance without reason' => [fn () => Invoices::base()->line(fn ($l) => $l->name('A')->quantity(1)->netPrice('10')->vat(VatCategory::StandardRate, '19')->allowance(amount: '1.00'))->build(), 'BR-42'],
    'BR-27 negative net price' => [fn () => Invoices::base()->line(fn ($l) => $l->name('A')->quantity(1)->netPrice('-10')->vat(VatCategory::StandardRate, '19'))->build(), 'BR-27'],
    'BR-53 tax currency without rate' => [fn () => Invoices::simple()->currency('USD')->taxCurrency('EUR')->build(), 'BR-53'],
    'BR-S-05 standard rate zero' => [fn () => Invoices::base()->line(fn ($l) => $l->name('A')->quantity(1)->netPrice('10')->vat(VatCategory::StandardRate, '0'))->build(), 'BR-S-05'],
    'BR-E-10 exempt without reason' => [fn () => Invoices::base()->line(fn ($l) => $l->name('A')->quantity(1)->netPrice('10')->vat(VatCategory::Exempt, '0'))->build(), 'BR-E-10'],
    'BR-AE-02 reverse charge without buyer VAT id' => [fn () => Invoices::base()->buyer(fn ($b) => $b->vatId(null))->line(fn ($l) => $l->name('A')->quantity(1)->netPrice('10')->vat(VatCategory::ReverseCharge, '0', 'Reverse charge'))->build(), 'BR-AE-02'],
    'BR-IC-12 intra-community without deliver to country' => [fn () => Invoices::base()->buyer(fn ($b) => $b->vatId('FR12345678901'))->line(fn ($l) => $l->name('A')->quantity(1)->netPrice('10')->vat(VatCategory::IntraCommunitySupply, '0', 'Intra-community supply'))->build(), 'BR-IC-12'],
    'BR-O-02 not taxable with VAT id' => [fn () => Invoices::base()->line(fn ($l) => $l->name('A')->quantity(1)->netPrice('10')->vat(VatCategory::NotSubjectToVat, null, 'Not taxable'))->build(), 'BR-O-02'],
    'BR-O-11 not taxable mixed' => [fn () => Invoices::simple()->line(fn ($l) => $l->name('A')->quantity(1)->netPrice('10')->vat(VatCategory::NotSubjectToVat, null, 'Not taxable'))->build(), 'BR-O-11'],
    'BR-CL-23 unknown unit' => [fn () => Invoices::base()->line(fn ($l) => $l->name('A')->quantity(1)->unit('NOPE')->netPrice('10')->vat(VatCategory::StandardRate, '19'))->build(), 'BR-CL-23'],
    'BR-CL-14 unknown country' => [fn () => Invoices::simple()->buyer(fn ($b) => $b->address('x', '1', 'y', 'QQ'))->build(), 'BR-CL-14'],
    'BR-CL-04 unknown currency' => [fn () => Invoices::simple()->currency('XYZ')->build(), 'BR-CL-04'],
    'BR-CL-24 XML attachment outside the XRechnung extension' => [fn () => Invoices::simple()->attachment('ATT-1', '<data/>', Quittio\Core\Enums\MimeType::Xml, 'data.xml')->build(), 'BR-CL-24'],
    'BR-CL-01 unknown type code' => [fn () => Invoices::simple()->type('999')->build(), 'BR-CL-01'],
    'QUITTIO-NEGATIVE-TOTAL' => [fn () => Invoices::base()->line(fn ($l) => $l->name('Return')->quantity(-1)->netPrice('10')->vat(VatCategory::StandardRate, '19'))->build(), 'QUITTIO-NEGATIVE-TOTAL'],
    'QUITTIO-IBAN' => [fn () => Invoices::simple()->payment(PaymentMeansCode::SepaCreditTransfer, iban: 'DE02120300000000202052')->build(), 'QUITTIO-IBAN'],
    'QUITTIO-BIC' => [fn () => Invoices::base()->payment(PaymentMeansCode::SepaCreditTransfer, iban: 'DE02120300000000202051', bic: 'XX')->line(fn ($l) => $l->name('A')->quantity(1)->netPrice('1')->vat(VatCategory::StandardRate, '19'))->build(), 'QUITTIO-BIC'],
    'QUITTIO-VATID-FORMAT' => [fn () => Invoices::simple()->seller(fn ($s) => $s->vatId('DE12345'))->build(), 'QUITTIO-VATID-FORMAT'],
    'QUITTIO-EMAIL' => [fn () => Invoices::simple()->buyer(fn ($b) => $b->electronicAddress('not-an-email', 'EM'))->build(), 'QUITTIO-EMAIL'],
    'QUITTIO-DUE-DATE' => [fn () => Invoices::simple()->dueDate('2026-09-01')->build(), 'QUITTIO-DUE-DATE'],
    'QUITTIO-ATTACHMENT-FILENAME' => [fn () => Invoices::simple()->attachment('A', 'x', 'text/csv', 'a.csv')->attachment('B', 'y', 'text/csv', 'a.csv')->build(), 'QUITTIO-ATTACHMENT-FILENAME'],
]);

it('reports BR-Z-10 for a declared zero rated VAT breakdown with exemption reason', function () {
    $invoice = (new Quittio\Core\Calculation\En16931Calculator)->calculate(Invoices::base()->line(fn ($l) => $l->name('A')->quantity(1)->netPrice('10')->vat(VatCategory::ZeroRated, '0'))->build());
    $declared = $invoice->with(['vatBreakdown' => [$invoice->vatBreakdown[0]->with(['exemptionReason' => 'Why'])]]);

    expect(Validate::codes(Validate::data($declared, calculate: false)))->toContain('BR-Z-10');
});

it('requires exactly one VAT breakdown for categories other than S (BR-E-01, BR-Z-01)', function () {
    $invoice = (new Quittio\Core\Calculation\En16931Calculator)->calculate(Invoices::base()->line(fn ($l) => $l->name('A')->quantity(1)->netPrice('10')->vat(VatCategory::Exempt, '0', 'Exempt'))->build());
    $twice = $invoice->with(['vatBreakdown' => [$invoice->vatBreakdown[0], $invoice->vatBreakdown[0]]]);

    expect(Validate::codes(Validate::data($twice, calculate: false)))->toContain('BR-E-01')
        ->and(Validate::codes(Validate::data($invoice, calculate: false)))->not->toContain('BR-E-01');
});

it('requires sub lines with the VAT category and rate of their parent line', function () {
    $bundle = fn (string $rate) => Invoices::base()
        ->line(fn ($l) => $l->name('Bundle')->quantity(1)->vat(VatCategory::StandardRate, '19')
            ->subLine(fn ($s) => $s->name('Book')->quantity(1)->netPrice('10.00')->vat(VatCategory::StandardRate, $rate))
            ->subLine(fn ($s) => $s->name('Pen')->quantity(1)->netPrice('5.00')->vat(VatCategory::StandardRate, '19')))
        ->build();

    expect(Validate::codes(Validate::data($bundle('7'), 'extended')))->toContain('QUITTIO-SUBLINE-VAT')
        ->and(Validate::codes(Validate::data($bundle('19.00'), 'extended')))->not->toContain('QUITTIO-SUBLINE-VAT');
});

it('warns that CII transmits only one preceding invoice reference', function () {
    $twice = Invoices::simple()->precedingInvoice('INV-1', '2026-09-01')->precedingInvoice('INV-2', '2026-09-02')->build();
    $once = Invoices::simple()->precedingInvoice('INV-1', '2026-09-01')->build();

    expect(Validate::codes(Validate::data($twice)))->toContain('QUITTIO-PRECEDING-INVOICES-CII')
        ->and(Validate::codes(Validate::data($twice, 'peppol')))->not->toContain('QUITTIO-PRECEDING-INVOICES-CII')
        ->and(Validate::codes(Validate::data($once)))->not->toContain('QUITTIO-PRECEDING-INVOICES-CII');
});

it('reports PEPPOL-EN16931-R005 when the tax currency equals the invoice currency', function () {
    $result = Validate::data(Invoices::simple()->taxCurrency('EUR', '1')->build(), 'peppol');

    expect(array_values(array_map(fn ($m) => $m->severity, array_filter($result->messages, fn ($m) => $m->code === 'PEPPOL-EN16931-R005'))))->toBe([Severity::Warning]);
});

it('reports PEPPOL-EN16931-CL008 for electronic address schemes Peppol does not accept', function () {
    $email = Invoices::simple()->build();
    $result = Validate::data($email, 'peppol');
    $messages = array_values(array_filter($result->messages, fn ($m) => $m->code === 'PEPPOL-EN16931-CL008'));

    expect(array_map(fn ($m) => $m->path, $messages))->toBe(['seller.electronicAddress.scheme', 'buyer.electronicAddress.scheme'])
        ->and($messages[0]->severity)->toBe(Severity::Error)
        ->and($messages[0]->message)->toContain('"EM"')
        ->and(Validate::codes(Validate::data($email)))->not->toContain('PEPPOL-EN16931-CL008');

    $participant = Invoices::simple()
        ->seller(fn ($s) => $s->electronicAddress('DE123456789', '9930'))
        ->buyer(fn ($b) => $b->electronicAddress('4000001000005', '0088'))
        ->build();

    expect(Validate::codes(Validate::data($participant, 'peppol')))->not->toContain('PEPPOL-EN16931-CL008');
});

it('reports PEPPOL-EN16931-CL008 for EAS codes that Peppol has not adopted yet', function () {
    $invoice = Invoices::simple()
        ->seller(fn ($s) => $s->electronicAddress('DE123456789', '9930'))
        ->buyer(fn ($b) => $b->electronicAddress('12345', '0242'))
        ->build();

    expect(Validate::codes(Validate::data($invoice)))->not->toContain('BR-CL-25')
        ->and(Validate::codes(Validate::data($invoice, 'peppol')))->toContain('PEPPOL-EN16931-CL008');
});

it('reports BR-61 for credit transfer without account', function () {
    $invoice = InvoiceBuilder::from(Invoices::simple()->build())
        ->payment(PaymentMeansCode::SepaCreditTransfer)
        ->build()
        ->with(['paymentInstructions' => new Quittio\Core\Data\Payment\PaymentInstructions(PaymentMeansCode::SepaCreditTransfer)]);

    expect(Validate::codes(Validate::data($invoice)))->toContain('BR-61');
});

it('accepts correct use of every VAT category', function (Closure $build) {
    $result = Validate::data($build());

    expect($result->errors())->toBe([]);
})->with([
    'zero rated' => [fn () => Invoices::base()->line(fn ($l) => $l->name('A')->quantity(1)->netPrice('10')->vat(VatCategory::ZeroRated, '0'))->build()],
    'exempt' => [fn () => Invoices::base()->line(fn ($l) => $l->name('A')->quantity(1)->netPrice('10')->vat(VatCategory::Exempt, '0', 'Exempt'))->build()],
    'reverse charge' => [fn () => Invoices::base()->line(fn ($l) => $l->name('A')->quantity(1)->netPrice('10')->vat(VatCategory::ReverseCharge, '0', 'Reverse charge', 'VATEX-EU-AE'))->build()],
    'intra-community' => [fn () => Invoices::base()
        ->buyer(fn ($b) => $b->vatId('FR12345678901')->address('Rue 1', '75001', 'Paris', 'FR'))
        ->deliverTo('Buyer Inc', Address::make('Rue 1', '75001', 'Paris', 'FR'))
        ->line(fn ($l) => $l->name('A')->quantity(1)->netPrice('10')->vat(VatCategory::IntraCommunitySupply, '0', 'Intra-community supply', 'VATEX-EU-IC'))->build()],
    'export' => [fn () => Invoices::base()->line(fn ($l) => $l->name('A')->quantity(1)->netPrice('10')->vat(VatCategory::Export, '0', 'Export', 'VATEX-EU-G'))->build()],
    'not subject to VAT' => [fn () => Invoices::base()->seller(fn ($s) => $s->vatId(null)->legalRegistration('HRB 1'))->buyer(fn ($b) => $b->vatId(null))
        ->line(fn ($l) => $l->name('A')->quantity(1)->netPrice('10')->vat(VatCategory::NotSubjectToVat, null, 'Not taxable', 'VATEX-EU-O'))->build()],
    'credit note' => [fn () => Invoices::simple()->type(InvoiceTypeCode::CreditNote)->precedingInvoice('INV-1', '2026-09-01')->build()],
]);

it('detects inconsistent totals in data that was not calculated by the package', function () {
    $invoice = (new Quittio\Core\Calculation\En16931Calculator)->calculate(Invoices::simple()->build());
    $broken = $invoice->with(['totals' => $invoice->totals?->with(['grossTotal' => Quittio\Core\Support\Amount::of('1.00', 'EUR')])]);

    $codes = Validate::codes(Validate::data($broken, calculate: false));

    expect($codes)->toContain('BR-CO-15')->toContain('BR-CO-16');
});

it('reports messages in German with solution hints', function () {
    $result = Validate::data(Invoices::simple()->paymentTerms(new Quittio\Core\Data\Payment\PaymentTerms)->build(), locale: 'de');
    $message = array_values(array_filter($result->messages, fn ($m) => $m->code === 'BR-CO-25'))[0];

    expect($message->message)->toStartWith('Ist der Zahlbetrag (BT-115) positiv')
        ->and($message->hint)->toContain('dueInDays')
        ->and($message->bt)->toBe('BT-9')
        ->and($message->severity)->toBe(Severity::Error)
        ->and($message->level)->toBe(ValidationLevel::L3);
});

it('renders the result as array and HTML', function () {
    $result = Validate::data(Invoices::simple()->number('')->build());

    expect($result->toArray()['valid'])->toBeFalse()
        ->and($result->toHtml())->toContain('BR-02')->toContain('Invalid');
});
