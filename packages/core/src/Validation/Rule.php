<?php

declare(strict_types=1);

namespace Quittio\Core\Validation;

use Quittio\Core\Data\InvoiceData;

/**
 * A business or plausibility rule checked in PHP (levels L1 to L3 and L7).
 */
interface Rule
{
    /**
     * Official rule code (e.g. BR-CO-25, BR-DE-1) or a package code (QUITTIO-…).
     */
    public function code(): string;

    public function level(): ValidationLevel;

    /**
     * @return iterable<ValidationMessage>
     */
    public function check(InvoiceData $invoice, ValidationContext $context): iterable;
}
