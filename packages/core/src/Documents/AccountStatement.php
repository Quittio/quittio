<?php

declare(strict_types=1);

namespace Quittio\Core\Documents;

use Brick\Money\Money;
use Quittio\Core\Data\Delivery\Period;
use Quittio\Core\Support\Amount;

/**
 * Account statement for a customer: open and paid invoices of a period.
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
    ) {}

    /**
     * @return list<OpenItem>
     */
    public function openItems(): array
    {
        return array_values(array_filter($this->items, static fn (OpenItem $item): bool => ! $item->isSettled()));
    }

    public function balance(string $currency): Money
    {
        $sum = $this->openingBalance ?? Amount::zero($currency);
        foreach ($this->items as $item) {
            $sum = $sum->plus($item->open());
        }

        return $sum;
    }
}
