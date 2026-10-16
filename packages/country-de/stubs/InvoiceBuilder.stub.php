<?php

declare(strict_types=1);

/*
 * IDE and static analysis stub for the builder macros of the German country pack
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

use Quittio\Core\Data\InvoiceData;
use Quittio\Country\DE\Builder\GermanInvoice;

class InvoiceBuilder
{
    /**
     * The German special cases: ->de()->smallAmount(), ->de()->selfBilled(), ->de()->deductAdvance($advance) …
     *
     * @return GermanInvoice<static>
     */
    public function de(): GermanInvoice {}

    /**
     * @deprecated use ->de()->smallAmount()
     */
    public function smallAmount(): static {}

    /**
     * @deprecated use ->de()->propertyRelatedService()
     */
    public function propertyRelatedService(string $locale = 'de'): static {}

    /**
     * @deprecated use ->de()->newVehicle()
     */
    public function newVehicle(string $locale = 'de'): static {}

    /**
     * @deprecated use ->de()->selfBilled()
     */
    public function selfBilled(string $locale = 'de'): static {}

    /**
     * @deprecated use ->de()->deductAdvance()
     */
    public function deductAdvance(InvoiceData $advance, string $locale = 'de'): static {}

    /**
     * @deprecated use ->de()->consumerDefaultNotice()
     */
    public function consumerDefaultNotice(string $locale = 'de'): static {}
}
