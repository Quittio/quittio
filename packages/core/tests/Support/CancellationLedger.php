<?php

declare(strict_types=1);

namespace Quittio\Core\Tests\Support;

use Brick\Math\BigDecimal;
use LogicException;
use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Calculation\DeclaredAmounts;
use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Calculation\LineCalculator;
use Quittio\Core\Corrections\Cancellation;
use Quittio\Core\Corrections\CancellationHistory;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Data\Lines\InvoiceLine;

/**
 * The cancellation ledger of the Managed layer in a nutshell, for core tests of partial
 * cancellations with a CancellationHistory: remaining quantity and amount per line (net amount,
 * gross amount for lines with a price including VAT), the last part of a line capped to the
 * remaining amount, the VAT groups a cancellation closes and the history of the earlier ones.
 */
final class CancellationLedger
{
    /** @var array<string, array{quantity: BigDecimal, amount: BigDecimal, initial: BigDecimal, byAmount: bool, key: string}> */
    private array $lines = [];

    private CancellationHistory $history;

    private int $count = 0;

    public function __construct(
        public readonly InvoiceData $original,
        public readonly string $strategy = 'credit_note',
    ) {
        foreach ($original->lines as $line) {
            $amount = self::amount($line);
            $this->lines[$line->id] = [
                'quantity' => $line->quantity->abs(),
                'amount' => $amount,
                'initial' => $amount,
                'byAmount' => false,
                'key' => DeclaredAmounts::breakdownKey($line->vat->category, $line->vat->rate),
            ];
        }
        $this->history = new CancellationHistory;
    }

    /**
     * Ids of the lines that can still be cancelled.
     *
     * @return list<string>
     */
    public function open(): array
    {
        return array_map(strval(...), array_keys(array_filter($this->lines, self::isOpen(...))));
    }

    /**
     * @return array{quantity: BigDecimal, amount: BigDecimal, byAmount: bool}
     */
    public function remaining(string $line): array
    {
        return $this->lines[$line];
    }

    /**
     * The request for everything that remains of a line.
     *
     * @return array{line: string, quantity?: string, amount?: string}
     */
    public function rest(string $line): array
    {
        $balance = $this->lines[$line];

        return $balance['byAmount'] || ! $balance['quantity']->isPositive()
            ? ['line' => $line, 'amount' => (string) $balance['amount']]
            : ['line' => $line, 'quantity' => (string) $balance['quantity']];
    }

    /**
     * Issues a partial cancellation with the history of the earlier ones.
     *
     * @param  list<array{line: string, quantity?: string, amount?: string}>  $request
     * @return array{builder: InvoiceBuilder, data: InvoiceData, history: CancellationHistory}
     */
    public function cancel(array $request): array
    {
        $number = 'ST-'.(++$this->count);
        $calculator = new En16931Calculator;
        $calculated = $calculator->calculate(Cancellation::of($this->original, $number, $this->strategy, lines: $request)->build());

        // The last part of a line cancels exactly what remains.
        $capped = [];
        foreach ($request as $entry) {
            $balance = $this->lines[$entry['line']];
            $line = self::find($calculated, $entry['line']);
            if (isset($entry['quantity']) && BigDecimal::of($entry['quantity'])->isEqualTo($balance['quantity'])
                && $balance['amount']->isPositive() && ! self::amount($line)->isEqualTo($balance['amount'])) {
                $entry = ['line' => $entry['line'], 'amount' => (string) $balance['amount']];
            }
            $capped[] = $entry;
        }
        if ($capped !== $request) {
            $request = $capped;
            $calculated = $calculator->calculate(Cancellation::of($this->original, $number, $this->strategy, lines: $request)->build());
        }

        $after = $this->lines;
        foreach ($request as $entry) {
            $line = self::find($calculated, $entry['line']);
            $balance = $after[$entry['line']];
            $after[$entry['line']] = [
                ...$balance,
                'quantity' => isset($entry['amount']) ? $balance['quantity'] : $balance['quantity']->minus($line->quantity->abs()),
                'amount' => $balance['amount']->minus(self::amount($line)),
                'byAmount' => $balance['byAmount'] || isset($entry['amount']),
            ];
        }
        $open = [];
        foreach ($after as $balance) {
            $open[$balance['key']] = ($open[$balance['key']] ?? false) || self::isOpen($balance);
        }

        $history = new CancellationHistory($this->history->groups, array_keys(array_filter($open, static fn (bool $isOpen): bool => ! $isOpen)));
        $builder = Cancellation::of($this->original, $number, $this->strategy, lines: $request, history: $history);
        $data = $calculator->calculate($builder->build());

        $this->lines = $after;
        $this->history = $history->withCancellation($this->original, $data, $history->closes);

        return ['builder' => $builder, 'data' => $data, 'history' => $history];
    }

    /**
     * @param  array{quantity: BigDecimal, amount: BigDecimal, initial: BigDecimal, byAmount: bool, key: string}  $balance
     */
    private static function isOpen(array $balance): bool
    {
        return $balance['amount']->isPositive() || ($balance['initial']->isZero() && ! $balance['byAmount'] && $balance['quantity']->isPositive());
    }

    private static function find(InvoiceData $data, string $id): InvoiceLine
    {
        foreach ($data->lines as $line) {
            if ($line->id === $id) {
                return $line;
            }
        }

        throw new LogicException(sprintf('Line %s is missing.', $id));
    }

    /**
     * Net amount, gross amount for a price including VAT; as magnitude.
     */
    private static function amount(InvoiceLine $line): BigDecimal
    {
        $priceInclVat = $line->price->priceInclVat;

        return $priceInclVat === null
            ? ($line->netAmount?->getAmount() ?? BigDecimal::zero())->abs()
            : LineCalculator::baseAmount($line->quantity, $priceInclVat, $line->price->baseQuantity)->abs();
    }
}
