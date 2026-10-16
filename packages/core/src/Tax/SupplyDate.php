<?php

declare(strict_types=1);

namespace Quittio\Core\Tax;

use Quittio\Core\Data\Delivery\Period;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Data\Lines\InvoiceLine;
use Quittio\Core\Support\Date;

/**
 * The date that decides the VAT rate of a line: the end of the line period (BT-135), its start
 * (BT-134), the VAT point date (BT-7), the actual delivery date (BT-72), the end of the invoicing
 * period (BT-74) and finally the issue date (BT-2), in this order.
 */
final class SupplyDate
{
    /**
     * The first date that is set, in the order above; null if none is.
     */
    public static function resolve(?Period $linePeriod, ?Date $taxPointDate, ?Date $deliveryDate, ?Period $invoicingPeriod, ?Date $issueDate): ?Date
    {
        return $linePeriod->end ?? $linePeriod->start ?? $taxPointDate ?? $deliveryDate ?? $invoicingPeriod->end ?? $issueDate;
    }

    /**
     * The supply date of a line of the invoice.
     */
    public static function ofLine(InvoiceData $invoice, InvoiceLine $line): Date
    {
        return self::resolve($line->period, $invoice->taxPointDate, $invoice->delivery?->actualDeliveryDate, $invoice->invoicingPeriod, $invoice->issueDate) ?? $invoice->issueDate;
    }
}
