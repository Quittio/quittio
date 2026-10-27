<?php

declare(strict_types=1);

namespace Quittio\Core\Documents;

use Brick\Money\Money;
use Quittio\Core\Support\Amount;
use Quittio\Core\Support\Date;

/**
 * An invoice in a payment reminder or account statement.
 */
final readonly class OpenItem
{
    public function __construct(
        public string $invoiceNumber,
        public Date $invoiceDate,
        public Money $amount,
        public ?Date $dueDate = null,
        public ?Money $paid = null,
        public ?string $description = null,
    ) {}

    /**
     * Amount still open (amount minus paid); negative for overpaid items and credits.
     */
    public function open(): Money
    {
        $currency = $this->amount->getCurrency()->getCurrencyCode();
        $amount = Amount::of($this->amount, $currency);

        return $this->paid === null ? $amount : $amount->minus(Amount::of($this->paid, $currency));
    }

    /**
     * Whether nothing is open any more (open() is zero or negative).
     */
    public function isSettled(): bool
    {
        return ! $this->open()->isPositive();
    }
}
