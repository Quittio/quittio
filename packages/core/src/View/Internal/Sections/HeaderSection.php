<?php

declare(strict_types=1);

namespace Quittio\Core\View\Internal\Sections;

use Quittio\Core\Data\Delivery\Period;
use Quittio\Core\Data\Lines\InvoiceLine;
use Quittio\Core\Enums\InvoiceTypeCode;
use Quittio\Core\Support\Date;
use Quittio\Core\View\FormattedValue;
use Quittio\Core\View\Internal\RunScope;
use Quittio\Core\View\InvoiceTitle;

/**
 * Number, title, dates, service period, currency, the "document" node and "meta".
 *
 * @internal
 */
final readonly class HeaderSection
{
    public function __construct(private RunScope $scope) {}

    /**
     * @return array<string, mixed> number … document
     */
    public function build(): array
    {
        $invoice = $this->scope->invoice;
        $labels = $this->scope->baseLabels;
        $typeCode = $invoice->typeCodeValue();

        return [
            'number' => $invoice->number,
            'numberLabel' => $labels['invoice_number'],
            'dateLabel' => $labels['invoice_date'],
            'title' => $this->scope->presentation->title ?? InvoiceTitle::policyTitle($invoice, $this->scope->context->country, $this->scope->locale) ?? $labels[InvoiceTitle::key($invoice)] ?? $labels['title_default'],
            'issueDate' => $this->scope->date($invoice->issueDate),
            'dueDate' => $this->scope->date($invoice->dueDate),
            'servicePeriod' => $this->servicePeriod(),
            // A single date (delivery date or one-day period) is a "date of supply", not a period.
            'servicePeriodLabel' => $this->servicePeriodIsDate() ? $labels['delivery_date'] : $labels['service_period'],
            // BT-72 in addition to an invoicing period (otherwise servicePeriod already shows it).
            'deliveryDate' => $invoice->invoicingPeriod === null ? null : $this->scope->date($invoice->delivery?->actualDeliveryDate),
            'currency' => $invoice->currency,
            'document' => [
                'typeCode' => $typeCode,
                'type' => $invoice->typeCode instanceof InvoiceTypeCode ? $invoice->typeCode->label($this->scope->locale) : $typeCode,
                'isCreditNote' => $invoice->isCreditNote(),
                'isSelfBilled' => $this->scope->selfBilled,
                'profile' => $this->scope->context->profile?->name(),
                'profileKey' => $this->scope->context->profile?->key(),
                'kind' => 'invoice',
                'isInvoice' => true,
                'showPrices' => true,
                'showTotals' => true,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function meta(): array
    {
        return [
            'locale' => $this->scope->locale,
            'custom' => $this->scope->invoice->meta->custom,
            'source' => $this->scope->invoice->meta->source,
        ];
    }

    /**
     * Whether the service period is a single date (delivery date or a one-day period).
     */
    private function servicePeriodIsDate(): bool
    {
        $period = $this->scope->invoice->invoicingPeriod;
        if ($period !== null) {
            return $period->start === null || $period->end === null || $period->start->compareTo($period->end) === 0;
        }

        return $this->scope->invoice->delivery?->actualDeliveryDate !== null;
    }

    /**
     * Delivery date or invoicing period, formatted.
     */
    private function servicePeriod(): ?FormattedValue
    {
        $invoice = $this->scope->invoice;
        $period = $invoice->invoicingPeriod;
        if ($period !== null) {
            return $this->scope->period($period);
        }

        $date = $invoice->delivery?->actualDeliveryDate;
        if ($date !== null) {
            return $this->scope->date($date);
        }

        $periods = array_values(array_filter(array_map(static fn (InvoiceLine $l): ?Period => $l->period, $invoice->lines)));
        if ($periods !== [] && count($periods) === count($invoice->lines)) {
            $starts = array_values(array_filter(array_map(static fn (Period $p): ?Date => $p->start, $periods)));
            $ends = array_values(array_filter(array_map(static fn (Period $p): ?Date => $p->end, $periods)));
            usort($starts, static fn (Date $a, Date $b): int => $a->compareTo($b));
            usort($ends, static fn (Date $a, Date $b): int => $b->compareTo($a));

            return $this->scope->period(new Period($starts[0] ?? null, $ends[0] ?? null));
        }

        return null;
    }
}
