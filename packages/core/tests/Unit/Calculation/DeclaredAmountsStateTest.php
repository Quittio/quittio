<?php

declare(strict_types=1);

use Brick\Math\BigDecimal;
use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Calculation\DeclaredAmounts;
use Quittio\Core\Calculation\DeclaredAmountsState;
use Quittio\Core\Calculation\DeclaredAmountsStatus;
use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Data\Meta;
use Quittio\Core\Tests\Support\Invoices;

/**
 * @return array{string, ?string, bool, ?string}
 */
function stateFields(DeclaredAmountsState $state): array
{
    return [$state->status->name, $state->fingerprint, $state->vatAligned, $state->vatInTaxCurrency?->toString()];
}

describe('status', function () {
    it('maps the two flags to four states', function (bool $declared, bool $recalculated, DeclaredAmountsStatus $status) {
        expect(DeclaredAmountsStatus::of($declared, $recalculated))->toBe($status)
            ->and($status->declared())->toBe($declared)
            ->and($status->recalculated())->toBe($recalculated);
    })->with([
        'none' => [false, false, DeclaredAmountsStatus::None],
        'declared' => [true, false, DeclaredAmountsStatus::Declared],
        'partly recalculated' => [true, true, DeclaredAmountsStatus::PartlyRecalculated],
        'recalculated' => [false, true, DeclaredAmountsStatus::Recalculated],
    ]);
});

describe('meta flags', function () {
    it('reads no state from meta data without its flags', function () {
        $state = DeclaredAmountsState::fromMeta(new Meta(flags: [Meta::FLAG_SMALL_BUSINESS]));

        expect(stateFields($state))->toBe(['None', null, false, null])
            ->and(stateFields(DeclaredAmountsState::none()))->toBe(['None', null, false, null])
            ->and($state->isDeclared())->toBeFalse()
            ->and($state->isRecalculated())->toBeFalse();
    });

    it('writes every state as the flags of earlier versions and reads it back', function (DeclaredAmountsStatus $status, ?string $fingerprint, bool $aligned, ?string $vat, array $flags) {
        $state = new DeclaredAmountsState($status, $fingerprint, $aligned, $vat === null ? null : BigDecimal::of($vat));
        $meta = $state->applyTo(new Meta(locale: 'de', flags: ['small_business', 'de.small_amount']));

        expect($meta->flags)->toBe(['small_business', 'de.small_amount', ...$flags])
            ->and($meta->locale)->toBe('de')
            ->and(stateFields(DeclaredAmountsState::fromMeta($meta)))->toBe(stateFields($state))
            ->and($state->isDeclared())->toBe($status->declared())
            ->and($state->isRecalculated())->toBe($status->recalculated())
            ->and($state->applyTo($meta)->flags)->toBe($meta->flags);
    })->with([
        'none' => [DeclaredAmountsStatus::None, null, false, null, []],
        'calculated' => [DeclaredAmountsStatus::None, 'f00', false, null, ['amounts_fingerprint:f00']],
        'declared without fingerprint' => [DeclaredAmountsStatus::Declared, null, false, null, ['declared_amounts']],
        'declared' => [DeclaredAmountsStatus::Declared, 'f00', false, null, ['declared_amounts', 'amounts_fingerprint:f00']],
        'partly recalculated' => [DeclaredAmountsStatus::PartlyRecalculated, 'f00', false, null, ['declared_amounts', 'declared_amounts_recalculated', 'amounts_fingerprint:f00']],
        'recalculated' => [DeclaredAmountsStatus::Recalculated, 'f00', false, null, ['declared_amounts_recalculated', 'amounts_fingerprint:f00']],
        'aligned' => [DeclaredAmountsStatus::None, null, true, null, ['cancellation_vat_aligned']],
        'aligned and declared' => [DeclaredAmountsStatus::Declared, 'f00', true, '26.13', ['declared_amounts', 'cancellation_vat_aligned', 'declared_vat_in_tax_currency:26.13', 'amounts_fingerprint:f00']],
        'everything' => [DeclaredAmountsStatus::PartlyRecalculated, 'f00', true, '-0.10', ['declared_amounts', 'declared_amounts_recalculated', 'cancellation_vat_aligned', 'declared_vat_in_tax_currency:-0.10', 'amounts_fingerprint:f00']],
    ]);

    it('reads the flags in any order and writes them after the other flags in a fixed order', function () {
        $stored = new Meta(flags: ['amounts_fingerprint:f00', 'declared_amounts_recalculated', 'u', 'declared_vat_in_tax_currency:1.50', 'cancellation_vat_aligned', 'declared_amounts', 'v']);
        $state = DeclaredAmountsState::fromMeta($stored);

        expect(stateFields($state))->toBe(['PartlyRecalculated', 'f00', true, '1.50'])
            ->and($state->applyTo($stored)->flags)->toBe(['u', 'v', 'declared_amounts', 'declared_amounts_recalculated', 'cancellation_vat_aligned', 'declared_vat_in_tax_currency:1.50', 'amounts_fingerprint:f00']);
    });

    it('replaces every flag of the previous state, also duplicates and unreadable values', function () {
        $meta = new Meta(flags: ['declared_amounts', 'u', 'declared_amounts', 'declared_amounts_recalculated', 'cancellation_vat_aligned', 'declared_vat_in_tax_currency:1e5', 'amounts_fingerprint:a', 'amounts_fingerprint:b']);

        expect(DeclaredAmountsState::fromMeta($meta)->vatInTaxCurrency)->toBeNull()
            ->and(DeclaredAmountsState::fromMeta($meta)->fingerprint)->toBe('a')
            ->and(DeclaredAmountsState::none()->applyTo($meta)->flags)->toBe(['u'])
            ->and(DeclaredAmountsState::none()->withFingerprint('c')->applyTo($meta)->flags)->toBe(['u', 'amounts_fingerprint:c'])
            ->and(DeclaredAmountsState::fromMeta($meta)->applyTo($meta)->flags)->toBe(['u', 'declared_amounts', 'declared_amounts_recalculated', 'cancellation_vat_aligned', 'amounts_fingerprint:a']);
    });

    it('survives toArray() and fromArray() of the invoice data unchanged', function () {
        $data = Invoices::simple()->build();
        $state = new DeclaredAmountsState(DeclaredAmountsStatus::PartlyRecalculated, 'f00', true, BigDecimal::of('26.13'));
        $data = $data->with(['meta' => $state->applyTo($data->meta)]);
        $restored = InvoiceData::fromArray(json_decode($data->toJson(), true, flags: JSON_THROW_ON_ERROR));

        expect($restored->meta->flags)->toBe($data->meta->flags)
            ->and(stateFields(DeclaredAmountsState::fromMeta($restored->meta)))->toBe(stateFields($state));
    });
});

describe('transitions', function () {
    it('changes the declared and the recalculated flag independently', function (DeclaredAmountsStatus $from, bool $declared, bool $recalculated, DeclaredAmountsStatus $withDeclared, DeclaredAmountsStatus $withRecalculated) {
        $state = new DeclaredAmountsState($from, 'f00', true, BigDecimal::of('1.00'));

        expect($state->withDeclared($declared)->status)->toBe($withDeclared)
            ->and($state->withRecalculated($recalculated)->status)->toBe($withRecalculated)
            ->and(array_slice(stateFields($state->withDeclared($declared)), 1))->toBe(['f00', true, '1.00'])
            ->and(array_slice(stateFields($state->withRecalculated($recalculated)), 1))->toBe(['f00', true, '1.00']);
    })->with([
        [DeclaredAmountsStatus::None, true, true, DeclaredAmountsStatus::Declared, DeclaredAmountsStatus::Recalculated],
        [DeclaredAmountsStatus::Declared, false, true, DeclaredAmountsStatus::None, DeclaredAmountsStatus::PartlyRecalculated],
        [DeclaredAmountsStatus::PartlyRecalculated, false, false, DeclaredAmountsStatus::Recalculated, DeclaredAmountsStatus::Declared],
        [DeclaredAmountsStatus::Recalculated, true, false, DeclaredAmountsStatus::PartlyRecalculated, DeclaredAmountsStatus::None],
    ]);

    it('defaults to setting the flags', function () {
        $none = DeclaredAmountsState::none();

        expect($none->withDeclared()->status)->toBe(DeclaredAmountsStatus::Declared)
            ->and($none->withRecalculated()->status)->toBe(DeclaredAmountsStatus::Recalculated)
            ->and($none->withVatAligned()->vatAligned)->toBeTrue()
            ->and($none->withVatAligned()->withVatAligned(false)->vatAligned)->toBeFalse();
    });

    it('replaces one component and keeps the others', function () {
        $state = new DeclaredAmountsState(DeclaredAmountsStatus::Declared, 'f00', true, BigDecimal::of('1.00'));

        expect(stateFields($state->withStatus(DeclaredAmountsStatus::Recalculated)))->toBe(['Recalculated', 'f00', true, '1.00'])
            ->and(stateFields($state->withFingerprint(null)))->toBe(['Declared', null, true, '1.00'])
            ->and(stateFields($state->withVatAligned(false)))->toBe(['Declared', 'f00', false, '1.00'])
            ->and(stateFields($state->withVatInTaxCurrency(BigDecimal::of('2.00'))))->toBe(['Declared', 'f00', true, '2.00'])
            ->and(stateFields($state->withVatInTaxCurrency(null)))->toBe(['Declared', 'f00', true, null]);
    });
});

describe('call sites', function () {
    it('writes the flags of the calculator in the same order as those of the builder', function () {
        $calculated = (new En16931Calculator)->calculate(Invoices::simple()->build());
        $declared = $calculated->with(['meta' => $calculated->meta->with(['flags' => ['declared_amounts', 'u']])]);
        $sealed = $declared->with(['meta' => $declared->meta->withAmountsFingerprint(DeclaredAmounts::fingerprint($declared))->withFlag('v')]);
        $built = InvoiceBuilder::from($sealed)->build();

        // Quittio reproduces its own amounts: from() drops the declared amounts flag.
        expect((new En16931Calculator)->calculate($sealed)->meta->flags)->toBe(['u', 'v', 'declared_amounts', 'amounts_fingerprint:'.DeclaredAmounts::fingerprint($sealed)])
            ->and($built->meta->flags)->toBe(['u', 'v', 'amounts_fingerprint:'.DeclaredAmounts::fingerprint($built)]);
    });
});
