<?php

declare(strict_types=1);

use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Corrections\PriceReductionReason;
use Quittio\Core\Enums\InvoiceTypeCode;
use Quittio\Core\Enums\UnitCode;
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
