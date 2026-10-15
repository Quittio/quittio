<?php

declare(strict_types=1);

namespace Quittio\Core\Documents;

use Brick\Math\BigDecimal;
use DateTimeInterface;
use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Builder\Line;
use Quittio\Core\Builder\LineBuilder;
use Quittio\Core\Builder\PartyBuilder;
use Quittio\Core\Builder\PaymentTermsBuilder;
use Quittio\Core\Codes\RawCode;
use Quittio\Core\Contracts\ProvidesInvoiceLine;
use Quittio\Core\Contracts\ProvidesParty;
use Quittio\Core\Data\Delivery\Period;
use Quittio\Core\Data\Lines\InvoiceLine;
use Quittio\Core\Data\Party\Address;
use Quittio\Core\Data\Party\BuyerParty;
use Quittio\Core\Data\Party\SellerParty;
use Quittio\Core\Data\Payment\PaymentTerms;
use Quittio\Core\Data\Presentation\Presentation;
use Quittio\Core\Enums\AllowanceReasonCode;
use Quittio\Core\Enums\NoteSubjectCode;
use Quittio\Core\Enums\UnitCode;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Enums\VatExemptionReasonCode;
use Quittio\Core\Exceptions\InvalidArgumentException;
use Quittio\Core\Exceptions\InvalidInvoiceData;
use Quittio\Core\Support\Amount;
use Quittio\Core\Support\Date;
use Quittio\Core\Support\Decimal;
use Quittio\Core\Support\Macroable;
use Quittio\Core\Tax\TaxPreset;

/**
 * Fluent builder for documents. Parties, lines, notes, discounts and
 * payment data work exactly like in the {@see InvoiceBuilder}.
 */
class DocumentBuilder
{
    use Macroable;

    private InvoiceBuilder $invoice;

    private int $lineCount = 0;

    /** @var array<int, DocumentLineOptions> line index => options */
    private array $options = [];

    private ?Date $validUntil = null;

    private ?string $reference = null;

    private ?Date $referenceDate = null;

    /** @var list<Package> */
    private array $packages = [];

    private int $dunningLevel = 0;

    private ?Date $deadline = null;

    /** @var list<array{number: string, date: Date, amount: string, due: ?Date, paid: ?string, description: ?string}> */
    private array $openItems = [];

    /** @var list<array{description: string, amount: string}> */
    private array $fees = [];

    /** @var list<array{base: string, rate: BigDecimal, from: Date, until: Date, description: ?string, dayCount: ?int}> */
    private array $interest = [];

    private ?Period $statementPeriod = null;

    private ?string $openingBalance = null;

    final public function __construct(
        private readonly DocumentType $type,
    ) {
        $this->invoice = InvoiceBuilder::make();
    }

    /**
     * A new builder for a document of $type.
     */
    public static function make(DocumentType $type): static
    {
        return new static($type);
    }

    /**
     * A quote (Angebot): lines, optional and alternative lines, validUntil().
     */
    public static function quote(): static
    {
        return new static(DocumentType::Quote);
    }

    /**
     * An order confirmation (Auftragsbestätigung), e.g. with reference() to the quote.
     */
    public static function orderConfirmation(): static
    {
        return new static(DocumentType::OrderConfirmation);
    }

    /**
     * A delivery note (Lieferschein), e.g. with packages and serialised lines.
     */
    public static function deliveryNote(): static
    {
        return new static(DocumentType::DeliveryNote);
    }

    /**
     * A proforma invoice: looks like an invoice, but is not one for tax purposes.
     */
    public static function proforma(): static
    {
        return new static(DocumentType::Proforma);
    }

    /**
     * @param  int  $level  0 = payment reminder (Zahlungserinnerung), 1 = first formal reminder (1. Mahnung), 2 = second …
     */
    public static function paymentReminder(int $level = 0): static
    {
        $builder = new static(DocumentType::PaymentReminder);
        $builder->dunningLevel = $level;

        return $builder;
    }

    /**
     * An account statement (Kontoauszug): open items of a statementPeriod() with an opening
     * balance.
     */
    public static function accountStatement(): static
    {
        return new static(DocumentType::AccountStatement);
    }

    /**
     * Document number.
     */
    public function number(string $number): static
    {
        $this->invoice->number($number);

        return $this;
    }

    /**
     * Document date; default today.
     */
    public function issueDate(Date|DateTimeInterface|string $date): static
    {
        $this->invoice->issueDate($date);

        return $this;
    }

    /**
     * Currency, ISO 4217; default EUR.
     */
    public function currency(string $currency): static
    {
        $this->invoice->currency($currency);

        return $this;
    }

    /**
     * @param  SellerParty|PartyBuilder|ProvidesParty|callable(PartyBuilder): mixed  $seller
     */
    public function seller(SellerParty|PartyBuilder|ProvidesParty|callable $seller): static
    {
        $this->invoice->seller($seller);

        return $this;
    }

    /**
     * @param  BuyerParty|PartyBuilder|ProvidesParty|callable(PartyBuilder): mixed  $buyer
     */
    public function buyer(BuyerParty|PartyBuilder|ProvidesParty|callable $buyer): static
    {
        $this->invoice->buyer($buyer);

        return $this;
    }

    /**
     * A line in the forms of InvoiceBuilder::line(), e.g. ->line('Consulting', netPrice: '120.00').
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
        ?DocumentLineOptions $options = null,
    ): static {
        if (! $line instanceof InvoiceLine) {
            $line = Line::from($line, $netPrice, $quantity, $unit, $vat, $description, $priceInclVat);
        }
        if ($this->type === DocumentType::DeliveryNote && $line instanceof LineBuilder) {
            // Delivery notes carry no prices: defaults keep the line valid for the calculator.
            if (! $line->hasPrice()) {
                $line->netPrice(0);
            }
            if (! $line->hasVat()) {
                $line->vat(VatCategory::NotSubjectToVat);
            }
        }

        $this->invoice->line($line);
        if ($options !== null) {
            $this->options[$this->lineCount] = $options;
        }
        $this->lineCount++;

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
     * Optional quote line, shown with its price but not included in the totals.
     *
     * @param  InvoiceLine|LineBuilder|array<array-key, mixed>|callable(LineBuilder): mixed|string  $line
     */
    public function optionalLine(
        InvoiceLine|LineBuilder|array|callable|string $line,
        BigDecimal|int|float|string|null $netPrice = null,
        BigDecimal|int|float|string|null $quantity = null,
        UnitCode|RawCode|string|null $unit = null,
        TaxPreset|VatCategory|null $vat = null,
        ?string $description = null,
        BigDecimal|int|float|string|null $priceInclVat = null,
    ): static {
        return $this->line($line, $netPrice, $quantity, $unit, $vat, $description, $priceInclVat, new DocumentLineOptions(optional: true));
    }

    /**
     * Alternative to another line, shown but not included in the totals.
     *
     * @param  InvoiceLine|LineBuilder|array<array-key, mixed>|callable(LineBuilder): mixed|string  $line
     */
    public function alternativeLine(
        string $alternativeTo,
        InvoiceLine|LineBuilder|array|callable|string $line,
        BigDecimal|int|float|string|null $netPrice = null,
        BigDecimal|int|float|string|null $quantity = null,
        UnitCode|RawCode|string|null $unit = null,
        TaxPreset|VatCategory|null $vat = null,
        ?string $description = null,
        BigDecimal|int|float|string|null $priceInclVat = null,
    ): static {
        return $this->line($line, $netPrice, $quantity, $unit, $vat, $description, $priceInclVat, new DocumentLineOptions(alternativeTo: $alternativeTo));
    }

    /**
     * Delivery note line with serial numbers.
     *
     * @param  InvoiceLine|LineBuilder|array<array-key, mixed>|callable(LineBuilder): mixed|string  $line
     * @param  list<string>  $serialNumbers
     */
    public function serialisedLine(
        InvoiceLine|LineBuilder|array|callable|string $line,
        array $serialNumbers,
        BigDecimal|int|float|string|null $netPrice = null,
        BigDecimal|int|float|string|null $quantity = null,
        UnitCode|RawCode|string|null $unit = null,
        TaxPreset|VatCategory|null $vat = null,
        ?string $description = null,
        BigDecimal|int|float|string|null $priceInclVat = null,
    ): static {
        return $this->line($line, $netPrice, $quantity, $unit, $vat, $description, $priceInclVat, new DocumentLineOptions(serialNumbers: $serialNumbers));
    }

    /**
     * VAT treatment of the lines that set none, see InvoiceBuilder::defaultVat().
     */
    public function defaultVat(TaxPreset|VatCategory $presetOrCategory, BigDecimal|int|float|string|null $rate = null, ?string $exemptionReason = null, VatExemptionReasonCode|RawCode|string|null $exemptionReasonCode = null): static
    {
        $this->invoice->defaultVat($presetOrCategory, $rate, $exemptionReason, $exemptionReasonCode);

        return $this;
    }

    /**
     * Adds a note with an optional subject code, see InvoiceBuilder::note().
     */
    public function note(string $content, NoteSubjectCode|RawCode|string|null $subject = null): static
    {
        $this->invoice->note($content, $subject);

        return $this;
    }

    /**
     * Percentage discount on the lines that count towards the totals, see
     * InvoiceBuilder::discountPercent().
     */
    public function discountPercent(BigDecimal|int|float|string $percent, ?string $reason = null, AllowanceReasonCode|RawCode|string|null $reasonCode = AllowanceReasonCode::Discount): static
    {
        $this->invoice->discountPercent($percent, $reason, $reasonCode);

        return $this;
    }

    /**
     * Absolute discount on the lines that count towards the totals, see
     * InvoiceBuilder::discountAmount().
     */
    public function discountAmount(BigDecimal|int|float|string $amount, ?string $reason = null, AllowanceReasonCode|RawCode|string|null $reasonCode = AllowanceReasonCode::Discount): static
    {
        $this->invoice->discountAmount($amount, $reason, $reasonCode);

        return $this;
    }

    /**
     * @param  PaymentTerms|string|callable(PaymentTermsBuilder): mixed  $terms
     */
    public function paymentTerms(PaymentTerms|string|callable $terms): static
    {
        $this->invoice->paymentTerms($terms);

        return $this;
    }

    /**
     * Payment target in days, see InvoiceBuilder::dueInDays().
     */
    public function dueInDays(int $days): static
    {
        $this->invoice->dueInDays($days);

        return $this;
    }

    /**
     * Cash discount ("Skonto"), see InvoiceBuilder::cashDiscount().
     */
    public function cashDiscount(int $days, string $percent, ?string $baseAmount = null): static
    {
        $this->invoice->cashDiscount($days, $percent, $baseAmount);

        return $this;
    }

    /**
     * Bank account for the payment, see InvoiceBuilder::creditTransfer().
     */
    public function creditTransfer(string $iban, ?string $bic = null, ?string $accountName = null): static
    {
        $this->invoice->creditTransfer($iban, $bic, $accountName);

        return $this;
    }

    /**
     * The buyer's reference; null removes it.
     */
    public function buyerReference(?string $reference): static
    {
        $this->invoice->buyerReference($reference);

        return $this;
    }

    /**
     * The buyer's purchase order reference; null removes it.
     */
    public function purchaseOrderReference(?string $reference): static
    {
        $this->invoice->purchaseOrderReference($reference);

        return $this;
    }

    /**
     * Preceding document, e.g. the quote of an order confirmation or the order of a delivery note.
     */
    public function reference(string $number, Date|DateTimeInterface|string|null $date = null): static
    {
        $this->reference = $number;
        $this->referenceDate = $date === null ? null : Date::parse($date);

        return $this;
    }

    /**
     * Date until which a quote is valid.
     */
    public function validUntil(Date|DateTimeInterface|string $date): static
    {
        $this->validUntil = Date::parse($date);

        return $this;
    }

    /**
     * Delivery date.
     */
    public function deliveryDate(Date|DateTimeInterface|string $date): static
    {
        $this->invoice->deliveryDate($date);

        return $this;
    }

    /**
     * Deliver-to party: recipient name and address, see InvoiceBuilder::deliverTo().
     */
    public function deliverTo(?string $recipientName, ?Address $address = null): static
    {
        $this->invoice->deliverTo($recipientName, $address);

        return $this;
    }

    /**
     * Adds a package of a delivery note.
     */
    public function package(Package $package): static
    {
        $this->packages[] = $package;

        return $this;
    }

    /**
     * Payment deadline of a payment reminder.
     */
    public function deadline(Date|DateTimeInterface|string $date): static
    {
        $this->deadline = Date::parse($date);

        return $this;
    }

    /**
     * Adds an open invoice of a payment reminder or account statement: number, date, amount
     * (string), due date, the amount paid so far and a description.
     */
    public function openItem(
        string $invoiceNumber,
        Date|DateTimeInterface|string $invoiceDate,
        string $amount,
        Date|DateTimeInterface|string|null $dueDate = null,
        ?string $paid = null,
        ?string $description = null,
    ): static {
        $this->openItems[] = [
            'number' => $invoiceNumber,
            'date' => Date::parse($invoiceDate),
            'amount' => $amount,
            'due' => $dueDate === null ? null : Date::parse($dueDate),
            'paid' => $paid,
            'description' => $description,
        ];

        return $this;
    }

    /**
     * Adds a fee of a payment reminder, e.g. a reminder fee.
     *
     * @throws InvalidArgumentException for a float amount
     */
    public function fee(string $description, BigDecimal|int|float|string $amount): static
    {
        $this->fees[] = ['description' => $description, 'amount' => (string) Decimal::rejectFloat($amount)];

        return $this;
    }

    /**
     * Default interest from $from (first day with interest) until $until (exclusive: the
     * last day with interest is the day before). $dayCount null counts actual/actual
     * (365 or 366 days per calendar year); 365 or 360 use a fixed basis, e.g. from
     * the dayCount of the country pack's DunningLaw.
     */
    public function interest(
        string $base,
        BigDecimal|string $ratePercent,
        Date|DateTimeInterface|string $from,
        Date|DateTimeInterface|string $until,
        ?string $description = null,
        ?int $dayCount = InterestCharge::ACTUAL_ACTUAL,
    ): static {
        $this->interest[] = [
            'base' => $base,
            'rate' => Decimal::of($ratePercent),
            'from' => Date::parse($from),
            'until' => Date::parse($until),
            'description' => $description,
            'dayCount' => $dayCount,
        ];

        return $this;
    }

    /**
     * Period of an account statement.
     */
    public function statementPeriod(Date|DateTimeInterface|string $start, Date|DateTimeInterface|string $end): static
    {
        $this->statementPeriod = new Period(Date::parse($start), Date::parse($end));

        return $this;
    }

    /**
     * Opening balance of an account statement.
     *
     * @throws InvalidArgumentException for a float amount
     */
    public function openingBalance(BigDecimal|int|float|string $amount): static
    {
        $this->openingBalance = (string) Decimal::rejectFloat($amount);

        return $this;
    }

    /**
     * Design of this document, see InvoiceBuilder::presentation().
     *
     * @param  Presentation|array<string, mixed>|callable(Presentation): Presentation  $presentation
     */
    public function presentation(Presentation|array|callable $presentation): static
    {
        $this->invoice->presentation($presentation);

        return $this;
    }

    /**
     * Language of the document, see InvoiceBuilder::locale().
     */
    public function locale(string $locale): static
    {
        $this->invoice->locale($locale);

        return $this;
    }

    /**
     * Free data for templates (view model key meta.custom).
     */
    public function custom(string $key, mixed $value): static
    {
        $this->invoice->custom($key, $value);

        return $this;
    }

    /**
     * Direct access to the underlying invoice builder for everything not wrapped here.
     *
     * @param  callable(InvoiceBuilder): mixed  $callback
     */
    public function tap(callable $callback): static
    {
        $callback($this->invoice);

        return $this;
    }

    /**
     * The immutable document, not calculated yet (DocumentCalculator, or the render run).
     *
     * @throws InvalidInvoiceData when the seller or the buyer is missing, or an item name or the VAT
     * of a line without default VAT
     */
    public function build(): DocumentData
    {
        $draft = $this->invoice->buildDraft();
        $currency = $draft->currency;

        $lineOptions = [];
        foreach ($this->options as $index => $options) {
            if (isset($draft->lines[$index])) {
                $lineOptions[$draft->lines[$index]->id] = $options;
            }
        }

        return new DocumentData(
            type: $this->type,
            number: $draft->number,
            issueDate: $draft->issueDate,
            currency: $currency,
            seller: $draft->seller,
            buyer: $draft->buyer,
            lines: $draft->lines,
            lineOptions: $lineOptions,
            allowances: $draft->allowances,
            charges: $draft->charges,
            notes: $draft->notes,
            validUntil: $this->validUntil,
            reference: $this->reference,
            referenceDate: $this->referenceDate,
            buyerReference: $draft->buyerReference,
            purchaseOrderReference: $draft->purchaseOrderReference,
            delivery: $draft->delivery,
            packages: $this->packages,
            dunning: $this->type === DocumentType::PaymentReminder
                ? $this->buildDunning($currency)
                : null,
            statement: $this->type === DocumentType::AccountStatement
                ? new AccountStatement(
                    period: $this->statementPeriod ?? new Period(end: $draft->issueDate),
                    items: $this->buildOpenItems($currency),
                    openingBalance: Amount::ofNullable($this->openingBalance, $currency),
                )
                : null,
            paymentTerms: $draft->paymentTerms,
            paymentInstructions: $draft->paymentInstructions,
            presentation: $draft->presentation,
            meta: $draft->meta,
        );
    }

    /**
     * @return list<OpenItem>
     */
    private function buildOpenItems(string $currency): array
    {
        return array_map(static fn (array $item): OpenItem => new OpenItem(
            invoiceNumber: $item['number'],
            invoiceDate: $item['date'],
            amount: Amount::of($item['amount'], $currency),
            dueDate: $item['due'],
            paid: Amount::ofNullable($item['paid'], $currency),
            description: $item['description'],
        ), $this->openItems);
    }

    private function buildDunning(string $currency): Dunning
    {
        return new Dunning(
            level: $this->dunningLevel,
            openItems: $this->buildOpenItems($currency),
            deadline: $this->deadline,
            fees: array_map(static fn (array $fee): DunningFee => new DunningFee($fee['description'], Amount::of($fee['amount'], $currency)), $this->fees),
            interest: array_map(static fn (array $entry): InterestCharge => new InterestCharge(
                base: Amount::of($entry['base'], $currency),
                ratePercent: $entry['rate'],
                from: $entry['from'],
                until: $entry['until'],
                description: $entry['description'],
                dayCount: $entry['dayCount'],
            ), $this->interest),
        );
    }
}
