<?php

declare(strict_types=1);

namespace Quittio\Core\Documents;

use Brick\Math\BigDecimal;
use DateTimeInterface;
use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Builder\LineBuilder;
use Quittio\Core\Builder\PartyBuilder;
use Quittio\Core\Builder\PaymentTermsBuilder;
use Quittio\Core\Contracts\ProvidesInvoiceLine;
use Quittio\Core\Data\Delivery\Period;
use Quittio\Core\Data\Lines\InvoiceLine;
use Quittio\Core\Data\Party\Address;
use Quittio\Core\Data\Party\BuyerParty;
use Quittio\Core\Data\Party\SellerParty;
use Quittio\Core\Data\Payment\PaymentTerms;
use Quittio\Core\Data\Presentation\Presentation;
use Quittio\Core\Enums\NoteSubjectCode;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Support\Amount;
use Quittio\Core\Support\Date;
use Quittio\Core\Support\Decimal;
use Quittio\Core\Support\Macroable;

/**
 * Fluent builder for documents (specification 13). Parties, lines, notes, discounts and
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

    public static function make(DocumentType $type): static
    {
        return new static($type);
    }

    public static function quote(): static
    {
        return new static(DocumentType::Quote);
    }

    public static function orderConfirmation(): static
    {
        return new static(DocumentType::OrderConfirmation);
    }

    public static function deliveryNote(): static
    {
        return new static(DocumentType::DeliveryNote);
    }

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

    public static function accountStatement(): static
    {
        return new static(DocumentType::AccountStatement);
    }

    public function number(string $number): static
    {
        $this->invoice->number($number);

        return $this;
    }

    public function issueDate(Date|DateTimeInterface|string $date): static
    {
        $this->invoice->issueDate($date);

        return $this;
    }

    public function currency(string $currency): static
    {
        $this->invoice->currency($currency);

        return $this;
    }

    /**
     * @param  SellerParty|callable(PartyBuilder): mixed  $seller
     */
    public function seller(SellerParty|callable $seller): static
    {
        $this->invoice->seller($seller);

        return $this;
    }

    /**
     * @param  BuyerParty|callable(PartyBuilder): mixed  $buyer
     */
    public function buyer(BuyerParty|callable $buyer): static
    {
        $this->invoice->buyer($buyer);

        return $this;
    }

    /**
     * @param  InvoiceLine|ProvidesInvoiceLine|callable(LineBuilder): mixed  $line
     */
    public function line(InvoiceLine|ProvidesInvoiceLine|callable $line, ?DocumentLineOptions $options = null): static
    {
        if ($this->type === DocumentType::DeliveryNote && ! $line instanceof InvoiceLine && ! $line instanceof ProvidesInvoiceLine) {
            // Delivery notes carry no prices: defaults keep the line valid for the calculator.
            $callback = $line;
            $line = static function (LineBuilder $builder) use ($callback): void {
                $builder->netPrice(0)->vat(VatCategory::NotSubjectToVat);
                $callback($builder);
            };
        }

        $this->invoice->line($line);
        if ($options !== null) {
            $this->options[$this->lineCount] = $options;
        }
        $this->lineCount++;

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
     * Optional quote line, shown with its price but not included in the totals.
     *
     * @param  InvoiceLine|callable(LineBuilder): mixed  $line
     */
    public function optionalLine(InvoiceLine|callable $line): static
    {
        return $this->line($line, new DocumentLineOptions(optional: true));
    }

    /**
     * Alternative to another line, shown but not included in the totals.
     *
     * @param  InvoiceLine|callable(LineBuilder): mixed  $line
     */
    public function alternativeLine(string $alternativeTo, InvoiceLine|callable $line): static
    {
        return $this->line($line, new DocumentLineOptions(alternativeTo: $alternativeTo));
    }

    /**
     * Delivery note line with serial numbers.
     *
     * @param  InvoiceLine|callable(LineBuilder): mixed  $line
     * @param  list<string>  $serialNumbers
     */
    public function serialisedLine(InvoiceLine|callable $line, array $serialNumbers): static
    {
        return $this->line($line, new DocumentLineOptions(serialNumbers: $serialNumbers));
    }

    public function note(string $content, ?NoteSubjectCode $subject = null): static
    {
        $this->invoice->note($content, $subject);

        return $this;
    }

    public function discountPercent(string $percent, ?string $reason = null): static
    {
        $this->invoice->discountPercent($percent, $reason);

        return $this;
    }

    public function discountAmount(string $amount, ?string $reason = null): static
    {
        $this->invoice->discountAmount($amount, $reason);

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

    public function creditTransfer(string $iban, ?string $bic = null, ?string $accountName = null): static
    {
        $this->invoice->creditTransfer($iban, $bic, $accountName);

        return $this;
    }

    public function buyerReference(?string $reference): static
    {
        $this->invoice->buyerReference($reference);

        return $this;
    }

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

    public function validUntil(Date|DateTimeInterface|string $date): static
    {
        $this->validUntil = Date::parse($date);

        return $this;
    }

    public function deliveryDate(Date|DateTimeInterface|string $date): static
    {
        $this->invoice->deliveryDate($date);

        return $this;
    }

    public function deliverTo(?string $recipientName, ?Address $address = null): static
    {
        $this->invoice->deliverTo($recipientName, $address);

        return $this;
    }

    public function package(Package $package): static
    {
        $this->packages[] = $package;

        return $this;
    }

    public function deadline(Date|DateTimeInterface|string $date): static
    {
        $this->deadline = Date::parse($date);

        return $this;
    }

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

    public function fee(string $description, string $amount): static
    {
        $this->fees[] = ['description' => $description, 'amount' => $amount];

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

    public function statementPeriod(Date|DateTimeInterface|string $start, Date|DateTimeInterface|string $end): static
    {
        $this->statementPeriod = new Period(Date::parse($start), Date::parse($end));

        return $this;
    }

    public function openingBalance(string $amount): static
    {
        $this->openingBalance = $amount;

        return $this;
    }

    /**
     * @param  Presentation|callable(Presentation): Presentation  $presentation
     */
    public function presentation(Presentation|callable $presentation): static
    {
        $this->invoice->presentation($presentation);

        return $this;
    }

    public function locale(string $locale): static
    {
        $this->invoice->locale($locale);

        return $this;
    }

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
