<?php

declare(strict_types=1);

namespace Quittio\Core\View;

use Psr\Log\LoggerInterface;
use Quittio\Core\Countries\MandatoryField;
use Quittio\Core\Countries\MandatoryFieldList;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Data\Note;
use Quittio\Core\Enums\NoteSubjectCode;

/**
 * Checks after rendering that the template printed all mandatory values (specification 11.8).
 *
 * A custom template could omit mandatory information (e.g. the VAT identifier); then PDF
 * and XML would differ in content. Access keys are view model paths:
 *
 * - "[*]" stands for every index: "vatRows[*].amount" requires the amount of every VAT row;
 * - "&" combines paths that must all be printed, "|" separates alternatives;
 * - a path counts only where the view model has a value: paths of null values and empty
 *   lists are not required, and a field whose alternatives all have no value is skipped.
 *
 * Only printed leaf values count (see {@see ViewNode}).
 */
final class MandatoryFieldGuard
{
    public const string MODE_EXCEPTION = 'exception';

    public const string MODE_LOG = 'log';

    public const string MODE_OFF = 'off';

    /** Note subjects (BT-21) that the view model lists in "legalNotes". */
    public const array LEGAL_NOTE_SUBJECTS = [NoteSubjectCode::RegulatoryInformation, NoteSubjectCode::LegalInformation, NoteSubjectCode::TaxDeclaration];

    public function __construct(
        private readonly string $mode = self::MODE_EXCEPTION,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * Fields every invoice PDF must show, independent of the country.
     */
    public static function baseFields(InvoiceData $invoice): MandatoryFieldList
    {
        $fields = [
            new MandatoryField('number', 'Invoice number (BT-1)'),
            new MandatoryField('issueDate', 'Invoice date (BT-2)'),
            // The full name (BT-27), not only the trading name; the address lines start with it
            // (self-billed: the seller is the recipient).
            new MandatoryField('seller.name|seller.address.lines[*]', 'Seller name (BT-27)'),
            new MandatoryField(self::address('seller'), 'Seller address (BG-5)'),
            new MandatoryField('buyer.name|buyer.address.lines[*]', 'Buyer name (BT-44)'),
            new MandatoryField(self::address('buyer'), 'Buyer address (BG-8)'),
            new MandatoryField('lineGroups[*].lines[*].name|lines[*].name', 'Item name of every line (BT-153)'),
            // Quantity with its unit: "Menge und Art" (section 14 (4) no. 5 UStG).
            new MandatoryField('lineGroups[*].lines[*].quantity&lineGroups[*].lines[*].unit.label|lines[*].quantity&lines[*].unit.label', 'Quantity and unit of every line (BT-129, BT-130)'),
            new MandatoryField('vatRows[*].label|vatRows[*].base&vatRows[*].rate', 'Taxable amount and VAT rate per VAT category and rate (BT-116, BT-119)'),
            new MandatoryField('vatRows[*].amount', 'VAT amount per VAT category and rate (BT-117)'),
            new MandatoryField('totals.amountDue', 'Amount due (BT-115)'),
            // Exemption reasons (BT-120) and regulatory, legal and tax notes (BT-22), each of them.
            new MandatoryField('legalNotes[*]', 'VAT exemption and regulatory notes (BT-120, BT-22)'),
            new MandatoryField('precedingInvoices[*].number', 'Preceding invoice reference (BG-3)'),
            new MandatoryField('taxRepresentative.name|taxRepresentative.address.lines[*]', 'Name of the seller tax representative (BT-62)'),
            new MandatoryField('taxRepresentative.address.lines[*]', 'Address of the seller tax representative (BG-12)'),
            new MandatoryField('taxRepresentative.vatId', 'VAT identifier of the seller tax representative (BT-63)'),
        ];

        // An agreed reduction of the price, e.g. a cash discount, is part of the invoice.
        if (($invoice->paymentTerms->discounts ?? []) !== []) {
            $fields[] = new MandatoryField('paymentTerms.discounts[*].text|paymentTerms.lines[*]', 'Cash discount terms (BT-20)');
        }

        return new MandatoryFieldList($fields);
    }

    /**
     * The address of a party: all address lines, or street, postcode and city.
     */
    public static function address(string $party): string
    {
        return sprintf('%1$s.address.lines[*]|%1$s.address.street&%1$s.address.postcode&%1$s.address.city', $party);
    }

    /**
     * View model path of the first note that matches, wherever the view model lists it:
     * "legalNotes[i]" for regulatory, legal and tax notes, otherwise "notes[i].content|notes[i].html".
     *
     * @param  callable(Note): bool  $matches
     */
    public static function notePath(InvoiceData $invoice, callable $matches): ?string
    {
        foreach ($invoice->notes as $note) {
            if (! $matches($note)) {
                continue;
            }
            if (in_array($note->subjectCode, self::LEGAL_NOTE_SUBJECTS, true)) {
                $index = array_search($note->content, ViewModelFactory::legalNoteTexts($invoice), true);

                return $index === false ? null : 'legalNotes['.$index.']';
            }
            $index = array_search($note, ViewModelFactory::otherNotes($invoice), true);

            return $index === false ? null : sprintf('notes[%1$d].content|notes[%1$d].html', $index);
        }

        return null;
    }

    /**
     * @return list<MandatoryField> fields that were not printed
     */
    public function check(MandatoryFieldList $fields, AccessLog $log): array
    {
        if ($this->mode === self::MODE_OFF) {
            return [];
        }

        $missing = [];
        $paths = [];
        foreach ($fields->fields as $field) {
            $unprinted = self::unprinted($field->accessKey, $log);
            if ($unprinted !== null) {
                $missing[] = $field;
                $paths[] = $unprinted;
            }
        }

        if ($missing === []) {
            return [];
        }

        $message = 'The template does not output mandatory information: '.implode(', ', array_map(
            static fn (MandatoryField $f, array $unprinted): string => $f->description.($f->legalBasis === null ? '' : ' ('.$f->legalBasis.')').($unprinted === [] ? '' : ' ['.implode(', ', array_slice($unprinted, 0, 3)).(count($unprinted) > 3 ? ', …' : '').']'),
            $missing,
            $paths,
        ));

        if ($this->mode === self::MODE_EXCEPTION) {
            throw new MandatoryFieldMissing($message, $missing);
        }

        $this->logger?->warning($message);

        return $missing;
    }

    /**
     * Paths of a field the template did not print (of the alternative closest to complete),
     * or null if one alternative is printed completely or no alternative has a value.
     *
     * @return list<string>|null
     */
    private static function unprinted(string $accessKey, AccessLog $log): ?array
    {
        $closest = null;
        foreach (explode('|', $accessKey) as $alternative) {
            $required = [];
            foreach (explode('&', $alternative) as $part) {
                array_push($required, ...$log->expand($part));
            }
            if ($required === []) {
                continue;
            }

            $unprinted = array_values(array_filter($required, static fn (string $path): bool => ! $log->accessed($path)));
            if ($unprinted === []) {
                return null;
            }
            if ($closest === null || count($unprinted) < count($closest)) {
                $closest = $unprinted;
            }
        }

        return $closest;
    }
}
