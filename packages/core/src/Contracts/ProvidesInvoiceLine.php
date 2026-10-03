<?php

declare(strict_types=1);

namespace Quittio\Core\Contracts;

use Quittio\Core\Builder\LineBuilder;

/**
 * Implemented by application models that describe an invoice line (e.g. an order item).
 */
interface ProvidesInvoiceLine
{
    public function fillLine(LineBuilder $line): void;
}
