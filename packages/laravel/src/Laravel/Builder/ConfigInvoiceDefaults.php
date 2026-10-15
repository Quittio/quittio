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
    public function apply(InvoiceBuilder $builder): void
    {
        Seller::apply($builder);
    }
}
