<?php

declare(strict_types=1);

namespace Quittio\Core\View;

use Brick\Math\BigDecimal;
use Quittio\Core\Calculation\CalculationException;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Data\Lines\InvoiceLine;
use Quittio\Core\Data\Note;
use Quittio\Core\Data\Presentation\Presentation;
use Quittio\Core\Documents\DocumentData;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\View\Internal\ViewModelRun;
use ReflectionClass;

/**
 * Builds the template view model from calculated invoice data.
 */
final class ViewModelFactory
{
    /** Top page margin of the first page of the default templates in millimetres (the letterhead starts here). */
    public const float PAGE_MARGIN_TOP = 15.0;

    /** Top page margin of the following pages, below their header (logo, title, number and buyer). */
    public const float CONTINUATION_MARGIN_TOP = 40.0;

    public const array PAYMENT_QR_MODES = [Presentation::PAYMENT_QR_AUTO, Presentation::PAYMENT_QR_GIROCODE, Presentation::PAYMENT_QR_SWISS, Presentation::PAYMENT_QR_NONE];

    /** Margin schemes and travel agents (section 25, 25a UStG): no separate VAT statement. */
    public const array SPECIAL_SCHEME_CODES = ['VATEX-EU-D', 'VATEX-EU-F', 'VATEX-EU-I', 'VATEX-EU-J'];

    /**
     * Whether a line is taxed under a margin scheme or the special scheme for travel agents
     * (section 25, 25a UStG): category E with one of SPECIAL_SCHEME_CODES on the line.
     */
    public static function isSpecialSchemeLine(InvoiceLine $line): bool
    {
        return $line->vat->category === VatCategory::Exempt
            && in_array(strtoupper((string) $line->vat->exemptionReasonCode?->value), self::SPECIAL_SCHEME_CODES, true);
    }

    /**
     * The exempt VAT group (category E) when it mixes lines of a special scheme with other
     * exempt lines: EN 16931 allows only one E group (BR-E-01), whose code then conflicts. The
     * PDF shows the two parts in rows of their own when the line net amounts add up to the
     * taxable amount of the group and no document level allowance or charge is exempt: then
     * ['special' => net amount, 'other' => net amount]; ['special' => null, 'other' => null]
     * when they cannot be told apart; null when the group does not mix.
     *
     * @return array{special: ?BigDecimal, other: ?BigDecimal}|null
     */
    public static function mixedExemptGroup(InvoiceData $invoice): ?array
    {
        $special = BigDecimal::zero();
        $other = BigDecimal::zero();
        $hasSpecial = false;
        $hasOther = false;
        $known = true;
        foreach ($invoice->lines as $line) {
            if ($line->vat->category !== VatCategory::Exempt) {
                continue;
            }
            $net = $line->netAmount?->getAmount();
            $known = $known && $net !== null;
            if (self::isSpecialSchemeLine($line)) {
                $hasSpecial = true;
                $special = $special->plus($net ?? BigDecimal::zero());
            } else {
                $hasOther = true;
                $other = $other->plus($net ?? BigDecimal::zero());
            }
        }
        if (! $hasSpecial || ! $hasOther) {
            return null;
        }
        foreach ([...$invoice->allowances, ...$invoice->charges] as $entry) {
            $known = $known && $entry->vatCategory !== VatCategory::Exempt;
        }
        $group = null;
        foreach ($invoice->vatBreakdown as $row) {
            $group = $row->category === VatCategory::Exempt ? $row : $group;
        }
        $known = $known && $group !== null && $group->taxableAmount->getAmount()->isEqualTo($special->plus($other));

        return $known ? ['special' => $special, 'other' => $other] : ['special' => null, 'other' => null];
    }

    /**
     * The index of the row of the view model's "vatRows" that shows each VAT breakdown row
     * (BG-23), by the index of the breakdown: a mixed exempt group (mixedExemptGroup()) takes two
     * rows, its first one is given.
     *
     * @return array<int, int>
     */
    public static function vatRowIndexes(InvoiceData $invoice): array
    {
        $split = self::mixedExemptGroup($invoice)['special'] ?? null;
        $indexes = [];
        $next = 0;
        foreach ($invoice->vatBreakdown as $index => $row) {
            $indexes[$index] = $next;
            $next += $split !== null && $row->category === VatCategory::Exempt ? 2 : 1;
        }

        return $indexes;
    }

    /**
     * The view model of an invoice.
     *
     * @throws CalculationException when the invoice is not calculated
     */
    public function build(InvoiceData $invoice, ViewContext $context = new ViewContext): InvoiceViewModel
    {
        return (new ViewModelRun)->build($invoice, $context);
    }

    /**
     * View model for quotes, delivery notes, reminders and other documents.
     * Templates see the same structure as for invoices plus the "documentInfo" node.
     *
     * @throws CalculationException when the document is not calculated
     */
    public function buildDocument(DocumentData $document, ViewContext $context = new ViewContext): InvoiceViewModel
    {
        return (new ViewModelRun)->buildDocument($document, $context);
    }

    /**
     * $own over $defaults, property by property: values of $own that equal the defaults of
     * an empty Presentation count as not set and keep the value of $defaults, unless $own
     * names the property in $explicit (e.g. false, [] or "A4" over a configured value).
     * Labels are merged key by key.
     */
    public static function mergePresentation(Presentation $own, Presentation $defaults): Presentation
    {
        $empty = new Presentation;
        $merged = [];
        foreach ((new ReflectionClass(Presentation::class))->getProperties() as $property) {
            $name = $property->getName();
            $value = $property->getValue($own);
            $merged[$name] = match (true) {
                $name === 'explicit' => array_values(array_unique([...$defaults->explicit, ...$own->explicit])),
                $own->isExplicit($name) => $value,
                $name === 'labels' => [...$defaults->labels, ...$own->labels],
                $value === $property->getValue($empty) => $property->getValue($defaults),
                default => $value,
            };
        }

        return $empty->with($merged);
    }

    /**
     * The "legalNotes" of the view model: exemption reasons (BT-120) and regulatory, legal
     * and tax notes (BT-22), in this order and without duplicates.
     *
     * @return list<string>
     */
    public static function legalNoteTexts(InvoiceData $invoice): array
    {
        $notes = [];
        foreach ($invoice->vatBreakdown as $row) {
            if ($row->exemptionReason !== null && $row->category !== VatCategory::StandardRate) {
                $notes[] = $row->exemptionReason;
            }
        }
        foreach ($invoice->notes as $note) {
            if (in_array($note->subjectCode, MandatoryFieldGuard::LEGAL_NOTE_SUBJECTS, true)) {
                $notes[] = $note->content;
            }
        }

        return array_values(array_unique($notes));
    }

    /**
     * The notes outside "legalNotes" in the order of the view model's "notes".
     *
     * @return list<Note>
     */
    public static function otherNotes(InvoiceData $invoice): array
    {
        return array_values(array_filter($invoice->notes, static fn (Note $note): bool => ! in_array($note->subjectCode, MandatoryFieldGuard::LEGAL_NOTE_SUBJECTS, true)));
    }

    /**
     * Remittance information (BT-83), otherwise the invoice number.
     */
    public static function remittance(InvoiceData $invoice): string
    {
        $remittance = $invoice->paymentInstructions?->remittanceInformation;

        return $remittance === null || trim($remittance) === '' ? $invoice->number : $remittance;
    }
}
