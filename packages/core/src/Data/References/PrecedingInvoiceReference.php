<?php

declare(strict_types=1);

namespace Quittio\Core\Data\References;

use Quittio\Core\Data\Concerns\Immutable;
use Quittio\Core\Mapping\Attributes as Q;
use Quittio\Core\Profiles\ProfileLevel;
use Quittio\Core\Support\Date;

/**
 * Preceding invoice reference (BG-3), used for cancellations, corrections and final invoices.
 */
final readonly class PrecedingInvoiceReference
{
    use Immutable;

    public function __construct(
        #[Q\Bt('BT-25'), Q\Cardinality('1'), Q\Level(ProfileLevel::BasicWl), Q\Cii('ram:InvoiceReferencedDocument/ram:IssuerAssignedID'), Q\Ubl('cac:BillingReference/cac:InvoiceDocumentReference/cbc:ID')]
        public string $number,
        #[Q\Bt('BT-26'), Q\Cardinality('0..1'), Q\Level(ProfileLevel::BasicWl), Q\Cii('ram:InvoiceReferencedDocument/ram:FormattedIssueDateTime'), Q\Ubl('cac:BillingReference/cac:InvoiceDocumentReference/cbc:IssueDate')]
        public ?Date $issueDate = null,
    ) {}
}
