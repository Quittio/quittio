<?php

declare(strict_types=1);

namespace Quittio\Laravel\Builder;

use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Laravel\Contracts\InvoiceDefaults;
use Quittio\Laravel\Seller;

/**
 * The seller, its first bank account and the small business flag of config/quittio.php.
 */
final class ConfigInvoiceDefaults implements InvoiceDefaults
{
    /**
     * Sets the seller of config/quittio.php, its first bank account with an IBAN and the small
     * business flag on $builder (see Seller::apply()).
     */
    public function apply(InvoiceBuilder $builder): void
    {
        Seller::apply($builder);
    }
}
