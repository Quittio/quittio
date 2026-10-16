<?php

declare(strict_types=1);

namespace Quittio\Core\Builder;

use Brick\Math\BigDecimal;
use Brick\Money\Money;
use Closure;
use Quittio\Core\Builder\Concerns\BuildsAllowancesAndCharges;
use Quittio\Core\Builder\Concerns\BuildsDelivery;
use Quittio\Core\Builder\Concerns\BuildsExtensions;
use Quittio\Core\Builder\Concerns\BuildsHeader;
use Quittio\Core\Builder\Concerns\BuildsLines;
use Quittio\Core\Builder\Concerns\BuildsNotesAndAttachments;
use Quittio\Core\Builder\Concerns\BuildsParties;
use Quittio\Core\Builder\Concerns\BuildsPayment;
use Quittio\Core\Builder\Concerns\BuildsPresentationAndMeta;
use Quittio\Core\Builder\Concerns\BuildsReferences;
use Quittio\Core\Calculation\AllowanceSplitter;
use Quittio\Core\Calculation\CalculationException;
use Quittio\Core\Calculation\DeclaredAmounts;
use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Calculation\LargestRemainder;
use Quittio\Core\Calculation\LineCalculator;
use Quittio\Core\Calculation\TotalsCalculator;
use Quittio\Core\Codes\RawCode;
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
use Quittio\Core\Data\Party\BuyerParty;
use Quittio\Core\Data\Party\PayeeParty;
use Quittio\Core\Data\Party\SellerParty;
use Quittio\Core\Data\Party\TaxRepresentativeParty;
use Quittio\Core\Data\Payment\PaymentInstructions;
use Quittio\Core\Data\Payment\PaymentTerms;
use Quittio\Core\Data\Presentation\Presentation;
use Quittio\Core\Data\References\PrecedingInvoiceReference;
use Quittio\Core\Data\References\ProjectReference;
use Quittio\Core\Data\Totals\VatBreakdown;
use Quittio\Core\Documents\DocumentData;
use Quittio\Core\Documents\DocumentType;
use Quittio\Core\Enums\AllowanceReasonCode;
use Quittio\Core\Enums\ChargeReasonCode;
use Quittio\Core\Enums\InvoiceTypeCode;
use Quittio\Core\Enums\TaxPointDateCode;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Enums\VatExemptionReasonCode;
use Quittio\Core\Exceptions\InvalidInvoiceData;
use Quittio\Core\Standards\StandardVersion;
use Quittio\Core\Support\Amount;
use Quittio\Core\Support\Date;
use Quittio\Core\Support\Decimal;
use Quittio\Core\Support\Macroable;
use Quittio\Core\Tax\TaxPreset;
use ReflectionClass;
use Throwable;

/**
 * Fluent builder for InvoiceData.
 *
 * Amounts, prices and quantities are passed as strings or integers, never as floats.
 * The result is not yet calculated; totals and the VAT breakdown are set by the calculator.
 */
class InvoiceBuilder
{
    use BuildsAllowancesAndCharges;
    use BuildsDelivery;
    use BuildsExtensions;
    use BuildsHeader;
    use BuildsLines;
    use BuildsNotesAndAttachments;
    use BuildsParties;
    use BuildsPayment;
    use BuildsPresentationAndMeta;
    use BuildsReferences;
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

    /** @var array{0: self, 1: self}|null the builder before and after applyDefaults(), see seller() */
    private ?array $defaults = null;

    /** @var array<string, true> flags set with flag() after applyDefaults() */
    private array $explicitFlags = [];

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

    /**
     * A new, empty builder; type code 380 and the issue date today unless set.
     */
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
     *
     * The declared amounts are compared with the result of the default En16931Calculator; use
     * fromUsing() or Quittio::edit() to compare with another calculator.
     */
    public static function from(InvoiceData $data): static
    {
        return static::fromUsing($data, new En16931Calculator);
    }

    /**
     * Like from(), with the calculator that decides whether the declared amounts of the data
     * differ from a recalculation, e.g. the calculator of the Quittio instance.
     */
    public static function fromUsing(InvoiceData $data, TotalsCalculator $calculator): static
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
        $builder->declaredAmounts = ! $stale && DeclaredAmounts::needed($data, $calculator);
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

    /**
     * Applies the defaults of a seller, e.g. the seller of config/quittio.php with its bank
     * account and small business flag (Quittio::invoice()) or a Managed issuer. The builder
     * remembers what they set: seller() with another seller removes it again.
     *
     * @param  callable(static): mixed  $apply
     */
    public function applyDefaults(callable $apply): static
    {
        $before = clone $this;
        $apply($this);
        // Builders of the state are copied as well: a callable may change them in place later.
        $after = clone $this;
        foreach ((new ReflectionClass(self::class))->getProperties() as $property) {
            $value = $property->isStatic() ? null : $property->getValue($after);
            if ($value instanceof PartyBuilder || $value instanceof PaymentTermsBuilder || $value instanceof LineBuilder) {
                $property->setValue($after, clone $value);
            }
        }
        $this->defaults = [$before, $after];
        $this->explicitFlags = [];

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

    /**
     * The invoice data, not calculated yet (the calculator or a render run adds the totals).
     *
     * @throws InvalidInvoiceData when seller (BG-4), buyer (BG-7), the lines (BG-25), an item name
     * (BT-153) or the VAT of a line without default VAT (BG-30) is missing, or a line identifier
     * is used twice
     */
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
     * Removes what applyDefaults() set and the caller did not change since (see seller()).
     * Builders in the state are compared by content, values by identity.
     */
    private function removeDefaults(): void
    {
        if ($this->defaults === null) {
            return;
        }
        [$before, $after] = $this->defaults;
        $this->defaults = null;
        foreach ((new ReflectionClass(self::class))->getProperties() as $property) {
            if ($property->isStatic() || in_array($property->getName(), ['seller', 'defaults', 'explicitFlags'], true)) {
                continue;
            }
            $old = $property->getValue($before);
            $default = $property->getValue($after);
            $current = $property->getValue($this);
            if ($old === $default) {
                continue;
            }
            $builder = $current instanceof PartyBuilder || $current instanceof PaymentTermsBuilder || $current instanceof LineBuilder;
            if ($old instanceof Meta && $default instanceof Meta && $current instanceof Meta) {
                $property->setValue($this, $this->metaWithoutDefaults($old, $default, $current));
            } elseif ($builder ? self::sameContent($current, $default) : $current === $default) {
                $property->setValue($this, $old);
            }
        }
        $this->explicitFlags = [];
    }

    /**
     * Whether a builder of the state still has the content of its copy; builders that cannot
     * be serialized count as changed.
     */
    private static function sameContent(mixed $current, mixed $copy): bool
    {
        try {
            return serialize($current) === serialize($copy);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The meta data without the values and flags of the defaults, field by field, so that a
     * locale or flag the caller set stays.
     */
    private function metaWithoutDefaults(Meta $old, Meta $default, Meta $current): Meta
    {
        $before = get_object_vars($old);
        $now = get_object_vars($current);
        $changes = [];
        foreach (get_object_vars($default) as $name => $value) {
            if (is_string($name) && $name !== 'flags' && $value !== $before[$name] && $now[$name] === $value) {
                $changes[$name] = $before[$name];
            }
        }
        $meta = $current->with($changes);
        foreach (array_diff($default->flags, $old->flags) as $flag) {
            if (! isset($this->explicitFlags[$flag])) {
                $meta = $meta->withFlag($flag, false);
            }
        }

        return $meta;
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

    private function money(?string $value): ?Money
    {
        return Amount::ofNullable($value, $this->currency);
    }
}
