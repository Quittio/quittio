<?php

declare(strict_types=1);

namespace Quittio\Core\Documents;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Quittio\Core\Support\Amount;
use Quittio\Core\Support\Date;

/**
 * Default interest on an open amount for a period (simple interest, actual/365).
 */
final readonly class InterestCharge
{
    public function __construct(
        public Money $base,
        public BigDecimal $ratePercent,
        public Date $from,
        public Date $until,
        public ?string $description = null,
        public ?Money $amount = null,
    ) {}

    public function days(): int
    {
        return max(0, $this->from->diffInDays($this->until));
    }

    /**
     * Stated amount, or base × rate × days / 365 rounded half up.
     */
    public function total(): Money
    {
        if ($this->amount !== null) {
            return $this->amount;
        }

        $interest = $this->base->getAmount()
            ->multipliedBy($this->ratePercent)
            ->multipliedBy($this->days())
            ->dividedBy(36500, Amount::SCALE, RoundingMode::HalfUp);

        return Amount::of($interest, $this->base->getCurrency()->getCurrencyCode());
    }
}
