<?php

declare(strict_types=1);

namespace Quittio\Core\Documents;

use Brick\Money\Money;
use Quittio\Core\Data\Delivery\Period;
use Quittio\Core\Exceptions\InvalidArgumentException;
use Quittio\Core\Support\Amount;

/**
 * Account statement for a customer: open and paid invoices of a period.
 *
 * The balance is the opening balance plus the open amounts of the listed items. The
 * opening balance is the balance at the start of the period, so it already contains every
 * invoice dated before the period: with an opening balance, only items dated within the
 * period may be listed. Without an opening balance the statement is an open item list and
 * may list older items. Items dated after the end of the period are never accepted.
 */
final readonly class AccountStatement
{
    /**
     * @param  list<OpenItem>  $items
     */
    public function __construct(
        public Period $period,
        public array $items,
        public ?Money $openingBalance = null,
    ) {
        if (! $period->isValid()) {
            throw new InvalidArgumentException('The end of the statement period must not lie before its start.');
        }
        foreach ($items as $item) {
            if ($period->end !== null && $item->invoiceDate->isAfter($period->end)) {
                throw new InvalidArgumentException(sprintf(
                    'The item %s is dated %s, after the end of the statement period (%s).',
                    $item->invoiceNumber,
                    $item->invoiceDate->toIsoString(),
                    $period->end->toIsoString(),
                ));
            }
            if ($openingBalance !== null && $period->start !== null && $item->invoiceDate->isBefore($period->start)) {
                throw new InvalidArgumentException(sprintf(
                    'The item %s is dated %s, before the start of the statement period (%s). The opening balance already contains it; list only items of the period or omit the opening balance.',
                    $item->invoiceNumber,
                    $item->invoiceDate->toIsoString(),
                    $period->start->toIsoString(),
                ));
            }
        }
    }

    /**
     * @return list<OpenItem>
     */
    public function openItems(): array
    {
        return array_values(array_filter($this->items, static fn (OpenItem $item): bool => ! $item->isSettled()));
    }

    /**
     * Opening balance plus the open amounts of all listed items.
     */
    public function balance(string $currency): Money
    {
        $sum = $this->openingBalance === null ? Amount::zero($currency) : Amount::of($this->openingBalance, $currency);
        foreach ($this->items as $item) {
            $sum = $sum->plus(Amount::of($item->open(), $currency));
        }

        return $sum;
    }
}
