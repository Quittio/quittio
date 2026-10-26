<?php

declare(strict_types=1);

use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Corrections\PriceReduction;
use Quittio\Core\Corrections\PriceReductionReason;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Data\Meta;
use Quittio\Core\Enums\InvoiceTypeCode;
use Quittio\Core\Exceptions\InvalidArgumentException;
use Quittio\Core\Support\Date;
use Quittio\Core\Testing\Scenarios;
use Quittio\Core\Tests\Support\Validate;

function reductionOriginal(string $scenario = 'mixed-vat'): InvoiceData
{
    return (new En16931Calculator)->calculate(Scenarios::make($scenario)->build());
}

it('reduces every VAT group by a percentage, e.g. a cash discount taken', function () {
    $original = reductionOriginal();
    $reduction = (new En16931Calculator)->calculate(
        PriceReduction::of($original, 'EM-1', PriceReductionReason::CashDiscount, percent: '2', issueDate: Date::of(2026, 10, 20), locale: 'de')->build(),
    );
    $expected = array_map(static fn ($row): string => $row->taxableAmount->getAmount()->multipliedBy('0.02')->toScale(2, Brick\Math\RoundingMode::HalfUp)->toString(), $original->vatBreakdown);

    expect($reduction->typeCode)->toBe(InvoiceTypeCode::CreditNote)
        ->and($reduction->precedingInvoices[0]->number)->toBe($original->number)
        ->and($reduction->meta->hasFlag(Meta::FLAG_PRICE_REDUCTION))->toBeTrue()
        ->and($reduction->presentation?->title)->toBe('Entgeltminderung')
        ->and(array_map(static fn ($row): string => $row->taxableAmount->getAmount()->toString(), $reduction->vatBreakdown))->toBe($expected)
        ->and($reduction->notes[0]->content)->toStartWith("Minderung des Entgelts zur Rechnung RE-2026-0042 vom 03.10.2026 wegen in Anspruch genommenen Skontos (2\u{00A0}%). Minderung Entgelt ")
        ->and($reduction->paymentTerms?->text)->toBe('Die Minderung wurde bei der Zahlung bereits einbehalten.')
        ->and(Validate::data($reduction, calculate: false)->errors())->toBe([]);
});

it('names the reduction of net amount and VAT per rate in the note', function () {
    $original = reductionOriginal('simple');
    $reduction = PriceReduction::of($original, 'EM-2', PriceReductionReason::Defect, amount: '100.00', locale: 'de')->build();

    expect($reduction->notes[0]->content)->toBe("Minderung des Entgelts zur Rechnung RE-2026-0042 vom 03.10.2026 wegen einer Mängelrüge. Minderung Entgelt 100,00\u{00A0}€, Umsatzsteuer 19\u{00A0}%: 19,00\u{00A0}€.")
        ->and($reduction->lines)->toHaveCount(1)
        ->and($reduction->lines[0]->item->name)->toBe('Minderung des Entgelts')
        ->and($reduction->lines[0]->price->netPrice?->toString())->toBe('100.00')
        ->and($reduction->paymentTerms?->text)->toBe('Der Betrag wird mit offenen Forderungen verrechnet oder erstattet.');
});

it('reduces single VAT groups by amounts keyed by category and rate', function () {
    $original = reductionOriginal();
    $keys = array_map(static fn ($row): string => $row->category->value.':'.$row->rate?->strippedOfTrailingZeros()->toString(), $original->vatBreakdown);
    $reduction = (new En16931Calculator)->calculate(PriceReduction::of($original, 'EM-3', PriceReductionReason::Bonus, amounts: [$keys[0] => '10.00'], locale: 'en')->build());

    expect($reduction->vatBreakdown)->toHaveCount(1)
        ->and($reduction->vatBreakdown[0]->taxableAmount->getAmount()->toString())->toBe('10.00')
        ->and($reduction->notes[0]->content)->toContain('due to a bonus.');
});

it('rejects reductions that do not fit the original', function (Closure $reduce, string $message) {
    expect($reduce)->toThrow(InvalidArgumentException::class, $message);
})->with([
    'amount with several VAT groups' => [fn () => PriceReduction::of(reductionOriginal(), 'EM', PriceReductionReason::Other, amount: '10'), 'give the reduction as percent or as amounts per VAT group'],
    'more than the group' => [fn () => PriceReduction::of(reductionOriginal('simple'), 'EM', PriceReductionReason::Other, amount: '100000'), 'must be greater than 0 and at most'],
    'unknown group' => [fn () => PriceReduction::of(reductionOriginal('simple'), 'EM', PriceReductionReason::Other, amounts: ['S:16' => '1']), 'has no VAT group S:16'],
    'two kinds' => [fn () => PriceReduction::of(reductionOriginal('simple'), 'EM', PriceReductionReason::Other, percent: '2', amount: '1'), 'exactly one of percent, amount or amounts'],
    'percent above 100' => [fn () => PriceReduction::of(reductionOriginal('simple'), 'EM', PriceReductionReason::Other, percent: '101'), 'at most 100'],
    'credit note' => [fn () => PriceReduction::of((new En16931Calculator)->calculate(Scenarios::make('simple')->type('381')->build()), 'EM', PriceReductionReason::Other, percent: '2'), 'cannot be reduced'],
    'not calculated' => [fn () => PriceReduction::of(Scenarios::make('simple')->build(), 'EM', PriceReductionReason::Other, percent: '2'), 'must be calculated'],
    // The advance payment is settled in the final invoice, an advance refunded is cancelled.
    'prepayment invoice' => [fn () => PriceReduction::of(reductionOriginal('simple')->with(['typeCode' => InvoiceTypeCode::PrepaymentInvoice]), 'EM', PriceReductionReason::CashDiscount, percent: '2'), 'cannot be reduced by a price reduction document: reduce the price in the final invoice'],
    'corrected prepayment invoice' => [fn () => PriceReduction::of(reductionOriginal('simple')->with(['typeCode' => InvoiceTypeCode::CorrectedInvoice, 'meta' => (new Meta)->withFlag(Meta::FLAG_PREPAYMENT_CORRECTION)]), 'EM', PriceReductionReason::Other, percent: '2'), 'prepayment invoice RE-2026-0042'],
]);
