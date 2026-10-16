<?php

declare(strict_types=1);

namespace Quittio\Core\Builder\Concerns;

use Brick\Math\BigDecimal;
use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Builder\Line;
use Quittio\Core\Builder\LineBuilder;
use Quittio\Core\Codes\RawCode;
use Quittio\Core\Contracts\ProvidesInvoiceLine;
use Quittio\Core\Data\Identifier;
use Quittio\Core\Data\Lines\InvoiceLine;
use Quittio\Core\Data\Note;
use Quittio\Core\Data\Presentation\LineGroup;
use Quittio\Core\Data\Presentation\Presentation;
use Quittio\Core\Enums\UnitCode;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Exceptions\InvalidArgumentException;
use Quittio\Core\Support\Amount;
use Quittio\Core\Support\Decimal;
use Quittio\Core\Tax\TaxPreset;

/**
 * Invoice lines (BG-25) and their groups.
 *
 * Part of {@see InvoiceBuilder}, which holds the state; use the builder, not the trait.
 */
trait BuildsLines
{
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
     * with the named values ->updateLine('2', netPrice: '80.00', quantity: 3), or with a callable
     * that receives the InvoiceLine and returns the changed one, e.g.
     * ->updateLine('2', fn (InvoiceLine $l) => $l->with(['note' => 'Delivered in two parts'])).
     * The calculated line net amount is reset, and percentage allowances and charges on the
     * line amount are recalculated from the new amount.
     *
     * @param  (callable(InvoiceLine): InvoiceLine)|null  $change
     */
    public function updateLine(
        string $id,
        ?callable $change = null,
        BigDecimal|int|float|string|null $netPrice = null,
        BigDecimal|int|float|string|null $quantity = null,
        BigDecimal|int|float|string|null $priceInclVat = null,
        ?string $name = null,
        ?string $description = null,
    ): static {
        $named = static function (InvoiceLine $line) use ($netPrice, $quantity, $priceInclVat, $name, $description): InvoiceLine {
            if ($netPrice !== null) {
                $line = $line->withNetPrice($netPrice);
            }
            if ($priceInclVat !== null) {
                $line = $line->withPriceInclVat($priceInclVat);
            }
            if ($quantity !== null) {
                $line = $line->with(['quantity' => Decimal::of($quantity)]);
            }
            $item = array_filter(['name' => $name, 'description' => $description], static fn (?string $value): bool => $value !== null);

            return $item === [] ? $line : $line->with(['item' => $line->item->with($item)]);
        };
        $found = false;
        $lines = [];
        foreach ($this->lines as $line) {
            if (! $found && $line instanceof InvoiceLine && $line->id === $id) {
                $changed = $named($change === null ? $line : $change($line));
                $line = self::withPercentagesReset($changed, self::lineBaseAmount($line))->with(['netAmount' => null]);
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
}
