<?php

declare(strict_types=1);

namespace Quittio\Core\View;

use Brick\Math\BigDecimal;
use Brick\Money\Money;
use Quittio\Core\Calculation\CalculationException;
use Quittio\Core\Codes\RawCode;
use Quittio\Core\Data\Delivery\Period;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Data\Lines\InvoiceLine;
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
use Quittio\Core\Enums\NoteSubjectCode;
use Quittio\Core\Enums\UnitCode;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Pdf\LogoCache;
use Quittio\Core\Pdf\Qr\EpcGiroCodeGenerator;
use Quittio\Core\Pdf\Qr\QrImage;
use Quittio\Core\Support\Amount;
use Quittio\Core\Support\Date;
use Quittio\Core\Validation\Plausibility\Iban;
use ReflectionClass;

/**
 * Builds the template view model from calculated invoice data.
 */
final class ViewModelFactory
{
    private ViewContext $context;

    private InvoiceData $invoice;

    private Presentation $presentation;

    /** Top page margin of the default templates in millimetres. */
    public const float PAGE_MARGIN_TOP = 20.0;

    private ?DocumentData $document = null;

    /** @var array<string, string> labels of the current run including per invoice overrides */
    private array $labels = [];

    public function build(InvoiceData $invoice, ViewContext $context = new ViewContext): InvoiceViewModel
    {
        if (! $invoice->isCalculated()) {
            throw new CalculationException('The invoice must be calculated before it can be rendered.');
        }

        $this->document = null;

        return new InvoiceViewModel($this->data($invoice, $context));
    }

    /**
     * View model for quotes, delivery notes, reminders and other documents (specification 13).
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
        $title = $labels[$type->labelKey()] ?? $type->value;
        if ($document->dunning !== null && $document->dunning->level > 1) {
            $title = str_replace(':level', (string) $document->dunning->level, $labels['document_payment_reminder_level'] ?? $title);
        }

        $data['title'] = $this->presentation->title ?? $title;
        $data['numberLabel'] = $labels['document_number'] ?? 'Number';
        $data['dateLabel'] = $labels['document_date'] ?? 'Date';
        $data['document'] = [
            ...(array) $data['document'],
            'kind' => $type->value,
            'isInvoice' => false,
            'showPrices' => $type->showsPrices(),
            'showTotals' => $type->hasLineTotals() && $document->totals !== null,
        ];
        $data['documentInfo'] = $this->documentInfo($document, $labels);
        if ($document->delivery?->actualDeliveryDate !== null) {
            // The delivery date is shown as such; a derived service period would repeat it.
            $data['servicePeriod'] = null;
        }
        if ($type === DocumentType::Proforma) {
            $data['legalNotes'] = [...(array) $data['legalNotes'], $labels['proforma_notice'] ?? ''];
        }
        if (! $type->hasLineTotals()) {
            $data['payment'] = in_array($type, [DocumentType::PaymentReminder, DocumentType::AccountStatement], true) ? $data['payment'] : null;
            $data['paymentTerms'] = null;
        }

        $this->document = null;

        return new InvoiceViewModel($data);
    }

    /**
     * @return array<string, mixed>
     */
    private function data(InvoiceData $invoice, ViewContext $context): array
    {
        $this->invoice = $invoice;
        $this->context = $context;
        $this->presentation = $this->presentation($invoice, $context);

        $labels = $this->labels();
        $this->labels = [...$labels, ...$this->presentation->labels];
        $typeCode = $invoice->typeCodeValue();

        return [
            'number' => $invoice->number,
            'numberLabel' => $labels['invoice_number'],
            'dateLabel' => $labels['invoice_date'],
            'title' => $this->presentation->title ?? $labels['title_'.$typeCode] ?? $labels['title_default'],
            'issueDate' => $this->date($invoice->issueDate),
            'dueDate' => $this->date($invoice->dueDate),
            'servicePeriod' => $this->servicePeriod(),
            'currency' => $invoice->currency,
            'document' => [
                'typeCode' => $typeCode,
                'type' => $invoice->typeCode instanceof InvoiceTypeCode ? $invoice->typeCode->label($this->locale()) : $typeCode,
                'isCreditNote' => $invoice->isCreditNote(),
                'isSelfBilled' => $invoice->typeCode instanceof InvoiceTypeCode && $invoice->typeCode->isSelfBilled(),
                'profile' => $this->context->profile?->name(),
                'profileKey' => $this->context->profile?->key(),
                'kind' => 'invoice',
                'isInvoice' => true,
                'showPrices' => true,
                'showTotals' => true,
            ],
            'documentInfo' => null,
            'seller' => $this->seller(),
            'buyer' => $this->buyer(),
            'payee' => $invoice->payee === null ? null : ['name' => $invoice->payee->name],
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
            'design' => $this->design(),
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
                        [$f->percent($i->ratePercent, $locale), (string) $this->money($i->base), (string) $this->date($i->from), (string) $this->date($i->until)],
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
        if ($own === null) {
            return $defaults;
        }

        $empty = new Presentation;
        $merged = [];
        foreach ((new ReflectionClass(Presentation::class))->getProperties() as $property) {
            $value = $property->getValue($own);
            $merged[$property->getName()] = $value === $property->getValue($empty) ? $property->getValue($defaults) : $value;
        }

        return $empty->with($merged);
    }

    private function locale(): string
    {
        return $this->invoice->meta->locale ?? $this->context->locale;
    }

    /**
     * @return array<string, string>
     */
    private function labels(): array
    {
        $translator = $this->context->translator();
        $labels = [];
        foreach (['title_380', 'title_381', 'title_384', 'title_389', 'title_326', 'title_386', 'title_875', 'title_876', 'title_877', 'title_default'] as $key) {
            $labels[$key] = $translator->get('quittio::labels.'.$key, [], $this->locale());
        }

        $file = dirname(__DIR__, 2).'/resources/lang/en/labels.php';
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
            'address' => $this->address($seller->address),
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
        $sender = $this->invoice->seller->address->countryCode;

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
                'html' => Markdown::toHtml($line->item->description),
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
            'showGrossPrice' => $showGross && $line->price->priceInclVat !== null,
            'total' => $this->money($line->netAmount),
            'vatRate' => $line->vat->rate === null ? null : new FormattedValue($f->percent($line->vat->rate, $locale), $line->vat->rate),
            'vatCategory' => $line->vat->category->value,
            'period' => $line->period === null ? null : $this->period($line->period),
            'attributes' => array_map(static fn ($a): array => ['name' => $a->name, 'value' => $a->value], $line->item->attributes),
            'allowances' => array_map(fn ($a): array => ['reason' => $a->reason ?? ($a->reasonCode === null ? null : $a->reasonCodeValue()), 'amount' => $this->money($a->amount), 'percent' => $a->percent === null ? null : new FormattedValue($f->percent($a->percent, $locale), $a->percent)], $line->allowances),
            'charges' => array_map(fn ($c): array => ['reason' => $c->reason ?? ($c->reasonCode === null ? null : $c->reasonCodeValue()), 'amount' => $this->money($c->amount), 'percent' => $c->percent === null ? null : new FormattedValue($f->percent($c->percent, $locale), $c->percent)], $line->charges),
            'subLines' => array_map(fn (InvoiceLine $sub): array => $this->line($sub), $line->subLines),
            'highlight' => $line->presentation->highlight ?? false,
            'groupId' => $line->presentation?->groupId,
            'optional' => $this->document?->options($line->id)->optional ?? false,
            'alternativeTo' => $this->document?->options($line->id)->alternativeTo,
            'serialNumbers' => $this->document?->options($line->id)->serialNumbers ?? [],
        ];
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
            if ($id !== null && isset($groups[$id])) {
                $groups[$id]['lines'][] = $view;
                $groups[$id]['sum'] = $groups[$id]['sum']->plus($amount);
            } else {
                $ungrouped['lines'][] = $view;
                $ungrouped['sum'] = $ungrouped['sum']->plus($amount);
            }
        }

        $result = [];
        foreach ([...array_values($groups), $ungrouped] as $group) {
            if ($group['lines'] === []) {
                continue;
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

        return array_map(fn ($entry): array => [
            'reason' => $entry->reason ?? $entry->reasonCodeValue(),
            'amount' => $this->money($entry->amount),
            'percent' => $entry->percent === null ? null : new FormattedValue($f->percent($entry->percent, $this->locale()), $entry->percent),
            'base' => $this->money($entry->baseAmount),
            'vatRate' => $entry->vatRate === null ? null : new FormattedValue($f->percent($entry->vatRate, $this->locale()), $entry->vatRate),
            'vatCategory' => $entry->vatCategory->value,
        ], $entries);
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
            $label = $row->category->isZeroTax()
                ? str_replace([':category', ':base'], [$row->category->label($locale), (string) $base], $labels['vat_row_exempt'])
                : str_replace([':rate', ':base'], [(string) $rate, (string) $base], $labels['vat_row']);

            return [
                'label' => $label,
                'category' => $row->category->value,
                'categoryLabel' => $row->category->label($locale),
                'rate' => $rate,
                'base' => $base,
                'amount' => $this->money($row->taxAmount),
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
            'prepaid' => $this->money($t->prepaidAmount),
            'rounding' => $this->money($t->roundingAmount),
            'thirdPartyPayments' => $thirdParty,
            'amountDue' => $this->money($t->amountDue),
        ];
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
            'remittance' => $instructions->remittanceInformation ?? $this->invoice->number,
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
        ];
    }

    /**
     * @return array{type: string, payload: string, dataUri: string}|null
     */
    private function qrCode(): ?array
    {
        $mode = $this->presentation->paymentQr;
        if ($mode === Presentation::PAYMENT_QR_NONE) {
            return null;
        }

        $generator = match (true) {
            $mode === Presentation::PAYMENT_QR_AUTO => $this->context->paymentQr ?? $this->context->country?->paymentQr() ?? new EpcGiroCodeGenerator,
            $mode === Presentation::PAYMENT_QR_GIROCODE => new EpcGiroCodeGenerator,
            default => $this->context->paymentQr,
        };

        if ($generator === null || ! $generator->supports($this->invoice)) {
            return null;
        }

        $amountOption = $this->context->options['girocode_amount'] ?? EpcGiroCodeGenerator::AMOUNT_DUE;
        $payload = $generator->payload($this->invoice, ['amount' => $amountOption]);

        return $payload === null ? null : [
            'type' => $generator->key(),
            'payload' => $payload,
            'dataUri' => QrImage::dataUri($payload),
        ];
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
        // Credit notes are refunds: no request to pay and no cash discount.
        $payable = $due !== null && $due->isPositive() && ! $this->invoice->isCreditNote();
        if ($this->invoice->dueDate !== null && $payable) {
            $lines[] = str_replace(':date', (string) $this->date($this->invoice->dueDate), $labels['payable_until']);
        }

        $discounts = [];
        foreach ($payable ? $terms->discounts ?? [] : [] as $discount) {
            $until = $this->invoice->issueDate->addDays($discount->days);
            $amount = (new EpcGiroCodeGenerator)->amount($this->invoice, true);
            $percent = $this->context->formatter->percent($discount->percent, $this->locale());
            $amountText = $amount === null ? '' : (string) $this->money(Amount::fromDecimal($amount, $this->invoice->currency));
            $text = str_replace([':date', ':percent', ':amount'], [(string) $this->date($until), $percent, $amountText], $labels['cash_discount']);
            $discounts[] = ['days' => $discount->days, 'percent' => $percent, 'until' => $this->date($until), 'text' => $text];
            $lines[] = $text;
        }
        if ($terms?->lateFeeTerms?->text !== null) {
            $lines[] = $terms->lateFeeTerms->text;
        }

        return ['lines' => $lines, 'discounts' => $discounts, 'dueDate' => $this->date($this->invoice->dueDate)];
    }

    /**
     * Exemption reasons and regulatory notes that must appear on the invoice.
     *
     * @return list<string>
     */
    private function legalNotes(): array
    {
        $notes = [];
        foreach ($this->invoice->vatBreakdown as $row) {
            if ($row->exemptionReason !== null && $row->category !== VatCategory::StandardRate) {
                $notes[] = $row->exemptionReason;
            }
        }
        foreach ($this->invoice->notes as $note) {
            if (in_array($note->subjectCode, [NoteSubjectCode::RegulatoryInformation, NoteSubjectCode::LegalInformation, NoteSubjectCode::TaxDeclaration], true)) {
                $notes[] = $note->content;
            }
        }

        return array_values(array_unique($notes));
    }

    /**
     * @return list<array{subject: ?string, content: string, html: string}>
     */
    private function notes(): array
    {
        $result = [];
        foreach ($this->invoice->notes as $note) {
            if (in_array($note->subjectCode, [NoteSubjectCode::RegulatoryInformation, NoteSubjectCode::LegalInformation, NoteSubjectCode::TaxDeclaration], true)) {
                continue;
            }
            $result[] = ['subject' => $note->subjectCode?->value, 'content' => $note->content, 'html' => Markdown::toHtml($note->content)];
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function design(): array
    {
        $p = $this->presentation;
        $colors = $p->colors ?? new \Quittio\Core\Data\Presentation\Colors;
        $layoutName = $p->letterLayout ?? $this->context->country?->defaults()->letterLayout ?? 'free';
        $layout = $this->context->country?->letterLayouts()[$layoutName] ?? LetterLayout::free();
        $marks = ($this->context->options['fold_marks'] ?? false) === true;

        return [
            'primaryColor' => $colors->primary,
            'secondaryColor' => $colors->secondary ?? $colors->primary,
            'textColor' => $colors->text,
            'mutedColor' => $colors->muted,
            'font' => $p->font ?? 'inter',
            'logo' => is_string($this->context->options['logo_cache'] ?? null)
                ? (new LogoCache($this->context->options['logo_cache']))->resolve($p->logo)
                : $p->logo,
            'letterLayout' => $layout->name,
            'pageMarginTop' => self::PAGE_MARGIN_TOP,
            'letterheadHeight' => $layout->letterheadHeight(self::PAGE_MARGIN_TOP),
            'addressHeight' => $layout->addressHeight,
            'addressWidth' => $layout->addressWidth,
            'foldMarks' => $marks ? $layout->foldMarks : [],
            'punchMark' => $marks ? $layout->punchMark : null,
            'paper' => $p->paper,
            'watermark' => $p->watermark ?? (is_string($this->context->options['watermark'] ?? null) ? $this->context->options['watermark'] : null),
            'senderLine' => $p->senderLine ?? $this->senderLine(),
            'footerColumns' => $p->footerColumns !== [] ? $p->footerColumns : $this->footerColumns(),
            'showItemNumbers' => $p->showItemNumbers,
            'showGrossPrices' => $p->showGrossPrices,
        ];
    }

    private function senderLine(): string
    {
        $seller = $this->invoice->seller;
        $address = $seller->address;

        return implode(' · ', array_filter([$seller->tradingName ?? $seller->name, $address->line1, trim(($address->postcode ?? '').' '.($address->city ?? ''))], static fn (?string $v): bool => $v !== null && $v !== ''));
    }

    /**
     * Default footer: address, contact, bank details, registration.
     *
     * @return list<list<string>>
     */
    private function footerColumns(): array
    {
        $seller = $this->invoice->seller;
        $labels = $this->labels();
        $address = $this->address($seller->address, [$seller->name]);
        $columns = [];
        /** @var list<string> $lines */
        $lines = $address['lines'];
        $columns[] = $lines;

        $contact = array_values(array_filter([
            $seller->contact?->phone === null ? null : $labels['phone'].': '.$seller->contact->phone,
            $seller->contact?->email === null ? null : $labels['email'].': '.$seller->contact->email,
            $seller->vatId === null ? null : $labels['vat_id'].': '.$seller->vatId,
            $seller->taxNumber === null ? null : $labels['tax_number'].': '.$seller->taxNumber,
        ]));
        if ($contact !== []) {
            $columns[] = $contact;
        }

        $transfer = $this->invoice->paymentInstructions?->primaryCreditTransfer();
        if ($transfer !== null) {
            $columns[] = self::filled([
                $transfer->accountName,
                $labels['iban'].': '.($transfer->isIban() ? trim(chunk_split(Iban::normalize($transfer->accountId), 4, ' ')) : $transfer->accountId),
                $transfer->serviceProviderId === null ? null : $labels['bic'].': '.$transfer->serviceProviderId,
            ]);
        }

        $legal = self::filled([$seller->legalRegistration?->value, $seller->additionalLegalInfo]);
        if ($legal !== []) {
            $columns[] = $legal;
        }

        return $columns;
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

        return new FormattedValue(match (true) {
            $start !== null && $end !== null && $start === $end => $start,
            $start !== null && $end !== null => $start."\u{2009}–\u{2009}".$end,
            default => (string) ($start ?? $end),
        }, $period);
    }
}
