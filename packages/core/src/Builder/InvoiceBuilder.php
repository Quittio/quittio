<?php

declare(strict_types=1);

namespace Quittio\Core\Builder;

use Brick\Math\BigDecimal;
use Brick\Money\Money;
use Closure;
use DateTimeInterface;
use InvalidArgumentException;
use Quittio\Core\Calculation\AllowanceSplitter;
use Quittio\Core\Calculation\CalculationException;
use Quittio\Core\Calculation\DeclaredAmounts;
use Quittio\Core\Calculation\LargestRemainder;
use Quittio\Core\Calculation\LineCalculator;
use Quittio\Core\Codes\RawCode;
use Quittio\Core\Contracts\ProvidesInvoiceLine;
use Quittio\Core\Contracts\ProvidesParty;
use Quittio\Core\Data\AllowanceCharge\DocumentAllowance;
use Quittio\Core\Data\AllowanceCharge\DocumentAllowanceCharge;
use Quittio\Core\Data\AllowanceCharge\DocumentCharge;
use Quittio\Core\Data\AllowanceCharge\LineAllowanceCharge;
use Quittio\Core\Data\Attachments\Attachment;
use Quittio\Core\Data\Delivery\Delivery;
use Quittio\Core\Data\Delivery\Period;
use Quittio\Core\Data\Extensions\ExtendedData;
use Quittio\Core\Data\Extensions\XRechnungExtensionData;
use Quittio\Core\Data\Identifier;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Data\Lines\InvoiceLine;
use Quittio\Core\Data\Lines\LineVat;
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
use Quittio\Core\Data\Totals\VatBreakdown;
use Quittio\Core\Documents\DocumentData;
use Quittio\Core\Documents\DocumentType;
use Quittio\Core\Enums\AllowanceReasonCode;
use Quittio\Core\Enums\ChargeReasonCode;
use Quittio\Core\Enums\IdentifierScheme;
use Quittio\Core\Enums\InvoiceTypeCode;
use Quittio\Core\Enums\MimeType;
use Quittio\Core\Enums\NoteSubjectCode;
use Quittio\Core\Enums\PaymentMeansCode;
use Quittio\Core\Enums\TaxPointDateCode;
use Quittio\Core\Enums\UnitCode;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Enums\VatExemptionReasonCode;
use Quittio\Core\Exceptions\InvalidInvoiceData;
use Quittio\Core\Standards\StandardVersion;
use Quittio\Core\Support\Amount;
use Quittio\Core\Support\Date;
use Quittio\Core\Support\Decimal;
use Quittio\Core\Support\Macroable;
use Quittio\Core\Tax\TaxPreset;

/**
 * Fluent builder for InvoiceData.
 *
 * Amounts, prices and quantities are passed as strings or integers, never as floats.
 * The result is not yet calculated; totals and the VAT breakdown are set by the calculator.
 */
class InvoiceBuilder
{
    use Macroable;

    /** Payment means codes (UNTDID 4461) of credit transfers, payment cards and direct debits. */
    private const array CREDIT_TRANSFER_MEANS = ['30', '42', '58'];

    private const array CARD_MEANS = ['48', '54', '55'];

    private const array DIRECT_DEBIT_MEANS = ['49', '59'];

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

    private PayeeParty|PartyBuilder|null $payee = null;

    private ?TaxRepresentativeParty $taxRepresentative = null;

    private ?Delivery $delivery = null;

    private ?Period $invoicingPeriod = null;

    private ?PaymentInstructions $paymentInstructions = null;

    /** @var list<array{charge: bool, amount: ?string, percent: ?string, base: ?string, category: VatCategory, rate: ?string, reason: ?string, code: AllowanceReasonCode|ChargeReasonCode|RawCode|null, exemptionReason: ?string, exemptionCode: VatExemptionReasonCode|RawCode|null}> */
    private array $allowanceCharges = [];

    /** @var list<array{charge: bool, percent: ?string, amount: ?string, reason: ?string, code: AllowanceReasonCode|ChargeReasonCode|RawCode|null}> */
    private array $splitAllowanceCharges = [];

    /** @var list<Attachment> */
    private array $attachments = [];

    /** @var list<LineBuilder|InvoiceLine> */
    private array $lines = [];

    /** VAT treatment of lines that set none, see defaultVat(). */
    private ?LineBuilder $defaultVat = null;

    /** @var (Closure(?string, bool): ?TaxPreset)|TaxPreset|null see useDefaultVat() */
    private static Closure|TaxPreset|null $applicationVat = null;

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
     *
     * For data with a declared VAT total in accounting currency (BT-111) but without exchange
     * rate (e.g. read from XML) the implied rate is taken over, so that BT-111 is recalculated.
     * Once lines, allowances, charges or the VAT accounting currency (BT-6) change, the implied
     * rate is dropped: it is only as precise as the declared amounts and holds only for the
     * declared currency, so set the rate with exchangeRate() (BR-53 otherwise).
     * Percentage document allowances and charges on the line sum of their VAT group, and
     * discounts split over the VAT groups (discountAmount(), discountPercent()), follow the
     * lines when lines are added, changed or removed.
     *
     * As long as nothing amount-relevant changes, declared line net amounts and the VAT breakdown
     * that the recalculation does not reproduce (e.g. rounded per line by another system) are kept
     * by the calculator (Meta::FLAG_DECLARED_AMOUNTS with a fingerprint of the amount inputs).
     * Data flagged earlier whose amount inputs no longer match its fingerprint (e.g. changed with
     * with() or in stored JSON) is treated as changed lines and recalculated.
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
        $withRate = $data->withImpliedExchangeRate();
        $builder->meta = $withRate->meta;
        $builder->impliedExchangeRate = $data->meta->exchangeRate === null && $withRate->meta->exchangeRate !== null;
        $stale = DeclaredAmounts::isStale($data);
        $builder->declaredAmounts = ! $stale && DeclaredAmounts::needed($data);
        $builder->linesChanged = $stale;
        $builder->declaredBreakdown = $data->vatBreakdown;
        $builder->declaredCurrency = $data->currency;
        $builder->declaredTaxCurrency = $data->taxCurrency;
        $builder->standardVersion = $data->standardVersion;
        $builder->existingAllowances = $data->allowances;
        $builder->existingCharges = $data->charges;

        $sums = self::groupLineSums($data->lines, $data->currency);
        $builder->derivedAllowanceBases = array_map(static fn (DocumentAllowance $a): bool => self::hasDerivedBase($a, $sums), $data->allowances);
        $builder->derivedChargeBases = array_map(static fn (DocumentCharge $c): bool => self::hasDerivedBase($c, $sums), $data->charges);
        $builder->splitDiscounts = self::splitDiscounts($data->allowances, $sums);

        return $builder;
    }

    /** Declared amounts of the data given to from() are kept until amounts change. */
    private bool $declaredAmounts = false;

    /** @var list<VatBreakdown> */
    private array $declaredBreakdown = [];

    private ?string $declaredCurrency = null;

    /** VAT accounting currency (BT-6) of the data given to from(), for which an implied rate holds. */
    private ?string $declaredTaxCurrency = null;

    /** The exchange rate was implied by from() from BT-111 / BT-110. */
    private bool $impliedExchangeRate = false;

    /**
     * Existing allowances that a discount split over the VAT groups produced, see {@see self::splitDiscounts()}.
     *
     * @var list<array{indices: list<int>, percent: ?BigDecimal, amount: ?BigDecimal, reason: ?string, code: AllowanceReasonCode|RawCode|null}>
     */
    private array $splitDiscounts = [];

    /** @var list<DocumentAllowance> */
    private array $existingAllowances = [];

    /** @var list<DocumentCharge> */
    private array $existingCharges = [];

    /**
     * Whether the existing allowance or charge with the same index is a percentage of the line
     * sum of its VAT group (BT-93 derived by the calculator), see {@see self::from()}.
     *
     * @var list<bool>
     */
    private array $derivedAllowanceBases = [];

    /** @var list<bool> */
    private array $derivedChargeBases = [];

    private bool $linesChanged = false;

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
    public function taxCurrency(string $currency, BigDecimal|int|float|string|null $exchangeRate = null, Date|DateTimeInterface|string|null $rateDate = null): static
    {
        $this->taxCurrency = strtoupper($currency);
        if ($exchangeRate !== null) {
            $this->exchangeRate($exchangeRate, $rateDate);
        }

        return $this;
    }

    public function exchangeRate(BigDecimal|int|float|string $rate, Date|DateTimeInterface|string|null $date = null): static
    {
        $this->impliedExchangeRate = false;
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
     * The seller (BG-4): a SellerParty, a PartyBuilder (Party::make()) or an application model
     * (ProvidesParty) replace the seller set before; a callable receives the builder of the
     * seller set before and changes it.
     *
     * @param  SellerParty|PartyBuilder|ProvidesParty|callable(PartyBuilder): mixed  $seller
     */
    public function seller(SellerParty|PartyBuilder|ProvidesParty|callable $seller): static
    {
        if ($seller instanceof SellerParty || $seller instanceof PartyBuilder || $seller instanceof ProvidesParty) {
            $this->seller = $seller instanceof SellerParty ? $seller : self::partyBuilder($seller);

            return $this;
        }

        $builder = $this->seller instanceof SellerParty ? PartyBuilder::fromSeller($this->seller) : ($this->seller ?? new PartyBuilder);
        $seller($builder);
        $this->seller = $builder;

        return $this;
    }

    /**
     * The buyer (BG-7): a BuyerParty, a PartyBuilder (Party::make()) or an application model
     * (ProvidesParty), e.g. ->buyer($customer), replace the buyer set before; a callable
     * receives the builder of the buyer set before and changes it.
     *
     * @param  BuyerParty|PartyBuilder|ProvidesParty|callable(PartyBuilder): mixed  $buyer
     */
    public function buyer(BuyerParty|PartyBuilder|ProvidesParty|callable $buyer): static
    {
        if ($buyer instanceof BuyerParty || $buyer instanceof PartyBuilder || $buyer instanceof ProvidesParty) {
            $this->buyer = $buyer instanceof BuyerParty ? $buyer : self::partyBuilder($buyer);

            return $this;
        }

        $builder = $this->buyer instanceof BuyerParty ? PartyBuilder::fromBuyer($this->buyer) : ($this->buyer ?? new PartyBuilder);
        $buyer($builder);
        $this->buyer = $builder;

        return $this;
    }

    /**
     * Payee (BG-10), if different from the seller (e.g. a factoring company): a PayeeParty, the
     * name with the identifier (BT-60) and the legal registration identifier (BT-61) and their
     * schemes, or a callable that receives a PartyBuilder (name(), identifier(), legalRegistration())
     * and, like seller() and buyer(), continues an earlier payee.
     *
     * @param  PayeeParty|string|callable(PartyBuilder): mixed  $payee
     *
     * @throws InvalidArgumentException for identifiers or schemes next to a PayeeParty or a callable
     */
    public function payee(
        PayeeParty|string|callable $payee,
        ?string $identifier = null,
        ?string $legalRegistration = null,
        IdentifierScheme|RawCode|string|null $identifierScheme = null,
        IdentifierScheme|RawCode|string|null $legalRegistrationScheme = null,
    ): static {
        if (is_string($payee)) {
            $this->payee = new PayeeParty(
                $payee,
                $identifier === null ? null : Identifier::of($identifier, $identifierScheme),
                $legalRegistration === null ? null : Identifier::of($legalRegistration, $legalRegistrationScheme),
            );

            return $this;
        }
        if ($identifier !== null || $legalRegistration !== null || $identifierScheme !== null || $legalRegistrationScheme !== null) {
            throw new InvalidArgumentException('Identifiers and schemes of the payee are given with its name only; set them on the PayeeParty or with the PartyBuilder of the callable.');
        }
        if ($payee instanceof PayeeParty) {
            $this->payee = $payee;

            return $this;
        }

        $builder = $this->payee instanceof PayeeParty ? PartyBuilder::fromPayee($this->payee) : ($this->payee ?? new PartyBuilder);
        $payee($builder);
        $this->payee = $builder;

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
     *
     * A second call replaces the payment instructions: an account given here replaces the
     * accounts, and data of other payment means is dropped (e.g. bank accounts when switching
     * to direct debit). Without an account, existing accounts are kept for credit transfers
     * (e.g. a seller default account); likewise card (BG-18) and direct debit (BG-19) data for
     * card payments and direct debits. The remittance information (BT-83) is kept unless a new
     * one is given. Further accounts are added with creditTransfer().
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
        $previous = $this->paymentInstructions;
        $code = (string) $means->value;

        $transfers = match (true) {
            $iban !== null => [new CreditTransfer(strtoupper(str_replace(' ', '', $iban)), $accountName, $bic)],
            in_array($code, self::CREDIT_TRANSFER_MEANS, true) => $previous === null ? [] : $previous->creditTransfers,
            default => [],
        };

        $this->paymentInstructions = new PaymentInstructions(
            meansCode: $means,
            meansText: $meansText,
            remittanceInformation: $remittanceInformation ?? $previous?->remittanceInformation,
            creditTransfers: $transfers,
            card: in_array($code, self::CARD_MEANS, true) ? $previous?->card : null,
            directDebit: in_array($code, self::DIRECT_DEBIT_MEANS, true) ? $previous?->directDebit : null,
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
     * Payment terms (BT-20): a PaymentTerms object or a text replace earlier terms; a callable
     * receives a PaymentTermsBuilder that continues the earlier terms, also those of a
     * PaymentTerms object or of from(), like seller() and buyer().
     *
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

        $builder = $this->paymentTerms instanceof PaymentTerms ? PaymentTermsBuilder::fromTerms($this->paymentTerms) : ($this->paymentTerms ?? new PaymentTermsBuilder);
        $terms($builder);
        $this->paymentTerms = $builder;

        return $this;
    }

    /**
     * Document level allowance (BG-20) for one VAT category and rate.
     *
     * The exemption reason (BT-120/BT-121) is used for the VAT breakdown when no invoice
     * line of the same VAT group provides one, e.g. an exempt charge on a standard rated invoice.
     */
    public function allowance(
        VatCategory $category,
        BigDecimal|int|float|string|null $rate,
        ?string $amount = null,
        ?string $percent = null,
        ?string $reason = null,
        AllowanceReasonCode|RawCode|string|null $reasonCode = null,
        ?string $baseAmount = null,
        ?string $exemptionReason = null,
        VatExemptionReasonCode|RawCode|string|null $exemptionReasonCode = null,
    ): static {
        $this->allowanceCharges[] = [
            'charge' => false,
            'amount' => $amount,
            'percent' => $percent,
            'base' => $baseAmount,
            'category' => $category,
            'rate' => $rate === null ? null : (string) Decimal::rejectFloat($rate),
            'reason' => $reason,
            'code' => is_string($reasonCode) ? (AllowanceReasonCode::tryFrom($reasonCode) ?? new RawCode($reasonCode)) : $reasonCode,
            'exemptionReason' => $exemptionReason,
            'exemptionCode' => is_string($exemptionReasonCode) ? (VatExemptionReasonCode::tryFrom($exemptionReasonCode) ?? new RawCode($exemptionReasonCode)) : $exemptionReasonCode,
        ];

        return $this;
    }

    /**
     * Document level charge (BG-21) for one VAT category and rate. See allowance() for the exemption reason.
     */
    public function charge(
        VatCategory $category,
        BigDecimal|int|float|string|null $rate,
        ?string $amount = null,
        ?string $percent = null,
        ?string $reason = null,
        ChargeReasonCode|RawCode|string|null $reasonCode = null,
        ?string $baseAmount = null,
        ?string $exemptionReason = null,
        VatExemptionReasonCode|RawCode|string|null $exemptionReasonCode = null,
    ): static {
        $this->allowanceCharges[] = [
            'charge' => true,
            'amount' => $amount,
            'percent' => $percent,
            'base' => $baseAmount,
            'category' => $category,
            'rate' => $rate === null ? null : (string) Decimal::rejectFloat($rate),
            'reason' => $reason,
            'code' => is_string($reasonCode) ? (ChargeReasonCode::tryFrom($reasonCode) ?? new RawCode($reasonCode)) : $reasonCode,
            'exemptionReason' => $exemptionReason,
            'exemptionCode' => is_string($exemptionReasonCode) ? (VatExemptionReasonCode::tryFrom($exemptionReasonCode) ?? new RawCode($exemptionReasonCode)) : $exemptionReasonCode,
        ];

        return $this;
    }

    /**
     * Percentage discount on all lines. Split into one allowance per VAT group.
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
     * Absolute discount distributed over all VAT groups in proportion to their line totals.
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
     * Adds a line (BG-25): a name with the common values (->line('Consulting', netPrice: '120.00',
     * quantity: 4, unit: UnitCode::Hour)), a LineBuilder (Line::make()), an array with the keys
     * of LineBuilder::ARRAY_KEYS, an application model (ProvidesInvoiceLine), a callable that
     * receives a new LineBuilder, or a finished InvoiceLine.
     *
     * @param  InvoiceLine|LineBuilder|ProvidesInvoiceLine|array<array-key, mixed>|callable(LineBuilder): mixed|string  $line
     */
    public function line(
        InvoiceLine|LineBuilder|ProvidesInvoiceLine|array|callable|string $line,
        BigDecimal|int|float|string|null $netPrice = null,
        BigDecimal|int|float|string|null $quantity = null,
        UnitCode|RawCode|string|null $unit = null,
        TaxPreset|VatCategory|null $vat = null,
        ?string $description = null,
        BigDecimal|int|float|string|null $priceInclVat = null,
    ): static {
        $this->linesChanged = true;
        if (! $line instanceof InvoiceLine) {
            $this->lines[] = Line::from($line, $netPrice, $quantity, $unit, $vat, $description, $priceInclVat);

            return $this;
        }
        if ($netPrice !== null || $quantity !== null || $unit !== null || $vat !== null || $description !== null || $priceInclVat !== null) {
            throw new InvalidArgumentException('An InvoiceLine is taken as it is; change it with InvoiceLine::with() or, after from(), with updateLine().');
        }
        $this->lines[] = $line;

        return $this;
    }

    /**
     * @param  iterable<InvoiceLine|LineBuilder|ProvidesInvoiceLine|array<array-key, mixed>|callable(LineBuilder): mixed|string>  $lines
     */
    public function lines(iterable $lines): static
    {
        foreach ($lines as $line) {
            $this->line($line);
        }

        return $this;
    }

    /**
     * Changes an existing line, e.g. of a correction draft started with {@see self::from()}:
     * $builder->updateLine('2', fn (InvoiceLine $l) => $l->withNetPrice('80.00')).
     * The calculated line net amount is reset, and percentage allowances and charges on the
     * line amount are recalculated from the new amount.
     *
     * @param  callable(InvoiceLine): InvoiceLine  $change
     */
    public function updateLine(string $id, callable $change): static
    {
        $found = false;
        $lines = [];
        foreach ($this->lines as $line) {
            if (! $found && $line instanceof InvoiceLine && $line->id === $id) {
                $line = self::withPercentagesReset($change($line), self::lineBaseAmount($line))->with(['netAmount' => null]);
                $found = true;
            }
            $lines[] = $line;
        }
        if (! $found) {
            throw new InvalidArgumentException(sprintf('The invoice has no line "%s".', $id));
        }
        $this->lines = $lines;
        $this->linesChanged = true;

        return $this;
    }

    /**
     * Removes an existing line by its identifier (BT-126).
     */
    public function removeLine(string $id): static
    {
        $lines = array_values(array_filter($this->lines, static fn (InvoiceLine|LineBuilder $line): bool => ! $line instanceof InvoiceLine || $line->id !== $id));
        if (count($lines) < count($this->lines)) {
            $this->lines = $lines;
            $this->linesChanged = true;

            return $this;
        }

        throw new InvalidArgumentException(sprintf('The invoice has no line "%s".', $id));
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
     * Adds to the amount already paid (BT-113), e.g. one of several advance payments.
     */
    public function addPrepaid(string $amount): static
    {
        $this->prepaid = (string) Decimal::of($this->prepaid ?? '0')->plus(Decimal::of($amount));

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

    /**
     * VAT treatment of the lines that set none (also sub lines), with the arguments of
     * LineBuilder::vat(). Without it, the application default of useDefaultVat() applies.
     */
    public function defaultVat(TaxPreset|VatCategory $presetOrCategory, BigDecimal|int|float|string|null $rate = null, ?string $exemptionReason = null, VatExemptionReasonCode|RawCode|string|null $exemptionReasonCode = null): static
    {
        $this->defaultVat = (new LineBuilder)->vat($presetOrCategory, $rate, $exemptionReason, $exemptionReasonCode);

        return $this;
    }

    /**
     * Application default VAT of lines that set none, for invoices without defaultVat(): a
     * preset, or a callable that receives the seller's country code and whether the invoice is
     * flagged as small business invoice (Meta::FLAG_SMALL_BUSINESS) and returns a preset or null,
     * e.g. Quittio\Core\Tax\CountryDefaultVat. The Laravel integration sets it from
     * quittio.defaults.vat; null removes it. Lines without VAT and without a default make
     * build() fail (BG-30).
     *
     * @param  (callable(?string, bool): ?TaxPreset)|TaxPreset|null  $default
     */
    public static function useDefaultVat(callable|TaxPreset|null $default): void
    {
        self::$applicationVat = $default instanceof TaxPreset || $default === null ? $default : $default(...);
    }

    public function build(): InvoiceData
    {
        return $this->assemble(requireLines: true);
    }

    /**
     * Builds without requiring lines, e.g. for payment reminders or account statements, or
     * for drafts. Data without lines can be calculated (its line sum is zero; document
     * allowances and charges still count), but it is not a valid invoice: EN 16931 requires
     * at least one line (BR-16), so validation reports it.
     */
    public function buildDraft(): InvoiceData
    {
        return $this->assemble(requireLines: false);
    }

    /**
     * Starts an invoice from a quote, order confirmation, delivery note or proforma invoice:
     * parties, billable lines, document allowances and charges and payment data are taken
     * over; number and issue date must be set.
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
        $builder->linesChanged = true;

        match ($document->type) {
            DocumentType::OrderConfirmation => $builder->references['salesOrderReference'] = $document->number,
            DocumentType::DeliveryNote => $builder->references['despatchAdviceReference'] = $document->number,
            default => null,
        };

        return $builder;
    }

    /**
     * The VAT treatment for lines without one: defaultVat(), otherwise the application default.
     */
    private function vatDefault(): ?LineBuilder
    {
        if ($this->defaultVat !== null) {
            return $this->defaultVat;
        }
        $default = self::$applicationVat;
        if ($default instanceof Closure) {
            $country = $this->seller instanceof SellerParty ? $this->seller->address->countryCode : $this->seller?->countryCode();
            $default = $default($country, $this->meta->hasFlag(Meta::FLAG_SMALL_BUSINESS));
        }

        return $default instanceof TaxPreset ? (new LineBuilder)->vat($default) : null;
    }

    /**
     * The builder of a party given to seller() or buyer(): a builder is copied, so a party used
     * as template stays unchanged.
     */
    private static function partyBuilder(PartyBuilder|ProvidesParty $party): PartyBuilder
    {
        return $party instanceof PartyBuilder ? clone $party : (new PartyBuilder)->fromModel($party);
    }

    /**
     * Identifiers of the lines and their sub lines.
     *
     * @param  list<InvoiceLine>  $lines
     * @return list<string>
     */
    private static function lineIds(array $lines): array
    {
        $ids = [];
        foreach ($lines as $line) {
            $ids = [...$ids, $line->id, ...self::lineIds($line->subLines)];
        }

        return $ids;
    }

    private function assemble(bool $requireLines): InvoiceData
    {
        $locale = $this->meta->locale;
        // Default line identifiers (BT-126) count the position and skip the identifiers of
        // existing lines and those set with id(), which must stay unique, e.g. after from() and
        // removeLine().
        $taken = [];
        foreach ($this->lines as $line) {
            $id = $line instanceof InvoiceLine ? $line->id : $line->explicitId();
            if ($id !== null) {
                $taken[$id] = true;
            }
        }
        $vatDefault = null;
        foreach ($this->lines as $line) {
            if ($line instanceof LineBuilder && $line->lacksVat()) {
                $vatDefault = $this->vatDefault();
                break;
            }
        }
        $lines = [];
        foreach ($this->lines as $index => $line) {
            if (! $line instanceof InvoiceLine) {
                $default = $index + 1;
                if ($line->explicitId() === null) {
                    while (isset($taken[(string) $default])) {
                        $default++;
                    }
                }
                $line = $line->build($this->currency, (string) $default, $locale, $vatDefault);
                $taken[$line->id] = true;
            }
            $lines[] = $line;
        }
        $seen = [];
        foreach (self::lineIds($lines) as $id) {
            if (isset($seen[$id])) {
                throw InvalidInvoiceData::duplicateLineId($id);
            }
            $seen[$id] = true;
        }

        if ($lines === [] && $requireLines) {
            throw InvalidInvoiceData::missing('BG-25', 'invoice lines');
        }

        $allowances = $this->existingAllowances;
        $charges = $this->existingCharges;
        $splitter = new AllowanceSplitter;
        if ($this->linesChanged) {
            // Percentages of the group line sum are recalculated from the changed lines.
            foreach ($allowances as $index => $allowance) {
                $allowances[$index] = ($this->derivedAllowanceBases[$index] ?? false) ? $allowance->with(['amount' => null, 'baseAmount' => null]) : $allowance;
            }
            foreach ($charges as $index => $charge) {
                $charges[$index] = ($this->derivedChargeBases[$index] ?? false) ? $charge->with(['amount' => null, 'baseAmount' => null]) : $charge;
            }
            // Discounts split over the VAT groups are split again over the changed lines.
            $resplit = [];
            foreach ($this->splitDiscounts as $split) {
                foreach ($split['indices'] as $index) {
                    unset($allowances[$index]);
                }
                $resplit = [...$resplit, ...$splitter->splitAllowance(
                    lines: $lines,
                    currency: $this->currency,
                    percent: $split['percent'],
                    amount: $split['amount'] === null ? null : Amount::fromDecimal($split['amount'], $this->currency),
                    reason: $split['reason'],
                    reasonCode: $split['code'],
                )];
            }
            $allowances = [...array_values($allowances), ...$resplit];
        }
        foreach ($this->allowanceCharges as $entry) {
            $args = [
                'amount' => Amount::ofNullable($entry['amount'], $this->currency),
                'vatCategory' => $entry['category'],
                'vatRate' => Decimal::ofNullable($entry['rate']),
                'baseAmount' => Amount::ofNullable($entry['base'], $this->currency),
                'percent' => Decimal::ofNullable($entry['percent']),
                'reason' => $entry['reason'],
                'exemptionReason' => $entry['exemptionReason'],
                'exemptionReasonCode' => $entry['exemptionCode'],
            ];
            if ($entry['charge']) {
                $charges[] = new DocumentCharge(...$args, reasonCode: $entry['code'] instanceof AllowanceReasonCode ? new RawCode($entry['code']->value) : $entry['code']);
            } else {
                $allowances[] = new DocumentAllowance(...$args, reasonCode: $entry['code'] instanceof ChargeReasonCode ? new RawCode($entry['code']->value) : $entry['code']);
            }
        }

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

        // Declared amounts (from()) stay as long as no amount-relevant input changed; an implied
        // exchange rate is not applied to changed amounts or to another VAT accounting currency.
        $amountsChanged = $this->linesChanged || $this->allowanceCharges !== [] || $this->splitAllowanceCharges !== []
            || $this->currency !== ($this->declaredCurrency ?? $this->currency);
        $keepDeclared = $this->declaredAmounts && ! $amountsChanged;
        // Unchanged data keeps the information that declared amounts had to be recalculated, and
        // that the VAT of a partial cancellation is aligned with the earlier ones.
        $meta = $this->meta->withFlag(Meta::FLAG_DECLARED_AMOUNTS, $keepDeclared)->withAmountsFingerprint(null)
            ->withFlag(Meta::FLAG_DECLARED_AMOUNTS_RECALCULATED, ! $amountsChanged && $this->meta->hasFlag(Meta::FLAG_DECLARED_AMOUNTS_RECALCULATED))
            ->withFlag(Meta::FLAG_CANCELLATION_VAT_ALIGNED, ! $amountsChanged && $this->meta->hasFlag(Meta::FLAG_CANCELLATION_VAT_ALIGNED))
            ->withDeclaredVatInTaxCurrency($keepDeclared ? $this->meta->declaredVatInTaxCurrency() : null);
        if ($amountsChanged) {
            // Changed data is recalculated completely: line net amounts of the previous data must
            // not be taken as declared later (e.g. by from() of a stored draft).
            $lines = self::withoutNetAmounts($lines);
        }
        if ($this->impliedExchangeRate && ($amountsChanged || $this->taxCurrency !== $this->declaredTaxCurrency)) {
            $meta = $meta->with(['exchangeRate' => null, 'exchangeRateDate' => null]);
        }

        $invoice = new InvoiceData(
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
            payee: $this->payee instanceof PartyBuilder ? $this->payee->buildPayee() : $this->payee,
            sellerTaxRepresentative: $this->taxRepresentative,
            delivery: $this->delivery,
            invoicingPeriod: $this->invoicingPeriod,
            paymentInstructions: $this->paymentInstructions,
            allowances: $allowances,
            charges: $charges,
            attachments: $this->attachments,
            prepaidAmount: $this->money($this->prepaid),
            roundingAmount: $this->money($this->rounding),
            vatBreakdown: $keepDeclared ? $this->declaredBreakdown : [],
            extended: $this->extended,
            xrechnungExtension: $this->xrechnungExtension,
            presentation: $this->presentation,
            meta: $meta,
            standardVersion: $this->standardVersion,
        );

        // Amounts taken over from the data given to from() belong to their inputs (fingerprint).
        return DeclaredAmounts::hasAmounts($invoice) ? $invoice->with(['meta' => $meta->withAmountsFingerprint(DeclaredAmounts::fingerprint($invoice))]) : $invoice;
    }

    /**
     * @param  list<InvoiceLine>  $lines
     * @return list<InvoiceLine>
     */
    private static function withoutNetAmounts(array $lines): array
    {
        return array_map(static fn (InvoiceLine $line): InvoiceLine => $line->with(['netAmount' => null, 'subLines' => self::withoutNetAmounts($line->subLines)]), $lines);
    }

    /**
     * Existing allowances produced by a discount split over the VAT groups (discountAmount(),
     * discountPercent()): at least two allowances with the same reason, one per VAT group, whose
     * amounts are the largest remainder distribution of their total over the group line sums.
     * A percentage split needs one percentage and the total round2(line sum × percentage); it is
     * split again like discountPercent(), so that lines of a new VAT group get the discount and a
     * VAT group without lines keeps no allowance of 0.00.
     *
     * @param  list<DocumentAllowance>  $allowances
     * @param  array<string, BigDecimal>|null  $sums
     * @return list<array{indices: list<int>, percent: ?BigDecimal, amount: ?BigDecimal, reason: ?string, code: AllowanceReasonCode|RawCode|null}>
     */
    private static function splitDiscounts(array $allowances, ?array $sums): array
    {
        if ($sums === null || count($sums) < 2) {
            return [];
        }
        $lineSum = array_reduce($sums, static fn (BigDecimal $carry, BigDecimal $sum): BigDecimal => $carry->plus($sum), BigDecimal::zero());
        if ($lineSum->isZero()) {
            return [];
        }

        $candidates = [];
        foreach ($allowances as $index => $allowance) {
            $code = $allowance->reasonCode;
            if ($allowance->amount !== null && ($code === null || $code instanceof AllowanceReasonCode || $code instanceof RawCode)) {
                $candidates[($allowance->reason ?? '')."\0".($allowance->reasonCodeValue() ?? '')][] = $index;
            }
        }

        $result = [];
        foreach ($candidates as $indices) {
            $parts = [];
            $percents = [];
            foreach ($indices as $index) {
                $allowance = $allowances[$index];
                $key = LineVat::key($allowance->vatCategory, $allowance->vatRate);
                if (isset($parts[$key]) || $allowance->amount === null) {
                    continue 2;
                }
                $parts[$key] = $allowance->amount->getAmount();
                if ($allowance->percent !== null) {
                    $percents[$allowance->percent->strippedOfTrailingZeros()->toString()] = $allowance->percent;
                }
            }
            $total = array_reduce($parts, static fn (BigDecimal $carry, BigDecimal $part): BigDecimal => $carry->plus($part), BigDecimal::zero());
            $expected = array_filter(LargestRemainder::distribute($total, $sums), static fn (BigDecimal $part): bool => ! $part->isZero());
            $percent = count($percents) === 1 ? array_values($percents)[0] : null;
            if (count($parts) < 2 || count($percents) > 1 || array_diff_key($parts, $expected) !== [] || array_diff_key($expected, $parts) !== []
                || array_filter($expected, static fn (BigDecimal $part, string $key): bool => ! $part->isEqualTo($parts[$key]), ARRAY_FILTER_USE_BOTH) !== []
                || ($percent !== null && ! Decimal::round($lineSum->multipliedBy($percent)->dividedBy(100, 10, \Brick\Math\RoundingMode::HalfUp), 2)->isEqualTo($total))) {
                continue;
            }

            $first = $allowances[$indices[0]];
            $code = $first->reasonCode;
            $result[] = [
                'indices' => $indices,
                'percent' => $percent,
                'amount' => $percent === null ? $total : null,
                'reason' => $first->reason,
                'code' => $code instanceof AllowanceReasonCode || $code instanceof RawCode ? $code : null,
            ];
        }

        return $result;
    }

    /**
     * Line sums per VAT group. Lines without net amount (e.g. of a cancellation, whose amounts
     * are recalculated) are calculated; null if a line cannot be calculated.
     *
     * @param  list<InvoiceLine>  $lines
     * @return array<string, BigDecimal>|null
     */
    private static function groupLineSums(array $lines, string $currency): ?array
    {
        $calculator = new LineCalculator;
        $sums = [];
        foreach ($lines as $line) {
            try {
                $amount = $line->netAmount ?? $calculator->calculate($line, $currency)->netAmount;
            } catch (CalculationException) {
                return null;
            }
            if ($amount === null) {
                return null;
            }
            $key = $line->vat->groupKey();
            $sums[$key] = ($sums[$key] ?? BigDecimal::zero())->plus($amount->getAmount());
        }

        return $sums;
    }

    /**
     * A percentage without base amount, or with the line sum of its VAT group as base amount.
     *
     * @param  array<string, BigDecimal>|null  $sums
     */
    private static function hasDerivedBase(DocumentAllowanceCharge $entry, ?array $sums): bool
    {
        if ($entry->percent === null) {
            return false;
        }
        if ($entry->baseAmount === null) {
            return true;
        }
        if ($sums === null) {
            return false;
        }
        // A VAT group without lines has the line sum zero, as in the calculator.
        $sum = $sums[LineVat::key($entry->vatCategory, $entry->vatRate)] ?? BigDecimal::zero();

        return $entry->baseAmount->getAmount()->isEqualTo($sum);
    }

    /**
     * Line amount before allowances and charges (BT-129 × BT-146 / BT-149), null without price.
     */
    private static function lineBaseAmount(InvoiceLine $line): ?BigDecimal
    {
        $price = $line->price;
        $netPrice = $price->netPrice ?? ($price->grossPrice === null ? null : $price->grossPrice->minus($price->discount ?? BigDecimal::zero()));

        return $netPrice === null ? null : LineCalculator::baseAmount($line->quantity, $netPrice, $price->baseQuantity);
    }

    /**
     * Percentage allowances and charges of a changed line: the amount is recalculated, and a base
     * amount that equals the previous line amount (set by the calculator) follows the new one.
     */
    private static function withPercentagesReset(InvoiceLine $line, ?BigDecimal $previousBase): InvoiceLine
    {
        $reset = static function (LineAllowanceCharge $entry) use ($previousBase): LineAllowanceCharge {
            if ($entry->percent === null) {
                return $entry;
            }
            $derived = $entry->baseAmount === null
                || ($previousBase !== null && $entry->baseAmount->getAmount()->isEqualTo($previousBase));

            return $entry->with(['amount' => null, 'baseAmount' => $derived ? null : $entry->baseAmount]);
        };

        return $line->with([
            'allowances' => array_map($reset, $line->allowances),
            'charges' => array_map($reset, $line->charges),
        ]);
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
