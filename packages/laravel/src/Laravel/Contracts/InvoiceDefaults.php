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
    /**
     * Sets the seller and its defaults on $builder. Called for every invoice builder of
     * Quittio::invoice() and for the invoice builder of every document; ->seller() with another
     * seller removes these values again.
     */
    public function apply(InvoiceBuilder $builder): void;
}
