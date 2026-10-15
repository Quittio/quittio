<?php

declare(strict_types=1);

use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Builder\Party;
use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Data\Meta;
use Quittio\Core\Documents\DocumentBuilder;
use Quittio\Core\Documents\DocumentCalculator;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Profiles\ProfileRegistry;
use Quittio\Core\Reading\InvoiceReader;
use Quittio\Core\Testing\Scenarios;
use Quittio\Core\Tests\Support\Invoices;
use Quittio\Core\Xml\Cii\CiiWriter;

/*
 * Characterisation of what the builder produces, including editing with from(), split
 * discounts and seller defaults. Refactors of InvoiceBuilder must keep these snapshots unchanged.
 */

function corpusSnapshot(InvoiceData $invoice): string
{
    return json_encode($invoice->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
}

function corpusCalculated(InvoiceBuilder $builder): InvoiceData
{
    return (new En16931Calculator)->calculate($builder->build());
}

function corpusLine(string $name, string $price, string $rate = '19'): Closure
{
    return fn ($l) => $l->name($name)->quantity(1)->netPrice($price)->vat(VatCategory::StandardRate, $rate);
}

function corpusDefaults(InvoiceBuilder $builder): InvoiceBuilder
{
    return $builder
        ->seller(Party::make('Muster GmbH')->vatId('DE123456789')->address('Hauptstraße 1', '12345', 'Musterstadt', 'DE'))
        ->creditTransfer('DE02120300000000202051', 'BYLADEM1001', 'Muster GmbH')
        ->flag(Meta::FLAG_SMALL_BUSINESS)
        ->timezone('Europe/Berlin')
        ->paymentTerms('Zahlbar ohne Abzug.');
}

function corpusWithDefaults(): InvoiceBuilder
{
    return InvoiceBuilder::make()->number('INV-1')->issueDate('2026-10-03')
        ->applyDefaults(corpusDefaults(...))
        ->buyer(Party::make('Kunde AG')->address('Weg 1', '54321', 'Beispielstadt', 'DE'))
        ->line(corpusLine('Logo', '650.00'));
}

it('keeps what the scenarios build', function (string $scenario) {
    expect(corpusSnapshot(Scenarios::make($scenario)->build()))->toMatchSnapshot();
})->with(Scenarios::names());

it('keeps what the builder produces', function (InvoiceData $invoice) {
    expect(corpusSnapshot($invoice))->toMatchSnapshot();
})->with([
    'from unchanged' => fn () => InvoiceBuilder::from(corpusCalculated(Scenarios::make('mixed-vat')))->build(),
    'from with updated line' => fn () => InvoiceBuilder::from(corpusCalculated(Invoices::simple()))
        ->updateLine('1', netPrice: '120.00', quantity: '3', name: 'Changed')
        ->build(),
    'from with removed and added line' => fn () => InvoiceBuilder::from(corpusCalculated(Invoices::base()
        ->line(corpusLine('A', '10.00'))->line(corpusLine('B', '20.00'))->line(corpusLine('C', '30.00'))))
        ->removeLine('2')
        ->line(corpusLine('D', '40.00', '7'))
        ->build(),
    'percentage discount split over VAT rates' => fn () => Invoices::base()
        ->line(corpusLine('A', '100.00'))->line(corpusLine('B', '50.00', '7'))
        ->discountPercent('10', 'Loyalty')
        ->build(),
    'amount discount split over VAT rates' => fn () => Invoices::base()
        ->line(corpusLine('A', '100.00'))->line(corpusLine('B', '50.00', '7'))
        ->discountAmount('30.00', 'Goodwill')
        ->build(),
    'split discount after a line change' => fn () => InvoiceBuilder::from(corpusCalculated(Invoices::base()
        ->line(corpusLine('A', '100.00'))->line(corpusLine('B', '50.00', '7'))
        ->discountPercent('10', 'Loyalty')))
        ->updateLine('2', netPrice: '80.00')
        ->build(),
    'allowances and charges with bases' => fn () => Invoices::base()
        ->line(corpusLine('A', '200.00'))
        ->allowance(VatCategory::StandardRate, '19', percent: '3', reason: 'Discount')
        ->charge(VatCategory::StandardRate, '19', amount: '15.00', reason: 'Freight')
        ->build(),
    'seller defaults kept' => fn () => corpusWithDefaults()->build(),
    'seller defaults removed by another seller' => fn () => corpusWithDefaults()
        ->seller(Party::make('Lisa Klein')->taxNumber('21/815/08150')->address('Gartenstraße 7', '12345', 'Musterstadt', 'DE'))
        ->build(),
    'seller defaults continued' => fn () => corpusWithDefaults()
        ->paymentTerms(fn ($t) => $t->dueInDays(30))
        ->seller(fn ($s) => $s->contact('Max Muster'))
        ->build(),
    'line groups' => fn () => Invoices::base()
        ->group('g1', 'Hardware')
        ->line(fn ($l) => $l->name('A')->quantity(1)->netPrice('10.00')->vat(VatCategory::StandardRate, '19')->group('g1'))
        ->line(corpusLine('B', '20.00'))
        ->build(),
    'prepaid and rounding' => fn () => Invoices::simple()->prepaid('100.00')->addPrepaid('50')->rounding('0.01')->build(),
    'draft without lines' => fn () => Invoices::base()->buildDraft(),
    'quote turned into an invoice' => fn () => InvoiceBuilder::fromDocument((new DocumentCalculator)->calculate(
        DocumentBuilder::quote()->number('Q-1')->issueDate('2026-10-01')
            ->seller(fn ($p) => $p->name('Seller Ltd')->vatId('DE123456789')->address('Main Street 1', '12345', 'Sample City', 'DE'))
            ->buyer(fn ($p) => $p->name('Buyer Inc')->address('Station Road 5', '54321', 'Example Town', 'DE'))
            ->line(corpusLine('Consulting', '100.00'))
            ->optionalLine(corpusLine('Training', '500.00'))
            ->build(),
    ))->number('INV-1')->issueDate('2026-11-02')->build(),
    'read invoice edited' => fn () => InvoiceBuilder::from(
        (new InvoiceReader)->read((new CiiWriter)->serialize(corpusCalculated(Scenarios::make('allowances')), ProfileRegistry::withDefaults()->get('en16931')))->data,
    )->note('Edited after reading')->build(),
]);
