<?php

declare(strict_types=1);

namespace Quittio\Core\Countries;

/**
 * Statutory late payment rules (EU late payment directive plus national law).
 * Amounts and percentage points are decimal strings.
 *
 * Informational metadata for applications and the pack's own helpers: the core does not apply
 * it. Reminders take their fees and default interest explicitly (DocumentBuilder::fee(),
 * interest() with a rate and a day count basis, e.g. $dayCount from here).
 */
final readonly class DunningLaw
{
    /**
     * @param  int|null  $dayCount  days per year for default interest: 365 or 360, or null for actual/actual
     *         (365 or 366 days per calendar year, InterestCharge::ACTUAL_ACTUAL)
     * @param  int|null  $consumerDefaultAfterDays  days after due date and receipt after which a consumer is in
     *         default without a reminder, if the invoice points this out (e.g. section 286 (3) BGB); null = no such rule
     * @param  string|null  $b2cFixedRate  fixed rate in percent for consumers instead of the reference rate plus
     *         $b2cInterestPoints (e.g. 4 % in Austria, section 1000 (1) ABGB); null = reference rate plus points
     * @param  string|null  $b2bFixedRate  fixed rate in percent for businesses instead of the reference rate plus
     *         $b2bInterestPoints (e.g. 5 % in Switzerland, Art. 104 OR); null = reference rate plus points
     */
    public function __construct(
        public string $b2bInterestPoints = '8.00',
        public string $b2cInterestPoints = '0.00',
        public ?string $b2bFlatRate = '40.00',
        public ?int $dayCount = 365,
        public ?int $consumerDefaultAfterDays = null,
        public ?string $referenceRateName = null,
        public ?string $b2cFixedRate = null,
        public ?string $b2bFixedRate = null,
    ) {}

    /**
     * Whether the law needs no reference rate: fixed rates for businesses and consumers.
     */
    public function hasFixedRates(): bool
    {
        return $this->b2bFixedRate !== null && $this->b2cFixedRate !== null;
    }
}
