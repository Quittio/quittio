<?php

declare(strict_types=1);

use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Corrections\Cancellation;
use Quittio\Core\Countries\CorrectionPolicy;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Enums\InvoiceTypeCode;
use Quittio\Core\Enums\NoteSubjectCode;
use Quittio\Core\Enums\UnitCode;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Enums\VatExemptionReasonCode;
use Quittio\Core\Profiles\ProfileRegistry;
use Quittio\Core\Reading\InvoiceReader;
use Quittio\Core\Tests\Support\Invoices;
use Quittio\Core\Tests\Support\Validate;
use Quittio\Core\Validation\Severity;
use Quittio\Core\Xml\Cii\CiiWriter;
use Quittio\Core\Xml\Ubl\UblWriter;

/**
 * @param  list<array{0: ?string, 1: ?VatExemptionReasonCode}>  $reasons
 */
function round3Exempt(array $reasons): InvoiceData
{
    $builder = Invoices::base();
    foreach ($reasons as $index => [$reason, $code]) {
        $builder->line(fn ($l) => $l->name('Item '.$index)->quantity(1, UnitCode::Piece)->netPrice('100.00')->vat(VatCategory::Exempt, '0', $reason, $code));
    }

    return (new En16931Calculator)->calculate($builder->build());
}

describe('exemption reasons of one VAT breakdown', function () {
    it('joins different reason texts and omits a code that holds only for part of the group', function () {
        $invoice = round3Exempt([
            ['Steuerfrei nach § 4 Nr. 14 UStG', null],
            ['Gebrauchtgegenstände/Sonderregelung', VatExemptionReasonCode::SecondHandGoods],
        ]);
        $result = Validate::data($invoice, calculate: false);
        $conflicts = array_values(array_filter($result->messages, fn ($m) => $m->code === 'QUITTIO-EXEMPTION-CONFLICT'));

        expect($invoice->vatBreakdown)->toHaveCount(1)
            ->and($invoice->vatBreakdown[0]->exemptionReason)->toBe('Steuerfrei nach § 4 Nr. 14 UStG; Gebrauchtgegenstände/Sonderregelung')
            ->and($invoice->vatBreakdown[0]->exemptionReasonCode)->toBeNull()
            ->and($conflicts)->toHaveCount(1)
            ->and($conflicts[0]->severity)->toBe(Severity::Warning)
            ->and($result->errors())->toBe([]);
    });

    it('keeps a common code and reports nothing when only the texts differ', function () {
        $invoice = round3Exempt([
            ['Gebrauchtgegenstände/Sonderregelung', VatExemptionReasonCode::SecondHandGoods],
            ['Gebrauchtgegenstände/Sonderregelung (Fahrzeug)', VatExemptionReasonCode::SecondHandGoods],
            ['Gebrauchtgegenstände/Sonderregelung', VatExemptionReasonCode::SecondHandGoods],
        ]);

        expect($invoice->vatBreakdown[0]->exemptionReason)->toBe('Gebrauchtgegenstände/Sonderregelung; Gebrauchtgegenstände/Sonderregelung (Fahrzeug)')
            ->and($invoice->vatBreakdown[0]->exemptionReasonCode)->toBe(VatExemptionReasonCode::SecondHandGoods)
            ->and(Validate::codes(Validate::data($invoice, calculate: false)))->not->toContain('QUITTIO-EXEMPTION-CONFLICT');
    });

    it('keeps the first code when the lines carry only different codes', function () {
        $invoice = round3Exempt([[null, VatExemptionReasonCode::SecondHandGoods], [null, VatExemptionReasonCode::WorksOfArt]]);

        expect($invoice->vatBreakdown[0]->exemptionReason)->toBeNull()
            ->and($invoice->vatBreakdown[0]->exemptionReasonCode)->toBe(VatExemptionReasonCode::SecondHandGoods)
            ->and(Validate::codes(Validate::data($invoice, calculate: false)))->toContain('QUITTIO-EXEMPTION-CONFLICT');
    });

    it('reads the joined reasons back and recalculates the same breakdown', function (string $syntax) {
        $invoice = round3Exempt([
            ['Steuerfrei nach § 4 Nr. 14 UStG', null],
            ['Gebrauchtgegenstände/Sonderregelung', VatExemptionReasonCode::SecondHandGoods],
        ]);
        $profile = ProfileRegistry::withDefaults()->get('en16931');
        $xml = $syntax === 'cii' ? (new CiiWriter)->serialize($invoice, $profile) : (new UblWriter)->serialize($invoice, $profile);

        $read = (new InvoiceReader)->read($xml)->data;
        $recalculated = (new En16931Calculator)->calculate($read->with(['totals' => null, 'vatBreakdown' => []]));

        expect($recalculated->vatBreakdown[0]->exemptionReason)->toBe($invoice->vatBreakdown[0]->exemptionReason)
            ->and($recalculated->vatBreakdown[0]->exemptionReasonCode)->toBeNull();
    })->with(['cii', 'ubl']);
});

describe('cancellation of self-billed invoices', function () {
    $policy = new CorrectionPolicy(titles: [
        'cancellation' => ['de' => 'Stornorechnung', 'en' => 'Cancellation invoice'],
        'cancellation_self_billed' => ['de' => 'Storno-Gutschrift'],
    ]);

    it('words the note and takes the title for self-billed originals', function (InvoiceTypeCode $type, string $locale, string $title, string $note) use ($policy) {
        $original = (new En16931Calculator)->calculate(Invoices::simple()->type($type)->note('Gutschrift', NoteSubjectCode::RegulatoryInformation)->build());

        $cancellation = Cancellation::of($original, 'ST-1', locale: $locale, policy: $policy)->build();

        expect($cancellation->presentation?->title)->toBe($title)
            ->and($cancellation->notes[0]->content)->toStartWith($note.' INV-2026-0001');
    })->with([
        'self-billed invoice, de' => [InvoiceTypeCode::SelfBilledInvoice, 'de', 'Storno-Gutschrift', 'Stornierung der Gutschrift'],
        'self-billed credit note, de' => [InvoiceTypeCode::SelfBilledCreditNote, 'de', 'Storno-Gutschrift', 'Stornierung der Gutschrift'],
        'self-billed invoice, en (falls back to cancellation)' => [InvoiceTypeCode::SelfBilledInvoice, 'en', 'Cancellation invoice', 'Cancellation of the self-billed invoice'],
        'commercial invoice, de' => [InvoiceTypeCode::CommercialInvoice, 'de', 'Stornorechnung', 'Stornierung der Rechnung'],
    ]);

    it('prefers an explicit title', function () use ($policy) {
        $original = (new En16931Calculator)->calculate(Invoices::simple()->type(InvoiceTypeCode::SelfBilledInvoice)->build());

        expect(Cancellation::of($original, 'ST-2', locale: 'de', title: 'Storno', policy: $policy)->build()->presentation?->title)->toBe('Storno');
    });
});
