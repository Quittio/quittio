<?php

declare(strict_types=1);

namespace Quittio\Core\Documents;

use Brick\Money\Money;
use Quittio\Core\Support\Amount;
use Quittio\Core\Support\Date;

/**
 * Data of a payment reminder: open items, fees, interest and the new deadline.
 */
final readonly class Dunning
{
    /**
     * @param  list<OpenItem>  $openItems
     * @param  list<DunningFee>  $fees
     * @param  list<InterestCharge>  $interest
     */
    public function __construct(
        public int $level,
        public array $openItems,
        public ?Date $deadline = null,
        public array $fees = [],
        public array $interest = [],
    ) {}

    public function openTotal(string $currency): Money
    {
        $sum = Amount::zero($currency);
        foreach ($this->openItems as $item) {
            $sum = $sum->plus($item->open());
        }

        return $sum;
    }

    public function feeTotal(string $currency): Money
    {
        $sum = Amount::zero($currency);
        foreach ($this->fees as $fee) {
            $sum = $sum->plus($fee->amount);
        }

        return $sum;
    }

    public function interestTotal(string $currency): Money
    {
        $sum = Amount::zero($currency);
        foreach ($this->interest as $interest) {
            $sum = $sum->plus($interest->total());
        }

        return $sum;
    }

    public function total(string $currency): Money
    {
        return $this->openTotal($currency)->plus($this->feeTotal($currency))->plus($this->interestTotal($currency));
    }
}
