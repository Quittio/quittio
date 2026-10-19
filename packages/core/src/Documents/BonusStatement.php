<?php

declare(strict_types=1);

namespace Quittio\Core\Documents;

use Quittio\Core\Data\Delivery\Period;
use Quittio\Core\Exceptions\InvalidArgumentException;

/**
 * Data of a bonus statement: a reduction of the consideration of all supplies of a period, e.g.
 * an annual bonus, documented in one statement instead of one document per invoice (in
 * Germany section 17 (4) UStG). The reduction per VAT rate is in the lines of the document.
 */
final readonly class BonusStatement
{
    /**
     * @param  string  $agreement  the agreement the bonus is based on, e.g. "der Bonusvereinbarung vom 15.01.2026"
     * @param  list<string>  $invoices  numbers of the invoices concerned (optional)
     */
    public function __construct(
        public Period $period,
        public string $agreement,
        public array $invoices = [],
    ) {
        if (! $period->isValid()) {
            throw new InvalidArgumentException('The end of the bonus period must not lie before its start.');
        }
        if (trim($agreement) === '') {
            throw new InvalidArgumentException('A bonus statement must name the agreement the bonus is based on.');
        }
    }
}
