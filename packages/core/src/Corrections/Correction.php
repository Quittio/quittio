<?php

declare(strict_types=1);

namespace Quittio\Core\Corrections;

use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Calculation\DeclaredAmounts;
use Quittio\Core\Calculation\DeclaredAmountsState;
use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Calculation\TotalsCalculator;
use Quittio\Core\Countries\CorrectionPolicy;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Data\Note;
use Quittio\Core\Data\Presentation\Presentation;
use Quittio\Core\Data\References\PrecedingInvoiceReference;
use Quittio\Core\Enums\InvoiceTypeCode;
use Quittio\Core\Enums\NoteSubjectCode;
use Quittio\Core\Exceptions\InvalidArgumentException;
use Quittio\Core\I18n\Translations;
use Quittio\Core\Support\Date;
use Quittio\Core\View\IntlFormatter;

/**
 * Corrected invoice (type code 384) that replaces an issued invoice: a copy of the original with
 * its own number, the reference to the original (BG-3) and the note "Diese Rechnung berichtigt
 * und ersetzt die Rechnung … vom …" (BT-22), so that it states by a specific and unambiguous
 * reference that it corrects the original (section 31 (5) UStDV, section 15.2a (7) UStAE).
 * Change what was wrong on the returned builder.
 *
 * Cancelling the original and issuing a new invoice (Cancellation) is the safer default: a
 * receiving system that does not read 384 as replacement books both invoices. Credit notes and
 * self-billed documents are corrected that way only.
 */
final class Correction
{
    /**
     * @param  string|null  $title  document title in the PDF, e.g. "Rechnungskorrektur"; without it the
     *         title "correction" of $policy
     *
     * @throws InvalidArgumentException for a credit note or a self-billed document
     */
    public static function of(
        InvoiceData $original,
        string $number,
        ?string $reason = null,
        ?Date $issueDate = null,
        ?string $locale = null,
        ?string $title = null,
        ?CorrectionPolicy $policy = null,
        TotalsCalculator $calculator = new En16931Calculator,
    ): InvoiceBuilder {
        if ($original->isCreditNote() || ($original->typeCode instanceof InvoiceTypeCode && $original->typeCode->isSelfBilled())) {
            throw new InvalidArgumentException(sprintf('The document %s (type code %s) cannot be replaced by a corrected invoice (384): cancel it and issue a new one.', $original->number, $original->typeCodeValue()));
        }

        $locale ??= $original->meta->locale ?? 'en';
        $title ??= $policy?->title('correction', $locale);
        $note = self::note($original->number, $original->issueDate, $locale);
        $reasonNote = $reason !== null && $reason !== '' ? ' '.Translations::translator()->get('quittio::labels.cancellation_reason', ['reason' => $reason], $locale) : '';

        // A correction of a corrected invoice replaces its note on the invoice it corrected.
        $replaced = array_map(static fn (PrecedingInvoiceReference $reference): string => self::note($reference->number, $reference->issueDate, $locale), $original->precedingInvoices);
        $notes = [new Note($note.$reasonNote, NoteSubjectCode::GeneralInformation)];
        foreach ($original->notes as $existing) {
            if (! in_array(self::withoutReason($existing->content, $replaced), $replaced, true)) {
                $notes[] = $existing;
            }
        }

        $correction = $original->with([
            'number' => $number,
            'issueDate' => $issueDate ?? Date::today($original->meta->timezone),
            'typeCode' => InvoiceTypeCode::CorrectedInvoice,
            'precedingInvoices' => [new PrecedingInvoiceReference($original->number, $original->issueDate)],
            'notes' => $notes,
            'presentation' => $title === null ? $original->presentation : ($original->presentation ?? new Presentation)->with(['title' => $title]),
            // Like a full cancellation: declared amounts of the original stay declared unless stale.
            'meta' => DeclaredAmountsState::none()->withDeclared(DeclaredAmounts::isStale($original))->applyTo($original->meta),
        ]);

        return InvoiceBuilder::fromUsing($correction, $calculator);
    }

    /**
     * The note of a corrected invoice in the locale (label "correction_note").
     */
    public static function note(string $number, ?Date $issueDate, string $locale): string
    {
        return Translations::translator()->get('quittio::labels.correction_note', [
            'number' => $number,
            'date' => $issueDate === null ? '' : (new IntlFormatter)->date($issueDate, $locale),
        ], $locale);
    }

    /**
     * The note without a reason after it, if it starts with one of $notes.
     *
     * @param  list<string>  $notes
     */
    private static function withoutReason(string $content, array $notes): string
    {
        foreach ($notes as $note) {
            if (str_starts_with($content, $note)) {
                return $note;
            }
        }

        return $content;
    }
}
