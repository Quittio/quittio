<?php

declare(strict_types=1);

namespace Quittio\Core\Testing;

use Brick\Math\BigDecimal;
use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Builder\LineBuilder;
use Quittio\Core\Builder\PartyBuilder;
use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Calculation\TotalsCalculator;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Enums\InvoiceTypeCode;
use Quittio\Core\Enums\PaymentMeansCode;
use Quittio\Core\Enums\UnitCode;
use Quittio\Core\Enums\VatCategory;

/**
 * Test data factory: InvoiceData::factory()->reverseCharge()->withLines(5)->make().
 *
 * Deterministic (no random values), so that snapshots stay stable.
 */
final class InvoiceFactory
{
    private int $lines = 1;

    private string $vatMode = 'standard';

    private bool $consumer = false;

    private string $currency = 'EUR';

    private ?string $number = null;

    private bool $creditNote = false;

    /** @var list<callable(InvoiceBuilder): mixed> */
    private array $states = [];

    /**
     * Starts a factory for an invoice on the Scenarios::base() data: German seller and buyer, EUR,
     * one line at 19 % standard rate. Same as InvoiceData::factory().
     */
    public static function new(): self
    {
        return new self;
    }

    /**
     * Uses $count lines (at least 1): line n is "Item n", n pieces at n × 10.00 net. Returns a
     * copy.
     */
    public function withLines(int $count): self
    {
        return $this->set('lines', max(1, $count));
    }

    /**
     * Makes a reverse charge invoice (category AE, VATEX-EU-AE) to a French company with a VAT ID.
     * Returns a copy.
     */
    public function reverseCharge(): self
    {
        return $this->set('vatMode', 'reverse-charge');
    }

    /**
     * Makes an intra-community supply (category K, VATEX-EU-IC) to an Italian company with a VAT
     * ID. Returns a copy.
     */
    public function intraCommunity(): self
    {
        return $this->set('vatMode', 'intra-community');
    }

    /**
     * Makes an export invoice (category G, VATEX-EU-G) to a US company without a VAT ID. Returns a
     * copy.
     */
    public function export(): self
    {
        return $this->set('vatMode', 'export');
    }

    /**
     * Makes the buyer a German consumer without a buyer reference (BT-10). Takes precedence over
     * the buyer of reverseCharge(), intraCommunity() and export(). Returns a copy.
     */
    public function b2c(): self
    {
        return $this->set('consumer', true);
    }

    /**
     * Sets the invoice currency (BT-5). For any currency other than EUR the invoice also gets EUR
     * as VAT accounting currency (BT-6) at rate 0.9 and a plain credit transfer as payment means.
     * Returns a copy.
     */
    public function currency(string $currency): self
    {
        return $this->set('currency', $currency);
    }

    /**
     * Sets the invoice number (BT-1) instead of the base number "RE-2026-0042". Returns a copy.
     */
    public function number(string $number): self
    {
        return $this->set('number', $number);
    }

    /**
     * Makes a credit note (type 381) that refers to the preceding invoice RE-2026-0001 of
     * 2026-09-01 (BG-3). Returns a copy.
     */
    public function creditNote(): self
    {
        return $this->set('creditNote', true);
    }

    /**
     * Free modification of the builder.
     *
     * @param  callable(InvoiceBuilder): mixed  $state
     */
    public function state(callable $state): self
    {
        $clone = clone $this;
        $clone->states[] = $state;

        return $clone;
    }

    /**
     * Returns a new InvoiceBuilder with all chosen states applied, the state() callbacks last, for
     * further changes before build().
     */
    public function builder(): InvoiceBuilder
    {
        $builder = Scenarios::base()->currency($this->currency);
        if ($this->currency !== 'EUR') {
            $builder->taxCurrency('EUR', '0.9', '2026-10-02')->payment(PaymentMeansCode::CreditTransfer, iban: 'DE02120300000000202051', bic: 'BYLADEM1001');
        }
        if ($this->number !== null) {
            $builder->number($this->number);
        }
        if ($this->creditNote) {
            $builder->type(InvoiceTypeCode::CreditNote)->precedingInvoice('RE-2026-0001', '2026-09-01');
        }

        $buyer = match (true) {
            $this->consumer => static fn (PartyBuilder $b): PartyBuilder => $b->name('Erika Mustermann')->consumer()->address('Lindenweg 3', '10115', 'Berlin', 'DE'),
            $this->vatMode === 'reverse-charge' => static fn (PartyBuilder $b): PartyBuilder => $b->name('Client SARL')->vatId('FR12345678901')->address('12 Rue de la Paix', '75002', 'Paris', 'FR'),
            $this->vatMode === 'intra-community' => static fn (PartyBuilder $b): PartyBuilder => $b->name('Cliente S.r.l.')->vatId('IT12345678901')->address('Via Roma 1', '00184', 'Roma', 'IT'),
            $this->vatMode === 'export' => static fn (PartyBuilder $b): PartyBuilder => $b->name('Customer Inc.')->address('1 Main Street', '10001', 'New York', 'US'),
            default => null,
        };
        if ($buyer !== null) {
            $builder->buyer($buyer);
            if ($this->consumer) {
                $builder->buyerReference(null);
            }
        }

        for ($i = 1; $i <= $this->lines; $i++) {
            $builder->line(fn (LineBuilder $l): LineBuilder => $this->line($l, $i));
        }

        foreach ($this->states as $state) {
            $state($builder);
        }

        return $builder;
    }

    /**
     * Uncalculated invoice data.
     */
    public function make(): InvoiceData
    {
        return $this->builder()->build();
    }

    /**
     * Invoice data with totals and VAT breakdown, calculated with $calculator (default: En16931Calculator).
     */
    public function calculated(?TotalsCalculator $calculator = null): InvoiceData
    {
        return ($calculator ?? new En16931Calculator)->calculate($this->make());
    }

    private function line(LineBuilder $line, int $index): LineBuilder
    {
        $line->name(sprintf('Item %d', $index))
            ->quantity($index, UnitCode::Piece)
            ->netPrice(BigDecimal::of(10 * $index)->toScale(2));

        return match ($this->vatMode) {
            'reverse-charge' => $line->vat(VatCategory::ReverseCharge, '0', 'Reverse charge', 'VATEX-EU-AE'),
            'intra-community' => $line->vat(VatCategory::IntraCommunitySupply, '0', 'Intra-community supply', 'VATEX-EU-IC'),
            'export' => $line->vat(VatCategory::Export, '0', 'Export outside the EU', 'VATEX-EU-G'),
            default => $line->vat(VatCategory::StandardRate, '19'),
        };
    }

    private function set(string $property, mixed $value): self
    {
        $clone = clone $this;
        match ($property) {
            'lines' => $clone->lines = is_int($value) ? $value : 1,
            'vatMode' => $clone->vatMode = is_string($value) ? $value : 'standard',
            'consumer' => $clone->consumer = $value === true,
            'currency' => $clone->currency = is_string($value) ? $value : 'EUR',
            'number' => $clone->number = is_string($value) ? $value : null,
            'creditNote' => $clone->creditNote = $value === true,
            default => null,
        };

        return $clone;
    }
}
