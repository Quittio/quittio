<?php

declare(strict_types=1);

namespace Quittio\Core\Builder;

use Brick\Math\BigDecimal;
use InvalidArgumentException;
use Quittio\Core\Codes\RawCode;
use Quittio\Core\Contracts\ProvidesInvoiceLine;
use Quittio\Core\Enums\UnitCode;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Tax\TaxPreset;

/**
 * Starts an invoice line for InvoiceBuilder::line() and lines() without a callback:
 * ->line(Line::make('Consulting', netPrice: '120.00')->sellerId('C-1')). Simple lines need no
 * builder: ->line('Consulting', netPrice: '120.00', quantity: 4, unit: UnitCode::Hour).
 */
final class Line
{
    /**
     * A line builder with the name and, optionally, the most common values: the net price
     * (BT-146) or the price including VAT (gross price mode), the quantity (default 1) and
     * unit, the VAT treatment (default: the default VAT of the invoice) and the description.
     */
    public static function make(
        string $name,
        BigDecimal|int|float|string|null $netPrice = null,
        BigDecimal|int|float|string|null $quantity = null,
        UnitCode|RawCode|string|null $unit = null,
        TaxPreset|VatCategory|null $vat = null,
        ?string $description = null,
        BigDecimal|int|float|string|null $priceInclVat = null,
    ): LineBuilder {
        $line = (new LineBuilder)->name($name);
        if ($netPrice !== null) {
            $line->netPrice($netPrice);
        }
        if ($priceInclVat !== null) {
            $line->priceInclVat($priceInclVat);
        }
        if ($quantity !== null) {
            $line->quantity($quantity);
        }
        if ($unit !== null) {
            $line->unit($unit);
        }
        if ($vat !== null) {
            $line->vat($vat);
        }
        if ($description !== null) {
            $line->description($description);
        }

        return $line;
    }

    /**
     * A line from an array whose keys are methods of the LineBuilder (LineBuilder::ARRAY_KEYS):
     * ['name' => 'Consulting', 'quantity' => 4, 'netPrice' => '120.00'].
     *
     * @param  array<array-key, mixed>  $line
     */
    public static function fromArray(array $line): LineBuilder
    {
        return (new LineBuilder)->fill($line);
    }

    /**
     * The builder of a line given in any of the forms that line() accepts except InvoiceLine:
     * a name with the values of make(), a builder (copied, so a line used as template stays
     * unchanged), an array, a model or a callable. The values are only allowed with a name.
     *
     * @internal
     *
     * @param  LineBuilder|ProvidesInvoiceLine|array<array-key, mixed>|callable(LineBuilder): mixed|string  $line
     */
    public static function from(
        LineBuilder|ProvidesInvoiceLine|array|callable|string $line,
        BigDecimal|int|float|string|null $netPrice = null,
        BigDecimal|int|float|string|null $quantity = null,
        UnitCode|RawCode|string|null $unit = null,
        TaxPreset|VatCategory|null $vat = null,
        ?string $description = null,
        BigDecimal|int|float|string|null $priceInclVat = null,
    ): LineBuilder {
        // A string is always the name of the line, never the name of a callable function.
        if (is_string($line)) {
            return self::make($line, $netPrice, $quantity, $unit, $vat, $description, $priceInclVat);
        }
        if ($netPrice !== null || $quantity !== null || $unit !== null || $vat !== null || $description !== null || $priceInclVat !== null) {
            throw new InvalidArgumentException('Price, quantity, unit, VAT and description can only be given with the name of a line, e.g. ->line(\'Consulting\', netPrice: \'120.00\'); set them on the line builder otherwise.');
        }
        if ($line instanceof LineBuilder) {
            return clone $line;
        }
        if ($line instanceof ProvidesInvoiceLine) {
            return (new LineBuilder)->fromModel($line);
        }
        if (is_array($line) && ! array_is_list($line)) {
            return self::fromArray($line);
        }
        if (! is_callable($line)) {
            throw new InvalidArgumentException('An invoice line given as array needs keys such as "name" and "netPrice".');
        }
        $builder = new LineBuilder;
        $line($builder);

        return $builder;
    }
}
