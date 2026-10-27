<?php

declare(strict_types=1);

/*
 * IDE and static analysis stub for the builder macro of the German country pack
 * (GermanInvoiceMacros). It is never loaded at runtime: point your IDE at it, or add it to
 * PHPStan's stubFiles:
 *
 *     parameters:
 *         stubFiles:
 *             - vendor/quittio/country-de/stubs/InvoiceBuilder.stub.php
 *
 * (with quittio/quittio: vendor/quittio/quittio/packages/country-de/stubs/InvoiceBuilder.stub.php)
 */

namespace Quittio\Core\Builder;

use Quittio\Country\DE\Builder\GermanInvoice;

class InvoiceBuilder
{
    /**
     * The German special cases: ->de()->smallAmount(), ->de()->selfBilled(), ->de()->deductAdvance($advance) …
     *
     * @return GermanInvoice<static>
     */
    public function de(): GermanInvoice {}
}
