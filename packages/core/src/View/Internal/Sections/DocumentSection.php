<?php

declare(strict_types=1);

namespace Quittio\Core\View\Internal\Sections;

use Quittio\Core\Documents\DocumentData;
use Quittio\Core\Documents\DocumentType;
use Quittio\Core\Documents\DunningFee;
use Quittio\Core\Documents\InterestCharge;
use Quittio\Core\Documents\OpenItem;
use Quittio\Core\Documents\Package;
use Quittio\Core\View\FormattedValue;
use Quittio\Core\View\Internal\RunScope;

/**
 * Quotes, delivery notes, reminders and other documents: the "documentInfo" node and what
 * differs from the view model of an invoice.
 *
 * @internal
 */
final readonly class DocumentSection
{
    public function __construct(
        private RunScope $scope,
        private DocumentData $document,
        private PaymentSection $payment,
    ) {}

    /**
     * The view model of the internal invoice draft, changed for the document.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function apply(array $data): array
    {
        $document = $this->document;
        $labels = $this->scope->labels;

        $type = $document->type;
        $typeLabel = $labels[$type->labelKey()] ?? $type->value;
        $title = $typeLabel;
        if ($document->dunning !== null && $document->dunning->level >= 1) {
            $title = str_replace(':level', (string) $document->dunning->level, $labels['document_payment_reminder_level'] ?? $title);
        }

        $data['title'] = $this->scope->presentation->title ?? $title;
        $data['numberLabel'] = $labels['document_number'] ?? 'Number';
        $data['dateLabel'] = $labels['document_date'] ?? 'Date';
        $data['document'] = [
            ...(array) $data['document'],
            // A document is no invoice: no type code (BT-3) of the internal draft, the name
            // of the document type instead of "Commercial invoice".
            'typeCode' => null,
            'type' => $typeLabel,
            'kind' => $type->value,
            'isInvoice' => false,
            'showPrices' => $type->showsPrices(),
            'showTotals' => $type->hasLineTotals() && $document->totals !== null,
        ];
        $data['documentInfo'] = $this->documentInfo();
        if ($document->delivery?->actualDeliveryDate !== null) {
            // The delivery date is shown as such; a derived service period would repeat it.
            $data['servicePeriod'] = null;
            $data['deliveryDate'] = null;
        }
        if ($type === DocumentType::Proforma) {
            $data['legalNotes'] = [...(array) $data['legalNotes'], $labels['proforma_notice'] ?? ''];
        }
        if (in_array($type, [DocumentType::Quote, DocumentType::OrderConfirmation], true)) {
            // Offers are no request to pay: no bank details, no payment QR code, no amount due.
            $data['payment'] = null;
            if (is_array($data['totals'])) {
                $data['totals']['amountDue'] = null;
            }
        }
        if (! $type->hasLineTotals()) {
            $data['payment'] = in_array($type, [DocumentType::PaymentReminder, DocumentType::AccountStatement], true) ? $data['payment'] : null;
            $data['paymentTerms'] = null;
        }
        if ($document->dunning !== null && is_array($data['payment'])) {
            // A reminder asks for the open items plus fees and interest, referencing the open invoices.
            $remittance = $document->dunning->remittanceText();
            $remittance = $remittance === '' ? $document->number : $remittance;
            $data['payment']['remittance'] = $remittance;
            $data['payment']['qrCode'] = $this->payment->qrCode($document->dunning->total($document->currency)->getAmount(), $remittance);
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function documentInfo(): array
    {
        $scope = $this->scope;
        $document = $this->document;
        $labels = $scope->labels;
        $currency = $document->currency;
        $f = $scope->context->formatter;
        $locale = $scope->locale;
        $item = fn (OpenItem $item): array => [
            'invoiceNumber' => $item->invoiceNumber,
            'invoiceDate' => $scope->date($item->invoiceDate),
            'dueDate' => $scope->date($item->dueDate),
            'description' => $item->description,
            'amount' => $scope->money($item->amount),
            'paid' => $scope->money($item->paid),
            'open' => $scope->money($item->open()),
        ];

        $dunning = null;
        if ($document->dunning !== null) {
            $d = $document->dunning;
            $dunning = [
                'level' => $d->level,
                'deadline' => $scope->date($d->deadline),
                'deadlineText' => $d->deadline === null ? null : str_replace(':date', (string) $scope->date($d->deadline), $labels['dunning_deadline'] ?? ''),
                'openItems' => array_map($item, $d->openItems),
                'fees' => array_map(fn (DunningFee $fee): array => ['description' => $fee->description, 'amount' => $scope->money($fee->amount)], $d->fees),
                'interest' => array_map(fn (InterestCharge $i): array => [
                    'description' => $i->description ?? str_replace(
                        [':rate', ':base', ':from', ':until'],
                        // $until is exclusive: the text names the last day with interest.
                        [$f->percent($i->ratePercent, $locale), (string) $scope->money($i->base), (string) $scope->date($i->from), (string) $scope->date($i->lastDay())],
                        $labels['interest_text'] ?? '',
                    ),
                    'days' => $i->days(),
                    'amount' => $scope->money($i->total()),
                ], $d->interest),
                'openTotal' => $scope->money($d->openTotal($currency)),
                'feeTotal' => $scope->money($d->feeTotal($currency)),
                'interestTotal' => $scope->money($d->interestTotal($currency)),
                'total' => $scope->money($d->total($currency)),
            ];
        }

        $statement = null;
        if ($document->statement !== null) {
            $s = $document->statement;
            $statement = [
                'period' => $scope->period($s->period),
                'items' => array_map($item, $s->items),
                'openingBalance' => $scope->money($s->openingBalance),
                'balance' => $scope->money($s->balance($currency)),
            ];
        }

        $lines = [];
        foreach ($document->lineOptions as $id => $options) {
            $lines[$id] = [
                'optional' => $options->optional,
                'alternativeTo' => $options->alternativeTo,
                'serialNumbers' => $options->serialNumbers,
            ];
        }

        return [
            'type' => $document->type->value,
            'validUntil' => $scope->date($document->validUntil),
            'reference' => $document->reference,
            'referenceDate' => $scope->date($document->referenceDate),
            'deliveryDate' => $scope->date($document->delivery?->actualDeliveryDate),
            'packages' => array_map(static fn (Package $p): array => [
                'id' => $p->id,
                'type' => $p->type,
                'weight' => $p->weightKg === null ? null : new FormattedValue($f->quantity($p->weightKg, $locale).' kg', $p->weightKg),
                'dimensions' => $p->dimensions,
                'trackingNumber' => $p->trackingNumber,
            ], $document->packages),
            'lines' => $lines,
            'dunning' => $dunning,
            'statement' => $statement,
            'showAcceptance' => $document->type === DocumentType::Quote,
        ];
    }
}
