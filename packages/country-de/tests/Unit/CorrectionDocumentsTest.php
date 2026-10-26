<?php

declare(strict_types=1);

use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Corrections\PriceReductionReason;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Data\Meta;
use Quittio\Core\Enums\InvoiceTypeCode;
use Quittio\Core\Enums\UnitCode;
use Quittio\Core\Exceptions\InvalidArgumentException;
use Quittio\Core\Quittio;
use Quittio\Core\Support\Date;
use Quittio\Core\Testing\Scenarios;
use Quittio\Country\DE\CountryDE;
use Quittio\Country\DE\Tax\VatPreset;

function correctionsGermany(array $config = []): Quittio
{
    return Quittio::create($config, [new CountryDE]);
}

/**
 * @return list<string>
 */
function correctionCodes(Quittio $quittio, InvoiceBuilder $builder): array
{
    return array_values(array_unique(array_map(static fn ($m): string => $m->code, $quittio->make($builder)->format('zugferd')->check()->messages)));
}

it('titles corrected invoices and price reductions like the German correction policy', function () {
    $quittio = correctionsGermany();
    $original = (new En16931Calculator)->calculate(Scenarios::make('simple')->build());
    $correction = $quittio->correction($original, 'RE-2026-0050', issueDate: Date::of(2026, 10, 10))->build();
    $reduction = $quittio->priceReduction($original, 'EM-2026-0001', PriceReductionReason::CashDiscount, percent: '2', issueDate: Date::of(2026, 10, 20))->build();

    expect($correction->presentation?->title)->toBe('Rechnungskorrektur')
        ->and($correction->notes[0]->content)->toBe('Diese Rechnung berichtigt und ersetzt die Rechnung RE-2026-0042 vom 03.10.2026.')
        ->and(correctionCodes($quittio, $quittio->correction($original, 'RE-2026-0050', issueDate: Date::of(2026, 10, 10))))->not->toContain('DE-USTG-18')
        ->and($reduction->presentation?->title)->toBe('Entgeltminderung')
        ->and($reduction->typeCode)->toBe(InvoiceTypeCode::CreditNote)
        ->and(array_filter(correctionCodes($quittio, $quittio->priceReduction($original, 'EM-2026-0001', PriceReductionReason::CashDiscount, percent: '2', issueDate: Date::of(2026, 10, 20))), static fn (string $code): bool => str_starts_with($code, 'DE-')))->toBe([]);
});

it('asks for the correction note on corrected invoices (section 31 (5) UStDV)', function () {
    $quittio = correctionsGermany();
    $bare = Scenarios::make('simple')->type(InvoiceTypeCode::CorrectedInvoice)->precedingInvoice('RE-2026-0001', '2026-09-01');

    expect(correctionCodes($quittio, $bare))->toContain('DE-USTG-18')
        ->and(correctionCodes($quittio, $bare->note('Diese Rechnung berichtigt und ersetzt die Rechnung RE-2026-0001 vom 01.09.2026.')))->not->toContain('DE-USTG-18');
});

it('cancels self-billed invoices sent as XRechnung by a negative self-billed invoice (BR-DE-17)', function () {
    $quittio = correctionsGermany();
    $selfBilled = fn (?string $leitwegId) => (new En16931Calculator)->calculate(Scenarios::make('simple')->buyerReference($leitwegId ?? 'PO-4711')->de()->selfBilled()->build());

    expect($quittio->cancellation($selfBilled('04011000-12345-03'), 'ST-1')->build()->typeCodeValue())->toBe('389')
        ->and($quittio->cancellation($selfBilled('04011000-12345-03'), 'ST-1')->build()->lines[0]->quantity->isNegative())->toBeTrue()
        ->and($quittio->cancellation($selfBilled(null), 'ST-1')->build()->typeCodeValue())->toBe('261')
        // An explicit strategy and the configuration still win.
        ->and($quittio->cancellation($selfBilled('04011000-12345-03'), 'ST-1', strategy: 'credit_note')->build()->typeCodeValue())->toBe('261')
        ->and(correctionsGermany(['corrections' => ['cancellation_strategy' => 'credit_note']])->cancellation($selfBilled('04011000-12345-03'), 'ST-1')->build()->typeCodeValue())->toBe('261')
        // Invoices that are not self-billed keep the credit note.
        ->and($quittio->cancellation((new En16931Calculator)->calculate(Scenarios::make('simple')->buyerReference('04011000-12345-03')->build()), 'ST-1')->build()->typeCodeValue())->toBe('381');
});

it('notes a self-billed advance deducted in a final invoice that is no self-billed invoice', function () {
    $quittio = correctionsGermany();
    $advance = (new En16931Calculator)->calculate(Scenarios::base()->number('GS-1')->issueDate('2026-09-01')->de()->selfBilled()
        ->line(fn ($l) => $l->name('Anzahlung')->quantity(1, UnitCode::Piece)->netPrice('1000.00')->vat(VatPreset::Standard))->build());
    $final = fn () => Scenarios::base()->line(fn ($l) => $l->name('Maschine')->quantity(1, UnitCode::Piece)->netPrice('3000.00')->vat(VatPreset::Standard))
        ->de()->deductAdvance($advance);

    expect(correctionCodes($quittio, $final()))->toContain('DE-USTG-19')
        ->and(correctionCodes($quittio, $final()->de()->selfBilled()))->not->toContain('DE-USTG-19');
});

/**
 * A calculated prepayment invoice of 1,000.00 at 19 % and 100.00 at 7 % without a date of the
 * supply (BT-72, BG-14, BG-26): ->de()->advance() with the expected month, or the bare type code.
 */
function advanceWithoutSupplyDate(bool $macro = true): InvoiceData
{
    $builder = Scenarios::base()->number('AB-2026-0001')->issueDate('2026-09-01')
        ->line(fn ($l) => $l->name('Anzahlung Website')->quantity(1, UnitCode::Piece)->netPrice('1000.00')->vat(VatPreset::Standard))
        ->line(fn ($l) => $l->name('Anzahlung Buch')->quantity(1, UnitCode::Piece)->netPrice('100.00')->vat(VatPreset::Reduced));
    $builder = $macro ? $builder->de()->advance('2027-03') : $builder->type('386');

    return (new En16931Calculator)->calculate($builder->build())->with(['delivery' => null]);
}

/**
 * @return list<string>
 */
function correctionErrors(Quittio $quittio, InvoiceBuilder $builder): array
{
    return array_values(array_map(static fn ($m): string => $m->code, $quittio->make($builder)->format('zugferd')->check()->errors()));
}

it('cancels a prepayment invoice without a date of the supply, fully and partly (section 17 (2) no. 2 UStG)', function (string $strategy, bool $macro) {
    $quittio = correctionsGermany();
    $advance = advanceWithoutSupplyDate($macro);
    $full = $quittio->cancellation($advance, 'ST-1', strategy: $strategy, issueDate: Date::of(2026, 10, 1));
    $partial = $quittio->cancellation($advance, 'ST-2', strategy: $strategy, issueDate: Date::of(2026, 10, 1), lines: [['line' => '1']]);
    $amount = $quittio->cancellation($advance, 'ST-3', strategy: $strategy, issueDate: Date::of(2026, 10, 1), lines: [['line' => '2', 'amount' => '40.00']]);

    expect(correctionCodes($quittio, InvoiceBuilder::from($advance)))->toBe($macro ? [] : ['DE-USTG-03'])
        ->and($full->build()->typeCodeValue())->toBe($strategy === 'credit_note' ? '381' : '380')
        ->and($full->build()->meta->hasFlag(Meta::FLAG_PREPAYMENT_CORRECTION))->toBeTrue()
        ->and(correctionCodes($quittio, $full))->not->toContain('DE-USTG-03')
        ->and(correctionErrors($quittio, $full))->toBe([])
        ->and(correctionCodes($quittio, $partial))->not->toContain('DE-USTG-03')
        ->and(correctionErrors($quittio, $partial))->toBe([])
        ->and(correctionCodes($quittio, $amount))->not->toContain('DE-USTG-03')
        ->and(correctionErrors($quittio, $amount))->toBe([]);
})->with(['credit_note', 'negative_invoice'])->with(['advance()' => true, 'type code 386' => false]);

it('still requires the date of the supply of other invoices and their cancellations (section 14 (4) no. 6 UStG)', function () {
    $quittio = correctionsGermany();
    $invoice = advanceWithoutSupplyDate(false)->with(['typeCode' => InvoiceTypeCode::CommercialInvoice]);

    expect(correctionCodes($quittio, InvoiceBuilder::from($invoice)))->toContain('DE-USTG-03')
        ->and(correctionErrors($quittio, InvoiceBuilder::from($invoice)))->toContain('DE-USTG-03')
        ->and(correctionErrors($quittio, $quittio->cancellation($invoice, 'ST-1')))->toContain('DE-USTG-03')
        // The flag exempts a cancellation only together with the reference to the prepayment invoice (BG-3).
        ->and(correctionErrors($quittio, InvoiceBuilder::from($quittio->cancellation(advanceWithoutSupplyDate(), 'ST-2')->build()->with(['precedingInvoices' => []]))))->toContain('DE-USTG-03');
});

it('checks the corrected invoice of a prepayment invoice like the prepayment invoice', function () {
    $quittio = correctionsGermany();

    expect(correctionCodes($quittio, $quittio->correction(advanceWithoutSupplyDate(), 'AB-2026-0002')))->not->toContain('DE-USTG-03')
        ->and(correctionErrors($quittio, $quittio->correction(advanceWithoutSupplyDate(false), 'AB-2026-0002')))->toBe([])
        // A bare 386 without the expected date gets the warning, its corrected invoice as well.
        ->and(correctionCodes($quittio, $quittio->correction(advanceWithoutSupplyDate(false), 'AB-2026-0002')))->toContain('DE-USTG-03')
        ->and(correctionCodes($quittio, $quittio->correction(advanceWithoutSupplyDate(false), 'AB-2026-0002')->prepaid('1177.00')))->toContain('DE-USTG-17');
});

it('reduces no prepayment invoice: the final invoice settles the advance payment', function () {
    expect(fn () => correctionsGermany()->priceReduction(advanceWithoutSupplyDate(), 'EM-1', PriceReductionReason::CashDiscount, percent: '2'))
        ->toThrow(InvalidArgumentException::class, 'cannot be reduced by a price reduction document');
});
