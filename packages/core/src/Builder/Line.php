<?php

declare(strict_types=1);

namespace Quittio\Core\Builder;

use InvalidArgumentException;
use Quittio\Core\Contracts\ProvidesInvoiceLine;

/**
 * Starts an invoice line for InvoiceBuilder::line() and lines() without a callback:
 * ->line(Line::make('Consulting')->quantity(4, UnitCode::Hour)->netPrice('120.00')).
 */
final class Line
{
    public static function make(string $name): LineBuilder
    {
        return (new LineBuilder)->name($name);
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
     * The builder of a line given in any of the forms that line() accepts except InvoiceLine.
     * A builder is copied, so a line used as template stays unchanged.
     *
     * @internal
     *
     * @param  LineBuilder|ProvidesInvoiceLine|array<array-key, mixed>|callable(LineBuilder): mixed  $line
     */
    public static function from(LineBuilder|ProvidesInvoiceLine|array|callable $line): LineBuilder
    {
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
