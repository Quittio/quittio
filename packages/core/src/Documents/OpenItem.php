<?php

declare(strict_types=1);

namespace Quittio\Core\Documents;

use Brick\Money\Money;
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

    public function open(): Money
    {
        return $this->paid === null ? $this->amount : $this->amount->minus($this->paid);
    }

    public function isSettled(): bool
    {
        return ! $this->open()->isPositive();
    }
}
