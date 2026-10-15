<?php

declare(strict_types=1);

namespace Quittio\Laravel\Contracts;

use Quittio\Core\Builder\InvoiceBuilder;

/**
 * Sets the seller and its defaults (bank account, small business flag …) on the builders of
 * Quittio::invoice() and of the document methods (Quittio::quote() …). The default binding
 * takes them from config/quittio.php (ConfigInvoiceDefaults, Seller::apply()); quittio/managed
 * binds the current issuer.
 */
interface InvoiceDefaults
{
    public function apply(InvoiceBuilder $builder): void;
}
