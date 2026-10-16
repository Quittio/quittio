<?php

declare(strict_types=1);

use Brick\Math\BigDecimal;
use Quittio\Core\Data\Lines\InvoiceLine;
use Quittio\Core\Documents\DocumentBuilder;
use Quittio\Core\Documents\DocumentCalculator;
use Quittio\Core\Documents\DocumentData;
use Quittio\Core\Documents\DocumentLineOptions;
use Quittio\Core\Documents\Package;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Serialization\Snapshots\SnapshotUpcasterChain;
use Quittio\Core\Serialization\Snapshots\SnapshotVersionException;

const DOCUMENT_SNAPSHOTS = __DIR__.'/../../Fixtures/snapshots/documents/v1';

function snapshotParties(DocumentBuilder $builder): DocumentBuilder
{
    return $builder->number('DOC-1')->issueDate('2026-10-01')
        ->seller(fn ($p) => $p->name('Seller Ltd')->vatId('DE123456789')->address('Main Street 1', '12345', 'Sample City', 'DE'))
        ->buyer(fn ($p) => $p->name('Buyer Inc')->address('Station Road 5', '54321', 'Example Town', 'DE'));
}

function snapshotDocumentLine(string $name, int $quantity, string $price): Closure
{
    return fn ($l) => $l->name($name)->quantity($quantity, 'H87')->netPrice($price)->vat(VatCategory::StandardRate, '19');
}

/**
 * @return array<string, DocumentData>
 */
function snapshotDocuments(): array
{
    $documents = [
        'quote' => snapshotParties(DocumentBuilder::quote())->validUntil('2026-10-31')
            ->line(snapshotDocumentLine('Consulting', 10, '100.00'))
            ->optionalLine(snapshotDocumentLine('Training', 1, '500.00'))
            ->alternativeLine('1', snapshotDocumentLine('Remote consulting', 10, '80.00')),
        'order-confirmation' => snapshotParties(DocumentBuilder::orderConfirmation())->line(snapshotDocumentLine('Machine', 1, '5000.00')),
        'delivery-note' => snapshotParties(DocumentBuilder::deliveryNote())->reference('ORDER-7', '2026-09-28')->deliveryDate('2026-10-01')
            ->serialisedLine(fn ($l) => $l->name('Laptop')->quantity(2, 'H87'), ['SN-1', 'SN-2'])
            ->package(new Package('1', 'Box', BigDecimal::of('4.2'))),
        'proforma' => snapshotParties(DocumentBuilder::proforma())->line(snapshotDocumentLine('Machine', 1, '5000.00')),
        'payment-reminder' => snapshotParties(DocumentBuilder::paymentReminder(2))
            ->openItem('INV-42', '2026-08-01', '1190.00', '2026-08-15', '190.00')
            ->fee('Reminder fee', '5.00')
            ->interest('1000.00', '9.27', '2026-08-16', '2026-10-03', dayCount: 365)
            ->deadline('2026-10-17'),
        'account-statement' => snapshotParties(DocumentBuilder::accountStatement())->statementPeriod('2026-09-01', '2026-09-30')->openingBalance('100.00')
            ->openItem('INV-40', '2026-09-02', '500.00', '2026-09-16', '500.00')
            ->openItem('INV-42', '2026-09-20', '1190.00', '2026-10-04'),
    ];

    return array_map(fn (DocumentBuilder $b): DocumentData => (new DocumentCalculator)->calculate($b->build()), $documents);
}

it('keeps the stored format of every document type', function (string $name) {
    $file = DOCUMENT_SNAPSHOTS.'/'.$name.'.json';
    $snapshot = snapshotDocuments()[$name]->toSnapshot();
    if (! is_file($file)) {
        file_put_contents($file, json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
        $this->markTestIncomplete('Fixture written.');
    }
    $stored = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

    expect($snapshot)->toBe($stored)
        ->and(DocumentData::fromSnapshot($stored)->toSnapshot())->toBe($stored);
})->with(['quote', 'order-confirmation', 'delivery-note', 'proforma', 'payment-reminder', 'account-statement']);

it('keeps the line options of lines with numeric identifiers', function () {
    $document = snapshotDocuments()['quote'];
    $lines = array_map(fn (InvoiceLine $line, int $index): InvoiceLine => $line->with(['id' => (string) $index]), $document->lines, array_keys($document->lines));
    // Options of the lines "0" and "1" become a JSON list.
    $renumbered = $document->with(['lines' => $lines, 'lineOptions' => ['0' => new DocumentLineOptions(serialNumbers: ['SN-0']), '1' => new DocumentLineOptions(optional: true), '2' => new DocumentLineOptions(alternativeTo: '0')]]);
    $back = DocumentData::fromJson($renumbered->toJson());

    expect($renumbered->toArray()['lineOptions'])->toBeList()
        ->and($back->options('0')->serialNumbers)->toBe(['SN-0'])
        ->and($back->options('1')->optional)->toBeTrue()
        ->and($back->options('2')->alternativeTo)->toBe('0')
        ->and(DocumentData::fromSnapshot($renumbered->toSnapshot())->toSnapshot())->toBe($renumbered->toSnapshot());
});

it('refuses document snapshots of a newer version', function () {
    DocumentData::fromSnapshot([...snapshotDocuments()['proforma']->toSnapshot(), '_version' => 2]);
})->throws(SnapshotVersionException::class, 'reads up to version 1');

it('reads document snapshots with the document chain, not the invoice chain', function () {
    $invoiceChain = new SnapshotUpcasterChain;
    SnapshotUpcasterChain::useDefault($invoiceChain);
    $documentChain = new SnapshotUpcasterChain;
    SnapshotUpcasterChain::useDocumentDefault($documentChain);

    try {
        expect(SnapshotUpcasterChain::documentDefault())->toBe($documentChain)
            ->and(SnapshotUpcasterChain::default())->toBe($invoiceChain)
            ->and(DocumentData::fromSnapshot(snapshotDocuments()['proforma']->toSnapshot())->number)->toBe('DOC-1');
    } finally {
        SnapshotUpcasterChain::useDefault(null);
        SnapshotUpcasterChain::useDocumentDefault(null);
    }
});
