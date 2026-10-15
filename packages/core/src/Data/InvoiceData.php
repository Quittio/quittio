<?php

declare(strict_types=1);

namespace Quittio\Core\Data;

use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Quittio\Core\Codes\RawCode;
use Quittio\Core\Data\AllowanceCharge\DocumentAllowance;
use Quittio\Core\Data\AllowanceCharge\DocumentCharge;
use Quittio\Core\Data\Attachments\Attachment;
use Quittio\Core\Data\Concerns\Immutable;
use Quittio\Core\Data\Delivery\Delivery;
use Quittio\Core\Data\Delivery\Period;
use Quittio\Core\Data\Extensions\ExtendedData;
use Quittio\Core\Data\Extensions\XRechnungExtensionData;
use Quittio\Core\Data\Lines\InvoiceLine;
use Quittio\Core\Data\Party\BuyerParty;
use Quittio\Core\Data\Party\PayeeParty;
use Quittio\Core\Data\Party\SellerParty;
use Quittio\Core\Data\Party\TaxRepresentativeParty;
use Quittio\Core\Data\Payment\PaymentInstructions;
use Quittio\Core\Data\Payment\PaymentTerms;
use Quittio\Core\Data\Presentation\Presentation;
use Quittio\Core\Data\References\PrecedingInvoiceReference;
use Quittio\Core\Data\References\ProjectReference;
use Quittio\Core\Data\Totals\Totals;
use Quittio\Core\Data\Totals\VatBreakdown;
use Quittio\Core\Enums\InvoiceTypeCode;
use Quittio\Core\Enums\TaxPointDateCode;
use Quittio\Core\Exceptions\InvalidArgumentException;
use Quittio\Core\Mapping\Attributes as Q;
use Quittio\Core\Profiles\ProfileLevel;
use Quittio\Core\Serialization\ArrayHydrator;
use Quittio\Core\Serialization\ArrayNormalizer;
use Quittio\Core\Serialization\Snapshots\SnapshotUpcasterChain;
use Quittio\Core\Serialization\Snapshots\SnapshotVersionException;
use Quittio\Core\Standards\StandardVersion;
use Quittio\Core\Support\Date;
use Quittio\Core\Testing\InvoiceFactory;

/**
 * Root of the semantic invoice model (EN 16931, superset of the 2017 and 2026 versions).
 *
 * The specification identifier (BT-24) is not part of the data: it is derived from the profile.
 * Totals (BG-22) and the VAT breakdown (BG-23) are set by the calculator.
 */
final readonly class InvoiceData
{
    use Immutable;

    /** Schema version of serialised snapshots, used by upcasters. */
    public const int SNAPSHOT_VERSION = 1;

    /**
     * @param  list<InvoiceLine>  $lines
     * @param  list<Note>  $notes
     * @param  list<PrecedingInvoiceReference>  $precedingInvoices
     * @param  list<DocumentAllowance>  $allowances
     * @param  list<DocumentCharge>  $charges
     * @param  list<VatBreakdown>  $vatBreakdown
     * @param  list<Attachment>  $attachments
     */
    public function __construct(
        #[Q\Bt('BT-1'), Q\Cardinality('1'), Q\Level(ProfileLevel::Minimum), Q\Cii('rsm:ExchangedDocument/ram:ID'), Q\Ubl('cbc:ID')]
        public string $number,
        #[Q\Bt('BT-2'), Q\Cardinality('1'), Q\Level(ProfileLevel::Minimum), Q\Cii('rsm:ExchangedDocument/ram:IssueDateTime'), Q\Ubl('cbc:IssueDate')]
        public Date $issueDate,
        #[Q\Bt('BT-3'), Q\Cardinality('1'), Q\Level(ProfileLevel::Minimum), Q\Cii('rsm:ExchangedDocument/ram:TypeCode'), Q\Ubl('cbc:InvoiceTypeCode | cbc:CreditNoteTypeCode')]
        public InvoiceTypeCode|RawCode $typeCode,
        #[Q\Bt('BT-5'), Q\Cardinality('1'), Q\Level(ProfileLevel::Minimum), Q\Cii('ram:ApplicableHeaderTradeSettlement/ram:InvoiceCurrencyCode'), Q\Ubl('cbc:DocumentCurrencyCode')]
        public string $currency,
        #[Q\Bt('BG-4'), Q\Cardinality('1'), Q\Level(ProfileLevel::Minimum), Q\Cii('ram:ApplicableHeaderTradeAgreement/ram:SellerTradeParty'), Q\Ubl('cac:AccountingSupplierParty')]
        public SellerParty $seller,
        #[Q\Bt('BG-7'), Q\Cardinality('1'), Q\Level(ProfileLevel::Minimum), Q\Cii('ram:ApplicableHeaderTradeAgreement/ram:BuyerTradeParty'), Q\Ubl('cac:AccountingCustomerParty')]
        public BuyerParty $buyer,
        #[Q\Bt('BG-25'), Q\Cardinality('1..n'), Q\Level(ProfileLevel::Basic), Q\ListOf(InvoiceLine::class), Q\Cii('rsm:SupplyChainTradeTransaction/ram:IncludedSupplyChainTradeLineItem'), Q\Ubl('cac:InvoiceLine | cac:CreditNoteLine')]
        public array $lines,
        #[Q\Bt('BT-6'), Q\Cardinality('0..1'), Q\Level(ProfileLevel::BasicWl), Q\Cii('ram:ApplicableHeaderTradeSettlement/ram:TaxCurrencyCode'), Q\Ubl('cbc:TaxCurrencyCode')]
        public ?string $taxCurrency = null,
        #[Q\Bt('BT-7'), Q\Cardinality('0..1'), Q\Level(ProfileLevel::BasicWl), Q\Cii('ram:ApplicableTradeTax/ram:TaxPointDate'), Q\Ubl('cbc:TaxPointDate')]
        public ?Date $taxPointDate = null,
        #[Q\Bt('BT-8'), Q\Cardinality('0..1'), Q\Level(ProfileLevel::BasicWl), Q\Cii('ram:ApplicableTradeTax/ram:DueDateTypeCode'), Q\Ubl('cac:InvoicePeriod/cbc:DescriptionCode')]
        public TaxPointDateCode|RawCode|null $taxPointDateCode = null,
        #[Q\Bt('BT-9'), Q\Cardinality('0..1'), Q\Level(ProfileLevel::BasicWl), Q\Cii('ram:SpecifiedTradePaymentTerms/ram:DueDateDateTime'), Q\Ubl('cbc:DueDate (CreditNote: cac:PaymentMeans/cbc:PaymentDueDate)')]
        public ?Date $dueDate = null,
        #[Q\Bt('BT-10'), Q\Cardinality('0..1'), Q\Level(ProfileLevel::Minimum), Q\RequiredIn('xrechnung'), Q\Cii('ram:ApplicableHeaderTradeAgreement/ram:BuyerReference'), Q\Ubl('cbc:BuyerReference')]
        public ?string $buyerReference = null,
        #[Q\Bt('BT-11'), Q\Cardinality('0..1'), Q\Cii('ram:ApplicableHeaderTradeAgreement/ram:SpecifiedProcuringProject'), Q\Ubl('cac:ProjectReference/cbc:ID')]
        public ?ProjectReference $projectReference = null,
        #[Q\Bt('BT-12'), Q\Cardinality('0..1'), Q\Level(ProfileLevel::BasicWl), Q\Cii('ram:ContractReferencedDocument/ram:IssuerAssignedID'), Q\Ubl('cac:ContractDocumentReference/cbc:ID')]
        public ?string $contractReference = null,
        #[Q\Bt('BT-13'), Q\Cardinality('0..1'), Q\Level(ProfileLevel::Minimum), Q\Cii('ram:BuyerOrderReferencedDocument/ram:IssuerAssignedID'), Q\Ubl('cac:OrderReference/cbc:ID')]
        public ?string $purchaseOrderReference = null,
        #[Q\Bt('BT-14'), Q\Cardinality('0..1'), Q\Cii('ram:SellerOrderReferencedDocument/ram:IssuerAssignedID'), Q\Ubl('cac:OrderReference/cbc:SalesOrderID')]
        public ?string $salesOrderReference = null,
        #[Q\Bt('BT-15'), Q\Cardinality('0..1'), Q\Cii('ram:ReceivingAdviceReferencedDocument/ram:IssuerAssignedID'), Q\Ubl('cac:ReceiptDocumentReference/cbc:ID')]
        public ?string $receivingAdviceReference = null,
        #[Q\Bt('BT-16'), Q\Cardinality('0..1'), Q\Level(ProfileLevel::BasicWl), Q\Cii('ram:DespatchAdviceReferencedDocument/ram:IssuerAssignedID'), Q\Ubl('cac:DespatchDocumentReference/cbc:ID')]
        public ?string $despatchAdviceReference = null,
        #[Q\Bt('BT-17'), Q\Cardinality('0..1'), Q\Cii('ram:AdditionalReferencedDocument[ram:TypeCode="50"]/ram:IssuerAssignedID'), Q\Ubl('cac:OriginatorDocumentReference/cbc:ID')]
        public ?string $tenderReference = null,
        #[Q\Bt('BT-18'), Q\Cardinality('0..1'), Q\Cii('ram:AdditionalReferencedDocument[ram:TypeCode="130"]/ram:IssuerAssignedID'), Q\Ubl('cac:AdditionalDocumentReference[cbc:DocumentTypeCode="130"]/cbc:ID')]
        public ?Identifier $invoicedObject = null,
        #[Q\Bt('BT-19'), Q\Cardinality('0..1'), Q\Level(ProfileLevel::BasicWl), Q\Cii('ram:ReceivableSpecifiedTradeAccountingAccount/ram:ID'), Q\Ubl('cbc:AccountingCost')]
        public ?string $buyerAccountingReference = null,
        #[Q\Bt('BT-20'), Q\Cardinality('0..1'), Q\Level(ProfileLevel::BasicWl), Q\Cii('ram:SpecifiedTradePaymentTerms'), Q\Ubl('cac:PaymentTerms')]
        public ?PaymentTerms $paymentTerms = null,
        #[Q\Bt('BG-1'), Q\Cardinality('0..n'), Q\Level(ProfileLevel::BasicWl), Q\ListOf(Note::class), Q\Cii('rsm:ExchangedDocument/ram:IncludedNote'), Q\Ubl('cbc:Note')]
        public array $notes = [],
        #[Q\Bt('BT-23'), Q\Cardinality('0..1'), Q\Level(ProfileLevel::Minimum), Q\Cii('ram:BusinessProcessSpecifiedDocumentContextParameter/ram:ID'), Q\Ubl('cbc:ProfileID')]
        public ?string $businessProcess = null,
        #[Q\Bt('BG-3'), Q\Cardinality('0..n'), Q\Level(ProfileLevel::BasicWl), Q\ListOf(PrecedingInvoiceReference::class), Q\Cii('ram:InvoiceReferencedDocument'), Q\Ubl('cac:BillingReference')]
        public array $precedingInvoices = [],
        #[Q\Bt('BG-10'), Q\Cardinality('0..1'), Q\Level(ProfileLevel::BasicWl), Q\Cii('ram:PayeeTradeParty'), Q\Ubl('cac:PayeeParty')]
        public ?PayeeParty $payee = null,
        #[Q\Bt('BG-11'), Q\Cardinality('0..1'), Q\Level(ProfileLevel::BasicWl), Q\Cii('ram:SellerTaxRepresentativeTradeParty'), Q\Ubl('cac:TaxRepresentativeParty')]
        public ?TaxRepresentativeParty $sellerTaxRepresentative = null,
        #[Q\Bt('BG-13'), Q\Cardinality('0..1'), Q\Level(ProfileLevel::BasicWl), Q\Cii('ram:ApplicableHeaderTradeDelivery'), Q\Ubl('cac:Delivery')]
        public ?Delivery $delivery = null,
        #[Q\Bt('BG-14'), Q\Cardinality('0..1'), Q\Level(ProfileLevel::BasicWl), Q\Cii('ram:BillingSpecifiedPeriod'), Q\Ubl('cac:InvoicePeriod')]
        public ?Period $invoicingPeriod = null,
        #[Q\Bt('BG-16'), Q\Cardinality('0..1'), Q\Level(ProfileLevel::BasicWl), Q\RequiredIn('xrechnung'), Q\Cii('ram:SpecifiedTradeSettlementPaymentMeans'), Q\Ubl('cac:PaymentMeans')]
        public ?PaymentInstructions $paymentInstructions = null,
        #[Q\Bt('BG-20'), Q\Cardinality('0..n'), Q\Level(ProfileLevel::BasicWl), Q\ListOf(DocumentAllowance::class), Q\Cii('ram:SpecifiedTradeAllowanceCharge[ram:ChargeIndicator/udt:Indicator="false"]'), Q\Ubl('cac:AllowanceCharge[cbc:ChargeIndicator="false"]')]
        public array $allowances = [],
        #[Q\Bt('BG-21'), Q\Cardinality('0..n'), Q\Level(ProfileLevel::BasicWl), Q\ListOf(DocumentCharge::class), Q\Cii('ram:SpecifiedTradeAllowanceCharge[ram:ChargeIndicator/udt:Indicator="true"]'), Q\Ubl('cac:AllowanceCharge[cbc:ChargeIndicator="true"]')]
        public array $charges = [],
        #[Q\Bt('BG-22'), Q\Cardinality('1'), Q\Level(ProfileLevel::Minimum), Q\Computed, Q\Cii('ram:SpecifiedTradeSettlementHeaderMonetarySummation'), Q\Ubl('cac:LegalMonetaryTotal')]
        public ?Totals $totals = null,
        #[Q\Bt('BG-23'), Q\Cardinality('1..n'), Q\Level(ProfileLevel::BasicWl), Q\Computed, Q\ListOf(VatBreakdown::class), Q\Cii('ram:ApplicableTradeTax'), Q\Ubl('cac:TaxTotal/cac:TaxSubtotal')]
        public array $vatBreakdown = [],
        #[Q\Bt('BG-24'), Q\Cardinality('0..n'), Q\ListOf(Attachment::class), Q\Cii('ram:AdditionalReferencedDocument[ram:TypeCode="916"]'), Q\Ubl('cac:AdditionalDocumentReference')]
        public array $attachments = [],
        #[Q\InputFor('BT-113')]
        public ?Money $prepaidAmount = null,
        #[Q\InputFor('BT-114')]
        public ?Money $roundingAmount = null,
        #[Q\ExtendedOnly]
        public ?ExtendedData $extended = null,
        #[Q\XRechnungExtensionOnly]
        public ?XRechnungExtensionData $xrechnungExtension = null,
        #[Q\PdfOnly]
        public ?Presentation $presentation = null,
        #[Q\Internal]
        public Meta $meta = new Meta,
        #[Q\Internal]
        public StandardVersion $standardVersion = StandardVersion::En16931_2017,
    ) {}

    /**
     * Deterministic test data, e.g. InvoiceData::factory()->reverseCharge()->withLines(5)->make().
     */
    public static function factory(): InvoiceFactory
    {
        return InvoiceFactory::new();
    }

    public function isCalculated(): bool
    {
        return $this->totals !== null;
    }

    /**
     * Copy with the exchange rate implied by the declared VAT totals (BT-111 / BT-110, ten
     * decimals), e.g. for data read from XML, which carries no exchange rate. A recalculation
     * then reproduces BT-111, also with changed or negated amounts. Data with an exchange rate,
     * without a VAT accounting currency (BT-6) or without a declared non-zero BT-110 is returned unchanged.
     */
    public function withImpliedExchangeRate(): self
    {
        $declared = $this->totals?->vatTotalInTaxCurrency;
        $vatTotal = $this->totals?->vatTotal;
        if ($this->meta->exchangeRate !== null
            || $this->taxCurrency === null
            || $this->taxCurrency === $this->currency
            || $declared === null
            || $vatTotal === null
            || $vatTotal->isZero()
            || $declared->getCurrency()->getCurrencyCode() !== $this->taxCurrency) {
            return $this;
        }

        $rate = $declared->getAmount()->dividedBy($vatTotal->getAmount(), 10, RoundingMode::HalfUp)->strippedOfTrailingZeros();

        return $this->with(['meta' => $this->meta->with(['exchangeRate' => $rate])]);
    }

    public function typeCodeValue(): string
    {
        return (string) $this->typeCode->value;
    }

    public function isCreditNote(): bool
    {
        return $this->typeCode instanceof InvoiceTypeCode && $this->typeCode->isCreditNote();
    }

    public function withNumber(string $number): self
    {
        return $this->with(['number' => $number]);
    }

    public function withIssueDate(Date $issueDate): self
    {
        return $this->with(['issueDate' => $issueDate]);
    }

    /**
     * All lines including sub lines, depth first.
     *
     * @return list<InvoiceLine>
     */
    public function allLines(): array
    {
        return self::flatten($this->lines);
    }

    /**
     * @param  list<InvoiceLine>  $lines
     * @return list<InvoiceLine>
     */
    private static function flatten(array $lines): array
    {
        $result = [];
        foreach ($lines as $line) {
            $result[] = $line;
            foreach (self::flatten($line->subLines) as $sub) {
                $result[] = $sub;
            }
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return (new ArrayNormalizer)->normalize($this);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return (new ArrayHydrator)->hydrate(self::class, $data);
    }

    public function toJson(int $flags = 0): string
    {
        return json_encode($this->toArray(), $flags | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($data)) {
            throw new InvalidArgumentException('Invoice JSON must decode to an object.');
        }

        /** @var array<string, mixed> $data */
        return self::fromArray($data);
    }

    /**
     * Serialised form for storage (Managed layer). Includes the snapshot version.
     *
     * @return array<string, mixed>
     */
    public function toSnapshot(): array
    {
        return ['_version' => self::SNAPSHOT_VERSION] + $this->toArray();
    }

    /**
     * Restores a stored snapshot of any older version through the upcaster chain.
     * Snapshots without "_version" are treated as version 1.
     *
     * @param  array<array-key, mixed>  $snapshot
     */
    public static function fromSnapshot(array $snapshot, ?SnapshotUpcasterChain $chain = null): self
    {
        $version = $snapshot['_version'] ?? 1;
        if (! is_int($version)) {
            throw new SnapshotVersionException('The snapshot version must be an integer.');
        }

        $data = [];
        foreach ($snapshot as $key => $value) {
            if ($key !== '_version') {
                $data[(string) $key] = $value;
            }
        }

        return self::fromArray(($chain ?? SnapshotUpcasterChain::default())->upcast($data, $version));
    }
}
