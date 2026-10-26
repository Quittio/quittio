<?php

declare(strict_types=1);

use Brick\Math\BigDecimal;
use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Corrections\Cancellation;
use Quittio\Core\Corrections\CancellationHistory;
use Quittio\Core\Corrections\PriceReduction;
use Quittio\Core\Corrections\PriceReductionReason;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Data\Meta;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Exceptions\InvalidArgumentException;
use Quittio\Core\Quittio;
use Quittio\Core\Testing\Scenarios;
use Quittio\Core\Tests\Support\Validate;

/*
 * Price reductions with a CancellationHistory: the VAT per VAT group is rounded cumulatively with
 * the partial cancellations and price reductions that credited the same original before.
 */

/**
 * 3 × 0.50 at 19 %: net 1.50, VAT 0.29.
 */
function reductionAlignmentOriginal(?string $currency = null): InvoiceData
{
    $builder = Scenarios::base();
    if ($currency !== null) {
        $builder = $builder->currency($currency)->taxCurrency('EUR', '0.9');
    }

    return (new En16931Calculator)->calculate($builder
        ->line(fn ($l) => $l->name('Teil')->quantity(3, 'H87')->netPrice('0.50')->vat(VatCategory::StandardRate, '19'))
        ->build());
}

function reductionAlignmentReduce(InvoiceData $original, string $number, ?CancellationHistory $history, string $amount = '0.50', string $locale = 'de'): InvoiceData
{
    return (new En16931Calculator)->calculate(PriceReduction::of($original, $number, PriceReductionReason::Defect, amount: $amount, locale: $locale, history: $history)->build());
}

function reductionAlignmentCancel(InvoiceData $original, string $number, ?CancellationHistory $history, ?array $lines = [['line' => '1', 'quantity' => '1']]): InvoiceData
{
    return (new En16931Calculator)->calculate(Cancellation::of($original, $number, lines: $lines, history: $history)->build());
}

function reductionAlignmentVat(InvoiceData $document): string
{
    return (string) $document->totals?->vatTotal?->getAmount();
}

it('rounds the VAT of price reductions cumulatively', function () {
    $original = reductionAlignmentOriginal();
    $history = new CancellationHistory;
    $reductions = [];
    foreach (['EM-1', 'EM-2', 'EM-3'] as $number) {
        $reductions[] = $reduction = reductionAlignmentReduce($original, $number, $history);
        $history = $history->withPriceReduction($original, $reduction);
    }
    $validation = array_map(static fn (InvoiceData $reduction) => Validate::data($reduction, calculate: false), $reductions);

    // 0.10 + 0.09 + 0.10 = 0.29, rounded per document 3 × 0.10 = 0.30.
    expect(reductionAlignmentVat($original))->toBe('0.29')
        ->and(array_map(reductionAlignmentVat(...), $reductions))->toBe(['0.10', '0.09', '0.10'])
        ->and(array_map(static fn (InvoiceData $reduction): string => (string) $reduction->totals?->amountDue->getAmount(), $reductions))->toBe(['0.60', '0.59', '0.60'])
        ->and($reductions[1]->meta->hasFlag(Meta::FLAG_CANCELLATION_VAT_ALIGNED))->toBeTrue()
        ->and($reductions[1]->meta->hasFlag(Meta::FLAG_PRICE_REDUCTION))->toBeTrue()
        ->and($reductions[0]->meta->hasFlag(Meta::FLAG_CANCELLATION_VAT_ALIGNED))->toBeFalse()
        // The note names the VAT of the document, and the rounding only where it differs.
        ->and($reductions[0]->notes[0]->content)->toBe("Minderung des Entgelts zur Rechnung RE-2026-0042 vom 03.10.2026 wegen einer Mängelrüge. Minderung Entgelt 0,50\u{00A0}€, Umsatzsteuer 19\u{00A0}%: 0,10\u{00A0}€.")
        ->and($reductions[1]->notes[0]->content)->toBe("Minderung des Entgelts zur Rechnung RE-2026-0042 vom 03.10.2026 wegen einer Mängelrüge. Minderung Entgelt 0,50\u{00A0}€, Umsatzsteuer 19\u{00A0}%: 0,09\u{00A0}€. Rundungsausgleich: Die Umsatzsteuer dieser Entgeltminderung ist so bemessen, dass alle Entgeltminderungen und Stornos zur Rechnung RE-2026-0042 zusammen die Umsatzsteuer des geminderten und stornierten Entgelts exakt berichtigen.")
        ->and($reductions[2]->notes[0]->content)->toEndWith("Umsatzsteuer 19\u{00A0}%: 0,10\u{00A0}€.")
        ->and(Validate::codes($validation[1]))->toContain('QUITTIO-CANCELLATION-VAT-ALIGNED')->not->toContain('QUITTIO-VAT-ROUNDING')
        ->and(array_map(static fn ($result): array => $result->errors(), $validation))->toBe([[], [], []])
        ->and($history->taxable('S|19.00')->toString())->toBe('1.50')
        ->and($history->tax('S|19.00')->toString())->toBe('0.29');
});

it('writes the rounding adjustment in the locale of the reduction', function () {
    $original = reductionAlignmentOriginal();
    $first = reductionAlignmentReduce($original, 'EM-1', null);
    $second = reductionAlignmentReduce($original, 'EM-2', (new CancellationHistory)->withPriceReduction($original, $first), locale: 'en');

    expect($second->notes[0]->content)->toStartWith('Reduction of the consideration for invoice RE-2026-0042 of 03/10/2026 due to a complaint about defects. Reduction net ')
        ->and($second->notes[0]->content)->toContain('VAT 19%: ')->toContain('0.09')
        ->and($second->notes[0]->content)->toEndWith('. Rounding adjustment: the VAT of this price reduction is set so that all price reductions and cancellations of invoice RE-2026-0042 together correct exactly the VAT of the reduced and cancelled consideration.');
});

it('rounds every price reduction on its own without a history', function () {
    $original = reductionAlignmentOriginal();
    $reductions = array_map(static fn (string $number): InvoiceData => reductionAlignmentReduce($original, $number, null), ['EM-1', 'EM-2', 'EM-3']);
    $first = reductionAlignmentReduce($original, 'EM-1', new CancellationHistory);

    expect(array_map(reductionAlignmentVat(...), $reductions))->toBe(['0.10', '0.10', '0.10'])
        ->and($reductions[1]->meta->hasFlag(Meta::FLAG_CANCELLATION_VAT_ALIGNED))->toBeFalse()
        ->and($reductions[1]->notes[0]->content)->not->toContain('Rundungsausgleich')
        // A history that changes nothing gives the same document.
        ->and($first->with(['issueDate' => $reductions[0]->issueDate])->toSnapshot())->toBe($reductions[0]->toSnapshot());
});

it('shares the history with the partial cancellations of the original', function () {
    $original = reductionAlignmentOriginal();

    // Partial cancellation, price reduction, partial cancellation of the last unit (closes the group).
    $cancelled = reductionAlignmentCancel($original, 'ST-1', new CancellationHistory);
    $history = (new CancellationHistory)->withCancellation($original, $cancelled);
    $reduced = reductionAlignmentReduce($original, 'EM-1', $history);
    $history = $history->withPriceReduction($original, $reduced);
    $last = reductionAlignmentCancel($original, 'ST-2', new CancellationHistory($history->groups, ['S|19.00']));

    // Price reduction first, then the partial cancellations are aligned with it.
    $first = reductionAlignmentReduce($original, 'EM-2', new CancellationHistory);
    $then = reductionAlignmentCancel($original, 'ST-3', (new CancellationHistory)->withPriceReduction($original, $first));

    expect([reductionAlignmentVat($cancelled), reductionAlignmentVat($reduced), reductionAlignmentVat($last)])->toBe(['0.10', '0.09', '0.10'])
        ->and($reduced->meta->hasFlag(Meta::FLAG_CANCELLATION_VAT_ALIGNED))->toBeTrue()
        ->and([reductionAlignmentVat($first), reductionAlignmentVat($then)])->toBe(['0.10', '0.09'])
        ->and($then->notes[0]->content)->toContain('Rundungsausgleich')
        ->and(Validate::data($reduced, calculate: false)->errors())->toBe([])
        ->and(Validate::data($then, calculate: false)->errors())->toBe([]);
});

it('credits exactly the VAT of the original once its whole net amount is credited', function () {
    // 3 × 10.00 with a line allowance of 1.00 at 19 %: net 29.00, VAT 5.51.
    $original = (new En16931Calculator)->calculate(Scenarios::base()
        ->line(fn ($l) => $l->name('Teil')->quantity(3, 'H87')->netPrice('10.00')->allowance('1.00', reason: 'Rabatt')->vat(VatCategory::StandardRate, '19'))
        ->build());
    $history = new CancellationHistory;
    $reductions = [];
    foreach (['9.67', '9.67', '9.66'] as $index => $amount) {
        $reductions[] = $reduction = reductionAlignmentReduce($original, 'EM-'.($index + 1), $history, $amount);
        $history = $history->withPriceReduction($original, $reduction);
    }

    expect(reductionAlignmentVat($original))->toBe('5.51')
        ->and(array_map(reductionAlignmentVat(...), $reductions))->toBe(['1.84', '1.83', '1.84']);
});

it('closes every VAT group of an original with several rates on its own', function () {
    $original = (new En16931Calculator)->calculate(Scenarios::base()
        ->line(fn ($l) => $l->name('Teil')->quantity(3, 'H87')->netPrice('0.50')->vat(VatCategory::StandardRate, '19'))
        ->line(fn ($l) => $l->name('Buch')->quantity(3, 'H87')->netPrice('3.33')->vat(VatCategory::StandardRate, '7'))
        ->build());
    $history = new CancellationHistory;
    $vat = [];
    foreach ([['S:19' => '0.50', 'S:7' => '3.33'], ['S:19' => '0.50', 'S:7' => '6.66'], ['S:19' => '0.50']] as $index => $amounts) {
        $reduction = (new En16931Calculator)->calculate(PriceReduction::of($original, 'EM-'.$index, PriceReductionReason::Bonus, amounts: $amounts, history: $history)->build());
        $vat[] = array_map(static fn ($entry): string => $entry->taxableAmount->getAmount().' '.$entry->taxAmount->getAmount(), $reduction->vatBreakdown);
        $history = $history->withPriceReduction($original, $reduction);
    }

    // 19 %: 0.10 + 0.09 + 0.10 = 0.29; 7 %: 0.23 + 0.47 (closes: 0.70 − 0.23, own 0.47) = 0.70.
    expect($vat)->toBe([['0.50 0.10', '3.33 0.23'], ['0.50 0.09', '6.66 0.47'], ['0.50 0.10']])
        ->and($history->tax('S|19.00')->toString())->toBe('0.29')
        ->and($history->tax('S|7.00')->toString())->toBe('0.70');
});

it('counts the cancellation of a price reduction negatively', function () {
    $original = reductionAlignmentOriginal();
    $first = reductionAlignmentReduce($original, 'EM-1', null);
    $second = reductionAlignmentReduce($original, 'EM-2', (new CancellationHistory)->withPriceReduction($original, $first));
    $reversal = (new En16931Calculator)->calculate(Cancellation::of($second, 'ST-1')->build());
    $history = CancellationHistory::fromDocuments($original, [$first, $second, $reversal]);
    $third = reductionAlignmentReduce($original, 'EM-3', $history);

    // EM-2 (0.09) is cancelled: 0.50 net and 0.10 VAT stay credited, EM-3 brings it to round2(1.00 × 19 %) = 0.19.
    expect(reductionAlignmentVat($second))->toBe('0.09')
        ->and($reversal->typeCodeValue())->toBe('380')
        ->and($history->taxable('S|19.00')->toString())->toBe('0.50')
        ->and($history->tax('S|19.00')->toString())->toBe('0.10')
        ->and(reductionAlignmentVat($third))->toBe('0.09');
});

it('aligns the VAT total in accounting currency (BT-111)', function () {
    $original = reductionAlignmentOriginal('USD');
    $history = new CancellationHistory;
    $reductions = [];
    foreach (['EM-1', 'EM-2', 'EM-3'] as $number) {
        $reductions[] = $reduction = reductionAlignmentReduce($original, $number, $history);
        $history = $history->withPriceReduction($original, $reduction);
    }

    // 0.29 USD × 0.9 = 0.26 EUR: 0.09 + round2(0.19 × 0.9) − 0.09 = 0.08 + the rest 0.09.
    expect($original->totals?->vatTotalInTaxCurrency?->getAmount()->toString())->toBe('0.26')
        ->and(array_map(reductionAlignmentVat(...), $reductions))->toBe(['0.10', '0.09', '0.10'])
        ->and(array_map(static fn (InvoiceData $reduction): string => (string) $reduction->totals?->vatTotalInTaxCurrency?->getAmount(), $reductions))->toBe(['0.09', '0.08', '0.09'])
        ->and(array_map(static fn (InvoiceData $reduction): array => Validate::data($reduction, calculate: false)->errors(), $reductions))->toBe([[], [], []]);
});

it('recognises an issued price reduction whose history changed meanwhile', function () {
    $original = reductionAlignmentOriginal();
    $first = reductionAlignmentReduce($original, 'EM-1', null);
    $history = (new CancellationHistory)->withPriceReduction($original, $first);
    $second = reductionAlignmentReduce($original, 'EM-2', $history);

    expect($history->matches($original, $second))->toBeTrue()
        ->and((new CancellationHistory)->matches($original, $second))->toBeFalse();
});

it('rejects a reduction beyond what the earlier documents left of a VAT group', function () {
    $original = reductionAlignmentOriginal();
    $history = new CancellationHistory(['S|19.00' => ['taxable' => BigDecimal::of('1.00'), 'tax' => BigDecimal::of('0.19'), 'taxInTaxCurrency' => null]]);

    expect(fn () => PriceReduction::of($original, 'EM-3', PriceReductionReason::Other, amount: '0.60', history: $history))
        ->toThrow(InvalidArgumentException::class, 'must be at most 0.50: the earlier cancellations and price reductions of invoice RE-2026-0042 credit 1.00 of its taxable amount 1.50');
});

it('rejects a history whose VAT would be more than a cent off', function () {
    // After cancelled price reductions the VAT credited so far can be off the cumulative rounding.
    $history = new CancellationHistory(['S|19.00' => ['taxable' => BigDecimal::of('1.00'), 'tax' => BigDecimal::of('0.17'), 'taxInTaxCurrency' => null]]);

    expect(fn () => PriceReduction::of(reductionAlignmentOriginal(), 'EM-3', PriceReductionReason::Other, amount: '0.50', history: $history))
        ->toThrow(InvalidArgumentException::class, 'VAT of 0.12 on a taxable amount of 0.50 (rounded on its own 0.10)');
});

it('passes the history through Quittio::priceReduction()', function () {
    $original = reductionAlignmentOriginal();
    $quittio = Quittio::create();
    $first = (new En16931Calculator)->calculate($quittio->priceReduction($original, 'EM-1', PriceReductionReason::Defect, amount: '0.50')->build());
    $second = (new En16931Calculator)->calculate($quittio->priceReduction($original, 'EM-2', PriceReductionReason::Defect, amount: '0.50', history: (new CancellationHistory)->withPriceReduction($original, $first))->build());

    expect([reductionAlignmentVat($first), reductionAlignmentVat($second)])->toBe(['0.10', '0.09']);
});
