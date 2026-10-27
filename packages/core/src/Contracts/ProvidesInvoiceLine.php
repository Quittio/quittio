<?php

declare(strict_types=1);

namespace Quittio\Core\Contracts;

use Quittio\Core\Builder\LineBuilder;

/**
 * Implemented by application models that describe an invoice line (e.g. an order item).
 */
interface ProvidesInvoiceLine
{
    /**
     * Fills $line with the data of the model (name, quantity, price, VAT …).
     */
    public function fillLine(LineBuilder $line): void;
}
