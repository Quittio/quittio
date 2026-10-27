<?php

declare(strict_types=1);

namespace Quittio\Core\Documents;

use JsonException;
use Quittio\Core\Data\AllowanceCharge\DocumentAllowance;
use Quittio\Core\Data\AllowanceCharge\DocumentCharge;
use Quittio\Core\Data\Concerns\Immutable;
use Quittio\Core\Data\Delivery\Delivery;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Data\Lines\InvoiceLine;
use Quittio\Core\Data\Meta;
use Quittio\Core\Data\Note;
use Quittio\Core\Data\Party\BuyerParty;
use Quittio\Core\Data\Party\SellerParty;
use Quittio\Core\Data\Payment\PaymentInstructions;
use Quittio\Core\Data\Payment\PaymentTerms;
use Quittio\Core\Data\Presentation\Presentation;
use Quittio\Core\Data\Totals\Totals;
use Quittio\Core\Data\Totals\VatBreakdown;
use Quittio\Core\Enums\InvoiceTypeCode;
use Quittio\Core\Exceptions\HydrationException;
use Quittio\Core\Exceptions\InvalidArgumentException;
use Quittio\Core\I18n\Translations;
use Quittio\Core\Mapping\Attributes as Q;
use Quittio\Core\Serialization\ArrayHydrator;
use Quittio\Core\Serialization\ArrayNormalizer;
use Quittio\Core\Serialization\Snapshots\SnapshotUpcasterChain;
use Quittio\Core\Serialization\Snapshots\SnapshotVersionException;
use Quittio\Core\Support\Amount;
use Quittio\Core\Support\Date;
use Quittio\Core\Validation\Severity;
use Quittio\Core\Validation\ValidationLevel;
use Quittio\Core\Validation\ValidationMessage;
use Quittio\Core\View\IntlFormatter;

/**
 * Quote, order confirmation, delivery note, proforma invoice, payment reminder, account
 * statement or bonus statement. Shares parties, lines and calculation with {@see InvoiceData},
 * but never produces e-invoice XML. Payment reminders and account statements
 * have no lines: their amount comes from {@see Dunning} and {@see AccountStatement}.
 */
final readonly class DocumentData
{
    use Immutable;

    /** Version of the format of toSnapshot(); a new version comes with an upcaster. */
    public const int SNAPSHOT_VERSION = 1;

    /**
     * @param  list<InvoiceLine>  $lines
     * @param  array<string, DocumentLineOptions>  $lineOptions  keyed by line identifier
     * @param  list<DocumentAllowance>  $allowances
     * @param  list<DocumentCharge>  $charges
     * @param  list<Note>  $notes
     * @param  list<Package>  $packages
     * @param  list<VatBreakdown>  $vatBreakdown
     */
    public function __construct(
        public DocumentType $type,
        public string $number,
        public Date $issueDate,
        public string $currency,
        public SellerParty $seller,
        public BuyerParty $buyer,
        #[Q\ListOf(InvoiceLine::class)]
        public array $lines = [],
        #[Q\MapOf(DocumentLineOptions::class)]
        public array $lineOptions = [],
        #[Q\ListOf(DocumentAllowance::class)]
        public array $allowances = [],
        #[Q\ListOf(DocumentCharge::class)]
        public array $charges = [],
        #[Q\ListOf(Note::class)]
        public array $notes = [],
        public ?Date $validUntil = null,
        public ?string $reference = null,
        public ?Date $referenceDate = null,
        public ?string $buyerReference = null,
        public ?string $purchaseOrderReference = null,
        public ?Delivery $delivery = null,
        #[Q\ListOf(Package::class)]
        public array $packages = [],
        public ?Dunning $dunning = null,
        public ?AccountStatement $statement = null,
        public ?PaymentTerms $paymentTerms = null,
        public ?PaymentInstructions $paymentInstructions = null,
        public ?Totals $totals = null,
        #[Q\ListOf(VatBreakdown::class)]
        public array $vatBreakdown = [],
        public ?Presentation $presentation = null,
        public Meta $meta = new Meta,
        public ?BonusStatement $bonus = null,
    ) {
        if ($type === DocumentType::BonusStatement && $bonus === null) {
            throw new InvalidArgumentException('A bonus statement requires its period and agreement (bonusPeriod(), bonusAgreement()).');
        }
        if ($type === DocumentType::PaymentReminder && $dunning === null) {
            throw new InvalidArgumentException('A payment reminder requires dunning data.');
        }
        if ($type === DocumentType::AccountStatement && $statement === null) {
            throw new InvalidArgumentException('An account statement requires statement data.');
        }
        if (in_array($type, [DocumentType::PaymentReminder, DocumentType::AccountStatement], true) && ($lines !== [] || $allowances !== [] || $charges !== [])) {
            // Their amount is the sum of open items, fees and interest; lines would add VAT
            // to fees and leave the amount to pay ambiguous.
            throw new InvalidArgumentException(sprintf(
                'A %s lists open items and has no lines, allowances or charges. Use openItem(), fee() and interest() instead.',
                str_replace('_', ' ', $type->value),
            ));
        }
        if (($type->hasLineTotals() || $type === DocumentType::DeliveryNote) && $lines === []) {
            throw new InvalidArgumentException(sprintf('A %s requires at least one line.', str_replace('_', ' ', $type->value)));
        }
    }

    /**
     * Notices on the document; documents are not validated, so these are plausibility hints
     * only. QUITTIO-DUNNING-FLAT-RATE-FEES (warning): a reminder to a business claims the flat
     * rate for recovery costs and reminder fees of its own, which count towards the flat rate
     * (Article 6 (3) Directive 2011/7/EU, section 288 (5) sentence 3 BGB).
     *
     * @return list<ValidationMessage>
     */
    public function notices(string $locale = 'en'): array
    {
        $notices = [];
        $dunning = $this->dunning;
        if ($dunning !== null && ! $this->buyer->isConsumer) {
            $flatRate = Amount::zero($this->currency);
            $fees = Amount::zero($this->currency);
            foreach ($dunning->fees as $fee) {
                if ($fee->kind === DunningFeeKind::FlatRate) {
                    $flatRate = $flatRate->plus(Amount::of($fee->amount, $this->currency));
                } else {
                    $fees = $fees->plus(Amount::of($fee->amount, $this->currency));
                }
            }
            if ($flatRate->isPositive() && $fees->isPositive()) {
                $formatter = new IntlFormatter;
                $notices[] = self::notice('QUITTIO-DUNNING-FLAT-RATE-FEES', $locale, 'dunning.fees', [
                    'fees' => $formatter->money($fees, $locale),
                    'flat_rate' => $formatter->money($flatRate, $locale),
                ], 'The reminder claims fees of :fees besides the flat rate for recovery costs of :flat_rate. Reminder fees are recovery costs and count towards the flat rate (Article 6 (3) Directive 2011/7/EU, section 288 (5) sentence 3 BGB): claim them only in so far as they exceed it.');
            }
        }

        return $notices;
    }

    /**
     * @param  array<string, string>  $replace
     */
    private static function notice(string $code, string $locale, string $path, array $replace, string $fallback): ValidationMessage
    {
        $translator = Translations::translator();
        $key = 'quittio::rules.'.$code;
        $message = $translator->has($key.'.message', $locale) ? $translator->get($key.'.message', $replace, $locale) : strtr($fallback, array_combine(array_map(static fn (string $k): string => ':'.$k, array_keys($replace)), $replace));
        $hint = $translator->has($key.'.hint', $locale) ? $translator->get($key.'.hint', [], $locale) : ($translator->has($key.'.hint', 'en') ? $translator->get($key.'.hint', [], 'en') : null);

        return new ValidationMessage(Severity::Warning, $code, $message, ValidationLevel::L7, path: $path, hint: $hint);
    }

    /**
     * The document specific options of the line with identifier $lineId (BT-126); default options
     * (billable, no serial numbers) when none are set.
     */
    public function options(string $lineId): DocumentLineOptions
    {
        return $this->lineOptions[$lineId] ?? new DocumentLineOptions;
    }

    /**
     * Lines that count towards the totals (without optional and alternative lines).
     *
     * @return list<InvoiceLine>
     */
    public function billableLines(): array
    {
        return array_values(array_filter($this->lines, fn (InvoiceLine $line): bool => $this->options($line->id)->isBillable()));
    }

    /**
     * Whether the document has totals or needs none, because it has no billable lines (e.g. a
     * payment reminder or delivery note without prices).
     */
    public function isCalculated(): bool
    {
        return $this->totals !== null || $this->billableLines() === [];
    }

    /**
     * The document as invoice data, e.g. for calculation or rendering. Never use it as an invoice.
     */
    public function toInvoiceDraft(bool $billableOnly = true): InvoiceData
    {
        return new InvoiceData(
            number: $this->number,
            issueDate: $this->issueDate,
            typeCode: InvoiceTypeCode::CommercialInvoice,
            currency: $this->currency,
            seller: $this->seller,
            buyer: $this->buyer,
            lines: $billableOnly ? $this->billableLines() : $this->lines,
            buyerReference: $this->buyerReference,
            purchaseOrderReference: $this->purchaseOrderReference,
            paymentTerms: $this->paymentTerms,
            notes: $this->notes,
            delivery: $this->delivery,
            paymentInstructions: $this->paymentInstructions,
            allowances: $this->allowances,
            charges: $this->charges,
            totals: $this->totals,
            vatBreakdown: $this->vatBreakdown,
            presentation: $this->presentation,
            meta: $this->meta,
        );
    }

    /**
     * The document as plain array (scalars only); values equal to their default are left out.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return (new ArrayNormalizer)->normalize($this);
    }

    /**
     * The document from an array of toArray().
     *
     * @param  array<string, mixed>  $data
     *
     * @throws HydrationException for missing, unknown or invalid values
     */
    public static function fromArray(array $data): self
    {
        return (new ArrayHydrator)->hydrate(self::class, $data);
    }

    /**
     * The document as JSON of toArray().
     */
    public function toJson(int $flags = 0): string
    {
        return json_encode($this->toArray(), $flags | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @throws HydrationException for invalid JSON or invalid values
     */
    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw HydrationException::at('', 'Invalid JSON: '.$e->getMessage(), $e);
        }
        if (! is_array($data)) {
            throw new InvalidArgumentException('Document JSON must decode to an object.');
        }

        /** @var array<string, mixed> $data */
        return self::fromArray($data);
    }

    /**
     * The document for storage, with the version of the format ("_version"), e.g. for a queued
     * render job. Read it with fromSnapshot().
     *
     * @return array<string, mixed>
     */
    public function toSnapshot(): array
    {
        return ['_version' => self::SNAPSHOT_VERSION] + $this->toArray();
    }

    /**
     * Restores a stored snapshot of any older version through the upcaster chain (default:
     * SnapshotUpcasterChain::documentDefault()). Snapshots without "_version" are version 1.
     *
     * @param  array<array-key, mixed>  $snapshot
     *
     * @throws SnapshotVersionException for a version that this installation cannot read
     * @throws HydrationException for missing, unknown or invalid values
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

        return self::fromArray(($chain ?? SnapshotUpcasterChain::documentDefault())->upcast($data, $version, self::SNAPSHOT_VERSION));
    }
}
