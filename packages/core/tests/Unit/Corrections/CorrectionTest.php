<?php

declare(strict_types=1);

use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Corrections\Correction;
use Quittio\Core\Countries\CorrectionPolicy;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Enums\InvoiceTypeCode;
use Quittio\Core\Enums\NoteSubjectCode;
use Quittio\Core\Enums\UnitCode;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Exceptions\InvalidArgumentException;
use Quittio\Core\Support\Date;
use Quittio\Core\Testing\Scenarios;
use Quittio\Core\Tests\Support\Validate;

function correctionOriginal(): InvoiceData
{
    return (new En16931Calculator)->calculate(Scenarios::make('simple')->note('Steuerhinweis', NoteSubjectCode::RegulatoryInformation)->build());
}

it('replaces an invoice with a corrected invoice that says so (section 31 (5) UStDV)', function () {
    $original = correctionOriginal();
    $policy = new CorrectionPolicy(titles: ['correction' => ['de' => 'Rechnungskorrektur']]);
    $builder = Correction::of($original, 'RE-2026-0050', reason: 'Falscher Stundensatz', issueDate: Date::of(2026, 10, 10), locale: 'de', policy: $policy)
        ->removeLine('1')
        ->line(fn ($l) => $l->name('Webentwicklung')->quantity(24, UnitCode::Hour)->netPrice('90.00')->vat(VatCategory::StandardRate, '19'));
    $correction = (new En16931Calculator)->calculate($builder->build());

    expect($correction->typeCode)->toBe(InvoiceTypeCode::CorrectedInvoice)
        ->and($correction->number)->toBe('RE-2026-0050')
        ->and($correction->issueDate->toIsoString())->toBe('2026-10-10')
        ->and($correction->precedingInvoices[0]->number)->toBe('RE-2026-0042')
        ->and($correction->precedingInvoices[0]->issueDate?->toIsoString())->toBe('2026-10-03')
        ->and($correction->notes[0]->content)->toBe('Diese Rechnung berichtigt und ersetzt die Rechnung RE-2026-0042 vom 03.10.2026. Grund: Falscher Stundensatz')
        ->and($correction->notes[0]->subjectCode)->toBe(NoteSubjectCode::GeneralInformation)
        ->and($correction->notes[1]->content)->toBe('Steuerhinweis')
        ->and($correction->presentation?->title)->toBe('Rechnungskorrektur')
        ->and($correction->totals?->netTotal->getAmount()->toString())->toBe('2189.00')
        ->and(Validate::data($correction, calculate: false)->errors())->toBe([]);
});

it('replaces the note of a corrected invoice that is corrected again', function () {
    $first = (new En16931Calculator)->calculate(Correction::of(correctionOriginal(), 'RE-2', issueDate: Date::of(2026, 10, 10), locale: 'en')->build());
    $second = Correction::of($first, 'RE-3', reason: 'Again', locale: 'en')->build();

    expect(array_map(static fn ($note): string => $note->content, $second->notes))->toBe([
        'This invoice corrects and replaces invoice RE-2 of 10/10/2026. Reason: Again',
        'Steuerhinweis',
    ])->and($second->precedingInvoices)->toHaveCount(1);
});

it('corrects neither credit notes nor self-billed invoices', function (string $type) {
    $original = (new En16931Calculator)->calculate(Scenarios::make('simple')->type($type)->build());

    expect(fn () => Correction::of($original, 'RE-9'))->toThrow(InvalidArgumentException::class, 'cancel it and issue a new one');
})->with(['381', '389', '261']);

it('keeps the builder of the correction usable like from()', function () {
    expect(Correction::of(correctionOriginal(), 'RE-2'))->toBeInstanceOf(InvoiceBuilder::class);
});
