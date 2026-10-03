<?php

declare(strict_types=1);

namespace Quittio\Core\View;

use Psr\Log\LoggerInterface;
use Quittio\Core\Countries\MandatoryField;
use Quittio\Core\Countries\MandatoryFieldList;
use Quittio\Core\Data\InvoiceData;

/**
 * Checks after rendering that the template printed all mandatory values (specification 11.8).
 *
 * A custom template could omit mandatory information (e.g. the VAT identifier); then PDF
 * and XML would differ in content. Access keys may list alternatives with "|".
 */
final class MandatoryFieldGuard
{
    public const string MODE_EXCEPTION = 'exception';

    public const string MODE_LOG = 'log';

    public const string MODE_OFF = 'off';

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
            new MandatoryField('seller.name|seller.displayName', 'Seller name (BT-27)'),
            new MandatoryField('seller.address|design.senderLine|design.footerColumns', 'Seller address (BG-5)'),
            new MandatoryField('buyer.name|buyer.address', 'Buyer name and address (BT-44, BG-8)'),
            new MandatoryField('lines|lineGroups', 'Invoice lines (BG-25)'),
            new MandatoryField('vatRows', 'VAT breakdown (BG-23)'),
            new MandatoryField('totals.amountDue', 'Amount due (BT-115)'),
        ];

        $hasLegalNotes = false;
        foreach ($invoice->vatBreakdown as $row) {
            $hasLegalNotes = $hasLegalNotes || $row->exemptionReason !== null;
        }
        if ($hasLegalNotes) {
            $fields[] = new MandatoryField('legalNotes', 'VAT exemption notes (BT-120)');
        }
        if ($invoice->precedingInvoices !== []) {
            $fields[] = new MandatoryField('precedingInvoices', 'Preceding invoice reference (BG-3)');
        }

        return new MandatoryFieldList($fields);
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
        foreach ($fields->fields as $field) {
            $found = false;
            foreach (explode('|', $field->accessKey) as $alternative) {
                $found = $found || $log->accessed($alternative);
            }
            if (! $found) {
                $missing[] = $field;
            }
        }

        if ($missing === []) {
            return [];
        }

        $message = 'The template does not output mandatory information: '.implode(', ', array_map(
            static fn (MandatoryField $f): string => $f->description.($f->legalBasis === null ? '' : ' ('.$f->legalBasis.')'),
            $missing,
        ));

        if ($this->mode === self::MODE_EXCEPTION) {
            throw new MandatoryFieldMissing($message, $missing);
        }

        $this->logger?->warning($message);

        return $missing;
    }
}
