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
     * @param  int|null  $consumerDefaultAfterDays  days after due date and receipt after which a consumer is in
     *         default without a reminder, if the invoice points this out (e.g. section 286 (3) BGB); null = no such rule
     */
    public function __construct(
        public string $b2bInterestPoints = '8.00',
        public string $b2cInterestPoints = '0.00',
        public ?string $b2bFlatRate = '40.00',
        public int $dayCount = 365,
        public ?int $consumerDefaultAfterDays = null,
        public ?string $referenceRateName = null,
    ) {}
}
