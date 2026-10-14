<?php

declare(strict_types=1);

namespace Quittio\Core\Countries;

/**
 * Requirements for invoice numbering in a country. The Managed layer applies the default
 * pattern, the default reset and the allowed resets.
 */
final readonly class NumberingRules
{
    /**
     * @param  list<string>  $allowedResets  never, yearly, monthly
     * @param  bool  $gapless  numbers must be consecutive without unexplained gaps. Informational metadata for
     *         applications; Quittio does not read it
     * @param  bool  $numberConsumedOnRejection  the number of an issued invoice that the recipient rejects stays
     *         used and is never issued again. Informational metadata for applications; Quittio does not read it
     */
    public function __construct(
        public string $defaultPattern = 'INV-{Y}-{seq:4}',
        public string $defaultReset = 'yearly',
        public array $allowedResets = ['never', 'yearly', 'monthly'],
        public bool $gapless = true,
        public bool $numberConsumedOnRejection = true,
    ) {}
}
