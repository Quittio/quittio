<?php

declare(strict_types=1);

namespace Quittio\Core\Countries;

/**
 * Requirements for invoice numbering in a country (used by the Managed layer).
 */
final readonly class NumberingRules
{
    /**
     * @param  list<string>  $allowedResets  never, yearly, monthly
     */
    public function __construct(
        public string $defaultPattern = 'INV-{Y}-{seq:4}',
        public string $defaultReset = 'yearly',
        public array $allowedResets = ['never', 'yearly', 'monthly'],
        public bool $gapless = true,
        public bool $numberConsumedOnRejection = true,
    ) {}
}
