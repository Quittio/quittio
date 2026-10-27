<?php

declare(strict_types=1);

namespace Quittio\Core\Contracts;

use Quittio\Core\Data\InvoiceData;

/**
 * Implemented by application models that can be turned into an invoice (e.g. an order).
 */
interface ProvidesInvoiceData
{
    /**
     * The complete invoice data of the model, e.g. built with InvoiceBuilder and ->build().
     */
    public function toInvoiceData(): InvoiceData;
}
