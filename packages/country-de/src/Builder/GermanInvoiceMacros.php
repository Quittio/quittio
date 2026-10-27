<?php

declare(strict_types=1);

namespace Quittio\Country\DE\Builder;

use Quittio\Core\Builder\InvoiceBuilder;

/**
 * Registers the builder macro de() of the German pack, which leads to the German special cases
 * (GermanInvoice):
 *
 *     InvoiceBuilder::make()->de()->smallAmount()->...
 *     InvoiceBuilder::make()->de()->deductAdvance($advanceInvoice)->...
 */
final class GermanInvoiceMacros
{
    /**
     * Registers the macro once; further calls do nothing.
     */
    public static function register(): void
    {
        if (InvoiceBuilder::hasMacro('de')) {
            return;
        }

        InvoiceBuilder::macro('de', function (): GermanInvoice {
            return new GermanInvoice($this);
        });
    }
}
