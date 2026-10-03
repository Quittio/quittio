<?php

declare(strict_types=1);

namespace Quittio\Core\Countries;

use Quittio\Core\Support\Date;

/**
 * Retention period for invoices.
 */
final readonly class RetentionRule
{
    public function __construct(
        public int $years,
        public bool $fromEndOfCalendarYear = true,
    ) {}

    /**
     * Last day of the retention period for an invoice issued on $issueDate.
     */
    public function until(Date $issueDate): Date
    {
        if ($this->fromEndOfCalendarYear) {
            return Date::of($issueDate->year + $this->years, 12, 31);
        }

        return Date::parse($issueDate->toCarbon('UTC')->addYears($this->years));
    }
}
