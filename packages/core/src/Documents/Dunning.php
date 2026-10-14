<?php

declare(strict_types=1);

namespace Quittio\Core\Documents;

use Brick\Money\Money;
use InvalidArgumentException;
use Quittio\Core\Support\Amount;
use Quittio\Core\Support\Date;

/**
 * Data of a payment reminder: open items, fees, interest and the new deadline.
 *
 * Level 0 is a friendly payment reminder (Zahlungserinnerung), level 1 the first formal
 * reminder (1. Mahnung), level 2 the second and so on.
 *
 * Fees and default interest are damages, not consideration for a supply: they carry no
 * VAT, and {@see total()} adds them without VAT.
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
    ) {
        if ($level < 0) {
            throw new InvalidArgumentException(sprintf('The dunning level must not be negative, %d given.', $level));
        }
    }

    public function openTotal(string $currency): Money
    {
        $sum = Amount::zero($currency);
        foreach ($this->openItems as $item) {
            $sum = $sum->plus(Amount::of($item->open(), $currency));
        }

        return $sum;
    }

    public function feeTotal(string $currency): Money
    {
        $sum = Amount::zero($currency);
        foreach ($this->fees as $fee) {
            $sum = $sum->plus(Amount::of($fee->amount, $currency));
        }

        return $sum;
    }

    public function interestTotal(string $currency): Money
    {
        $sum = Amount::zero($currency);
        foreach ($this->interest as $interest) {
            $sum = $sum->plus(Amount::of($interest->total(), $currency));
        }

        return $sum;
    }

    /**
     * Amount to pay: open items plus fees plus interest (no VAT on fees and interest).
     * This is the amount for a payment QR code of the reminder.
     */
    public function total(string $currency): Money
    {
        return $this->openTotal($currency)->plus($this->feeTotal($currency))->plus($this->interestTotal($currency));
    }

    /**
     * Invoice numbers of the items that are still open, e.g. for the remittance information.
     *
     * @return list<string>
     */
    public function openInvoiceNumbers(): array
    {
        $numbers = [];
        foreach ($this->openItems as $item) {
            if (! $item->isSettled() && ! in_array($item->invoiceNumber, $numbers, true)) {
                $numbers[] = $item->invoiceNumber;
            }
        }

        return $numbers;
    }

    /**
     * Remittance information for a payment of the reminder: the open invoice numbers,
     * separated by commas, with as many complete numbers as fit into $maxLength
     * (140 characters for SEPA and the EPC QR code).
     */
    public function remittanceText(int $maxLength = 140): string
    {
        $text = '';
        foreach ($this->openInvoiceNumbers() as $number) {
            $candidate = $text === '' ? $number : $text.', '.$number;
            if (mb_strlen($candidate) > $maxLength) {
                break;
            }
            $text = $candidate;
        }

        return $text;
    }
}
