<?php

declare(strict_types=1);

use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Config;
use Quittio\Core\Corrections\Cancellation;
use Quittio\Core\Data\Extensions\ThirdPartyPayment;
use Quittio\Core\Data\Extensions\XRechnungExtensionData;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Data\Meta;
use Quittio\Core\Enums\InvoiceTypeCode;
use Quittio\Core\Enums\NoteSubjectCode;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Exceptions\ConfigurationException;
use Quittio\Core\Profiles\ProfileRegistry;
use Quittio\Core\Quittio;
use Quittio\Core\Reading\InvoiceReader;
use Quittio\Core\Support\Amount;
use Quittio\Core\Support\Date;
use Quittio\Core\Testing\Scenarios;
use Quittio\Core\Tests\Support\Validate;
use Quittio\Core\Xml\Cii\CiiWriter;

function original(string $scenario = 'mixed-vat'): InvoiceData
{
    return (new En16931Calculator)->calculate(Scenarios::make($scenario)->build());
}

it('cancels with a credit note and positive amounts', function () {
    $original = original();
    $cancellation = (new En16931Calculator)->calculate(
        Cancellation::of($original, 'ST-1', reason: 'Doppelt berechnet', issueDate: Date::of(2026, 10, 5), locale: 'de')->build(),
    );

    expect($cancellation->typeCodeValue())->toBe('381')
        ->and($cancellation->number)->toBe('ST-1')
        ->and($cancellation->precedingInvoices[0]->number)->toBe('RE-2026-0042')
        ->and($cancellation->notes[0]->content)->toBe('Stornierung der Rechnung RE-2026-0042 vom 03.10.2026. Grund: Doppelt berechnet')
        ->and($cancellation->totals?->amountDue->getAmount()->toString())->toBe($original->totals?->amountDue->getAmount()->toString())
        ->and($original->number)->toBe('RE-2026-0042');
});

it('cancels with a negative invoice', function () {
    $original = original();
    $cancellation = (new En16931Calculator)->calculate(
        Cancellation::of($original, 'ST-2', strategy: 'negative_invoice', locale: 'en')->build(),
    );

    expect($cancellation->typeCodeValue())->toBe('380')
        ->and($cancellation->totals?->amountDue->getAmount()->toString())->toBe($original->totals?->amountDue->negated()->getAmount()->toString())
        ->and($cancellation->totals?->vatTotal?->getAmount()->toString())->toBe($original->totals?->vatTotal?->negated()->getAmount()->toString())
        ->and($cancellation->lines[0]->quantity->isNegative())->toBeTrue()
        ->and($cancellation->notes[0]->content)->toBe('Cancellation of invoice RE-2026-0042 of 03/10/2026.');
});

it('takes the strategy from the configuration', function () {
    $quittio = Quittio::create(['corrections' => ['cancellation_strategy' => 'negative_invoice']]);

    expect($quittio->cancellation(original('simple'), 'ST-3')->build()->typeCodeValue())->toBe('380')
        ->and($quittio->cancellation(original('simple'), 'ST-4', strategy: 'credit_note')->build()->typeCodeValue())->toBe('381')
        ->and(Quittio::create()->cancellation(original('simple'), 'ST-5')->build()->typeCodeValue())->toBe('381');
});

it('renders valid cancellations in both strategies', function (string $strategy, string $format) {
    $quittio = Quittio::create();
    $result = $quittio->make($quittio->cancellation(original(), 'ST-6', 'Test', $strategy))->format($format)->render();

    expect($result->validation()->errors())->toBe([]);
})->with(['credit_note', 'negative_invoice'])->with(['zugferd', 'ubl']);

it('rejects unknown strategies', function () {
    Cancellation::of(original('simple'), 'ST-7', strategy: 'storno');
})->throws(InvalidArgumentException::class);

it('rejects unknown strategies in the configuration', function () {
    new Config(cancellationStrategy: 'storno');
})->throws(ConfigurationException::class);

function linesOf(InvoiceData $invoice): array
{
    return array_map(static fn ($line): array => [$line->id, $line->quantity->toString(), $line->netAmount?->getAmount()->toString()], $invoice->lines);
}

it('cancels single lines, quantities and amounts', function (string $strategy, string $sign) {
    $original = original('simple');
    $cancellation = (new En16931Calculator)->calculate(Cancellation::of($original, 'ST-8', strategy: $strategy, lines: [
        ['line' => '2'],
        ['line' => '1', 'quantity' => '4'],
    ])->build());

    expect(linesOf($cancellation))->toBe([
        ['2', $sign.'1', $sign.'29.00'],
        ['1', $sign.'4', $sign.'380.00'],
    ])->and($cancellation->totals?->netTotal->getAmount()->toString())->toBe($sign.'409.00');

    $byAmount = (new En16931Calculator)->calculate(Cancellation::of($original, 'ST-9', strategy: $strategy, lines: [['line' => '1', 'amount' => '50.00']])->build());
    expect(linesOf($byAmount))->toBe([['1', $sign.'1', $sign.'50.00']])
        ->and($byAmount->lines[0]->item->name)->toBe('Webentwicklung')
        ->and($byAmount->lines[0]->vat->rate?->toString())->toBe('19');
})->with([['credit_note', ''], ['negative_invoice', '-']]);

it('scales line allowances for partial quantities', function () {
    $original = (new En16931Calculator)->calculate(Scenarios::base()->line(fn ($l) => $l
        ->name('Schrauben')->quantity(10, 'H87')->netPrice('10.00')->vat(VatCategory::StandardRate, '19')
        ->allowance(amount: '10.00', reason: 'Mengenrabatt'))->build());
    $cancellation = (new En16931Calculator)->calculate(Cancellation::of($original, 'ST-10', lines: [['line' => '1', 'quantity' => '4']])->build());

    expect($original->lines[0]->netAmount?->getAmount()->toString())->toBe('90.00')
        ->and($cancellation->lines[0]->allowances[0]->amount?->getAmount()->toString())->toBe('4.00')
        ->and($cancellation->lines[0]->netAmount?->getAmount()->toString())->toBe('36.00');
});

it('keeps the sign of negative lines in partial cancellations', function (string $strategy, string $sign) {
    $original = (new En16931Calculator)->calculate(Scenarios::base()
        ->line(fn ($l) => $l->name('Beratung')->quantity(2, 'HUR')->netPrice('100.00')->vat(VatCategory::StandardRate, '19'))
        ->line(fn ($l) => $l->name('Rabatt')->quantity(-2, 'C62')->netPrice('25.00')->vat(VatCategory::StandardRate, '19'))
        ->build());
    $cancel = fn (array $lines): InvoiceData => (new En16931Calculator)->calculate(Cancellation::of($original, 'ST-15', strategy: $strategy, lines: $lines)->build());

    // The discount line (-2 x 25.00 = -50.00) is reversed, never charged a second time.
    expect(linesOf($cancel([['line' => '2']])))->toBe([['2', $sign.'2', $sign.'50.00']])
        ->and(linesOf($cancel([['line' => '2', 'quantity' => '1']])))->toBe([['2', $sign.'1', $sign.'25.00']])
        ->and(linesOf($cancel([['line' => '2', 'amount' => '20.00']])))->toBe([['2', $sign.'1', $sign.'20.00']])
        ->and($cancel([['line' => '2', 'amount' => '20.00']])->lines[0]->price->netPrice->toString())->toBe('20.00');
})->with([['credit_note', '-'], ['negative_invoice', '']]);

it('reverses a credit note with an invoice in both strategies', function (string $strategy) {
    $credit = (new En16931Calculator)->calculate(Scenarios::make('simple')->type(InvoiceTypeCode::CreditNote)->build());
    $cancellation = (new En16931Calculator)->calculate(Cancellation::of($credit, 'ST-16', strategy: $strategy)->build());

    expect($cancellation->typeCodeValue())->toBe('380')
        ->and($cancellation->totals?->amountDue->getAmount()->toString())->toBe($credit->totals?->amountDue->getAmount()->toString())
        ->and($cancellation->lines[0]->quantity->isPositive())->toBeTrue();
})->with(['credit_note', 'negative_invoice']);

it('reverses prepaid and rounding amounts and keeps regulatory notes', function (string $strategy, bool $negated) {
    $original = (new En16931Calculator)->calculate(Scenarios::make('simple')
        ->prepaid('100.00')->rounding('0.01')
        ->note('Bitte bewahren Sie diese Rechnung zwei Jahre auf.', NoteSubjectCode::RegulatoryInformation)
        ->note('Vielen Dank für Ihren Auftrag.')
        ->build());
    $cancellation = (new En16931Calculator)->calculate(Cancellation::of($original, 'ST-17', strategy: $strategy, locale: 'de')->build());
    $partial = (new En16931Calculator)->calculate(Cancellation::of($original, 'ST-18', strategy: $strategy, lines: [['line' => '2']])->build());
    $due = $original->totals?->amountDue;

    expect($cancellation->totals?->amountDue->isEqualTo($negated ? $due?->negated() : $due))->toBeTrue()
        ->and($cancellation->prepaidAmount?->getAmount()->toString())->toBe($negated ? '-100.00' : '100.00')
        ->and($cancellation->roundingAmount?->getAmount()->toString())->toBe($negated ? '-0.01' : '0.01')
        ->and(array_map(static fn ($note): string => $note->content, $cancellation->notes))->toBe([
            'Stornierung der Rechnung RE-2026-0042 vom 03.10.2026.',
            'Bitte bewahren Sie diese Rechnung zwei Jahre auf.',
        ])
        ->and($partial->prepaidAmount)->toBeNull()
        ->and($partial->roundingAmount)->toBeNull();
})->with([['credit_note', false], ['negative_invoice', true]]);

it('renders valid partial cancellations', function (string $strategy) {
    $quittio = Quittio::create();
    $builder = $quittio->cancellation(original('simple'), 'ST-11', 'Teilrücknahme', $strategy, lines: [['line' => '1', 'quantity' => '2']]);

    expect($quittio->make($builder)->format('cii')->render()->validation()->errors())->toBe([]);
})->with(['credit_note', 'negative_invoice']);

it('rejects invalid partial cancellations', function (array $lines, string $message) {
    expect(fn () => Cancellation::of(original('simple'), 'ST-12', lines: $lines))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'unknown line' => [[['line' => '9']], 'no line "9"'],
    'duplicate' => [[['line' => '1'], ['line' => '1']], 'more than once'],
    'too many' => [[['line' => '1', 'quantity' => '25']], 'at most 24'],
    'zero' => [[['line' => '1', 'quantity' => '0']], 'greater than 0'],
    'amount too high' => [[['line' => '2', 'amount' => '29.01']], 'at most 29.00'],
    'both' => [[['line' => '1', 'quantity' => '1', 'amount' => '1.00']], 'either a quantity or an amount'],
    'empty' => [[], 'at least one line'],
]);

it('refuses partial cancellations with document level allowances', function () {
    Cancellation::of(original('mixed-vat'), 'ST-13', lines: [['line' => '1']]);
})->throws(InvalidArgumentException::class, 'document level allowances');

it('refuses partial quantities of lines with sub lines', function () {
    Cancellation::of(original('sub-lines'), 'ST-14', lines: [['line' => '1', 'quantity' => '1']]);
})->throws(InvalidArgumentException::class, 'sub lines');

it('reproduces the VAT total in accounting currency when a read invoice is cancelled (BR-53)', function (string $strategy, bool $negated) {
    $original = (new En16931Calculator)->calculate(Scenarios::make('simple')->currency('USD')->taxCurrency('EUR', '0.9')->build());
    $read = (new InvoiceReader)->read((new CiiWriter)->serialize($original, ProfileRegistry::withDefaults()->get('en16931')))->data;
    $cancellation = (new En16931Calculator)->calculate(Cancellation::of($read, 'ST-19', strategy: $strategy)->build());
    $declared = $original->totals?->vatTotalInTaxCurrency;

    expect($read->meta->exchangeRate)->toBeNull()
        // 394.84 EUR / 438.71 USD: the effective rate of the original, not the input 0.9.
        ->and($cancellation->meta->exchangeRate?->toString())->toBe('0.9000022794')
        ->and($declared)->not->toBeNull()
        ->and($cancellation->totals?->vatTotalInTaxCurrency?->isEqualTo($negated ? $declared?->negated() : $declared))->toBeTrue()
        ->and(Validate::codes(Validate::data($cancellation, calculate: false)))->not->toContain('BR-53');
})->with([['credit_note', false], ['negative_invoice', true]]);

it('reverses amounts collected for third parties in full cancellations only', function (string $strategy, bool $negated) {
    $original = (new En16931Calculator)->calculate(Scenarios::make('simple')
        ->xrechnungExtension(new XRechnungExtensionData([new ThirdPartyPayment('Porto', Amount::of('5.00', 'EUR'), 'Porto')]))
        ->build());
    $full = (new En16931Calculator)->calculate(Cancellation::of($original, 'ST-20', strategy: $strategy)->build());
    $partial = (new En16931Calculator)->calculate(Cancellation::of($original, 'ST-21', strategy: $strategy, lines: [['line' => '2']])->build());
    $due = $original->totals?->amountDue;

    expect($full->totals?->amountDue->isEqualTo($negated ? $due?->negated() : $due))->toBeTrue()
        ->and($partial->xrechnungExtension?->thirdPartyPayments)->toBe([])
        ->and($partial->totals?->amountDue->isEqualTo($partial->totals->grossTotal))->toBeTrue();
})->with([['credit_note', false], ['negative_invoice', true]]);

it('keeps self-billing when self-billed documents are cancelled', function (InvoiceTypeCode $type, string $strategy, string $expected, bool $negativeQuantities) {
    $original = (new En16931Calculator)->calculate(Scenarios::make('simple')->type($type)
        ->note('Gutschrift', NoteSubjectCode::RegulatoryInformation)->build());
    $cancellation = (new En16931Calculator)->calculate(Cancellation::of($original, 'ST-22', strategy: $strategy)->build());

    expect($cancellation->typeCodeValue())->toBe($expected)
        ->and($cancellation->lines[0]->quantity->isNegative())->toBe($negativeQuantities)
        ->and(array_map(static fn ($note): string => $note->content, $cancellation->notes))->toContain('Gutschrift');
})->with([
    'self-billed invoice, credit note' => [InvoiceTypeCode::SelfBilledInvoice, 'credit_note', '261', false],
    'self-billed invoice, negative' => [InvoiceTypeCode::SelfBilledInvoice, 'negative_invoice', '389', true],
    'self-billed credit note, credit note' => [InvoiceTypeCode::SelfBilledCreditNote, 'credit_note', '389', false],
    'self-billed credit note, negative' => [InvoiceTypeCode::SelfBilledCreditNote, 'negative_invoice', '389', false],
]);

it('cancels amounts including VAT of lines with gross prices', function (string $strategy, string $sign) {
    $original = (new En16931Calculator)->calculate(Scenarios::base()
        ->line(fn ($l) => $l->name('Shirt')->quantity(2)->priceInclVat('19.99')->vat(VatCategory::StandardRate, '19'))
        ->line(fn ($l) => $l->name('Shipping')->quantity(1)->priceInclVat('4.95')->vat(VatCategory::StandardRate, '19'))
        ->build());
    $cancellation = (new En16931Calculator)->calculate(Cancellation::of($original, 'ST-23', strategy: $strategy, lines: [
        ['line' => '1', 'amount' => '5.00'],
        ['line' => '2'],
    ])->build());

    expect($cancellation->totals?->amountDue->getAmount()->toString())->toBe($sign.'9.95')
        ->and($cancellation->lines[0]->price->priceInclVat?->toString())->toBe('5.00')
        ->and(fn () => Cancellation::of($original, 'ST-24', lines: [['line' => '1', 'amount' => '39.99']]))->toThrow(InvalidArgumentException::class, 'at most 39.98');
})->with([['credit_note', ''], ['negative_invoice', '-']]);

it('takes the title of the cancellation from Quittio::cancellation() like from Cancellation::of()', function () {
    $quittio = Quittio::create();

    expect($quittio->cancellation(original('simple'), 'ST-6', title: 'Stornorechnung')->build()->presentation?->title)->toBe('Stornorechnung')
        ->and(Cancellation::of(original('simple'), 'ST-7', title: 'Stornorechnung')->build()->presentation?->title)->toBe('Stornorechnung')
        ->and($quittio->cancellation(original('simple'), 'ST-8')->build()->presentation?->title)->toBeNull();
});

it('marks full and partial cancellations of a prepayment invoice, also when cancelled again', function (string $strategy) {
    $advance = original('simple')->with(['typeCode' => InvoiceTypeCode::PrepaymentInvoice]);
    $full = (new En16931Calculator)->calculate(Cancellation::of($advance, 'ST-1', $strategy)->build());
    $partial = Cancellation::of($advance, 'ST-2', $strategy, lines: [['line' => $advance->lines[0]->id]])->build();
    $reinstated = Cancellation::of($full, 'ST-3', $strategy)->build();

    expect($full->meta->hasFlag(Meta::FLAG_PREPAYMENT_CORRECTION))->toBeTrue()
        ->and($full->precedingInvoices[0]->number)->toBe($advance->number)
        ->and($partial->meta->hasFlag(Meta::FLAG_PREPAYMENT_CORRECTION))->toBeTrue()
        // The cancellation of the cancellation invoices the advance payment again.
        ->and($reinstated->typeCodeValue())->toBe('380')
        ->and($reinstated->meta->hasFlag(Meta::FLAG_PREPAYMENT_CORRECTION))->toBeTrue()
        ->and(Cancellation::of(original('simple'), 'ST-4', $strategy)->build()->meta->hasFlag(Meta::FLAG_PREPAYMENT_CORRECTION))->toBeFalse();
})->with(['credit_note', 'negative_invoice']);
