<?php

declare(strict_types=1);

namespace Quittio\Core\Documents;

use Quittio\Core\Data\AllowanceCharge\DocumentAllowance;
use Quittio\Core\Data\AllowanceCharge\DocumentCharge;
use Quittio\Core\Data\Concerns\Immutable;
use Quittio\Core\Data\Delivery\Delivery;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Data\Lines\InvoiceLine;
use Quittio\Core\Data\Meta;
use Quittio\Core\Data\Note;
use Quittio\Core\Data\Party\BuyerParty;
use Quittio\Core\Data\Party\SellerParty;
use Quittio\Core\Data\Payment\PaymentInstructions;
use Quittio\Core\Data\Payment\PaymentTerms;
use Quittio\Core\Data\Presentation\Presentation;
use Quittio\Core\Data\Totals\Totals;
use Quittio\Core\Data\Totals\VatBreakdown;
use Quittio\Core\Enums\InvoiceTypeCode;
use Quittio\Core\Exceptions\InvalidArgumentException;
use Quittio\Core\Support\Date;

/**
 * Quote, order confirmation, delivery note, proforma invoice, payment reminder or account
 * statement. Shares parties, lines and calculation with {@see InvoiceData}, but never
 * produces e-invoice XML. Payment reminders and account statements
 * have no lines: their amount comes from {@see Dunning} and {@see AccountStatement}.
 */
final readonly class DocumentData
{
    use Immutable;

    /**
     * @param  list<InvoiceLine>  $lines
     * @param  array<string, DocumentLineOptions>  $lineOptions  keyed by line identifier
     * @param  list<DocumentAllowance>  $allowances
     * @param  list<DocumentCharge>  $charges
     * @param  list<Note>  $notes
     * @param  list<Package>  $packages
     * @param  list<VatBreakdown>  $vatBreakdown
     */
    public function __construct(
        public DocumentType $type,
        public string $number,
        public Date $issueDate,
        public string $currency,
        public SellerParty $seller,
        public BuyerParty $buyer,
        public array $lines = [],
        public array $lineOptions = [],
        public array $allowances = [],
        public array $charges = [],
        public array $notes = [],
        public ?Date $validUntil = null,
        public ?string $reference = null,
        public ?Date $referenceDate = null,
        public ?string $buyerReference = null,
        public ?string $purchaseOrderReference = null,
        public ?Delivery $delivery = null,
        public array $packages = [],
        public ?Dunning $dunning = null,
        public ?AccountStatement $statement = null,
        public ?PaymentTerms $paymentTerms = null,
        public ?PaymentInstructions $paymentInstructions = null,
        public ?Totals $totals = null,
        public array $vatBreakdown = [],
        public ?Presentation $presentation = null,
        public Meta $meta = new Meta,
    ) {
        if ($type === DocumentType::PaymentReminder && $dunning === null) {
            throw new InvalidArgumentException('A payment reminder requires dunning data.');
        }
        if ($type === DocumentType::AccountStatement && $statement === null) {
            throw new InvalidArgumentException('An account statement requires statement data.');
        }
        if (in_array($type, [DocumentType::PaymentReminder, DocumentType::AccountStatement], true) && ($lines !== [] || $allowances !== [] || $charges !== [])) {
            // Their amount is the sum of open items, fees and interest; lines would add VAT
            // to fees and leave the amount to pay ambiguous.
            throw new InvalidArgumentException(sprintf(
                'A %s lists open items and has no lines, allowances or charges. Use openItem(), fee() and interest() instead.',
                str_replace('_', ' ', $type->value),
            ));
        }
        if (($type->hasLineTotals() || $type === DocumentType::DeliveryNote) && $lines === []) {
            throw new InvalidArgumentException(sprintf('A %s requires at least one line.', str_replace('_', ' ', $type->value)));
        }
    }

    public function options(string $lineId): DocumentLineOptions
    {
        return $this->lineOptions[$lineId] ?? new DocumentLineOptions;
    }

    /**
     * Lines that count towards the totals (without optional and alternative lines).
     *
     * @return list<InvoiceLine>
     */
    public function billableLines(): array
    {
        return array_values(array_filter($this->lines, fn (InvoiceLine $line): bool => $this->options($line->id)->isBillable()));
    }

    public function isCalculated(): bool
    {
        return $this->totals !== null || $this->billableLines() === [];
    }

    /**
     * The document as invoice data, e.g. for calculation or rendering. Never use it as an invoice.
     */
    public function toInvoiceDraft(bool $billableOnly = true): InvoiceData
    {
        return new InvoiceData(
            number: $this->number,
            issueDate: $this->issueDate,
            typeCode: InvoiceTypeCode::CommercialInvoice,
            currency: $this->currency,
            seller: $this->seller,
            buyer: $this->buyer,
            lines: $billableOnly ? $this->billableLines() : $this->lines,
            buyerReference: $this->buyerReference,
            purchaseOrderReference: $this->purchaseOrderReference,
            paymentTerms: $this->paymentTerms,
            notes: $this->notes,
            delivery: $this->delivery,
            paymentInstructions: $this->paymentInstructions,
            allowances: $this->allowances,
            charges: $this->charges,
            totals: $this->totals,
            vatBreakdown: $this->vatBreakdown,
            presentation: $this->presentation,
            meta: $this->meta,
        );
    }
}
