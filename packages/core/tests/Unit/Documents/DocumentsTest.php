<?php

declare(strict_types=1);

use Brick\Math\BigDecimal;
use Brick\Money\Money;
use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Codes\RawCode;
use Quittio\Core\Documents\DocumentBuilder;
use Quittio\Core\Documents\DocumentCalculator;
use Quittio\Core\Documents\DocumentData;
use Quittio\Core\Documents\DocumentType;
use Quittio\Core\Documents\Dunning;
use Quittio\Core\Documents\DunningFee;
use Quittio\Core\Documents\InterestCharge;
use Quittio\Core\Documents\OpenItem;
use Quittio\Core\Documents\Package;
use Quittio\Core\Enums\NoteSubjectCode;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Support\Amount;
use Quittio\Core\Support\Date;
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
        ->package(new Package('1', 'Box', BigDecimal::of('4.2')))
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

it('rejects lines, allowances and charges on reminders and account statements', function (Closure $build) {
    $build();
})->with([
    'reminder with a line' => [fn () => documentParties(DocumentBuilder::paymentReminder())
        ->openItem('INV-42', '2026-08-01', '1190.00')
        ->line(documentLine('Reminder fee', 1, '5.00'))
        ->build()],
    'statement with a line' => [fn () => documentParties(DocumentBuilder::accountStatement())
        ->openItem('INV-42', '2026-09-20', '1190.00')
        ->line(documentLine('Item', 1, '5.00'))
        ->build()],
])->throws(InvalidArgumentException::class, 'has no lines');

it('starts reminders at level 0 (payment reminder) and rejects negative levels', function () {
    $reminder = documentParties(DocumentBuilder::paymentReminder())->openItem('INV-42', '2026-08-01', '1190.00')->build();

    expect($reminder->dunning?->level)->toBe(0)
        ->and(fn () => new Dunning(-1, []))->toThrow(InvalidArgumentException::class);
});

it('adds fees in any money context without VAT and names the open invoices for the remittance', function () {
    $dunning = new Dunning(
        level: 1,
        openItems: [
            new OpenItem('INV-41', Date::parse('2026-07-01'), Money::of('100.00', 'EUR'), paid: Money::of('100.00', 'EUR')),
            new OpenItem('INV-42', Date::parse('2026-08-01'), Amount::of('1190.00', 'EUR')),
            new OpenItem('INV-43', Date::parse('2026-08-05'), Amount::of('50.00', 'EUR'), paid: Amount::of('10.00', 'EUR')),
        ],
        fees: [new DunningFee('Flat rate (section 288 (5) BGB)', Money::of('40.00', 'EUR'))],
    );

    expect($dunning->total('EUR')->getAmount()->toString())->toBe('1270.00')
        ->and($dunning->openInvoiceNumbers())->toBe(['INV-42', 'INV-43'])
        ->and($dunning->remittanceText())->toBe('INV-42, INV-43')
        ->and($dunning->remittanceText(10))->toBe('INV-42');
});

it('counts default interest actual/actual per calendar year', function (string $from, string $until, ?int $dayCount, string $expected) {
    $charge = new InterestCharge(Amount::of('1000.00', 'EUR'), BigDecimal::of('10.52'), Date::parse($from), Date::parse($until), dayCount: $dayCount);

    expect($charge->total()->getAmount()->toString())->toBe($expected);
})->with([
    'full leap year' => ['2028-01-01', '2029-01-01', null, '105.20'],
    'full common year' => ['2027-01-01', '2028-01-01', null, '105.20'],
    'across the turn of the year' => ['2027-12-01', '2028-02-01', null, '17.85'],
    'fixed basis 365' => ['2028-01-01', '2029-01-01', 365, '105.49'],
    'fixed basis 360' => ['2027-01-01', '2027-01-31', 360, '8.77'],
    'no days' => ['2027-01-01', '2027-01-01', null, '0.00'],
]);

it('names the last day with interest and rejects unknown day count bases', function () {
    $charge = new InterestCharge(Amount::of('1000.00', 'EUR'), BigDecimal::of('10.27'), Date::parse('2026-02-01'), Date::parse('2026-03-01'));

    expect($charge->days())->toBe(28)
        ->and($charge->lastDay()->toIsoString())->toBe('2026-02-28')
        ->and(fn () => new InterestCharge(Amount::of('1.00', 'EUR'), BigDecimal::one(), Date::parse('2026-01-01'), Date::parse('2026-02-01'), dayCount: 364))
        ->toThrow(InvalidArgumentException::class);
});

it('accepts only items of the statement period when an opening balance is given', function () {
    $statement = function (bool $opening, string $date): DocumentData {
        $builder = documentParties(DocumentBuilder::accountStatement())
            ->statementPeriod('2026-09-01', '2026-09-30')
            ->openItem('INV-'.$date, $date, '100.00');

        return ($opening ? $builder->openingBalance('500.00') : $builder)->build();
    };

    expect(fn () => $statement(true, '2026-08-15'))->toThrow(InvalidArgumentException::class, 'opening balance already contains it')
        ->and(fn () => $statement(false, '2026-10-01'))->toThrow(InvalidArgumentException::class, 'after the end of the statement period')
        ->and($statement(false, '2026-08-15')->statement?->balance('EUR')->getAmount()->toString())->toBe('100.00')
        ->and($statement(true, '2026-09-15')->statement?->balance('EUR')->getAmount()->toString())->toBe('600.00');
});

it('names the document type in the view model instead of the type code of the internal draft', function (Closure $build, string $type) {
    $view = (new ViewModelFactory)->buildDocument((new DocumentCalculator)->calculate($build()), new ViewContext(locale: 'en'));

    expect($view->document->typeCode)->toBeNull()
        ->and((string) $view->document->type)->toBe($type)
        ->and($view->document->isInvoice)->toBeFalse()
        ->and($view->document->isCreditNote)->toBeFalse();
})->with([
    'quote' => [fn () => quote(), 'Quote'],
    'delivery note' => [fn () => documentParties(DocumentBuilder::deliveryNote())->line(fn ($l) => $l->name('Laptop')->quantity(1, 'H87'))->build(), 'Delivery note'],
    'reminder' => [fn () => documentParties(DocumentBuilder::paymentReminder(2))->openItem('INV-42', '2026-08-01', '1190.00')->build(), 'Payment reminder'],
    'statement' => [fn () => documentParties(DocumentBuilder::accountStatement())->statementPeriod('2026-09-01', '2026-09-30')->openItem('INV-42', '2026-09-20', '1190.00')->build(), 'Account statement'],
]);

it('accepts amounts as BigDecimal, int or string like the invoice builder', function () {
    $reminder = documentParties(DocumentBuilder::paymentReminder(1))
        ->openItem('INV-42', '2026-08-01', '100.00', '2026-08-15')
        ->fee('Reminder fee', BigDecimal::of('2.50'))
        ->fee('Postage', 1)
        ->build();
    $statement = documentParties(DocumentBuilder::accountStatement())
        ->statementPeriod('2026-09-01', '2026-09-30')
        ->openingBalance(BigDecimal::of('100.00'))
        ->build();
    $quote = documentParties(DocumentBuilder::quote())
        ->line(documentLine('Beratung', 1, '100.00'))
        ->discountAmount(10, 'Treuerabatt', 'loyalty')
        ->note('Hinweis', 'AAI')
        ->build();

    expect($reminder->dunning?->total('EUR')->getAmount()->toString())->toBe('103.50')
        ->and($statement->statement?->balance('EUR')->getAmount()->toString())->toBe('100.00')
        ->and($quote->allowances[0]->reasonCode)->toEqual(new RawCode('loyalty'))
        ->and($quote->notes[0]->subjectCode)->toBe(NoteSubjectCode::from('AAI'))
        ->and(fn () => DocumentBuilder::quote()->fee('Fee', 2.5))->toThrow(InvalidArgumentException::class);
});
