<?php

declare(strict_types=1);

use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Enums\InvoiceTypeCode;
use Quittio\Core\Enums\PaymentMeansCode;
use Quittio\Core\Enums\UnitCode;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Quittio;
use Quittio\Core\Tests\Support\Official;
use Quittio\Country\AT\CountryAT;
use Quittio\Country\AT\EbInterface\EbInterfaceReader;
use Quittio\Country\AT\EbInterface\EbInterfaceWriter;
use Quittio\Country\AT\Tax\VatPreset;
use Quittio\Country\AT\Tests\Support\Austrian;

function ebInterface(InvoiceBuilder $builder): string
{
    return (new EbInterfaceWriter)->write(calculatedAustrian($builder));
}

function calculatedAustrian(InvoiceBuilder $builder): InvoiceData
{
    return (new En16931Calculator)->calculate($builder->build());
}

/**
 * @return array<string, array{0: Closure(): InvoiceBuilder}>
 */
function ebInterfaceCases(): array
{
    return [
        'simple' => [fn () => Austrian::invoice()],
        'two rates, allowance and charge' => [fn () => Austrian::invoice()
            ->line(fn ($l) => $l->name('Fachbuch')->sellerId('B-17')->quantity(3, UnitCode::Piece)->netPrice('24.90')->vat(VatPreset::Reduced)->allowance('5.00', reason: 'Mengenrabatt'))
            ->allowance(VatCategory::StandardRate, '20', amount: '50.00', reason: 'Treuerabatt')
            ->charge(VatCategory::StandardRate, '20', amount: '12.00', reason: 'Versand')],
        'credit note' => [fn () => Austrian::invoice()->type(InvoiceTypeCode::CreditNote)->precedingInvoice('RE-2026-0001', '2026-10-05')],
        'intra-community supply' => [fn () => Austrian::base()
            ->buyer(fn ($b) => $b->name('Berlin GmbH')->vatId('DE987654321')->address('Unter den Linden 1', '10117', 'Berlin', 'DE'))
            ->line(fn ($l) => $l->name('Maschine')->quantity(1, UnitCode::Piece)->netPrice('2000.00')->vat(VatPreset::IntraCommunitySupply))],
        'period, direct debit and cash discount' => [fn () => Austrian::base(null)->invoicingPeriod('2026-09-01', '2026-09-30')
            ->payment(PaymentMeansCode::SepaDirectDebit)->directDebit('M-77', 'AT61ZZZ01234567890', 'AT483200000012345864')
            ->paymentTerms(fn ($t) => $t->dueInDays(30)->cashDiscount(days: 10, percent: '2.00'))
            ->line(fn ($l) => $l->name('Wartung')->quantity(1, UnitCode::Month)->netPrice('480.00')->vat(VatPreset::Standard)->period('2026-09-01', '2026-09-30'))],
    ];
}

it('maps the invoice type code to the ebInterface document type', function (InvoiceTypeCode $type, string $documentType) {
    expect(EbInterfaceWriter::documentType(calculatedAustrian(Austrian::invoice()->type($type))))->toBe($documentType);
})->with([
    [InvoiceTypeCode::CommercialInvoice, 'Invoice'],
    [InvoiceTypeCode::CreditNote, 'CreditMemo'],
    [InvoiceTypeCode::PrepaymentInvoice, 'InvoiceForAdvancePayment'],
    [InvoiceTypeCode::PartialInvoice, 'InvoiceForPartialDelivery'],
    [InvoiceTypeCode::SelfBilledInvoice, 'SelfBilling'],
    [InvoiceTypeCode::FinalPaymentRequest, 'FinalSettlement'],
    [InvoiceTypeCode::DebitNote, 'SubsequentDebit'],
    [InvoiceTypeCode::CorrectedInvoice, 'Invoice'],
]);

it('writes the parties, the order reference and the amounts', function () {
    $xml = ebInterface(Austrian::invoice()->buyerReference('4700000001')
        ->buyer(fn ($b) => $b->vatId(null)));

    expect($xml)->toContain('<Invoice xmlns="http://www.ebinterface.at/schema/6p1/" GeneratingSystem="Quittio" DocumentType="Invoice" InvoiceCurrency="EUR">')
        ->and($xml)->toContain("<InvoiceRecipient>\n    <VATIdentificationNumber>ATU00000000</VATIdentificationNumber>\n    <OrderReference>\n      <OrderID>4700000001</OrderID>")
        ->and($xml)->toContain('<TaxPercent TaxCategoryCode="S">20</TaxPercent>')
        ->and($xml)->toContain('<TotalGrossAmount>1440.00</TotalGrossAmount>')
        ->and($xml)->toContain('<PayableAmount>1440.00</PayableAmount>');
});

it('reads what it writes, with the declared amounts matching the recalculation', function (Closure $build) {
    $quittio = Quittio::create([], [new CountryAT]);
    $original = calculatedAustrian($build());
    $read = $quittio->read((new EbInterfaceWriter)->write($original));
    $data = $read->data;

    expect($read->syntax)->toBe(EbInterfaceReader::SYNTAX)
        ->and(array_map(fn ($f) => $f->code, $read->findings))->toBe([])
        ->and([$data->number, $data->issueDate->toIsoString(), $data->typeCode, $data->currency])->toBe([$original->number, $original->issueDate->toIsoString(), $original->typeCode, $original->currency])
        ->and([$data->seller->name, $data->seller->vatId, $data->seller->address->city, $data->buyer->name, $data->buyer->vatId])->toBe([$original->seller->name, $original->seller->vatId, $original->seller->address->city, $original->buyer->name, $original->buyer->vatId])
        ->and(array_map(fn ($l) => [$l->item->name, (string) $l->quantity, (string) $l->netAmount, $l->vat->category, (string) $l->vat->rate], $data->lines))
        ->toBe(array_map(fn ($l) => [$l->item->name, (string) $l->quantity, (string) $l->netAmount, $l->vat->category, (string) $l->vat->rate], $original->lines))
        ->and((string) $data->totals?->amountDue)->toBe((string) $original->totals?->amountDue)
        ->and((string) $data->totals?->vatTotal)->toBe((string) $original->totals?->vatTotal)
        ->and(count($data->allowances) + count($data->charges))->toBe(count($original->allowances) + count($original->charges))
        ->and($data->paymentInstructions?->meansCode)->toBe($original->paymentInstructions?->meansCode);
})->with(ebInterfaceCases());

it('writes schema-valid ebInterface 6.1', function (Closure $build) {
    $document = new DOMDocument;
    $document->loadXML(ebInterface($build()));
    $previous = libxml_use_internal_errors(true);
    $valid = $document->schemaValidate(Official::root().'/ebinterface/6p1/Invoice.xsd');
    $errors = array_map(fn ($e) => trim($e->message), libxml_get_errors());
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    expect($errors)->toBe([])->and($valid)->toBeTrue();
})->with(ebInterfaceCases())->group('official')->skip(fn () => ! is_file(Official::root().'/ebinterface/6p1/Invoice.xsd'), 'The ebInterface schema is not available (run tools/schemas/download.sh).');

it('reads a missing VAT identifier and the language', function () {
    $xml = str_replace('InvoiceCurrency="EUR"', 'InvoiceCurrency="EUR" Language="de"', ebInterface(Austrian::invoice()->buyer(fn ($b) => $b->vatId(null))));
    $read = Quittio::create([], [new CountryAT])->read($xml);

    expect($read->data->buyer->vatId)->toBeNull()
        ->and($read->data->meta->locale)->toBe('de');
});
