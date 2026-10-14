<?php

declare(strict_types=1);

namespace Quittio\Core\Documents;

use Brick\Math\BigDecimal;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use InvalidArgumentException;
use Quittio\Core\Support\Amount;
use Quittio\Core\Support\Date;

/**
 * Default interest on an open amount for a period (simple interest).
 *
 * The period runs from $from (first day with interest, inclusive) to $until (exclusive):
 * interest from 2026-02-01 until 2026-03-01 covers 28 days, the last day with interest is
 * {@see lastDay()} (2026-02-28). Show lastDay() in texts, never $until.
 *
 * Day count: by default actual/actual, i.e. the days of each calendar year are divided by
 * the length of that year (365 or 366), so that a full year yields exactly the annual rate
 * (section 288 BGB: "für das Jahr"). A fixed basis ($dayCount 365 or 360) divides all
 * days by that number instead.
 */
final readonly class InterestCharge
{
    /** Actual days divided by the length of each calendar year (365 or 366). */
    public const ?int ACTUAL_ACTUAL = null;

    public function __construct(
        public Money $base,
        public BigDecimal $ratePercent,
        public Date $from,
        public Date $until,
        public ?string $description = null,
        public ?Money $amount = null,
        public ?int $dayCount = self::ACTUAL_ACTUAL,
    ) {
        if ($dayCount !== null && ! in_array($dayCount, [360, 365], true)) {
            throw new InvalidArgumentException(sprintf('The day count basis must be 360, 365 or null for actual/actual, %d given.', $dayCount));
        }
        if ($until->isBefore($from)) {
            // A reversed period would silently yield no interest and a last day before the first.
            throw new InvalidArgumentException(sprintf('The interest period must not end (%s) before it starts (%s).', $until->toIsoString(), $from->toIsoString()));
        }
    }

    /**
     * Days with interest: from $from inclusive to $until exclusive.
     */
    public function days(): int
    {
        return max(0, $this->from->diffInDays($this->until));
    }

    /**
     * The last day with interest (the day before $until), for display.
     */
    public function lastDay(): Date
    {
        return $this->until->addDays(-1);
    }

    /**
     * Stated amount, or base × rate × days / day count basis, rounded half up once.
     */
    public function total(): Money
    {
        if ($this->amount !== null) {
            return $this->amount;
        }

        $currency = $this->base->getCurrency()->getCurrencyCode();
        if ($this->days() === 0) {
            return Amount::zero($currency);
        }

        $principal = $this->base->getAmount()->multipliedBy($this->ratePercent);
        $interest = BigRational::zero();
        foreach ($this->yearSegments() as [$days, $yearLength]) {
            $interest = $interest->plus($principal->multipliedBy($days)->toBigRational()->dividedBy(100 * $yearLength));
        }

        return Amount::of($interest->toScale(Amount::SCALE, RoundingMode::HalfUp), $currency);
    }

    /**
     * Days per calendar year with the divisor that applies to them.
     *
     * @return list<array{0: int, 1: int}> [days, divisor]
     */
    private function yearSegments(): array
    {
        if ($this->dayCount !== null) {
            return [[$this->days(), $this->dayCount]];
        }

        $segments = [];
        $start = $this->from;
        while ($start->isBefore($this->until)) {
            $nextYear = Date::of($start->year + 1, 1, 1);
            $end = $nextYear->isBefore($this->until) ? $nextYear : $this->until;
            $yearLength = checkdate(2, 29, $start->year) ? 366 : 365;
            $segments[] = [$start->diffInDays($end), $yearLength];
            $start = $end;
        }

        return $segments;
    }
}
