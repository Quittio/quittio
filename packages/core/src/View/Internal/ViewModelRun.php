<?php

declare(strict_types=1);

namespace Quittio\Core\View\Internal;

use Brick\Math\BigDecimal;
use Brick\Money\Money;
use Quittio\Core\Calculation\CalculationException;
use Quittio\Core\Codes\RawCode;
use Quittio\Core\Data\AllowanceCharge\LineAllowanceCharge;
use Quittio\Core\Data\Delivery\Period;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Data\Lines\InvoiceLine;
use Quittio\Core\Data\Note;
use Quittio\Core\Data\Party\Address;
use Quittio\Core\Data\Party\Contact;
use Quittio\Core\Data\Presentation\Presentation;
use Quittio\Core\Documents\DocumentData;
use Quittio\Core\Documents\DocumentType;
use Quittio\Core\Documents\DunningFee;
use Quittio\Core\Documents\InterestCharge;
use Quittio\Core\Documents\OpenItem;
use Quittio\Core\Documents\Package;
use Quittio\Core\Enums\InvoiceTypeCode;
use Quittio\Core\Enums\UnitCode;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Exceptions\ConfigurationException;
use Quittio\Core\Pdf\LogoCache;
use Quittio\Core\Pdf\Qr\EpcGiroCodeGenerator;
use Quittio\Core\Pdf\Qr\PaymentQrGenerator;
use Quittio\Core\Pdf\Qr\QrImage;
use Quittio\Core\Support\Amount;
use Quittio\Core\Support\Date;
use Quittio\Core\Validation\Plausibility\Iban;
use Quittio\Core\View\CountryNames;
use Quittio\Core\View\FormattedValue;
use Quittio\Core\View\InternationalAddressFormatter;
use Quittio\Core\View\InvoiceTitle;
use Quittio\Core\View\InvoiceViewModel;
use Quittio\Core\View\LetterLayout;
use Quittio\Core\View\MandatoryFieldGuard;
use Quittio\Core\View\Markdown;
use Quittio\Core\View\ViewContext;
use Quittio\Core\View\ViewModelFactory;

/**
 * One build of ViewModelFactory: holds the invoice, the context and the presentation of the
 * call, so that the factory itself keeps no state.
 *
 * @internal
 */
final class ViewModelRun
{
    private ViewContext $context;

    private InvoiceData $invoice;

    private Presentation $presentation;

    private ?DocumentData $document = null;

    /** @var array<string, string> labels of the current run including per invoice overrides */
    private array $labels = [];

    /** @var array<string, string> view model nodes that stand for others (issuer, recipient) */
    private array $aliases = [];

    /** Self-billed invoice (section 14 (2) sentence 5 UStG): the buyer issues the document. */
    private bool $selfBilled = false;

    /** @var array<string, string> footer entries that print issuer fields (see {@see self::footerColumns()}) */
    private array $footerAliases = [];

    public function build(InvoiceData $invoice, ViewContext $context = new ViewContext): InvoiceViewModel
    {
        if (! $invoice->isCalculated()) {
            throw new CalculationException('The invoice must be calculated before it can be rendered.');
        }

        $this->document = null;

        return new InvoiceViewModel($this->data($invoice, $context), aliases: $this->aliases);
    }

    /**
     * View model for quotes, delivery notes, reminders and other documents.
     * Templates see the same structure as for invoices plus the "documentInfo" node.
     */
    public function buildDocument(DocumentData $document, ViewContext $context = new ViewContext): InvoiceViewModel
    {
        if (! $document->isCalculated()) {
            throw new CalculationException('The document must be calculated before it can be rendered.');
        }

        $this->document = $document;
        $data = $this->data($document->toInvoiceDraft(billableOnly: false), $context);
        $labels = $this->labels;

        $type = $document->type;
        $typeLabel = $labels[$type->labelKey()] ?? $type->value;
        $title = $typeLabel;
        if ($document->dunning !== null && $document->dunning->level >= 1) {
            $title = str_replace(':level', (string) $document->dunning->level, $labels['document_payment_reminder_level'] ?? $title);
        }

        $data['title'] = $this->presentation->title ?? $title;
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
        $data['documentInfo'] = $this->documentInfo($document, $labels);
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
            $data['payment']['qrCode'] = $this->qrCode($document->dunning->total($document->currency)->getAmount(), $remittance);
        }

        $this->document = null;

        return new InvoiceViewModel($data, aliases: $this->aliases);
    }

    /**
     * @return array<string, mixed>
     */
    private function data(InvoiceData $invoice, ViewContext $context): array
    {
        $this->invoice = $invoice;
        $this->context = $context;
        $this->presentation = $this->presentation($invoice, $context);
        $this->selfBilled = $invoice->typeCode instanceof InvoiceTypeCode && $invoice->typeCode->isSelfBilled();

        $labels = $this->labels();
        $this->labels = [...$labels, ...$this->presentation->labels];
        $typeCode = $invoice->typeCodeValue();

        $seller = $this->seller();
        $buyer = $this->buyer();
        // The issuer is shown in letterhead, sender line and footer, the recipient in the address
        // field. A self-billed invoice ("Gutschrift") is issued by the buyer to the seller.
        $issuer = $this->selfBilled ? $buyer : $seller;
        $design = $this->design($issuer);
        $this->aliases = $this->selfBilled ? ['issuer' => 'buyer', 'recipient' => 'seller'] : ['issuer' => 'seller', 'recipient' => 'buyer'];
        // The generated footer prints the issuer's address lines and tax identifiers.
        $this->aliases = [...$this->aliases, ...$this->footerAliases];

        return [
            'number' => $invoice->number,
            'numberLabel' => $labels['invoice_number'],
            'dateLabel' => $labels['invoice_date'],
            'title' => $this->presentation->title ?? InvoiceTitle::policyTitle($invoice, $this->context->country, $this->locale()) ?? $labels[InvoiceTitle::key($invoice)] ?? $labels['title_default'],
            'issueDate' => $this->date($invoice->issueDate),
            'dueDate' => $this->date($invoice->dueDate),
            'servicePeriod' => $this->servicePeriod(),
            // A single date (delivery date or one-day period) is a "date of supply", not a period.
            'servicePeriodLabel' => $this->servicePeriodIsDate() ? $labels['delivery_date'] : $labels['service_period'],
            // BT-72 in addition to an invoicing period (otherwise servicePeriod already shows it).
            'deliveryDate' => $invoice->invoicingPeriod === null ? null : $this->date($invoice->delivery?->actualDeliveryDate),
            'currency' => $invoice->currency,
            'document' => [
                'typeCode' => $typeCode,
                'type' => $invoice->typeCode instanceof InvoiceTypeCode ? $invoice->typeCode->label($this->locale()) : $typeCode,
                'isCreditNote' => $invoice->isCreditNote(),
                'isSelfBilled' => $this->selfBilled,
                'profile' => $this->context->profile?->name(),
                'profileKey' => $this->context->profile?->key(),
                'kind' => 'invoice',
                'isInvoice' => true,
                'showPrices' => true,
                'showTotals' => true,
            ],
            'documentInfo' => null,
            'seller' => $seller,
            'buyer' => $buyer,
            'issuer' => $issuer,
            'recipient' => $this->selfBilled ? $seller : $buyer,
            'payee' => $invoice->payee === null ? null : [
                'name' => $invoice->payee->name,
                'identifier' => $invoice->payee->identifier?->value,
                'legalRegistration' => $invoice->payee->legalRegistration?->value,
            ],
            'taxRepresentative' => $this->taxRepresentative(),
            'delivery' => $this->delivery(),
            'references' => $this->references($labels),
            'precedingInvoices' => array_map(fn ($p): array => [
                'number' => $p->number,
                'issueDate' => $this->date($p->issueDate),
            ], $invoice->precedingInvoices),
            'lineGroups' => $this->lineGroups(),
            'lines' => array_map(fn (InvoiceLine $l): array => $this->line($l), $invoice->lines),
            'allowances' => $this->documentAllowanceCharges(false),
            'charges' => $this->documentAllowanceCharges(true),
            'vatRows' => $this->vatRows($labels),
            'totals' => $invoice->totals === null ? null : $this->totals($labels),
            'payment' => $this->payment(),
            'paymentTerms' => $this->paymentTerms($labels),
            'legalNotes' => $this->legalNotes(),
            'notes' => $this->notes(),
            'attachments' => array_map(static fn ($a): array => [
                'id' => $a->id,
                'description' => $a->description,
                'filename' => $a->filename,
                'uri' => $a->externalUri,
            ], $invoice->attachments),
            'texts' => [
                'salutation' => $this->presentation->salutation,
                'intro' => Markdown::toHtml($this->presentation->introText),
                'closing' => Markdown::toHtml($this->presentation->closingText),
            ],
            'labels' => [...$labels, ...$this->presentation->labels],
            'design' => $design,
            'meta' => [
                'locale' => $this->locale(),
                'custom' => $invoice->meta->custom,
                'source' => $invoice->meta->source,
            ],
        ];
    }

    /**
     * @param  array<string, string>  $labels
     * @return array<string, mixed>
     */
    private function documentInfo(DocumentData $document, array $labels): array
    {
        $currency = $document->currency;
        $f = $this->context->formatter;
        $locale = $this->locale();
        $item = fn (OpenItem $item): array => [
            'invoiceNumber' => $item->invoiceNumber,
            'invoiceDate' => $this->date($item->invoiceDate),
            'dueDate' => $this->date($item->dueDate),
            'description' => $item->description,
            'amount' => $this->money($item->amount),
            'paid' => $this->money($item->paid),
            'open' => $this->money($item->open()),
        ];

        $dunning = null;
        if ($document->dunning !== null) {
            $d = $document->dunning;
            $dunning = [
                'level' => $d->level,
                'deadline' => $this->date($d->deadline),
                'deadlineText' => $d->deadline === null ? null : str_replace(':date', (string) $this->date($d->deadline), $labels['dunning_deadline'] ?? ''),
                'openItems' => array_map($item, $d->openItems),
                'fees' => array_map(fn (DunningFee $fee): array => ['description' => $fee->description, 'amount' => $this->money($fee->amount)], $d->fees),
                'interest' => array_map(fn (InterestCharge $i): array => [
                    'description' => $i->description ?? str_replace(
                        [':rate', ':base', ':from', ':until'],
                        // $until is exclusive: the text names the last day with interest.
                        [$f->percent($i->ratePercent, $locale), (string) $this->money($i->base), (string) $this->date($i->from), (string) $this->date($i->lastDay())],
                        $labels['interest_text'] ?? '',
                    ),
                    'days' => $i->days(),
                    'amount' => $this->money($i->total()),
                ], $d->interest),
                'openTotal' => $this->money($d->openTotal($currency)),
                'feeTotal' => $this->money($d->feeTotal($currency)),
                'interestTotal' => $this->money($d->interestTotal($currency)),
                'total' => $this->money($d->total($currency)),
            ];
        }

        $statement = null;
        if ($document->statement !== null) {
            $s = $document->statement;
            $statement = [
                'period' => $this->period($s->period),
                'items' => array_map($item, $s->items),
                'openingBalance' => $this->money($s->openingBalance),
                'balance' => $this->money($s->balance($currency)),
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
            'validUntil' => $this->date($document->validUntil),
            'reference' => $document->reference,
            'referenceDate' => $this->date($document->referenceDate),
            'deliveryDate' => $this->date($document->delivery?->actualDeliveryDate),
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

    private function presentation(InvoiceData $invoice, ViewContext $context): Presentation
    {
        $defaults = $context->defaults ?? new Presentation;
        $own = $invoice->presentation;

        return $own === null ? $defaults : ViewModelFactory::mergePresentation($own, $defaults);
    }

    /**
     * The locale of the render call wins (Quittio resolves it from the call, the invoice and
     * the configuration), so that PDF and generated XML texts use the same language.
     */
    private function locale(): string
    {
        return $this->context->locale ?? $this->invoice->meta->locale ?? 'en';
    }

    /**
     * @return array<string, string>
     */
    private function labels(): array
    {
        $translator = $this->context->translator();
        $labels = [];
        foreach (InvoiceTitle::keys() as $key) {
            $labels[$key] = $translator->get('quittio::labels.'.$key, [], $this->locale());
        }

        $file = dirname(__DIR__, 3).'/resources/lang/en/labels.php';
        $keys = is_file($file) ? array_keys((array) require $file) : [];
        foreach ($keys as $key) {
            $labels[(string) $key] = $translator->get('quittio::labels.'.$key, [], $this->locale());
        }

        if ($this->context->country !== null) {
            $namespace = 'quittio-'.strtolower($this->context->country->code());
            foreach (array_keys($labels) as $key) {
                if ($translator->has($namespace.'::labels.'.$key, $this->locale())) {
                    $labels[$key] = $translator->get($namespace.'::labels.'.$key, [], $this->locale());
                }
            }
        }

        return $labels;
    }

    /**
     * @return array<string, mixed>
     */
    private function seller(): array
    {
        $seller = $this->invoice->seller;

        return [
            'name' => $seller->name,
            'tradingName' => $seller->tradingName,
            'displayName' => $seller->tradingName ?? $seller->name,
            'address' => $this->address($seller->address, [$seller->name]),
            'vatId' => $seller->vatId,
            'taxNumber' => $seller->taxNumber,
            'legalRegistration' => $seller->legalRegistration?->value,
            'additionalLegalInfo' => $seller->additionalLegalInfo,
            'contact' => $this->contact($seller->contact),
            'electronicAddress' => $seller->electronicAddress?->value,
            'identifiers' => array_map(static fn ($i): string => $i->value, $seller->identifiers),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buyer(): array
    {
        $buyer = $this->invoice->buyer;

        return [
            'name' => $buyer->name,
            'tradingName' => $buyer->tradingName,
            'displayName' => $buyer->tradingName ?? $buyer->name,
            'address' => $this->address($buyer->address, [$buyer->name]),
            'vatId' => $buyer->vatId,
            'customerNumber' => $buyer->identifier?->value,
            'legalRegistration' => $buyer->legalRegistration?->value,
            'contact' => $this->contact($buyer->contact),
            'isConsumer' => $buyer->isConsumer,
        ];
    }

    /**
     * @param  list<string>  $nameLines
     * @return array<string, mixed>
     */
    private function address(Address $address, array $nameLines = []): array
    {
        $formatter = $this->context->country?->addressFormatter() ?? new InternationalAddressFormatter;
        // The country line depends on the country of the issuer (sender of the letter).
        $sender = ($this->selfBilled ? $this->invoice->buyer->address : $this->invoice->seller->address)->countryCode;

        return [
            'lines' => $formatter->format($address, $nameLines, $sender, $this->locale()),
            'street' => $address->line1,
            'additional' => $address->line2,
            'line3' => $address->line3,
            'postcode' => $address->postcode,
            'city' => $address->city,
            'subdivision' => $address->subdivision,
            'countryCode' => $address->countryCode,
            'country' => CountryNames::name($address->countryCode, $this->locale()),
        ];
    }

    /**
     * @return array<string, ?string>|null
     */
    private function contact(?Contact $contact): ?array
    {
        return $contact === null ? null : ['name' => $contact->name, 'phone' => $contact->phone, 'email' => $contact->email];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function delivery(): ?array
    {
        $delivery = $this->invoice->delivery;
        if ($delivery === null) {
            return null;
        }

        return [
            'recipientName' => $delivery->recipientName,
            'date' => $this->date($delivery->actualDeliveryDate),
            'address' => $delivery->address === null ? null : $this->address($delivery->address, self::filled([$delivery->recipientName])),
        ];
    }

    /**
     * Seller's tax representative (BG-11), shown where the seller's own identifiers are missing.
     *
     * @return array<string, mixed>|null
     */
    private function taxRepresentative(): ?array
    {
        $representative = $this->invoice->sellerTaxRepresentative;

        return $representative === null ? null : [
            'name' => $representative->name,
            'vatId' => $representative->vatId,
            'address' => $this->address($representative->address, [$representative->name]),
        ];
    }

    /**
     * Whether the service period is a single date (delivery date or a one-day period).
     */
    private function servicePeriodIsDate(): bool
    {
        $period = $this->invoice->invoicingPeriod;
        if ($period !== null) {
            return $period->start === null || $period->end === null || $period->start->compareTo($period->end) === 0;
        }

        return $this->invoice->delivery?->actualDeliveryDate !== null;
    }

    /**
     * Delivery date or invoicing period, formatted.
     */
    private function servicePeriod(): ?FormattedValue
    {
        $period = $this->invoice->invoicingPeriod;
        if ($period !== null) {
            return $this->period($period);
        }

        $date = $this->invoice->delivery?->actualDeliveryDate;
        if ($date !== null) {
            return $this->date($date);
        }

        $periods = array_values(array_filter(array_map(static fn (InvoiceLine $l): ?Period => $l->period, $this->invoice->lines)));
        if ($periods !== [] && count($periods) === count($this->invoice->lines)) {
            $starts = array_values(array_filter(array_map(static fn (Period $p): ?Date => $p->start, $periods)));
            $ends = array_values(array_filter(array_map(static fn (Period $p): ?Date => $p->end, $periods)));
            usort($starts, static fn (Date $a, Date $b): int => $a->compareTo($b));
            usort($ends, static fn (Date $a, Date $b): int => $b->compareTo($a));

            return $this->period(new Period($starts[0] ?? null, $ends[0] ?? null));
        }

        return null;
    }

    /**
     * @param  array<string, string>  $labels
     * @return list<array{key: string, label: string, value: string}>
     */
    private function references(array $labels): array
    {
        $i = $this->invoice;
        $references = [
            'buyer_reference' => $i->buyerReference,
            'purchase_order' => $i->purchaseOrderReference,
            'sales_order' => $i->salesOrderReference,
            'contract' => $i->contractReference,
            'project' => $i->projectReference === null ? null : trim($i->projectReference->id.' '.($i->projectReference->name ?? '')),
            'despatch_advice' => $i->despatchAdviceReference,
            'tender' => $i->tenderReference,
            'invoiced_object' => $i->invoicedObject?->value,
            'accounting_reference' => $i->buyerAccountingReference,
        ];

        $result = [];
        foreach ($references as $key => $value) {
            if ($value !== null && $value !== '') {
                $result[] = ['key' => $key, 'label' => $labels[$key] ?? $key, 'value' => $value];
            }
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function line(InvoiceLine $line): array
    {
        $currency = $this->invoice->currency;
        $f = $this->context->formatter;
        $locale = $this->locale();
        $showGross = $line->presentation->showGrossPrice ?? $this->presentation->showGrossPrices;

        return [
            'position' => $line->id,
            'id' => $line->id,
            'name' => $line->item->name,
            'description' => [
                'text' => $line->item->description,
                'html' => $this->textHtml($line->item->description),
            ],
            'note' => $line->note,
            'sellerId' => $line->item->sellerId,
            'buyerId' => $line->item->buyerId,
            'quantity' => new FormattedValue($f->quantity($line->quantity, $locale), $line->quantity),
            'unit' => [
                'code' => $line->unit->value,
                'label' => $line->unit instanceof UnitCode ? $line->unit->label($locale) : $line->unit->value,
            ],
            'unitPrice' => $line->price->netPrice === null ? null : new FormattedValue($f->price($line->price->netPrice, $currency, $locale), $line->price->netPrice),
            'grossUnitPrice' => $line->price->priceInclVat === null ? null : new FormattedValue($f->price($line->price->priceInclVat, $currency, $locale), $line->price->priceInclVat),
            'listPrice' => $line->price->grossPrice === null ? null : new FormattedValue($f->price($line->price->grossPrice, $currency, $locale), $line->price->grossPrice),
            'baseQuantity' => $line->price->baseQuantity === null ? null : new FormattedValue($f->quantity($line->price->baseQuantity, $locale), $line->price->baseQuantity),
            // Price base quantity (BT-149) with unit, e.g. "100 Stk." for a price per 100 pieces; null for 1.
            'unitPriceBase' => $this->priceBase($line),
            'showGrossPrice' => $showGross && $line->price->priceInclVat !== null,
            'total' => $this->money($line->netAmount),
            // Margin schemes and travel agents: no rate either (no separate VAT statement).
            'vatRate' => $line->vat->rate === null || $this->isSpecialScheme($line->vat->category) ? null : new FormattedValue($f->percent($line->vat->rate, $locale), $line->vat->rate),
            'vatCategory' => $line->vat->category->value,
            'period' => $line->period === null ? null : $this->period($line->period),
            'attributes' => array_map(static fn ($a): array => ['name' => $a->name, 'value' => $a->value], $line->item->attributes),
            'allowances' => array_map(fn ($a): array => $this->lineAllowanceCharge($a), $line->allowances),
            'charges' => array_map(fn ($c): array => $this->lineAllowanceCharge($c), $line->charges),
            'subLines' => array_map(fn (InvoiceLine $sub): array => $this->line($sub), $line->subLines),
            'highlight' => $line->presentation->highlight ?? false,
            'groupId' => $line->presentation?->groupId,
            'optional' => $this->document?->options($line->id)->optional ?? false,
            'alternativeTo' => $this->document?->options($line->id)->alternativeTo,
            'serialNumbers' => $this->document?->options($line->id)->serialNumbers ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function lineAllowanceCharge(LineAllowanceCharge $entry): array
    {
        $reason = $entry->reason ?? ($entry->reasonCode === null ? null : $entry->reasonCodeValue());
        $percent = $entry->percent === null ? null : new FormattedValue($this->context->formatter->percent($entry->percent, $this->locale()), $entry->percent);

        return [
            'reason' => $reason,
            'amount' => $this->money($entry->amount),
            'percent' => $percent,
            'label' => self::join([$this->labels[$entry->isCharge() ? 'charge' : 'allowance'] ?? '', $reason, $percent?->formatted]),
        ];
    }

    private function priceBase(InvoiceLine $line): ?FormattedValue
    {
        $base = $line->price->baseQuantity;
        if ($base === null || $base->isEqualTo(1)) {
            return null;
        }

        $unit = $line->price->baseQuantityUnit ?? $line->unit;
        $label = $unit instanceof UnitCode ? $unit->label($this->locale()) : $unit->value;

        return new FormattedValue(trim($this->context->formatter->quantity($base, $this->locale()).' '.$label), $base);
    }

    /**
     * Lines grouped for display, with optional subtotals.
     *
     * @return list<array<string, mixed>>
     */
    private function lineGroups(): array
    {
        $groups = [];
        foreach ($this->presentation->lineGroups as $group) {
            $groups[$group->id] = ['id' => $group->id, 'title' => $group->title, 'showSubtotal' => $group->showSubtotal, 'lines' => [], 'sum' => BigDecimal::zero()];
        }
        $ungrouped = ['id' => null, 'title' => null, 'showSubtotal' => false, 'lines' => [], 'sum' => BigDecimal::zero()];

        foreach ($this->invoice->lines as $line) {
            $id = $line->presentation?->groupId;
            $view = $this->line($line);
            $amount = $line->netAmount?->getAmount() ?? BigDecimal::zero();
            $view['amount'] = $amount;
            if ($id !== null && isset($groups[$id])) {
                $groups[$id]['lines'][] = $view;
                $groups[$id]['sum'] = $groups[$id]['sum']->plus($amount);
            } else {
                $ungrouped['lines'][] = $view;
                $ungrouped['sum'] = $ungrouped['sum']->plus($amount);
            }
        }

        $result = [];
        $running = BigDecimal::zero();
        foreach ([...array_values($groups), $ungrouped] as $group) {
            if ($group['lines'] === []) {
                continue;
            }
            // Sum of the line amounts up to each line in display order: the carry-over ("Übertrag")
            // when a page ends after the line.
            foreach ($group['lines'] as $i => $view) {
                $running = $running->plus($view['amount']);
                unset($view['amount']);
                $view['carryOver'] = $this->money(Amount::fromDecimal($running, $this->invoice->currency));
                $group['lines'][$i] = $view;
            }
            $result[] = [
                'id' => $group['id'],
                'title' => $group['title'],
                'lines' => $group['lines'],
                'subtotal' => $group['showSubtotal'] ? $this->money(Amount::fromDecimal($group['sum'], $this->invoice->currency)) : null,
            ];
        }

        return $result;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function documentAllowanceCharges(bool $charges): array
    {
        $f = $this->context->formatter;
        $entries = $charges ? $this->invoice->charges : $this->invoice->allowances;
        // With several VAT groups (e.g. a document discount split by AllowanceSplitter) each row names its VAT rate.
        $showVat = count($this->invoice->vatBreakdown) > 1;

        return array_map(function ($entry) use ($f, $charges, $showVat): array {
            $reason = $entry->reason ?? $entry->reasonCodeValue();
            $percent = $entry->percent === null ? null : new FormattedValue($f->percent($entry->percent, $this->locale()), $entry->percent);
            $vatRate = $entry->vatRate === null ? null : new FormattedValue($f->percent($entry->vatRate, $this->locale()), $entry->vatRate);
            $vat = match (true) {
                ! $showVat => null,
                $vatRate !== null && ! $entry->vatCategory->isZeroTax() => str_replace(':rate', $vatRate->formatted, $this->labels['allowance_vat_rate'] ?? ':rate'),
                default => $entry->vatCategory->label($this->locale()),
            };

            return [
                'reason' => $reason,
                'amount' => $this->money($entry->amount),
                'percent' => $percent,
                'base' => $this->money($entry->baseAmount),
                'vatRate' => $vatRate,
                'vatCategory' => $entry->vatCategory->value,
                'label' => self::join([$this->labels[$charges ? 'charge' : 'allowance'] ?? '', $reason, $percent?->formatted, $vat === null ? null : '('.$vat.')']),
            ];
        }, $entries);
    }

    /**
     * @param  array<string, string>  $labels
     * @return list<array<string, mixed>>
     */
    private function vatRows(array $labels): array
    {
        $f = $this->context->formatter;
        $locale = $this->locale();

        return array_map(function ($row) use ($labels, $f, $locale): array {
            $base = $this->money($row->taxableAmount);
            $rate = $row->rate === null ? null : new FormattedValue($f->percent($row->rate, $locale), $row->rate);
            // Margin schemes and travel agents are taxed on the margin: no "exempt", no VAT amount
            // (section 14a (6) sentence 2 UStG forbids a separate VAT statement).
            $special = $this->isSpecialScheme($row->category);
            // Reverse charge, zero rate and "not subject to VAT" are no tax exemptions and are named as such.
            $template = match (true) {
                $special => $labels['vat_row_special_scheme'],
                $row->category === VatCategory::ReverseCharge => $labels['vat_row_reverse_charge'],
                $row->category === VatCategory::ZeroRated => $labels['vat_row_zero_rated'],
                $row->category === VatCategory::NotSubjectToVat => $labels['vat_row_not_subject'],
                default => $row->category->isZeroTax() ? $labels['vat_row_exempt'] : $labels['vat_row'],
            };
            // One VAT rate: its base is the net total printed above, "USt. 19 %" suffices.
            if ($template === $labels['vat_row'] && count($this->invoice->vatBreakdown) === 1 && isset($labels['vat_row_single'])) {
                $template = $labels['vat_row_single'];
            }
            $label = str_replace([':category', ':rate', ':base'], [$row->category->label($locale), (string) $rate, (string) $base], $template);

            return [
                'label' => $label,
                'category' => $row->category->value,
                'categoryLabel' => $row->category->label($locale),
                'rate' => $rate,
                'base' => $base,
                'amount' => $special ? null : $this->money($row->taxAmount),
                'isSpecialScheme' => $special,
                'exemptionReason' => $row->exemptionReason,
                'exemptionReasonCode' => $row->exemptionReasonCode?->value,
            ];
        }, $this->invoice->vatBreakdown);
    }

    /**
     * @param  array<string, string>  $labels
     * @return array<string, mixed>
     */
    private function totals(array $labels): array
    {
        $t = $this->invoice->totals ?? throw new CalculationException('Missing totals.');
        $meta = $this->invoice->meta;
        $taxCurrencyNote = null;
        if ($t->vatTotalInTaxCurrency !== null && $meta->exchangeRate !== null) {
            $taxCurrencyNote = str_replace(
                [':currency', ':amount', ':rate', ':date'],
                [
                    $t->vatTotalInTaxCurrency->getCurrency()->getCurrencyCode(),
                    (string) $this->money($t->vatTotalInTaxCurrency),
                    $this->context->formatter->quantity($meta->exchangeRate, $this->locale()),
                    (string) ($this->date($meta->exchangeRateDate) ?? '–'),
                ],
                $labels['tax_currency_note'],
            );
        }

        $thirdParty = [];
        foreach ($this->invoice->xrechnungExtension->thirdPartyPayments ?? [] as $payment) {
            $thirdParty[] = ['description' => $payment->description, 'amount' => $this->money($payment->amount)];
        }

        return [
            'lineNetSum' => $this->money($t->lineNetSum),
            'allowanceSum' => $this->money($t->allowanceSum),
            'chargeSum' => $this->money($t->chargeSum),
            'netTotal' => $this->money($t->netTotal),
            'vatTotal' => $this->money($t->vatTotal),
            'vatTotalInTaxCurrency' => $this->money($t->vatTotalInTaxCurrency),
            'taxCurrencyNote' => $taxCurrencyNote,
            'grossTotal' => $this->money($t->grossTotal),
            // Nothing prepaid, rounded or paid by third parties: the amount due is the gross total,
            // templates may print it once, as gross total.
            'dueEqualsGross' => ! $this->isRefund() && ! $this->selfBilled && $t->amountDue->isEqualTo($t->grossTotal),
            'prepaid' => $this->money($t->prepaidAmount),
            'rounding' => $this->money($t->roundingAmount),
            'thirdPartyPayments' => $thirdParty,
            // A refund is shown as a positive amount next to "Erstattungsbetrag" (negative invoices,
            // e.g. cancellations); the raw value keeps the sign of BT-115 as in the XML.
            'amountDue' => $this->isRefund() && $t->amountDue->isNegative()
                ? new FormattedValue($this->context->formatter->money($t->amountDue->abs(), $this->locale()), $t->amountDue)
                : $this->money($t->amountDue),
            // Credit notes and negative invoices are no request to pay: the amount is refunded or
            // offset. On a self-billed invoice the issuer (buyer) pays the amount out.
            'amountDueLabel' => match (true) {
                $this->isRefund() => $this->labels['refund_amount'] ?? $labels['refund_amount'],
                $this->selfBilled => $this->labels['payout_amount'] ?? $labels['payout_amount'],
                default => $this->labels['amount_due'] ?? $labels['amount_due'],
            },
        ];
    }

    /**
     * Margin scheme or travel agents (section 25, 25a UStG): the exempt VAT group carries one
     * of their exemption codes. Taxed on the margin, without a separate VAT statement.
     */
    private function isSpecialScheme(VatCategory $category): bool
    {
        if ($category !== VatCategory::Exempt) {
            return false;
        }
        foreach ($this->invoice->vatBreakdown as $row) {
            if ($row->category === $category && in_array(strtoupper((string) $row->exemptionReasonCode?->value), ViewModelFactory::SPECIAL_SCHEME_CODES, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Credit notes and negative invoices: the buyer does not pay, so no bank details for a transfer.
     */
    private function isRefund(): bool
    {
        return $this->invoice->isCreditNote() || ($this->invoice->totals?->amountDue->isNegative() ?? false);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function payment(): ?array
    {
        $instructions = $this->invoice->paymentInstructions;
        if ($instructions === null) {
            return null;
        }

        $qr = $this->qrCode();

        return [
            'meansCode' => (string) $instructions->meansCode->value,
            'means' => $instructions->meansCode instanceof RawCode ? $instructions->meansCode->value : $instructions->meansCode->label($this->locale()),
            'meansText' => $instructions->meansText,
            'remittance' => ViewModelFactory::remittance($this->invoice),
            'accounts' => array_map(static fn ($t): array => [
                'iban' => $t->isIban() ? trim(chunk_split(Iban::normalize($t->accountId), 4, ' ')) : $t->accountId,
                'bic' => $t->serviceProviderId,
                'name' => $t->accountName,
            ], $instructions->creditTransfers),
            'card' => $instructions->card === null ? null : ['number' => '•••• '.$instructions->card->primaryAccountNumber, 'holder' => $instructions->card->holderName],
            'directDebit' => $instructions->directDebit === null ? null : [
                'mandate' => $instructions->directDebit->mandateReference,
                'creditorId' => $instructions->directDebit->creditorId,
                'account' => $instructions->directDebit->debitedAccountId,
            ],
            'qrCode' => $qr,
            'isRefund' => $this->isRefund(),
            // Self-billed: the accounts are the seller's accounts the amount is paid out to.
            'isPayout' => $this->selfBilled,
        ];
    }

    /**
     * @param  BigDecimal|null  $value  amount instead of the amount due (payment reminders)
     * @param  string|null  $remittance  remittance information instead of BT-83 (payment reminders)
     * @return array{type: string, payload: string, dataUri: string, amount: ?FormattedValue, note: ?string}|null
     */
    private function qrCode(?BigDecimal $value = null, ?string $remittance = null): ?array
    {
        $mode = $this->presentation->paymentQr;
        if (! in_array($mode, ViewModelFactory::PAYMENT_QR_MODES, true)) {
            throw new ConfigurationException(sprintf('Unknown payment QR code type "%s". Known types: %s.', $mode, implode(', ', ViewModelFactory::PAYMENT_QR_MODES)));
        }
        // No payment request for refunds and self-billed invoices (the issuer pays out).
        if ($mode === Presentation::PAYMENT_QR_NONE || $this->isRefund() || $this->selfBilled) {
            return null;
        }

        $generator = match (true) {
            $mode === Presentation::PAYMENT_QR_AUTO => $this->context->paymentQr ?? $this->countryPaymentQr(),
            $mode === Presentation::PAYMENT_QR_GIROCODE => new EpcGiroCodeGenerator,
            default => $this->context->paymentQr ?? throw new ConfigurationException(sprintf(
                'The payment QR code type "%s" needs a generator, and Quittio ships none. Register one with setPaymentQrGenerator() (Laravel: quittio.pdf.payment_qr.generator), or use the type "auto", "girocode" or "none".',
                $mode,
            )),
        };

        if ($generator === null || ($value === null && ! $generator->supports($this->invoice))) {
            return null;
        }

        $amountOption = $value === null ? $this->context->options['girocode_amount'] ?? EpcGiroCodeGenerator::AMOUNT_DUE : EpcGiroCodeGenerator::AMOUNT_DUE;
        $payload = $generator->payload($this->invoice, ['amount' => $amountOption, 'value' => $value, 'remittance' => $remittance]);
        if ($payload === null) {
            return null;
        }

        $amount = null;
        $note = null;
        if ($value !== null) {
            $amount = $this->money(Amount::fromDecimal($value, $this->invoice->currency));
        } elseif ($generator instanceof EpcGiroCodeGenerator) {
            $discount = $this->invoice->paymentTerms->discounts[0] ?? null;
            $discounted = $amountOption === EpcGiroCodeGenerator::AMOUNT_DISCOUNTED && $discount !== null;
            $value = $generator->amount($this->invoice, $discounted);
            $amount = $value === null ? null : $this->money(Amount::fromDecimal($value, $this->invoice->currency));
            if ($discounted && $amount !== null) {
                // The code contains the amount after cash discount; it is only valid until the discount ends.
                $note = str_replace([':amount', ':date'], [$amount->formatted, (string) $this->date($this->invoice->issueDate->addDays($discount->days))], $this->labels['girocode_discounted'] ?? '');
            }
        }

        return [
            'type' => $generator->key(),
            'payload' => $payload,
            'dataUri' => QrImage::dataUri($payload),
            'amount' => $amount,
            'note' => $note,
        ];
    }

    /**
     * Generator of the payment QR code type "auto" after a registered one: the type the country
     * pack names (CountryDefaults::$paymentQr; GiroCode without pack), "none" prints no code;
     * otherwise the pack's generator (CountryPack::paymentQr()), else the EPC GiroCode for
     * "girocode". Other types without a generator of the pack print no code.
     */
    private function countryPaymentQr(): ?PaymentQrGenerator
    {
        $country = $this->context->country;
        $type = $country?->defaults()->paymentQr ?? Presentation::PAYMENT_QR_GIROCODE;
        if ($type === Presentation::PAYMENT_QR_NONE) {
            return null;
        }

        return $country?->paymentQr() ?? ($type === Presentation::PAYMENT_QR_GIROCODE ? new EpcGiroCodeGenerator : null);
    }

    /**
     * @param  array<string, string>  $labels
     * @return array<string, mixed>|null
     */
    private function paymentTerms(array $labels): ?array
    {
        $terms = $this->invoice->paymentTerms;
        $due = $this->invoice->totals?->amountDue;
        if ($terms === null && $this->invoice->dueDate === null) {
            return null;
        }

        $lines = [];
        if ($terms?->text !== null) {
            $lines[] = $terms->text;
        }
        // Credit notes are refunds and offers are no request to pay: no due date, no amount after discount.
        $offer = in_array($this->document?->type, [DocumentType::Quote, DocumentType::OrderConfirmation], true);
        $payable = $due !== null && $due->isPositive() && ! $this->invoice->isCreditNote() && ! $offer;
        if ($this->invoice->dueDate !== null && $payable) {
            // Self-billed: the issuer (buyer) pays the amount out, the recipient is not asked to pay.
            // "Bitte bis zum … überweisen." only for a credit transfer without direct debit.
            $instructions = $this->invoice->paymentInstructions;
            $transfer = $instructions !== null && $instructions->creditTransfers !== [] && $instructions->directDebit === null && isset($labels['transfer_until']);
            $lines[] = str_replace(':date', (string) $this->date($this->invoice->dueDate), $labels[match (true) {
                $this->selfBilled => 'payout_until',
                $transfer => 'transfer_until',
                default => 'payable_until',
            }]);
        }

        $discounts = [];
        $generator = new EpcGiroCodeGenerator;
        foreach ($terms->discounts ?? [] as $discount) {
            $percent = $this->context->formatter->percent($discount->percent, $this->locale());
            $until = null;
            $amount = null;
            if ($payable) {
                $until = $this->date($this->invoice->issueDate->addDays($discount->days));
                $value = $generator->discountedAmount($this->invoice, $discount);
                $amount = $value === null ? null : $this->money(Amount::fromDecimal($value, $this->invoice->currency));
                $text = str_replace([':date', ':percent', ':amount'], [(string) $until, $percent, (string) $amount], $labels[$this->selfBilled ? 'cash_discount_payout' : 'cash_discount']);
            } else {
                // The agreement as stated in BT-20, without a date or an amount to pay.
                $text = str_replace([':days', ':percent'], [(string) $discount->days, $percent], $labels['cash_discount_terms']);
            }
            $discounts[] = ['days' => $discount->days, 'percent' => $percent, 'until' => $until, 'amount' => $amount, 'text' => $text];
            $lines[] = $text;
        }
        if ($terms?->lateFeeTerms?->text !== null) {
            $lines[] = $terms->lateFeeTerms->text;
        }

        return $lines === [] ? null : ['lines' => $lines, 'discounts' => $discounts, 'dueDate' => $this->date($this->invoice->dueDate)];
    }

    /**
     * Exemption reasons and regulatory notes that must appear on the invoice.
     *
     * @return list<string>
     */
    private function legalNotes(): array
    {
        return ViewModelFactory::legalNoteTexts($this->invoice);
    }

    /**
     * @return list<array{subject: ?string, content: string, html: string}>
     */
    private function notes(): array
    {
        $result = [];
        foreach ($this->invoice->notes as $note) {
            if (in_array($note->subjectCode, MandatoryFieldGuard::LEGAL_NOTE_SUBJECTS, true)) {
                continue;
            }
            $result[] = ['subject' => $note->subjectCode?->value, 'content' => $note->content, 'html' => $this->textHtml($note->content)];
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $issuer  the party shown in letterhead, sender line and footer
     * @return array<string, mixed>
     */
    private function design(array $issuer): array
    {
        $p = $this->presentation;
        $colors = $p->colors ?? new \Quittio\Core\Data\Presentation\Colors;
        $layouts = $this->context->country?->letterLayouts() ?? [];
        $layoutName = $p->letterLayout ?? $this->context->country?->defaults()->letterLayout ?? 'free';
        $layout = $layouts[$layoutName] ?? ($layoutName === 'free' ? LetterLayout::free() : throw new ConfigurationException(sprintf(
            'Unknown letter layout "%s". Known layouts: %s.',
            $layoutName,
            implode(', ', array_unique(['free', ...array_keys($layouts)])),
        )));
        $marks = ($this->context->options['fold_marks'] ?? true) === true;
        $watermark = $p->watermark ?? (is_string($this->context->options['watermark'] ?? null) ? $this->context->options['watermark'] : null);
        // Always prepared: mPDF never fetches a logo itself (remote URLs are downloaded from public hosts only).
        $resolved = $this->context->logo;
        if ($resolved !== null && $resolved->logo === $p->logo) {
            $logo = $resolved->src;
        } else {
            // Called directly, without a render run that resolved the logo.
            $cache = is_string($this->context->options['logo_cache'] ?? null) ? $this->context->options['logo_cache'] : sys_get_temp_dir().'/quittio-mpdf/logos';
            $logo = (new LogoCache($cache, pdfa: ($this->context->options['pdfa'] ?? false) === true))->resolve($p->logo);
        }
        // Own footer columns, also none at all when set explicitly to [].
        $customFooter = $p->footerColumns !== [] || $p->isExplicit('footerColumns');
        [$footer, $this->footerAliases] = $customFooter ? [$p->footerColumns, []] : $this->footerColumns($issuer);
        $font = self::font($p->font ?? 'geist');

        return [
            // Values printed into <style>: only hex colours and plain font names (no CSS injection).
            'primaryColor' => self::color($colors->primary),
            'secondaryColor' => self::color($colors->secondary ?? $colors->primary),
            'textColor' => self::color($colors->text),
            'mutedColor' => self::color($colors->muted),
            'font' => $font,
            // Medium weight (line names, table head) and semibold (title, headings): Geist has
            // them as the family "geistmedium" (normal and bold); for other fonts null, bold is used.
            'fontMedium' => strtolower($font) === 'geist' ? 'geistmedium' : null,
            'logo' => $logo,
            'letterLayout' => $layout->name,
            // Address field position on the first page, independent of the letterhead height.
            'addressTop' => $layout->addressTop,
            'addressLeft' => $layout->addressLeft,
            'pageMarginTop' => ViewModelFactory::PAGE_MARGIN_TOP,
            'continuationMarginTop' => ViewModelFactory::CONTINUATION_MARGIN_TOP,
            'letterheadHeight' => $layout->letterheadHeight(ViewModelFactory::PAGE_MARGIN_TOP),
            // The logo ends 2 mm above the address field (DIN 5008 form A leaves less room than form B).
            'logoMaxHeight' => round(min(14.0, max(6.0, $layout->letterheadHeight(ViewModelFactory::PAGE_MARGIN_TOP) - 2.0)), 2),
            'addressHeight' => $layout->addressHeight,
            'addressWidth' => $layout->addressWidth,
            // DIN 5008: the sender line is the last line of the "Zusatz- und Vermerkzone",
            // the recipient starts in the "Anschriftzone" at addressZoneTop below the field top.
            'addressZoneTop' => $layout->addressZoneTop,
            'senderLineHeight' => $layout->senderLineHeight,
            'noticeZoneHeight' => max(0.0, round($layout->addressZoneTop - $layout->senderLineHeight, 2)),
            'foldMarks' => $marks ? $layout->foldMarks : [],
            'punchMark' => $marks ? $layout->punchMark : null,
            'paper' => $p->paper,
            'watermark' => $watermark,
            // The watermark as CSS string literal for the HTML overlay (mPDF prints <watermarktext>).
            'watermarkCss' => $watermark === null ? null : self::cssString($watermark),
            'senderLine' => $p->senderLine ?? $this->senderLine($issuer),
            'footerColumns' => $footer,
            // The generated footer starts with the issuer's address; a custom footer may not.
            'footerHasAddress' => ! $customFooter && isset($this->footerAliases['design.footerColumns[0]']),
            // The generated footer shows the issuer's VAT identifier and tax number; otherwise the
            // template prints them with the invoice details.
            'footerHasTaxIds' => ! $customFooter && array_intersect(['issuer.vatId', 'issuer.taxNumber'], $this->footerAliases) !== [],
            'showItemNumbers' => $p->showItemNumbers,
            'showGrossPrices' => $p->showGrossPrices,
        ];
    }

    /**
     * @param  array<string, mixed>  $issuer
     */
    private function senderLine(array $issuer): string
    {
        $address = is_array($issuer['address'] ?? null) ? $issuer['address'] : [];
        $place = trim((is_string($address['postcode'] ?? null) ? $address['postcode'] : '').' '.(is_string($address['city'] ?? null) ? $address['city'] : ''));

        return implode(' · ', self::filled([
            is_string($issuer['displayName'] ?? null) ? $issuer['displayName'] : null,
            is_string($address['street'] ?? null) ? $address['street'] : null,
            $place,
        ]));
    }

    /**
     * Default footer: address, contact, registration and tax identifiers of the issuer. The bank
     * details are printed with the payment information.
     *
     * @param  array<string, mixed>  $issuer
     * @return array{0: list<list<string>>, 1: array<string, string>} the columns, and the entries
     *         that print issuer fields (aliases for the mandatory field check)
     */
    private function footerColumns(array $issuer): array
    {
        $labels = $this->labels;
        $label = static fn (string $key): string => $labels[$key] ?? $key;
        $string = static fn (mixed $value): ?string => is_string($value) && $value !== '' ? $value : null;
        $address = is_array($issuer['address'] ?? null) ? $issuer['address'] : [];
        $contact = is_array($issuer['contact'] ?? null) ? $issuer['contact'] : [];

        $columns = [];
        $aliases = [];
        // Exactly the issuer's address lines (the guard maps this column onto them).
        $lines = is_array($address['lines'] ?? null) ? array_values(array_filter($address['lines'], is_string(...))) : [];
        if ($lines !== []) {
            $aliases['design.footerColumns[0]'] = 'issuer.address.lines';
            $columns[] = $lines;
        }

        $reach = self::filled([$string($contact['phone'] ?? null), $string($contact['email'] ?? null)]);
        if ($reach !== []) {
            $columns[] = $reach;
        }

        // Register entry and further legal information (BT-33), e.g. "Amtsgericht Musterstadt · Geschäftsführer: …", one part per line.
        $additional = $string($issuer['additionalLegalInfo'] ?? null);
        $parts = $additional === null ? false : preg_split('/\s+·\s+|\R/u', $additional);
        $legal = self::filled([$string($issuer['legalRegistration'] ?? null), ...array_map(trim(...), $parts === false ? [] : $parts)]);
        if ($legal !== []) {
            $columns[] = $legal;
        }

        $tax = [];
        foreach (['vatId' => 'vat_id', 'taxNumber' => 'tax_number_short'] as $field => $key) {
            $value = $string($issuer[$field] ?? null);
            if ($value !== null) {
                $aliases[sprintf('design.footerColumns[%d][%d]', count($columns), count($tax))] = 'issuer.'.$field;
                $tax[] = $label($key).' '.$value;
            }
        }
        if ($tax !== []) {
            $columns[] = $tax;
        }

        return [$columns, $aliases];
    }

    /**
     * Descriptions and notes are part of the XML: plain text unless Markdown is enabled.
     */
    private function textHtml(?string $text): string
    {
        return ($this->context->options['markdown'] ?? false) === true ? Markdown::toHtml($text) : Markdown::plain($text);
    }

    private static function color(string $value): string
    {
        if (preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value) !== 1) {
            throw new ConfigurationException(sprintf('Invalid colour "%s": use a hex colour such as "#1e40af".', $value));
        }

        return $value;
    }

    private static function font(string $value): string
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9 _-]{0,63}$/', $value) !== 1) {
            throw new ConfigurationException(sprintf('Invalid font name "%s": use the name of a registered font family such as "geist".', $value));
        }

        return $value;
    }

    /**
     * Quoted CSS string with every character outside [A-Za-z0-9 ] escaped, safe inside <style>.
     */
    private static function cssString(string $value): string
    {
        $escaped = preg_replace_callback('/[^A-Za-z0-9 ]/u', static fn (array $m): string => sprintf('\\%X ', (int) mb_ord($m[0], 'UTF-8')), $value);

        return '"'.($escaped ?? '').'"';
    }

    /**
     * @param  list<string|null>  $values
     */
    private static function join(array $values, string $separator = ' '): string
    {
        return implode($separator, self::filled($values));
    }

    /**
     * @param  list<string|null>  $values
     * @return list<string>
     */
    private static function filled(array $values): array
    {
        return array_values(array_filter($values, static fn (?string $value): bool => $value !== null && $value !== ''));
    }

    private function money(?Money $money): ?FormattedValue
    {
        return $money === null ? null : new FormattedValue($this->context->formatter->money($money, $this->locale()), $money);
    }

    private function date(?Date $date): ?FormattedValue
    {
        return $date === null ? null : new FormattedValue($this->context->formatter->date($date, $this->locale()), $date);
    }

    private function period(Period $period): FormattedValue
    {
        $start = $period->start === null ? null : $this->context->formatter->date($period->start, $this->locale());
        $end = $period->end === null ? null : $this->context->formatter->date($period->end, $this->locale());
        // Within one year the German short form "01.10.–31.10.2026": the start without its year.
        $year = $period->start?->format('Y');
        $short = $start !== null && $end !== null && $year === $period->end?->format('Y') && str_ends_with($start, '.'.$year)
            ? substr($start, 0, -strlen($year))
            : $start;

        return new FormattedValue(match (true) {
            $start !== null && $end !== null && $start === $end => $start,
            $start !== null && $end !== null => $short.'–'.$end,
            default => (string) ($start ?? $end),
        }, $period);
    }
}
