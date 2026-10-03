<?php

declare(strict_types=1);

namespace Quittio\Core\Builder;

use Brick\Math\BigDecimal;
use Brick\Money\Money;
use DateTimeInterface;
use Quittio\Core\Calculation\AllowanceSplitter;
use Quittio\Core\Codes\RawCode;
use Quittio\Core\Contracts\ProvidesInvoiceLine;
use Quittio\Core\Data\AllowanceCharge\DocumentAllowance;
use Quittio\Core\Data\AllowanceCharge\DocumentCharge;
use Quittio\Core\Data\Attachments\Attachment;
use Quittio\Core\Data\Delivery\Delivery;
use Quittio\Core\Data\Delivery\Period;
use Quittio\Core\Data\Extensions\ExtendedData;
use Quittio\Core\Data\Extensions\XRechnungExtensionData;
use Quittio\Core\Data\Identifier;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Data\Lines\InvoiceLine;
use Quittio\Core\Data\Meta;
use Quittio\Core\Data\Note;
use Quittio\Core\Data\Party\Address;
use Quittio\Core\Data\Party\BuyerParty;
use Quittio\Core\Data\Party\PayeeParty;
use Quittio\Core\Data\Party\SellerParty;
use Quittio\Core\Data\Party\TaxRepresentativeParty;
use Quittio\Core\Data\Payment\CreditTransfer;
use Quittio\Core\Data\Payment\DirectDebit;
use Quittio\Core\Data\Payment\PaymentCard;
use Quittio\Core\Data\Payment\PaymentInstructions;
use Quittio\Core\Data\Payment\PaymentTerms;
use Quittio\Core\Data\Presentation\LineGroup;
use Quittio\Core\Data\Presentation\Presentation;
use Quittio\Core\Data\References\PrecedingInvoiceReference;
use Quittio\Core\Data\References\ProjectReference;
use Quittio\Core\Documents\DocumentData;
use Quittio\Core\Documents\DocumentType;
use Quittio\Core\Enums\AllowanceReasonCode;
use Quittio\Core\Enums\ChargeReasonCode;
use Quittio\Core\Enums\InvoiceTypeCode;
use Quittio\Core\Enums\MimeType;
use Quittio\Core\Enums\NoteSubjectCode;
use Quittio\Core\Enums\PaymentMeansCode;
use Quittio\Core\Enums\TaxPointDateCode;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Exceptions\InvalidInvoiceData;
use Quittio\Core\Standards\StandardVersion;
use Quittio\Core\Support\Amount;
use Quittio\Core\Support\Date;
use Quittio\Core\Support\Decimal;
use Quittio\Core\Support\Macroable;

/**
 * Fluent builder for InvoiceData (specification 5.17).
 *
 * Amounts, prices and quantities are passed as strings or integers, never as floats.
 * The result is not yet calculated; totals and the VAT breakdown are set by the calculator.
 */
class InvoiceBuilder
{
    use Macroable;

    private string $number = '';

    private ?Date $issueDate = null;

    private InvoiceTypeCode|RawCode $typeCode = InvoiceTypeCode::CommercialInvoice;

    private string $currency = 'EUR';

    private ?string $taxCurrency = null;

    private ?Date $taxPointDate = null;

    private TaxPointDateCode|RawCode|null $taxPointDateCode = null;

    private ?Date $dueDate = null;

    /** @var array<string, string|null> */
    private array $references = [];

    private ?ProjectReference $projectReference = null;

    private ?Identifier $invoicedObject = null;

    private PaymentTermsBuilder|PaymentTerms|null $paymentTerms = null;

    /** @var list<Note> */
    private array $notes = [];

    private ?string $businessProcess = null;

    /** @var list<PrecedingInvoiceReference> */
    private array $precedingInvoices = [];

    private SellerParty|PartyBuilder|null $seller = null;

    private BuyerParty|PartyBuilder|null $buyer = null;

    private ?PayeeParty $payee = null;

    private ?TaxRepresentativeParty $taxRepresentative = null;

    private ?Delivery $delivery = null;

    private ?Period $invoicingPeriod = null;

    private ?PaymentInstructions $paymentInstructions = null;

    /** @var list<array{charge: bool, amount: ?string, percent: ?string, base: ?string, category: VatCategory, rate: ?string, reason: ?string, code: AllowanceReasonCode|ChargeReasonCode|RawCode|null}> */
    private array $allowanceCharges = [];

    /** @var list<array{charge: bool, percent: ?string, amount: ?string, reason: ?string, code: AllowanceReasonCode|ChargeReasonCode|RawCode|null}> */
    private array $splitAllowanceCharges = [];

    /** @var list<Attachment> */
    private array $attachments = [];

    /** @var list<LineBuilder|InvoiceLine> */
    private array $lines = [];

    private ?string $prepaid = null;

    private ?string $rounding = null;

    private ?ExtendedData $extended = null;

    private ?XRechnungExtensionData $xrechnungExtension = null;

    private ?Presentation $presentation = null;

    private Meta $meta;

    private StandardVersion $standardVersion = StandardVersion::En16931_2017;

    final public function __construct()
    {
        $this->meta = new Meta;
    }

    public static function make(): static
    {
        return new static;
    }

    /**
     * Starts a builder from existing data, e.g. to change a draft.
     */
    public static function from(InvoiceData $data): static
    {
        $builder = new static;
        $builder->number = $data->number;
        $builder->issueDate = $data->issueDate;
        $builder->typeCode = $data->typeCode;
        $builder->currency = $data->currency;
        $builder->taxCurrency = $data->taxCurrency;
        $builder->taxPointDate = $data->taxPointDate;
        $builder->taxPointDateCode = $data->taxPointDateCode;
        $builder->dueDate = $data->dueDate;
        $builder->references = [
            'buyerReference' => $data->buyerReference,
            'contractReference' => $data->contractReference,
            'purchaseOrderReference' => $data->purchaseOrderReference,
            'salesOrderReference' => $data->salesOrderReference,
            'receivingAdviceReference' => $data->receivingAdviceReference,
            'despatchAdviceReference' => $data->despatchAdviceReference,
            'tenderReference' => $data->tenderReference,
            'buyerAccountingReference' => $data->buyerAccountingReference,
        ];
        $builder->projectReference = $data->projectReference;
        $builder->invoicedObject = $data->invoicedObject;
        $builder->paymentTerms = $data->paymentTerms;
        $builder->notes = $data->notes;
        $builder->businessProcess = $data->businessProcess;
        $builder->precedingInvoices = $data->precedingInvoices;
        $builder->seller = $data->seller;
        $builder->buyer = $data->buyer;
        $builder->payee = $data->payee;
        $builder->taxRepresentative = $data->sellerTaxRepresentative;
        $builder->delivery = $data->delivery;
        $builder->invoicingPeriod = $data->invoicingPeriod;
        $builder->paymentInstructions = $data->paymentInstructions;
        $builder->attachments = $data->attachments;
        $builder->lines = $data->lines;
        $builder->prepaid = $data->prepaidAmount === null ? null : Amount::format($data->prepaidAmount);
        $builder->rounding = $data->roundingAmount === null ? null : Amount::format($data->roundingAmount);
        $builder->extended = $data->extended;
        $builder->xrechnungExtension = $data->xrechnungExtension;
        $builder->presentation = $data->presentation;
        $builder->meta = $data->meta;
        $builder->standardVersion = $data->standardVersion;
        $builder->existingAllowances = $data->allowances;
        $builder->existingCharges = $data->charges;

        return $builder;
    }

    /** @var list<DocumentAllowance> */
    private array $existingAllowances = [];

    /** @var list<DocumentCharge> */
    private array $existingCharges = [];

    public function number(string $number): static
    {
        $this->number = $number;

        return $this;
    }

    public function issueDate(Date|DateTimeInterface|string $date): static
    {
        $this->issueDate = Date::parse($date, $this->meta->timezone);

        return $this;
    }

    public function type(InvoiceTypeCode|RawCode|string $type): static
    {
        $this->typeCode = is_string($type) ? (InvoiceTypeCode::tryFrom($type) ?? new RawCode($type)) : $type;

        return $this;
    }

    public function currency(string $currency): static
    {
        $this->currency = strtoupper($currency);

        return $this;
    }

    /**
     * VAT accounting currency (BT-6). With an exchange rate, BT-111 is calculated.
     */
    public function taxCurrency(string $currency, BigDecimal|int|string|null $exchangeRate = null, Date|DateTimeInterface|string|null $rateDate = null): static
    {
        $this->taxCurrency = strtoupper($currency);
        if ($exchangeRate !== null) {
            $this->exchangeRate($exchangeRate, $rateDate);
        }

        return $this;
    }

    public function exchangeRate(BigDecimal|int|string $rate, Date|DateTimeInterface|string|null $date = null): static
    {
        $this->meta = $this->meta->with([
            'exchangeRate' => Decimal::of($rate),
            'exchangeRateDate' => $date === null ? null : Date::parse($date),
        ]);

        return $this;
    }

    public function taxPointDate(Date|DateTimeInterface|string $date): static
    {
        $this->taxPointDate = Date::parse($date, $this->meta->timezone);

        return $this;
    }

    public function taxPointDateCode(TaxPointDateCode $code): static
    {
        $this->taxPointDateCode = $code;

        return $this;
    }

    public function dueDate(Date|DateTimeInterface|string $date): static
    {
        $this->dueDate = Date::parse($date, $this->meta->timezone);

        return $this;
    }

    /**
     * Buyer reference (BT-10). For public sector buyers in Germany this is the Leitweg-ID.
     */
    public function buyerReference(?string $reference): static
    {
        return $this->reference('buyerReference', $reference);
    }

    public function projectReference(string $id, ?string $name = null): static
    {
        $this->projectReference = new ProjectReference($id, $name);

        return $this;
    }

    public function contractReference(?string $reference): static
    {
        return $this->reference('contractReference', $reference);
    }

    public function purchaseOrderReference(?string $reference): static
    {
        return $this->reference('purchaseOrderReference', $reference);
    }

    public function salesOrderReference(?string $reference): static
    {
        return $this->reference('salesOrderReference', $reference);
    }

    public function receivingAdviceReference(?string $reference): static
    {
        return $this->reference('receivingAdviceReference', $reference);
    }

    public function despatchAdviceReference(?string $reference): static
    {
        return $this->reference('despatchAdviceReference', $reference);
    }

    public function tenderReference(?string $reference): static
    {
        return $this->reference('tenderReference', $reference);
    }

    public function buyerAccountingReference(?string $reference): static
    {
        return $this->reference('buyerAccountingReference', $reference);
    }

    public function invoicedObject(string $value, string|RawCode|null $scheme = null): static
    {
        $this->invoicedObject = Identifier::of($value, $scheme);

        return $this;
    }

    public function note(string $content, NoteSubjectCode|RawCode|string|null $subject = null): static
    {
        $this->notes[] = new Note(
            $content,
            is_string($subject) ? (NoteSubjectCode::tryFrom($subject) ?? new RawCode($subject)) : $subject,
        );

        return $this;
    }

    public function businessProcess(?string $process): static
    {
        $this->businessProcess = $process;

        return $this;
    }

    public function precedingInvoice(string $number, Date|DateTimeInterface|string|null $issueDate = null): static
    {
        $this->precedingInvoices[] = new PrecedingInvoiceReference($number, $issueDate === null ? null : Date::parse($issueDate));

        return $this;
    }

    /**
     * @param  SellerParty|callable(PartyBuilder): mixed  $seller
     */
    public function seller(SellerParty|callable $seller): static
    {
        if ($seller instanceof SellerParty) {
            $this->seller = $seller;

            return $this;
        }

        $builder = $this->seller instanceof SellerParty ? PartyBuilder::fromSeller($this->seller) : ($this->seller ?? new PartyBuilder);
        $seller($builder);
        $this->seller = $builder;

        return $this;
    }

    /**
     * @param  BuyerParty|callable(PartyBuilder): mixed  $buyer
     */
    public function buyer(BuyerParty|callable $buyer): static
    {
        if ($buyer instanceof BuyerParty) {
            $this->buyer = $buyer;

            return $this;
        }

        $builder = $this->buyer instanceof BuyerParty ? PartyBuilder::fromBuyer($this->buyer) : ($this->buyer ?? new PartyBuilder);
        $buyer($builder);
        $this->buyer = $builder;

        return $this;
    }

    public function payee(string $name, ?string $identifier = null, ?string $legalRegistration = null): static
    {
        $this->payee = new PayeeParty(
            $name,
            $identifier === null ? null : Identifier::of($identifier),
            $legalRegistration === null ? null : Identifier::of($legalRegistration),
        );

        return $this;
    }

    public function taxRepresentative(string $name, string $vatId, Address $address): static
    {
        $this->taxRepresentative = new TaxRepresentativeParty($name, $vatId, $address);

        return $this;
    }

    /**
     * Actual delivery or service date (BT-72).
     */
    public function deliveryDate(Date|DateTimeInterface|string $date): static
    {
        $this->delivery = ($this->delivery ?? new Delivery)->with(['actualDeliveryDate' => Date::parse($date, $this->meta->timezone)]);

        return $this;
    }

    /**
     * Deliver-to party and address (BT-70, BG-15).
     */
    public function deliverTo(?string $recipientName, ?Address $address = null, ?Identifier $locationId = null): static
    {
        $this->delivery = ($this->delivery ?? new Delivery)->with([
            'recipientName' => $recipientName,
            'address' => $address,
            'locationId' => $locationId,
        ]);

        return $this;
    }

    public function delivery(Delivery $delivery): static
    {
        $this->delivery = $delivery;

        return $this;
    }

    /**
     * Invoicing period (BG-14).
     */
    public function invoicingPeriod(Date|string|null $start, Date|string|null $end = null): static
    {
        $this->invoicingPeriod = Period::between($start, $end);

        return $this;
    }

    /**
     * Payment instructions (BG-16) with an optional credit transfer account (BG-17).
     * The remittance information (BT-83) defaults to the invoice number.
     */
    public function payment(
        PaymentMeansCode|RawCode|string $means,
        ?string $iban = null,
        ?string $bic = null,
        ?string $accountName = null,
        ?string $remittanceInformation = null,
        ?string $meansText = null,
    ): static {
        $means = is_string($means) ? (PaymentMeansCode::tryFrom($means) ?? new RawCode($means)) : $means;
        $transfers = $this->paymentInstructions === null ? [] : $this->paymentInstructions->creditTransfers;
        if ($iban !== null) {
            $transfers[] = new CreditTransfer(strtoupper(str_replace(' ', '', $iban)), $accountName, $bic);
        }

        $this->paymentInstructions = new PaymentInstructions(
            meansCode: $means,
            meansText: $meansText,
            remittanceInformation: $remittanceInformation,
            creditTransfers: $transfers,
            card: $this->paymentInstructions?->card,
            directDebit: $this->paymentInstructions?->directDebit,
        );

        return $this;
    }

    /**
     * Adds a further bank account (BG-17).
     */
    public function creditTransfer(string $accountId, ?string $bic = null, ?string $accountName = null): static
    {
        $instructions = $this->paymentInstructions ?? new PaymentInstructions(PaymentMeansCode::SepaCreditTransfer);
        $this->paymentInstructions = $instructions->with([
            'creditTransfers' => [...$instructions->creditTransfers, new CreditTransfer(strtoupper(str_replace(' ', '', $accountId)), $accountName, $bic)],
        ]);

        return $this;
    }

    public function paymentCard(string $lastDigits, ?string $holderName = null): static
    {
        $instructions = $this->paymentInstructions ?? new PaymentInstructions(PaymentMeansCode::BankCard);
        $this->paymentInstructions = $instructions->with(['card' => new PaymentCard($lastDigits, $holderName)]);

        return $this;
    }

    public function directDebit(?string $mandateReference, ?string $creditorId = null, ?string $debitedIban = null): static
    {
        $instructions = $this->paymentInstructions ?? new PaymentInstructions(PaymentMeansCode::SepaDirectDebit);
        $this->paymentInstructions = $instructions->with([
            'directDebit' => new DirectDebit($mandateReference, $creditorId, $debitedIban === null ? null : strtoupper(str_replace(' ', '', $debitedIban))),
        ]);

        return $this;
    }

    /**
     * @param  PaymentTerms|string|callable(PaymentTermsBuilder): mixed  $terms
     */
    public function paymentTerms(PaymentTerms|string|callable $terms): static
    {
        if ($terms instanceof PaymentTerms) {
            $this->paymentTerms = $terms;

            return $this;
        }

        if (is_string($terms)) {
            $this->paymentTerms = (new PaymentTermsBuilder)->text($terms);

            return $this;
        }

        $builder = $this->paymentTerms instanceof PaymentTermsBuilder ? $this->paymentTerms : new PaymentTermsBuilder;
        $terms($builder);
        $this->paymentTerms = $builder;

        return $this;
    }

    /**
     * Document level allowance (BG-20) for one VAT category and rate.
     */
    public function allowance(
        VatCategory $category,
        BigDecimal|int|string|null $rate,
        ?string $amount = null,
        ?string $percent = null,
        ?string $reason = null,
        AllowanceReasonCode|RawCode|string|null $reasonCode = null,
        ?string $baseAmount = null,
    ): static {
        $this->allowanceCharges[] = [
            'charge' => false,
            'amount' => $amount,
            'percent' => $percent,
            'base' => $baseAmount,
            'category' => $category,
            'rate' => $rate === null ? null : (string) $rate,
            'reason' => $reason,
            'code' => is_string($reasonCode) ? (AllowanceReasonCode::tryFrom($reasonCode) ?? new RawCode($reasonCode)) : $reasonCode,
        ];

        return $this;
    }

    /**
     * Document level charge (BG-21) for one VAT category and rate.
     */
    public function charge(
        VatCategory $category,
        BigDecimal|int|string|null $rate,
        ?string $amount = null,
        ?string $percent = null,
        ?string $reason = null,
        ChargeReasonCode|RawCode|string|null $reasonCode = null,
        ?string $baseAmount = null,
    ): static {
        $this->allowanceCharges[] = [
            'charge' => true,
            'amount' => $amount,
            'percent' => $percent,
            'base' => $baseAmount,
            'category' => $category,
            'rate' => $rate === null ? null : (string) $rate,
            'reason' => $reason,
            'code' => is_string($reasonCode) ? (ChargeReasonCode::tryFrom($reasonCode) ?? new RawCode($reasonCode)) : $reasonCode,
        ];

        return $this;
    }

    /**
     * Percentage discount on all lines. Split into one allowance per VAT group (specification 7.5).
     */
    public function discountPercent(string $percent, ?string $reason = null, AllowanceReasonCode|RawCode|string|null $reasonCode = AllowanceReasonCode::Discount): static
    {
        $this->splitAllowanceCharges[] = [
            'charge' => false,
            'percent' => $percent,
            'amount' => null,
            'reason' => $reason,
            'code' => is_string($reasonCode) ? (AllowanceReasonCode::tryFrom($reasonCode) ?? new RawCode($reasonCode)) : $reasonCode,
        ];

        return $this;
    }

    /**
     * Absolute discount distributed over all VAT groups in proportion to their line totals (specification 7.5).
     */
    public function discountAmount(string $amount, ?string $reason = null, AllowanceReasonCode|RawCode|string|null $reasonCode = AllowanceReasonCode::Discount): static
    {
        $this->splitAllowanceCharges[] = [
            'charge' => false,
            'percent' => null,
            'amount' => $amount,
            'reason' => $reason,
            'code' => is_string($reasonCode) ? (AllowanceReasonCode::tryFrom($reasonCode) ?? new RawCode($reasonCode)) : $reasonCode,
        ];

        return $this;
    }

    /**
     * Supporting document (BG-24), either embedded ($content) or as external link.
     */
    public function attachment(
        string $id,
        ?string $content = null,
        MimeType|string|null $mimeType = null,
        ?string $filename = null,
        ?string $description = null,
        ?string $externalUri = null,
    ): static {
        $this->attachments[] = new Attachment(
            id: $id,
            description: $description,
            externalUri: $externalUri,
            content: $content,
            mimeType: is_string($mimeType) ? MimeType::from($mimeType) : $mimeType,
            filename: $filename,
        );

        return $this;
    }

    /**
     * @param  InvoiceLine|ProvidesInvoiceLine|callable(LineBuilder): mixed  $line
     */
    public function line(InvoiceLine|ProvidesInvoiceLine|callable $line): static
    {
        if ($line instanceof InvoiceLine) {
            $this->lines[] = $line;

            return $this;
        }

        $builder = new LineBuilder;
        if ($line instanceof ProvidesInvoiceLine) {
            $builder->fromModel($line);
        } else {
            $line($builder);
        }
        $this->lines[] = $builder;

        return $this;
    }

    /**
     * @param  iterable<InvoiceLine|ProvidesInvoiceLine|callable(LineBuilder): mixed>  $lines
     */
    public function lines(iterable $lines): static
    {
        foreach ($lines as $line) {
            $this->line($line);
        }

        return $this;
    }

    /**
     * Amount already paid (BT-113), e.g. received advance payments.
     */
    public function prepaid(string $amount): static
    {
        $this->prepaid = $amount;

        return $this;
    }

    /**
     * Rounding amount (BT-114), e.g. cash rounding. Never use it to fix wrong totals.
     */
    public function rounding(string $amount): static
    {
        $this->rounding = $amount;

        return $this;
    }

    public function extended(ExtendedData $extended): static
    {
        $this->extended = $extended;

        return $this;
    }

    public function xrechnungExtension(XRechnungExtensionData $extension): static
    {
        $this->xrechnungExtension = $extension;

        return $this;
    }

    /**
     * @param  Presentation|callable(Presentation): Presentation  $presentation
     */
    public function presentation(Presentation|callable $presentation): static
    {
        $this->presentation = $presentation instanceof Presentation
            ? $presentation
            : $presentation($this->presentation ?? new Presentation);

        return $this;
    }

    /**
     * Defines a line group for the PDF. Lines join it via LineBuilder::group().
     */
    public function group(string $id, string $title, bool $showSubtotal = true): static
    {
        $presentation = $this->presentation ?? new Presentation;
        $this->presentation = $presentation->with([
            'lineGroups' => [...$presentation->lineGroups, new LineGroup($id, $title, $showSubtotal)],
        ]);

        return $this;
    }

    public function locale(string $locale): static
    {
        $this->meta = $this->meta->with(['locale' => $locale]);

        return $this;
    }

    public function timezone(string $timezone): static
    {
        $this->meta = $this->meta->with(['timezone' => $timezone]);

        return $this;
    }

    public function custom(string $key, mixed $value): static
    {
        $this->meta = $this->meta->with(['custom' => [...$this->meta->custom, $key => $value]]);

        return $this;
    }

    /**
     * Sets a flag that country packs evaluate (e.g. a small amount invoice in Germany).
     */
    public function flag(string $flag, bool $enabled = true): static
    {
        $this->meta = $this->meta->withFlag($flag, $enabled);

        return $this;
    }

    public function source(string $source): static
    {
        $this->meta = $this->meta->with(['source' => $source]);

        return $this;
    }

    public function standard(StandardVersion $version): static
    {
        $this->standardVersion = $version;

        return $this;
    }

    public function build(): InvoiceData
    {
        return $this->assemble(requireLines: true);
    }

    /**
     * Builds without requiring lines, e.g. for payment reminders or account statements.
     * Data without lines can neither be calculated nor serialised as an invoice.
     */
    public function buildDraft(): InvoiceData
    {
        return $this->assemble(requireLines: false);
    }

    /**
     * Starts an invoice from a quote, order confirmation, delivery note or proforma invoice:
     * parties, billable lines, document allowances and charges and payment data are taken
     * over; number and issue date must be set (specification 13).
     */
    public static function fromDocument(DocumentData $document): static
    {
        $builder = static::from($document->toInvoiceDraft()->with(['totals' => null, 'vatBreakdown' => []]));
        $builder->number = '';
        $builder->issueDate = null;
        $builder->notes = [];
        $builder->lines = array_map(
            static fn (InvoiceLine $line): InvoiceLine => $line->with(['netAmount' => null]),
            $document->billableLines(),
        );

        match ($document->type) {
            DocumentType::OrderConfirmation => $builder->references['salesOrderReference'] = $document->number,
            DocumentType::DeliveryNote => $builder->references['despatchAdviceReference'] = $document->number,
            default => null,
        };

        return $builder;
    }

    private function assemble(bool $requireLines): InvoiceData
    {
        $locale = $this->meta->locale;
        $lines = [];
        $counter = 0;
        foreach ($this->lines as $line) {
            $counter++;
            $lines[] = $line instanceof InvoiceLine ? $line : $line->build($this->currency, (string) $counter, $locale);
        }

        if ($lines === [] && $requireLines) {
            throw InvalidInvoiceData::missing('BG-25', 'invoice lines');
        }

        $allowances = $this->existingAllowances;
        $charges = $this->existingCharges;
        foreach ($this->allowanceCharges as $entry) {
            $args = [
                'amount' => Amount::ofNullable($entry['amount'], $this->currency),
                'vatCategory' => $entry['category'],
                'vatRate' => Decimal::ofNullable($entry['rate']),
                'baseAmount' => Amount::ofNullable($entry['base'], $this->currency),
                'percent' => Decimal::ofNullable($entry['percent']),
                'reason' => $entry['reason'],
            ];
            if ($entry['charge']) {
                $charges[] = new DocumentCharge(...$args, reasonCode: $entry['code'] instanceof AllowanceReasonCode ? new RawCode($entry['code']->value) : $entry['code']);
            } else {
                $allowances[] = new DocumentAllowance(...$args, reasonCode: $entry['code'] instanceof ChargeReasonCode ? new RawCode($entry['code']->value) : $entry['code']);
            }
        }

        $splitter = new AllowanceSplitter;
        foreach ($this->splitAllowanceCharges as $entry) {
            $allowances = [...$allowances, ...$splitter->splitAllowance(
                lines: $lines,
                currency: $this->currency,
                percent: Decimal::ofNullable($entry['percent']),
                amount: Amount::ofNullable($entry['amount'], $this->currency),
                reason: $entry['reason'],
                reasonCode: $entry['code'] instanceof ChargeReasonCode ? new RawCode($entry['code']->value) : $entry['code'],
            )];
        }

        $seller = $this->seller instanceof PartyBuilder ? $this->seller->buildSeller() : $this->seller;
        $buyer = $this->buyer instanceof PartyBuilder ? $this->buyer->buildBuyer() : $this->buyer;

        return new InvoiceData(
            number: $this->number,
            issueDate: $this->issueDate ?? Date::today($this->meta->timezone),
            typeCode: $this->typeCode,
            currency: $this->currency,
            seller: $seller ?? throw InvalidInvoiceData::missing('BG-4', 'seller'),
            buyer: $buyer ?? throw InvalidInvoiceData::missing('BG-7', 'buyer'),
            lines: $lines,
            taxCurrency: $this->taxCurrency,
            taxPointDate: $this->taxPointDate,
            taxPointDateCode: $this->taxPointDateCode,
            dueDate: $this->dueDate,
            buyerReference: $this->references['buyerReference'] ?? null,
            projectReference: $this->projectReference,
            contractReference: $this->references['contractReference'] ?? null,
            purchaseOrderReference: $this->references['purchaseOrderReference'] ?? null,
            salesOrderReference: $this->references['salesOrderReference'] ?? null,
            receivingAdviceReference: $this->references['receivingAdviceReference'] ?? null,
            despatchAdviceReference: $this->references['despatchAdviceReference'] ?? null,
            tenderReference: $this->references['tenderReference'] ?? null,
            invoicedObject: $this->invoicedObject,
            buyerAccountingReference: $this->references['buyerAccountingReference'] ?? null,
            paymentTerms: $this->paymentTerms instanceof PaymentTermsBuilder ? $this->paymentTerms->build($this->currency) : $this->paymentTerms,
            notes: $this->notes,
            businessProcess: $this->businessProcess,
            precedingInvoices: $this->precedingInvoices,
            payee: $this->payee,
            sellerTaxRepresentative: $this->taxRepresentative,
            delivery: $this->delivery,
            invoicingPeriod: $this->invoicingPeriod,
            paymentInstructions: $this->paymentInstructions,
            allowances: $allowances,
            charges: $charges,
            attachments: $this->attachments,
            prepaidAmount: $this->money($this->prepaid),
            roundingAmount: $this->money($this->rounding),
            extended: $this->extended,
            xrechnungExtension: $this->xrechnungExtension,
            presentation: $this->presentation,
            meta: $this->meta,
            standardVersion: $this->standardVersion,
        );
    }

    private function reference(string $key, ?string $value): static
    {
        $this->references[$key] = $value;

        return $this;
    }

    private function money(?string $value): ?Money
    {
        return Amount::ofNullable($value, $this->currency);
    }
}
