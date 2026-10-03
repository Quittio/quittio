<?php

declare(strict_types=1);

namespace Quittio\Core\Corrections;

use Brick\Money\Money;
use InvalidArgumentException;
use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Countries\CorrectionPolicy;
use Quittio\Core\Data\AllowanceCharge\DocumentAllowanceCharge;
use Quittio\Core\Data\AllowanceCharge\LineAllowanceCharge;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Data\Lines\InvoiceLine;
use Quittio\Core\Data\Note;
use Quittio\Core\Data\Payment\PaymentTerms;
use Quittio\Core\Data\Presentation\Presentation;
use Quittio\Core\Data\References\PrecedingInvoiceReference;
use Quittio\Core\Enums\InvoiceTypeCode;
use Quittio\Core\Enums\NoteSubjectCode;
use Quittio\Core\I18n\Translations;
use Quittio\Core\Support\Date;
use Quittio\Core\View\IntlFormatter;

/**
 * Full cancellation of an issued invoice (specification 17.1–17.3): a new invoice with its
 * own number that reverses the original and refers to it (BG-3).
 *
 * Sign strategies (17.2):
 * - credit_note:      type code 381, amounts positive; UBL writes a CreditNote document,
 * - negative_invoice: type code 380, quantities and allowance/charge amounts negated.
 *
 * Both are permitted in Germany; the document must not be called "Gutschrift", which
 * is reserved for self-billing. The original data is never changed.
 */
final class Cancellation
{
    public const array STRATEGIES = [CorrectionPolicy::CANCELLATION_CREDIT_NOTE, CorrectionPolicy::CANCELLATION_NEGATIVE_INVOICE];

    /**
     * @param  string|null  $title  document title in the PDF, e.g. "Stornorechnung"
     */
    public static function of(
        InvoiceData $original,
        string $number,
        string $strategy = CorrectionPolicy::CANCELLATION_CREDIT_NOTE,
        ?string $reason = null,
        ?Date $issueDate = null,
        ?string $locale = null,
        ?string $title = null,
    ): InvoiceBuilder {
        if (! in_array($strategy, self::STRATEGIES, true)) {
            throw new InvalidArgumentException(sprintf('Unknown cancellation strategy "%s". Use %s.', $strategy, implode(' or ', self::STRATEGIES)));
        }

        $locale ??= $original->meta->locale ?? 'en';
        $negative = $strategy === CorrectionPolicy::CANCELLATION_NEGATIVE_INVOICE;
        $translator = Translations::translator();
        $note = $translator->get('quittio::labels.cancellation_note', [
            'number' => $original->number,
            'date' => (new IntlFormatter)->date($original->issueDate, $locale),
        ], $locale);
        if ($reason !== null && $reason !== '') {
            $note .= ' '.$translator->get('quittio::labels.cancellation_reason', ['reason' => $reason], $locale);
        }

        $presentation = $title === null ? $original->presentation : ($original->presentation ?? new Presentation)->with(['title' => $title]);

        $cancellation = $original->with([
            'number' => $number,
            'issueDate' => $issueDate ?? Date::today($original->meta->timezone),
            'typeCode' => $negative ? InvoiceTypeCode::CommercialInvoice : InvoiceTypeCode::CreditNote,
            'precedingInvoices' => [new PrecedingInvoiceReference($original->number, $original->issueDate)],
            'notes' => [new Note($note, NoteSubjectCode::GeneralInformation)],
            'dueDate' => null,
            'paymentTerms' => new PaymentTerms(text: $translator->get('quittio::labels.cancellation_terms', [], $locale)),
            'lines' => array_map(static fn (InvoiceLine $line): InvoiceLine => self::line($line, $negative), $original->lines),
            'allowances' => $negative ? array_map(self::documentAllowanceCharge(...), $original->allowances) : $original->allowances,
            'charges' => $negative ? array_map(self::documentAllowanceCharge(...), $original->charges) : $original->charges,
            'prepaidAmount' => null,
            'roundingAmount' => null,
            'totals' => null,
            'vatBreakdown' => [],
            'presentation' => $presentation,
        ]);

        return InvoiceBuilder::from($cancellation);
    }

    private static function line(InvoiceLine $line, bool $negative): InvoiceLine
    {
        if (! $negative) {
            return $line->with(['netAmount' => null, 'subLines' => array_map(static fn (InvoiceLine $sub): InvoiceLine => self::line($sub, false), $line->subLines)]);
        }

        return $line->with([
            'quantity' => $line->quantity->negated(),
            'netAmount' => null,
            'allowances' => array_map(self::lineAllowanceCharge(...), $line->allowances),
            'charges' => array_map(self::lineAllowanceCharge(...), $line->charges),
            'subLines' => array_map(static fn (InvoiceLine $sub): InvoiceLine => self::line($sub, true), $line->subLines),
        ]);
    }

    /**
     * @template T of LineAllowanceCharge
     *
     * @param  T  $entry
     * @return T
     */
    private static function lineAllowanceCharge(LineAllowanceCharge $entry): LineAllowanceCharge
    {
        return $entry->with(['amount' => self::negate($entry->amount), 'baseAmount' => self::negate($entry->baseAmount)]);
    }

    /**
     * @template T of DocumentAllowanceCharge
     *
     * @param  T  $entry
     * @return T
     */
    private static function documentAllowanceCharge(DocumentAllowanceCharge $entry): DocumentAllowanceCharge
    {
        return $entry->with(['amount' => self::negate($entry->amount), 'baseAmount' => self::negate($entry->baseAmount)]);
    }

    private static function negate(?Money $money): ?Money
    {
        return $money?->negated();
    }
}
