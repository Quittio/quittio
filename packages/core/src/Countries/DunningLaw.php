<?php

declare(strict_types=1);

namespace Quittio\Core\Countries;

/**
 * Statutory late payment rules (EU late payment directive plus national law).
 * Amounts and percentage points are decimal strings.
 */
final readonly class DunningLaw
{
    public function __construct(
        public string $b2bInterestPoints = '8.00',
        public string $b2cInterestPoints = '0.00',
        public ?string $b2bFlatRate = '40.00',
        public int $dayCount = 365,
        public ?int $consumerDefaultAfterDays = null,
        public ?string $referenceRateName = null,
    ) {}
}
