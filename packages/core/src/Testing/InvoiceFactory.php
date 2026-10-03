<?php

declare(strict_types=1);

namespace Quittio\Core\Testing;

use Brick\Math\BigDecimal;
use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Builder\LineBuilder;
use Quittio\Core\Builder\PartyBuilder;
use Quittio\Core\Calculation\En16931Calculator;
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

    public static function new(): self
    {
        return new self;
    }

    public function withLines(int $count): self
    {
        return $this->set('lines', max(1, $count));
    }

    public function reverseCharge(): self
    {
        return $this->set('vatMode', 'reverse-charge');
    }

    public function intraCommunity(): self
    {
        return $this->set('vatMode', 'intra-community');
    }

    public function export(): self
    {
        return $this->set('vatMode', 'export');
    }

    public function b2c(): self
    {
        return $this->set('consumer', true);
    }

    public function currency(string $currency): self
    {
        return $this->set('currency', $currency);
    }

    public function number(string $number): self
    {
        return $this->set('number', $number);
    }

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
     * Invoice data with totals and VAT breakdown.
     */
    public function calculated(): InvoiceData
    {
        return (new En16931Calculator)->calculate($this->make());
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
