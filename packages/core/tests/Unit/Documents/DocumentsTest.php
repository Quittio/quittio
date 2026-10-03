<?php

declare(strict_types=1);

use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Documents\DocumentBuilder;
use Quittio\Core\Documents\DocumentCalculator;
use Quittio\Core\Documents\DocumentData;
use Quittio\Core\Documents\DocumentType;
use Quittio\Core\Documents\Package;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Templates\PhpTemplateEngine;
use Quittio\Core\View\ViewContext;
use Quittio\Core\View\ViewModelFactory;

function documentParties(DocumentBuilder $builder): DocumentBuilder
{
    return $builder->number('DOC-1')->issueDate('2026-10-01')
        ->seller(fn ($p) => $p->name('Seller Ltd')->vatId('DE123456789')->address('Main Street 1', '12345', 'Sample City', 'DE'))
        ->buyer(fn ($p) => $p->name('Buyer Inc')->address('Station Road 5', '54321', 'Example Town', 'DE'));
}

function documentLine(string $name, int $quantity, string $price): Closure
{
    return fn ($l) => $l->name($name)->quantity($quantity, 'H87')->netPrice($price)->vat(VatCategory::StandardRate, '19');
}

function documentHtml(DocumentData $document): string
{
    $view = (new ViewModelFactory)->buildDocument((new DocumentCalculator)->calculate($document), new ViewContext(locale: 'en'));

    return (new PhpTemplateEngine)->render('default', $view);
}

function quote(): DocumentData
{
    return documentParties(DocumentBuilder::quote())
        ->validUntil('2026-10-31')
        ->line(documentLine('Consulting', 10, '100.00'))
        ->optionalLine(documentLine('Training', 1, '500.00'))
        ->alternativeLine('1', documentLine('Remote consulting', 10, '80.00'))
        ->build();
}

it('excludes optional and alternative lines from the totals', function () {
    $quote = (new DocumentCalculator)->calculate(quote());

    expect($quote->totals?->netTotal->getAmount()->toString())->toBe('1000.00')
        ->and($quote->totals?->amountDue->getAmount()->toString())->toBe('1190.00')
        ->and($quote->lines[1]->netAmount?->getAmount()->toString())->toBe('500.00')
        ->and($quote->options('2')->optional)->toBeTrue()
        ->and($quote->options('3')->alternativeTo)->toBe('1')
        ->and($quote->billableLines())->toHaveCount(1);
});

it('renders a quote with validity, markers and acceptance field', function () {
    $html = documentHtml(quote());

    expect($html)->toContain('Quote')
        ->and($html)->toContain('Valid until')
        ->and($html)->toContain('[Optional]')
        ->and($html)->toContain('Alternative to pos. 1')
        ->and($html)->toContain('Order placed:')
        ->and($html)->not->toContain('Invoice number');
});

it('renders delivery notes without prices', function () {
    $note = documentParties(DocumentBuilder::deliveryNote())
        ->reference('ORDER-7', '2026-09-28')
        ->deliveryDate('2026-10-01')
        ->serialisedLine(fn ($l) => $l->name('Laptop')->quantity(2, 'H87'), ['SN-1', 'SN-2'])
        ->package(new Package('1', 'Box', Brick\Math\BigDecimal::of('4.2')))
        ->build();
    $html = documentHtml($note);

    expect($html)->toContain('Delivery note')
        ->and($html)->toContain('Serial numbers: SN-1, SN-2')
        ->and($html)->toContain('ORDER-7')
        ->and($html)->toContain('Packages')
        ->and($html)->not->toContain('Unit price')
        ->and($html)->not->toContain('Amount due');
});

it('marks proforma invoices as not being invoices', function () {
    $html = documentHtml(documentParties(DocumentBuilder::proforma())->line(documentLine('Machine', 1, '5000.00'))->build());

    expect($html)->toContain('Proforma invoice')
        ->and($html)->toContain('not an invoice for tax purposes');
});

it('sums open items, fees and default interest of a reminder', function () {
    $reminder = documentParties(DocumentBuilder::paymentReminder(2))
        ->openItem('INV-42', '2026-08-01', '1190.00', '2026-08-15', '190.00')
        ->fee('Reminder fee', '5.00')
        ->interest('1000.00', '9.27', '2026-08-16', '2026-10-03')
        ->deadline('2026-10-17')
        ->build();
    $dunning = $reminder->dunning;

    expect($dunning?->openTotal('EUR')->getAmount()->toString())->toBe('1000.00')
        ->and($dunning?->interest[0]->days())->toBe(48)
        ->and($dunning?->interestTotal('EUR')->getAmount()->toString())->toBe('12.19')
        ->and($dunning?->total('EUR')->getAmount()->toString())->toBe('1017.19');

    $html = documentHtml($reminder);
    expect($html)->toContain('Reminder no. 2')
        ->and($html)->toContain('Please pay the total amount by')
        ->and($html)->toContain('1,017.19');
});

it('computes the balance of an account statement', function () {
    $statement = documentParties(DocumentBuilder::accountStatement())
        ->statementPeriod('2026-09-01', '2026-09-30')
        ->openingBalance('100.00')
        ->openItem('INV-40', '2026-09-02', '500.00', '2026-09-16', '500.00')
        ->openItem('INV-42', '2026-09-20', '1190.00', '2026-10-04')
        ->build();

    expect($statement->statement?->balance('EUR')->getAmount()->toString())->toBe('1290.00')
        ->and($statement->statement?->openItems())->toHaveCount(1)
        ->and(documentHtml($statement))->toContain('Account statement');
});

it('turns a quote into an invoice', function () {
    $invoice = (new En16931Calculator)->calculate(
        InvoiceBuilder::fromDocument((new DocumentCalculator)->calculate(quote()))->number('INV-1')->issueDate('2026-11-02')->build(),
    );

    expect($invoice->number)->toBe('INV-1')
        ->and($invoice->lines)->toHaveCount(1)
        ->and($invoice->totals?->amountDue->getAmount()->toString())->toBe('1190.00')
        ->and($invoice->seller->name)->toBe('Seller Ltd');
});

it('references order confirmations and delivery notes in the invoice', function (DocumentType $type, string $property) {
    $builder = documentParties(DocumentBuilder::make($type))->line(documentLine('Item', 1, '10.00'));
    $invoice = InvoiceBuilder::fromDocument($builder->build())->number('INV-2')->build();

    expect($invoice->{$property})->toBe('DOC-1');
})->with([
    [DocumentType::OrderConfirmation, 'salesOrderReference'],
    [DocumentType::DeliveryNote, 'despatchAdviceReference'],
]);

it('requires type specific data', function (Closure $build) {
    $build();
})->with([
    'quote without lines' => [fn () => documentParties(DocumentBuilder::quote())->build()],
])->throws(InvalidArgumentException::class);
