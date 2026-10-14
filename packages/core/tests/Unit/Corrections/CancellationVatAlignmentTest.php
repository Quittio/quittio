<?php

declare(strict_types=1);

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Calculation\DeclaredAmounts;
use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Calculation\LineCalculator;
use Quittio\Core\Corrections\Cancellation;
use Quittio\Core\Corrections\CancellationHistory;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Data\Meta;
use Quittio\Core\Enums\InvoiceTypeCode;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Profiles\ProfileRegistry;
use Quittio\Core\Quittio;
use Quittio\Core\Reading\InvoiceReader;
use Quittio\Core\Support\Amount;
use Quittio\Core\Testing\Scenarios;
use Quittio\Core\Tests\Support\CancellationLedger;
use Quittio\Core\Tests\Support\Official;
use Quittio\Core\Tests\Support\Validate;
use Quittio\Core\Xml\Cii\CiiWriter;
use Quittio\Core\Xml\Ubl\UblWriter;
use Quittio\Country\DE\CountryDE;

/*
 * Partial cancellations with a CancellationHistory: the VAT per VAT group is
 * rounded cumulatively, so that all cancellations together reverse exactly the VAT of the original.
 */

/**
 * 3 × 10.00 with a line allowance of 1.00 at 19 %: net 29.00, VAT 5.51; one unit is 9.67.
 */
function alignmentOriginal(?InvoiceBuilder $builder = null): InvoiceData
{
    return (new En16931Calculator)->calculate(($builder ?? Scenarios::base())
        ->line(fn ($l) => $l->name('Teil')->quantity(3, 'H87')->netPrice('10.00')->allowance('1.00', reason: 'Rabatt')->vat(VatCategory::StandardRate, '19'))
        ->build());
}

/**
 * Cancels the given requests one after the other, each with the history of the earlier ones.
 *
 * @param  list<list<array{line: string, quantity?: string, amount?: string}>>  $requests
 * @return list<array{builder: InvoiceBuilder, data: InvoiceData, history: CancellationHistory}>
 */
function alignmentParts(InvoiceData $original, array $requests, string $strategy = 'credit_note'): array
{
    $ledger = new CancellationLedger($original, $strategy);

    return array_map(static fn (array $request): array => $ledger->cancel($request), $requests);
}

/**
 * @param  list<array{data: InvoiceData, ...}>  $parts
 * @return list<string>
 */
function alignmentTotals(array $parts, string $field = 'vatTotal'): array
{
    return array_map(static fn (array $part): string => (string) $part['data']->totals?->{$field}?->getAmount(), $parts);
}

function alignmentUnit(string $line = '1'): array
{
    return [['line' => $line, 'quantity' => '1']];
}

it('rounds the VAT of partial cancellations cumulatively', function (string $strategy, string $sign) {
    $original = alignmentOriginal();
    $parts = alignmentParts($original, [alignmentUnit(), alignmentUnit(), alignmentUnit()], $strategy);
    $validation = array_map(static fn (array $part) => Validate::data($part['data'], calculate: false), $parts);

    expect($original->totals?->vatTotal?->getAmount()->toString())->toBe('5.51')
        ->and(array_map(static fn (array $part): string => (string) $part['data']->totals?->netTotal->getAmount(), $parts))->toBe([$sign.'9.67', $sign.'9.67', $sign.'9.66'])
        // 1.84 + 1.83 + 1.84 = 5.51, rounded per document 3 × 1.84 = 5.52.
        ->and(alignmentTotals($parts))->toBe([$sign.'1.84', $sign.'1.83', $sign.'1.84'])
        ->and(alignmentTotals($parts, 'amountDue'))->toBe([$sign.'11.51', $sign.'11.50', $sign.'11.50'])
        ->and($parts[1]['data']->meta->hasFlag(Meta::FLAG_CANCELLATION_VAT_ALIGNED))->toBeTrue()
        // The note mentions the alignment only where a VAT amount differs.
        ->and($parts[0]['data']->notes[0]->content)->toBe('Stornierung der Rechnung RE-2026-0042 vom 03.10.2026.')
        ->and($parts[1]['data']->notes[0]->content)->toBe('Stornierung der Rechnung RE-2026-0042 vom 03.10.2026. Die Umsatzsteuer ist auf die bereits stornierten Teilbeträge abgestimmt.')
        ->and($parts[2]['data']->notes[0]->content)->toBe('Stornierung der Rechnung RE-2026-0042 vom 03.10.2026.')
        ->and(Validate::codes($validation[1]))->toContain('QUITTIO-CANCELLATION-VAT-ALIGNED')->not->toContain('QUITTIO-VAT-ROUNDING')
        ->and($validation[1]->infos()[0]->code ?? null)->toBe('QUITTIO-CANCELLATION-VAT-ALIGNED')
        ->and(array_map(static fn ($result): array => $result->errors(), $validation))->toBe([[], [], []]);
})->with([['credit_note', ''], ['negative_invoice', '-']]);

it('rounds every partial cancellation on its own without a history', function () {
    $original = alignmentOriginal();
    $parts = array_map(static fn (string $number): InvoiceData => (new En16931Calculator)->calculate(Cancellation::of($original, $number, lines: alignmentUnit())->build()), ['ST-1', 'ST-2']);

    expect(array_map(static fn (InvoiceData $part): string => (string) $part->totals?->vatTotal?->getAmount(), $parts))->toBe(['1.84', '1.84'])
        ->and($parts[0]->meta->hasFlag(Meta::FLAG_CANCELLATION_VAT_ALIGNED))->toBeFalse();
});

it('puts the reason after the alignment sentence', function () {
    $original = alignmentOriginal();
    $first = (new En16931Calculator)->calculate(Cancellation::of($original, 'ST-1', lines: alignmentUnit())->build());
    $history = new CancellationHistory((new CancellationHistory)->withCancellation($original, $first)->groups);
    $second = Cancellation::of($original, 'ST-2', reason: 'Retoure', lines: alignmentUnit(), history: $history, locale: 'en')->build();

    expect($second->notes[0]->content)->toBe('Cancellation of invoice RE-2026-0042 of 03/10/2026. The VAT is aligned with the amounts already cancelled. Reason: Retoure');
});

it('closes VAT groups separately and reverses negative lines', function (string $strategy, string $sign) {
    $original = (new En16931Calculator)->calculate(Scenarios::base()
        ->line(fn ($l) => $l->name('Teil')->quantity(3, 'H87')->netPrice('10.00')->allowance('1.00', reason: 'Rabatt')->vat(VatCategory::StandardRate, '19'))
        ->line(fn ($l) => $l->name('Buch')->quantity(3, 'H87')->netPrice('3.33')->vat(VatCategory::StandardRate, '7'))
        ->line(fn ($l) => $l->name('Gutschein')->quantity(-3, 'H87')->netPrice('1.11')->vat(VatCategory::StandardRate, '19'))
        ->build());
    $parts = alignmentParts($original, [
        [['line' => '1', 'quantity' => '1'], ['line' => '2', 'quantity' => '1'], ['line' => '3', 'quantity' => '1']],
        [['line' => '1', 'quantity' => '1'], ['line' => '2', 'quantity' => '2'], ['line' => '3', 'quantity' => '1']],
        [['line' => '1', 'quantity' => '1'], ['line' => '3', 'quantity' => '1']],
    ], $strategy);
    $groups = static fn (InvoiceData $data): array => array_map(static fn ($entry): string => $entry->taxableAmount->getAmount().' '.$entry->taxAmount->getAmount(), $data->vatBreakdown);

    // Original: 19 %: 29.00 - 3.33 = 25.67, VAT 4.88; 7 %: 9.99, VAT 0.70.
    expect($groups($original))->toBe(['25.67 4.88', '9.99 0.70'])
        ->and($groups($parts[0]['data']))->toBe([$sign.'8.56 '.$sign.'1.63', $sign.'3.33 '.$sign.'0.23'])
        // 19 %: round2(17.12 × 19 %) = 3.25 - 1.63 = 1.62; 7 %: closed, 0.70 - 0.23 = 0.47 (own: 0.47).
        ->and($groups($parts[1]['data']))->toBe([$sign.'8.56 '.$sign.'1.62', $sign.'6.66 '.$sign.'0.47'])
        ->and($parts[1]['history']->closes)->toBe(['S|7.00'])
        // 19 %: closed, 4.88 - 3.25 = 1.63 (own: round2(8.55 × 19 %) = 1.62).
        ->and($groups($parts[2]['data']))->toBe([$sign.'8.55 '.$sign.'1.63'])
        ->and(array_map(static fn (array $part): array => Validate::data($part['data'], calculate: false)->errors(), $parts))->toBe([[], [], []]);
})->with([['credit_note', ''], ['negative_invoice', '-']]);

it('reverses a credit note with invoices carrying its amounts', function () {
    $original = alignmentOriginal(Scenarios::base()->type(InvoiceTypeCode::CreditNote));
    $parts = alignmentParts($original, [alignmentUnit(), alignmentUnit(), alignmentUnit()], 'negative_invoice');

    expect(array_map(static fn (array $part): string => $part['data']->typeCodeValue(), $parts))->toBe(['380', '380', '380'])
        ->and(alignmentTotals($parts))->toBe(['1.84', '1.83', '1.84']);
});

it('aligns the VAT total in accounting currency (BT-111)', function (string $strategy, string $sign, bool $read) {
    $original = alignmentOriginal(Scenarios::base()->currency('USD')->taxCurrency('EUR', '0.9'));
    if ($read) {
        // Without an exchange rate: the rate implied by BT-111 / BT-110 of the original.
        $original = (new InvoiceReader)->read((new CiiWriter)->serialize($original, ProfileRegistry::withDefaults()->get('en16931')))->data;
    }
    $parts = alignmentParts($original, [alignmentUnit(), alignmentUnit(), alignmentUnit()], $strategy);

    // 5.51 USD × 0.9 = 4.96 EUR; per document 1.66 + 1.65 + 1.66 = 4.97.
    expect($original->totals?->vatTotalInTaxCurrency?->getAmount()->toString())->toBe('4.96')
        ->and(alignmentTotals($parts))->toBe([$sign.'1.84', $sign.'1.83', $sign.'1.84'])
        ->and(alignmentTotals($parts, 'vatTotalInTaxCurrency'))->toBe([$sign.'1.66', $sign.'1.64', $sign.'1.66'])
        ->and($parts[1]['data']->meta->exchangeRate?->toString())->toBe($read ? '0.9001814882' : '0.9')
        ->and(array_map(static fn (array $part): array => Validate::data($part['data'], calculate: false)->errors(), $parts))->toBe([[], [], []]);
})->with([['credit_note', ''], ['negative_invoice', '-']])->with(['explicit rate' => false, 'read from XML' => true]);

it('keeps the amount due of gross price lines and adjusts the rounding amount', function (string $strategy, string $sign) {
    $original = (new En16931Calculator)->calculate(Scenarios::base()
        ->line(fn ($l) => $l->name('Shirt')->quantity(3)->priceInclVat('9.99')->vat(VatCategory::StandardRate, '19'))
        ->build());
    $parts = alignmentParts($original, [alignmentUnit(), alignmentUnit(), alignmentUnit()], $strategy);

    // G = 29.97, N = 25.18, T = 4.78, BT-114 0.01: each unit is 9.99 gross, 8.39 net on its own
    // (3 × 8.39 = 25.17). The net amounts follow the gross amount cancelled so far: round2(19.98 /
    // 1.19) = 16.79, so the second part is 8.40 net and all parts reverse exactly 25.18.
    expect([(string) $original->totals?->vatTotal?->getAmount(), (string) $original->totals?->amountDue->getAmount(), (string) $original->totals?->roundingAmount?->getAmount()])->toBe(['4.78', '29.97', '0.01'])
        ->and(alignmentTotals($parts, 'netTotal'))->toBe([$sign.'8.39', $sign.'8.40', $sign.'8.39'])
        ->and(alignmentTotals($parts))->toBe([$sign.'1.59', $sign.'1.60', $sign.'1.59'])
        ->and(alignmentTotals($parts, 'amountDue'))->toBe([$sign.'9.99', $sign.'9.99', $sign.'9.99'])
        ->and(alignmentTotals($parts, 'roundingAmount'))->toBe([$sign.'0.01', ($sign === '' ? '-' : '').'0.01', $sign.'0.01'])
        ->and(array_map(static fn (array $part): array => Validate::data($part['data'], calculate: false)->errors(), $parts))->toBe([[], [], []]);
})->with([['credit_note', ''], ['negative_invoice', '-']]);

/**
 * @param  list<array{data: InvoiceData, ...}>  $parts
 */
function alignmentSum(array $parts, string $field): string
{
    return (string) array_reduce($parts, static fn (BigDecimal $total, array $part): BigDecimal => $total->plus($part['data']->totals?->{$field}?->getAmount() ?? BigDecimal::zero()), BigDecimal::zero());
}

/**
 * Per part and VAT group: |BT-117 - round2(BT-116 × rate)|, and whether BT-117 has the sign of
 * BT-116 (0.00 on 0.00).
 *
 * @param  list<array{data: InvoiceData, ...}>  $parts
 * @return array{deviation: string, signs: bool}
 */
function alignmentDeviation(array $parts): array
{
    $deviation = BigDecimal::zero();
    $signs = true;
    foreach ($parts as $part) {
        foreach ($part['data']->vatBreakdown as $entry) {
            $taxable = $entry->taxableAmount->getAmount();
            $tax = $entry->taxAmount->getAmount();
            $deviation = BigDecimal::max($deviation, $tax->minus(alignmentRound($taxable->multipliedBy($entry->rate ?? BigDecimal::zero())->dividedBy(100, 10, RoundingMode::HalfUp)))->abs());
            $signs = $signs && ($taxable->isZero() ? $tax->isZero() : ($tax->isZero() || $tax->getSign() === $taxable->getSign()));
        }
    }

    return ['deviation' => (string) $deviation, 'signs' => $signs];
}

it('reverses the net amount of gross price lines exactly over many parts', function (int $units, array $expected) {
    // N = round2(G / 1.19). One unit alone is round2(0.72 / 1.19) = 0.61 net: rounded per document,
    // 40 parts would cancel 24.40 net instead of 24.20 and the last part would carry 0.08 VAT on
    // 0.61; 140 parts 85.40 instead of 84.71 and -0.02 VAT on 0.61.
    $original = (new En16931Calculator)->calculate(Scenarios::base()
        ->line(fn ($l) => $l->name('Knopf')->quantity($units)->priceInclVat('0.72')->vat(VatCategory::StandardRate, '19'))
        ->build());
    $parts = alignmentParts($original, array_fill(0, $units, alignmentUnit()));

    expect([(string) $original->totals?->netTotal->getAmount(), (string) $original->totals?->vatTotal?->getAmount()])->toBe([$expected[0], $expected[1]])
        ->and(array_map(static fn (string $field): string => alignmentSum($parts, $field), ['netTotal', 'vatTotal', 'grossTotal', 'amountDue']))->toBe($expected)
        ->and(array_values(array_unique(alignmentTotals($parts, 'netTotal'))))->toEqualCanonicalizing(['0.60', '0.61'])
        ->and(alignmentDeviation($parts))->toBe(['deviation' => '0.01', 'signs' => true]);
})->with([
    '40 parts' => [40, ['24.20', '4.60', '28.80', '28.80']],
    '140 parts' => [140, ['84.71', '16.09', '100.80', '100.80']],
]);

it('reverses a small gross price line after the other lines without VAT of the wrong sign', function (string $price, int $units, string $small, array $nets) {
    $original = (new En16931Calculator)->calculate(Scenarios::base()
        ->line(fn ($l) => $l->name('Shirt')->quantity($units)->priceInclVat($price)->vat(VatCategory::StandardRate, '19'))
        ->line(fn ($l) => $l->name('Beigabe')->quantity(1)->priceInclVat($small)->vat(VatCategory::StandardRate, '19'))
        ->build());
    $parts = alignmentParts($original, [...array_fill(0, $units, alignmentUnit()), [['line' => '2']]]);

    // Rounded per document the last part would carry -0.01 VAT on 0.00 or 0.01.
    expect(alignmentTotals($parts, 'netTotal'))->toBe($nets)
        ->and(alignmentTotals($parts)[$units])->toBe('0.00')
        ->and([alignmentSum($parts, 'netTotal'), alignmentSum($parts, 'vatTotal'), alignmentSum($parts, 'amountDue')])
        ->toBe([(string) $original->totals?->netTotal->getAmount(), (string) $original->totals?->vatTotal?->getAmount(), (string) $original->totals?->amountDue->getAmount()])
        ->and(alignmentDeviation($parts)['signs'])->toBeTrue()
        ->and(BigDecimal::of(alignmentDeviation($parts)['deviation'])->isLessThanOrEqualTo('0.01'))->toBeTrue();
})->with([
    'free line' => ['3.33', 3, '0.00', ['2.80', '2.80', '2.79', '0.00']],
    'line of one cent' => ['0.72', 6, '0.01', ['0.61', '0.60', '0.61', '0.60', '0.61', '0.60', '0.01']],
]);

it('keeps its own net amount of gross price lines for a history that does not match', function () {
    $original = (new En16931Calculator)->calculate(Scenarios::base()
        ->line(fn ($l) => $l->name('Knopf')->quantity(40)->priceInclVat('0.72')->vat(VatCategory::StandardRate, '19'))
        ->build());
    // Ten units cancelled per document: 10 × 0.61 = 6.10 net, cumulatively round2(7.20 / 1.19) = 6.05.
    $history = new CancellationHistory(['S|19.00' => ['taxable' => BigDecimal::of('6.10'), 'tax' => BigDecimal::of('1.16'), 'taxInTaxCurrency' => null, 'gross' => BigDecimal::of('7.20')]]);
    $part = (new En16931Calculator)->calculate(Cancellation::of($original, 'ST-11', lines: alignmentUnit(), history: $history)->build());

    // round2(7.92 / 1.19) - 6.10 = 0.56 differs by more than 0.01 from 0.61: the part keeps 0.61;
    // its VAT round2(6.71 × 19 %) - 1.16 = 0.11.
    expect([(string) $part->totals?->netTotal->getAmount(), (string) $part->totals?->vatTotal?->getAmount(), (string) $part->totals?->amountDue->getAmount()])->toBe(['0.61', '0.11', '0.72']);
});

it('spreads the VAT rounding of another system over the parts', function (string $vat, array $taxes, array $warnings) {
    // 10 × 0.13 at 19 %, the VAT rounded per line by another system (10 × 0.02 = 0.20 instead of
    // round2(1.30 × 19 %) = 0.25) or declared as 0.30, read back from XML.
    $builder = Scenarios::base();
    for ($i = 1; $i <= 10; $i++) {
        $builder->line(fn ($l) => $l->name("Teil {$i}")->quantity(1, 'H87')->netPrice('0.13')->vat(VatCategory::StandardRate, '19'));
    }
    $calculated = (new En16931Calculator)->calculate($builder->build());
    $eur = static fn (string $amount) => Amount::of($amount, 'EUR');
    $foreign = $calculated->with([
        'vatBreakdown' => [$calculated->vatBreakdown[0]->with(['taxAmount' => $eur($vat)])],
        'totals' => $calculated->totals?->with(['vatTotal' => $eur($vat), 'grossTotal' => $eur('1.30')->plus($eur($vat)), 'amountDue' => $eur('1.30')->plus($eur($vat))]),
    ]);
    $original = (new InvoiceReader)->read((new CiiWriter)->serialize($foreign, ProfileRegistry::withDefaults()->get('en16931')))->data;
    $parts = alignmentParts($original, [array_map(static fn (int $line): array => ['line' => (string) $line], range(1, 9)), [['line' => '10']]]);

    // round2(1.17 × BT-117 / BT-116), the rest with the last line: no jump, no VAT of the wrong sign
    // (with round2(1.17 × 19 %) = 0.22 the last line would carry -0.02 or 0.08).
    expect(alignmentTotals($parts))->toBe($taxes)
        ->and(alignmentSum($parts, 'vatTotal'))->toBe($vat)
        ->and(alignmentDeviation($parts)['signs'])->toBeTrue()
        // More than 0.01 from round2(taxable amount × rate): a warning, not the information.
        ->and(array_map(static fn (array $part): array => Validate::codes(Validate::data($part['data'], calculate: false)), $parts)[0])->toContain(...$warnings)
        ->and(array_map(static fn (array $part): array => Validate::data($part['data'], calculate: false)->errors(), $parts))->toBe([[], []]);
})->with([
    'VAT rounded per line' => ['0.20', ['0.18', '0.02'], ['QUITTIO-VAT-ROUNDING']],
    'VAT declared higher' => ['0.30', ['0.27', '0.03'], ['QUITTIO-VAT-ROUNDING']],
]);

it('rejects a history that does not fit the original', function (CancellationHistory $history, string $message) {
    expect(fn () => Cancellation::of(alignmentOriginal(), 'ST-2', lines: alignmentUnit(), history: $history))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'unknown VAT group' => [new CancellationHistory(['S|7.00' => ['taxable' => BigDecimal::of('9.67'), 'tax' => BigDecimal::of('0.68'), 'taxInTaxCurrency' => null]]), 'names the VAT group "S|7.00"'],
    'unknown group closed' => [new CancellationHistory([], ['S|7.00']), 'names the VAT group "S|7.00"'],
    'more than invoiced' => [new CancellationHistory(['S|19.00' => ['taxable' => BigDecimal::of('100.00'), 'tax' => BigDecimal::of('19.00'), 'taxInTaxCurrency' => null]]), 'taxable amount of 100.00, the invoice has 29.00'],
    'VAT 0.99 too low' => [new CancellationHistory(['S|19.00' => ['taxable' => BigDecimal::of('9.67'), 'tax' => BigDecimal::of('0.85'), 'taxInTaxCurrency' => null]]), 'VAT of 2.82 on a taxable amount of 9.67 (rounded on its own 1.84)'],
    'VAT a cent off' => [new CancellationHistory(['S|19.00' => ['taxable' => BigDecimal::of('9.67'), 'tax' => BigDecimal::of('1.85'), 'taxInTaxCurrency' => null]]), 'VAT of 1.82'],
    'closed too early' => [new CancellationHistory([], ['S|19.00']), 'VAT of 5.51'],
    'VAT of the wrong sign' => [new CancellationHistory(['S|19.00' => ['taxable' => BigDecimal::of('9.67'), 'tax' => BigDecimal::of('-1.84'), 'taxInTaxCurrency' => null]]), 'VAT of 5.51'],
]);

it('reports aligned VAT beyond a cent or of the wrong sign as rounding warning', function (string $taxable, string $tax, string $code) {
    $part = alignmentParts(alignmentOriginal(), [alignmentUnit(), alignmentUnit()])[1]['data'];
    $entry = $part->vatBreakdown[0]->with(['taxableAmount' => Amount::of($taxable, 'EUR'), 'taxAmount' => Amount::of($tax, 'EUR')]);
    $codes = Validate::codes(Validate::data($part->with(['vatBreakdown' => [$entry]]), calculate: false));

    expect($part->meta->hasFlag(Meta::FLAG_CANCELLATION_VAT_ALIGNED) && $part->meta->hasFlag(Meta::FLAG_DECLARED_AMOUNTS))->toBeTrue()
        ->and($codes)->toContain($code)
        ->and($codes)->not->toContain($code === 'QUITTIO-VAT-ROUNDING' ? 'QUITTIO-CANCELLATION-VAT-ALIGNED' : 'QUITTIO-VAT-ROUNDING');
})->with([
    'a cent' => ['9.67', '1.83', 'QUITTIO-CANCELLATION-VAT-ALIGNED'],
    'two cents' => ['9.67', '1.82', 'QUITTIO-VAT-ROUNDING'],
    'wrong sign' => ['0.01', '-0.01', 'QUITTIO-VAT-ROUNDING'],
    'VAT on zero' => ['0.00', '0.01', 'QUITTIO-VAT-ROUNDING'],
]);

it('recognises a history that no longer matches', function () {
    $original = alignmentOriginal();
    $first = (new En16931Calculator)->calculate(Cancellation::of($original, 'ST-1', lines: alignmentUnit())->build());
    $second = (new En16931Calculator)->calculate(Cancellation::of($original, 'ST-2', lines: alignmentUnit(), history: new CancellationHistory)->build());
    $history = new CancellationHistory((new CancellationHistory)->withCancellation($original, $first)->groups);

    // The second part was built without the first one: its VAT 1.84 should be 1.83.
    expect((new CancellationHistory)->matches($original, $second))->toBeTrue()
        ->and($history->matches($original, $second))->toBeFalse()
        ->and($history->matches($original, (new En16931Calculator)->calculate(Cancellation::of($original, 'ST-2', lines: alignmentUnit(), history: $history)->build())))->toBeTrue();
});

it('passes the history through Quittio::cancellation()', function () {
    $original = alignmentOriginal();
    $quittio = Quittio::create();
    $first = (new En16931Calculator)->calculate($quittio->cancellation($original, 'ST-1', lines: alignmentUnit())->build());
    $history = new CancellationHistory((new CancellationHistory)->withCancellation($original, $first)->groups);
    $second = (new En16931Calculator)->calculate($quittio->cancellation($original, 'ST-2', lines: alignmentUnit(), history: $history)->build());

    expect($second->totals?->vatTotal?->getAmount()->toString())->toBe('1.83');
});

it('drops the alignment when the amounts of the cancellation change', function () {
    $original = alignmentOriginal();
    $parts = alignmentParts($original, [alignmentUnit(), alignmentUnit()]);
    $changed = (new En16931Calculator)->calculate(InvoiceBuilder::from($parts[1]['data'])
        ->line(fn ($l) => $l->name('Extra')->quantity(1)->netPrice('1.00')->vat(VatCategory::StandardRate, '19'))->build());

    expect($changed->vatBreakdown[0]->taxAmount->getAmount()->toString())->toBe('2.03')
        ->and($changed->meta->hasFlag(Meta::FLAG_CANCELLATION_VAT_ALIGNED))->toBeFalse()
        ->and($changed->meta->declaredVatInTaxCurrency())->toBeNull();
});

/**
 * A random invoice (VAT rates 19 %, 7 % and exempt, negative lines, decimal quantities, line
 * allowances, foreign currency, gross prices) cancelled in random parts: whole lines, quantities
 * and amounts, each with the history of the earlier ones, until nothing is left.
 *
 * @return array{original: InvoiceData, gross: bool, foreign: bool, sign: int, parts: list<array{builder: InvoiceBuilder, data: InvoiceData, history: CancellationHistory, open: list<string>}>}|null
 */
function randomPartialCancellations(int $seed): ?array
{
    mt_srand($seed);
    $gross = mt_rand(1, 4) === 1;
    $foreign = mt_rand(1, 4) === 1;
    $strategy = mt_rand(0, 1) === 1 ? 'credit_note' : 'negative_invoice';
    $builder = Scenarios::base();
    if ($foreign) {
        $builder->currency('USD')->taxCurrency('EUR', (string) BigDecimal::of(mt_rand(5000, 15000))->dividedBy(10000, 4));
    }
    for ($i = 1, $n = mt_rand(1, 5); $i <= $n; $i++) {
        [$category, $rate] = [[VatCategory::StandardRate, '19'], [VatCategory::StandardRate, '7'], [VatCategory::StandardRate, '19'], [VatCategory::Exempt, '0']][mt_rand(0, 3)];
        $quantity = (mt_rand(1, 5) === 1 && $i > 1 ? '-' : '').(mt_rand(1, 4) === 1 ? (string) BigDecimal::of(mt_rand(1, 400))->dividedBy(100, 2) : (string) mt_rand(1, 12));
        $price = (string) BigDecimal::of(mt_rand(1, 99999))->dividedBy(mt_rand(0, 2) === 0 ? 1000 : 100, 3);
        $allowance = ! $gross && mt_rand(1, 3) === 1 ? (string) BigDecimal::of(mt_rand(1, 100))->dividedBy(100, 2) : null;
        $builder->line(function ($l) use ($i, $quantity, $price, $gross, $category, $rate, $allowance) {
            $l->name('Position '.$i)->quantity($quantity, 'H87');
            $gross ? $l->priceInclVat($price) : $l->netPrice($price);
            $l->vat($category, $rate, $category === VatCategory::Exempt ? 'Steuerfrei nach § 4 Nr. 14 UStG' : null);

            return $allowance === null ? $l : $l->allowance($allowance, reason: 'Rabatt');
        });
    }
    $original = (new En16931Calculator)->calculate($builder->build());
    if (! $original->totals?->amountDue->isPositive()) {
        return null;
    }

    $ledger = new CancellationLedger($original, $strategy);
    $parts = [];
    while (($open = $ledger->open()) !== []) {
        shuffle($open);
        // At most five parts, then everything that remains.
        $rest = count($parts) >= 5 || mt_rand(1, 6) === 1;
        $request = [];
        foreach (array_slice($open, 0, $rest ? count($open) : mt_rand(1, count($open))) as $id) {
            $balance = $ledger->remaining($id);
            $choice = $rest ? 0 : mt_rand(0, 2);
            if ($choice === 1 && ! $balance['byAmount'] && $balance['quantity']->getScale() === 0 && $balance['quantity']->isGreaterThan(1)) {
                $request[] = ['line' => $id, 'quantity' => (string) mt_rand(1, $balance['quantity']->toInt() - 1)];
            } elseif ($choice === 2 && $balance['amount']->isGreaterThan('0.02')) {
                $request[] = ['line' => $id, 'amount' => (string) BigDecimal::of(mt_rand(1, $balance['amount']->withPointMovedRight(2)->toInt() - 1))->dividedBy(100, 2)];
            } else {
                $request[] = $ledger->rest($id);
            }
        }
        $parts[] = [...$ledger->cancel($request), 'open' => $ledger->open()];
    }

    return ['original' => $original, 'gross' => $gross, 'foreign' => $foreign, 'sign' => $strategy === 'negative_invoice' ? -1 : 1, 'parts' => $parts];
}

function alignmentRound(BigDecimal $value): BigDecimal
{
    return $value->toScale(2, RoundingMode::HalfUp);
}

it('keeps the cumulated VAT exact for random partial cancellations', function () {
    $quittio = Quittio::create([], [new CountryDE]);
    $documents = 0;
    $deviation = ['net' => BigDecimal::zero(), 'gross' => BigDecimal::zero(), 'BT-111' => BigDecimal::zero()];
    $failures = [];

    for ($seed = 1; $seed <= 150; $seed++) {
        $run = randomPartialCancellations($seed);
        if ($run === null) {
            continue;
        }
        $original = $run['original'];
        $sign = $run['sign'];
        $originalTax = [];
        foreach ($original->vatBreakdown as $entry) {
            $originalTax[DeclaredAmounts::breakdownKey($entry->category, $entry->rate)] = $entry->taxAmount->getAmount();
        }
        $taxable = $tax = [];
        $vat = $inTaxCurrency = $net = $due = BigDecimal::zero();

        foreach ($run['parts'] as $step => $part) {
            $documents++;
            $data = $part['data'];
            $label = "seed {$seed}, part ".($step + 1);
            foreach ($data->vatBreakdown as $entry) {
                $key = DeclaredAmounts::breakdownKey($entry->category, $entry->rate);
                $taxable[$key] = ($taxable[$key] ?? BigDecimal::zero())->plus($entry->taxableAmount->getAmount()->multipliedBy($sign));
                $tax[$key] = ($tax[$key] ?? BigDecimal::zero())->plus($entry->taxAmount->getAmount()->multipliedBy($sign));
                if ($entry->category->isZeroTax() || $entry->rate === null) {
                    continue;
                }
                // Cumulated: round2(cumulated taxable amount × rate), the original's VAT once closed.
                $expected = $part['history']->closes($key) ? $originalTax[$key] : alignmentRound($taxable[$key]->multipliedBy($entry->rate)->dividedBy(100, 10, RoundingMode::HalfUp));
                if (! $tax[$key]->isEqualTo($expected)) {
                    $failures[] = "{$label}: VAT {$key} cumulated {$tax[$key]}, expected {$expected}";
                }
                $own = alignmentRound($entry->taxableAmount->getAmount()->multipliedBy($entry->rate)->dividedBy(100, 10, RoundingMode::HalfUp));
                $partTaxable = $entry->taxableAmount->getAmount();
                $partTax = $entry->taxAmount->getAmount();
                if ($partTaxable->isZero() ? ! $partTax->isZero() : (! $partTax->isZero() && $partTax->getSign() !== $partTaxable->getSign())) {
                    $failures[] = "{$label}: VAT {$partTax} {$key} on a taxable amount of {$partTaxable}";
                }
                $mode = $run['gross'] ? 'gross' : 'net';
                $deviation[$mode] = BigDecimal::max($deviation[$mode], $entry->taxAmount->getAmount()->minus($own)->abs());
            }
            $totals = $data->totals;
            $vat = $vat->plus(($totals?->vatTotal?->getAmount() ?? BigDecimal::zero())->multipliedBy($sign));
            $net = $net->plus(($totals?->netTotal->getAmount() ?? BigDecimal::zero())->multipliedBy($sign));
            $due = $due->plus(($totals?->amountDue->getAmount() ?? BigDecimal::zero())->multipliedBy($sign));

            if ($run['foreign']) {
                $declared = $totals?->vatTotalInTaxCurrency?->getAmount() ?? BigDecimal::zero();
                $inTaxCurrency = $inTaxCurrency->plus($declared->multipliedBy($sign));
                $expected = $part['open'] === [] ? $original->totals?->vatTotalInTaxCurrency?->getAmount() : alignmentRound($vat->multipliedBy($original->meta->exchangeRate ?? BigDecimal::one()));
                if ($expected === null || ! $inTaxCurrency->isEqualTo($expected)) {
                    $failures[] = "{$label}: BT-111 cumulated {$inTaxCurrency}, expected {$expected}";
                }
                $deviation['BT-111'] = BigDecimal::max($deviation['BT-111'], $declared->minus(alignmentRound(($totals?->vatTotal?->getAmount() ?? BigDecimal::zero())->multipliedBy($data->meta->exchangeRate ?? BigDecimal::one())))->abs());
            }
            if ($run['gross']) {
                // The amount due is the gross amount of the cancelled lines.
                $lines = array_reduce($data->lines, static fn (BigDecimal $sum, $line): BigDecimal => $sum->plus(LineCalculator::baseAmount($line->quantity, $line->price->priceInclVat ?? BigDecimal::zero(), $line->price->baseQuantity)), BigDecimal::zero());
                if (! $totals?->amountDue->getAmount()->isEqualTo($lines)) {
                    $failures[] = "{$label}: amount due {$totals?->amountDue}, gross of the lines {$lines}";
                }
            }
            foreach ($quittio->make($part['builder'])->check()->errors() as $error) {
                $failures[] = "{$label}: {$error->code} {$error->message}";
            }
        }

        // All cancellations together reverse the original exactly, also the net amounts of gross
        // price lines (they follow from the gross amount cancelled so far).
        foreach ($originalTax as $key => $amount) {
            if (! ($tax[$key] ?? BigDecimal::zero())->isEqualTo($amount)) {
                $failures[] = "seed {$seed}: VAT {$key} together ".($tax[$key] ?? '0').", original {$amount}";
            }
        }
        if (! $due->isEqualTo($original->totals?->amountDue->getAmount() ?? BigDecimal::zero()) || ! $net->isEqualTo($original->totals?->netTotal->getAmount() ?? BigDecimal::zero())) {
            $failures[] = "seed {$seed}: net {$net} and amount due {$due} together differ from the original";
        }
    }

    expect($failures)->toBe([])
        ->and($documents)->toBeGreaterThan(300)
        ->and($deviation['net']->isLessThanOrEqualTo('0.01'))->toBeTrue()
        ->and($deviation['gross']->isLessThanOrEqualTo('0.01'))->toBeTrue()
        ->and($deviation['BT-111']->isLessThanOrEqualTo('0.01'))->toBeTrue();
});

it('passes the official EN 16931 schematron with random partial cancellations', function () {
    $directory = sys_get_temp_dir().'/quittio-vat-alignment-'.getmypid();
    $profile = ProfileRegistry::withDefaults()->get('en16931');
    $count = 0;
    foreach (['cii', 'ubl'] as $syntax) {
        @mkdir($directory.'/'.$syntax.'/in', 0777, true);
        @mkdir($directory.'/'.$syntax.'/out', 0777, true);
    }
    for ($seed = 1; $seed <= 150; $seed++) {
        foreach (randomPartialCancellations($seed)['parts'] ?? [] as $step => $part) {
            $count++;
            file_put_contents("{$directory}/cii/in/{$seed}-{$step}.xml", (new CiiWriter)->serialize($part['data'], $profile));
            file_put_contents("{$directory}/ubl/in/{$seed}-{$step}.xml", (new UblWriter)->serialize($part['data'], $profile));
        }
    }

    $failures = [];
    foreach (['cii' => 'CII', 'ubl' => 'UBL'] as $syntax => $name) {
        $command = sprintf(
            'java -cp %s net.sf.saxon.Transform -s:%s -xsl:%s -o:%s 2>&1',
            escapeshellarg(Official::root().'/validator.jar'),
            escapeshellarg("{$directory}/{$syntax}/in"),
            escapeshellarg(Official::root()."/xrechnung/EN16931-{$name}-validation.xsl"),
            escapeshellarg("{$directory}/{$syntax}/out"),
        );
        exec($command, $output, $code);
        expect($code)->toBe(0, implode("\n", $output));
        foreach (glob("{$directory}/{$syntax}/out/*.xml") ?: [] as $report) {
            $document = new DOMDocument;
            $document->load($report);
            $xpath = new DOMXPath($document);
            $xpath->registerNamespace('svrl', 'http://purl.oclc.org/dsdl/svrl');
            foreach ($xpath->query('//svrl:failed-assert[@flag="fatal"]') ?: [] as $assert) {
                assert($assert instanceof DOMElement);
                $failures[] = sprintf('%s %s: %s %s', $name, basename($report), $assert->getAttribute('id'), trim($assert->textContent));
            }
        }
        expect(glob("{$directory}/{$syntax}/out/*.xml") ?: [])->toHaveCount($count);
    }
    exec('rm -rf '.escapeshellarg($directory));

    expect($count)->toBeGreaterThan(300)
        ->and($failures)->toBe([]);
})->group('official')->skip(fn () => ! Official::available(), 'Java or the official validation artifacts are not available (run tools/schemas/download.sh).');
