<?php

declare(strict_types=1);

namespace Quittio\Core\Builder\Concerns;

use DateTimeInterface;
use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Codes\RawCode;
use Quittio\Core\Data\Identifier;
use Quittio\Core\Data\Note;
use Quittio\Core\Data\References\PrecedingInvoiceReference;
use Quittio\Core\Data\References\ProjectReference;
use Quittio\Core\Support\Date;

/**
 * Document references (BT-10 to BT-19, BG-3) and the invoiced object.
 *
 * Part of {@see InvoiceBuilder}, which holds the state; use the builder, not the trait.
 */
trait BuildsReferences
{
    /**
     * Buyer reference (BT-10). For public sector buyers in Germany this is the Leitweg-ID.
     */
    public function buyerReference(?string $reference): static
    {
        return $this->reference('buyerReference', $reference);
    }

    /**
     * Project reference (BT-11) with the project name, which CII requires in addition to the
     * identifier.
     */
    public function projectReference(string $id, ?string $name = null): static
    {
        $this->projectReference = new ProjectReference($id, $name);

        return $this;
    }

    /**
     * Contract reference (BT-12); null removes it.
     */
    public function contractReference(?string $reference): static
    {
        return $this->reference('contractReference', $reference);
    }

    /**
     * The buyer's purchase order reference (BT-13); null removes it.
     */
    public function purchaseOrderReference(?string $reference): static
    {
        return $this->reference('purchaseOrderReference', $reference);
    }

    /**
     * The seller's sales order reference (BT-14); null removes it.
     */
    public function salesOrderReference(?string $reference): static
    {
        return $this->reference('salesOrderReference', $reference);
    }

    /**
     * Receiving advice reference (BT-15); null removes it.
     */
    public function receivingAdviceReference(?string $reference): static
    {
        return $this->reference('receivingAdviceReference', $reference);
    }

    /**
     * Despatch advice reference (BT-16); null removes it.
     */
    public function despatchAdviceReference(?string $reference): static
    {
        return $this->reference('despatchAdviceReference', $reference);
    }

    /**
     * Tender or lot reference (BT-17); null removes it.
     */
    public function tenderReference(?string $reference): static
    {
        return $this->reference('tenderReference', $reference);
    }

    /**
     * The buyer's accounting reference (BT-19), e.g. a cost centre; null removes it.
     */
    public function buyerAccountingReference(?string $reference): static
    {
        return $this->reference('buyerAccountingReference', $reference);
    }

    /**
     * Identifier of the invoiced object (BT-18), e.g. a meter or subscription number, with
     * its scheme (BT-18-1).
     */
    public function invoicedObject(string $value, string|RawCode|null $scheme = null): static
    {
        $this->invoicedObject = Identifier::of($value, $scheme);

        return $this;
    }

    /**
     * Adds a reference to a preceding invoice (BG-3): its number (BT-25) and issue date
     * (BT-26), e.g. for a corrected invoice or a credit note.
     */
    public function precedingInvoice(string $number, Date|DateTimeInterface|string|null $issueDate = null): static
    {
        $this->precedingInvoices[] = new PrecedingInvoiceReference($number, $issueDate === null ? null : Date::parse($issueDate));

        return $this;
    }

    private function reference(string $key, ?string $value): static
    {
        $this->references[$key] = $value;

        return $this;
    }
}
