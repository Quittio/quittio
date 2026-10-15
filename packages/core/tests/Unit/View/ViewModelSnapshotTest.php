<?php

declare(strict_types=1);

use Brick\Math\BigNumber;
use Brick\Money\Money;
use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Data\Presentation\Presentation;
use Quittio\Core\Documents\DocumentBuilder;
use Quittio\Core\Documents\DocumentCalculator;
use Quittio\Core\Documents\Package;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Testing\Scenarios;
use Quittio\Core\View\FormattedValue;
use Quittio\Core\View\InvoiceViewModel;
use Quittio\Core\View\ViewContext;
use Quittio\Core\View\ViewModelFactory;

/*
 * Characterisation of the view model the templates receive: every node with its formatted and
 * raw value, and the aliases that the mandatory field guard follows. Refactors of the view layer
 * must keep these snapshots unchanged.
 */

const VIEW_SNAPSHOT_LOGO = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIyMCIgaGVpZ2h0PSIxMCI+PHJlY3Qgd2lkdGg9IjIwIiBoZWlnaHQ9IjEwIiBmaWxsPSIjMDAwIi8+PC9zdmc+';

function viewSnapshotValue(mixed $value): mixed
{
    return match (true) {
        is_array($value) => array_map(viewSnapshotValue(...), $value),
        is_string($value) => (string) preg_replace('/\p{Zs}/u', ' ', $value),
        $value instanceof FormattedValue => ['formatted' => viewSnapshotValue($value->formatted), 'raw' => viewSnapshotValue($value->raw)],
        $value instanceof Money => $value->getCurrency()->getCurrencyCode().' '.$value->getAmount(),
        $value instanceof BigNumber => (string) $value,
        $value instanceof UnitEnum => $value::class.'::'.$value->name,
        $value instanceof Stringable => viewSnapshotValue((string) $value),
        is_object($value) => $value::class,
        default => $value,
    };
}

function viewSnapshot(InvoiceViewModel $view): string
{
    $data = (fn () => $this->data)->call($view);
    $log = $view->accessLog();
    $aliases = (fn () => $this->aliases)->call($log);

    return json_encode(
        ['data' => viewSnapshotValue($data), 'aliases' => $aliases],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
    )."\n";
}

function invoiceView(string $scenario, string $locale = 'de', ?Presentation $presentation = null): InvoiceViewModel
{
    $invoice = (new En16931Calculator)->calculate(Scenarios::make($scenario)->build());

    return (new ViewModelFactory)->build($invoice, new ViewContext(locale: $locale, defaults: $presentation));
}

function snapshotDocument(DocumentBuilder $builder): InvoiceViewModel
{
    $document = $builder->number('DOC-1')->issueDate('2026-10-01')
        ->seller(fn ($p) => $p->name('Seller Ltd')->vatId('DE123456789')->address('Main Street 1', '12345', 'Sample City', 'DE'))
        ->buyer(fn ($p) => $p->name('Buyer Inc')->address('Station Road 5', '54321', 'Example Town', 'DE'))
        ->build();

    return (new ViewModelFactory)->buildDocument((new DocumentCalculator)->calculate($document), new ViewContext(locale: 'en'));
}

function snapshotLine(string $name, int $quantity, string $price): Closure
{
    return fn ($l) => $l->name($name)->quantity($quantity, 'H87')->netPrice($price)->vat(VatCategory::StandardRate, '19');
}

it('keeps the view model of the scenarios', function (string $scenario) {
    expect(viewSnapshot(invoiceView($scenario)))->toMatchSnapshot();
})->with(Scenarios::names());

it('keeps the English view model', function () {
    expect(viewSnapshot(invoiceView('simple', 'en')))->toMatchSnapshot();
});

it('keeps the view model of a self-billed invoice', function () {
    $invoice = (new En16931Calculator)->calculate(Scenarios::simple()->type('389')->build());

    expect(viewSnapshot((new ViewModelFactory)->build($invoice, new ViewContext(locale: 'de'))))->toMatchSnapshot();
});

it('keeps the view model with logo, footer and texts', function () {
    $presentation = new Presentation(
        logo: VIEW_SNAPSHOT_LOGO,
        senderLine: 'Muster GmbH · Musterstraße 1 · 12345 Musterstadt',
        salutation: 'Sehr geehrte Damen und Herren,',
        introText: 'wir berechnen Ihnen **folgende** Leistungen:',
        closingText: 'Vielen Dank für Ihren Auftrag.',
        footerColumns: [['Muster GmbH', 'Musterstraße 1'], ['IBAN DE02 1203 0000 0000 2020 51']],
        showGrossPrices: true,
        showItemNumbers: true,
        watermark: 'ENTWURF',
        labels: ['invoice_number' => 'Belegnummer'],
    );

    expect(viewSnapshot(invoiceView('simple', 'de', $presentation)))->toMatchSnapshot();
});

it('keeps the view model of the documents', function (InvoiceViewModel $document) {
    expect(viewSnapshot($document))->toMatchSnapshot();
})->with([
    'quote' => fn () => snapshotDocument(DocumentBuilder::quote()
        ->validUntil('2026-10-31')
        ->line(snapshotLine('Consulting', 10, '100.00'))
        ->optionalLine(snapshotLine('Training', 1, '500.00'))
        ->alternativeLine('1', snapshotLine('Remote consulting', 10, '80.00'))),
    'order confirmation' => fn () => snapshotDocument(DocumentBuilder::orderConfirmation()
        ->line(snapshotLine('Machine', 1, '5000.00'))),
    'delivery note' => fn () => snapshotDocument(DocumentBuilder::deliveryNote()
        ->reference('ORDER-7', '2026-09-28')
        ->deliveryDate('2026-10-01')
        ->serialisedLine(fn ($l) => $l->name('Laptop')->quantity(2, 'H87'), ['SN-1', 'SN-2'])
        ->package(new Package('1', 'Box', Brick\Math\BigDecimal::of('4.2')))),
    'proforma' => fn () => snapshotDocument(DocumentBuilder::proforma()
        ->line(snapshotLine('Machine', 1, '5000.00'))),
    'payment reminder' => fn () => snapshotDocument(DocumentBuilder::paymentReminder(2)
        ->openItem('INV-42', '2026-08-01', '1190.00', '2026-08-15', '190.00')
        ->fee('Reminder fee', '5.00')
        ->interest('1000.00', '9.27', '2026-08-16', '2026-10-03')
        ->deadline('2026-10-17')),
    'account statement' => fn () => snapshotDocument(DocumentBuilder::accountStatement()
        ->statementPeriod('2026-09-01', '2026-09-30')
        ->openingBalance('100.00')
        ->openItem('INV-40', '2026-09-02', '500.00', '2026-09-16', '500.00')
        ->openItem('INV-42', '2026-09-20', '1190.00', '2026-10-04')),
]);
