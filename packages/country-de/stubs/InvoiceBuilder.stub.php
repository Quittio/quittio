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

/**
 * @method static smallAmount() small amount invoice up to 250 EUR gross (section 33 UStDV)
 * @method static propertyRelatedService(string $locale = 'de') service connected with a property, with the retention note
 * @method static newVehicle(string $locale = 'de') intra-community supply of a new vehicle, with its note
 * @method static selfBilled(string $locale = 'de') self-billed invoice (type code 389) with the "Gutschrift" note
 * @method static deductAdvance(InvoiceData $advance, string $locale = 'de') final invoice that deducts a paid advance invoice
 * @method static consumerDefaultNotice(string $locale = 'de') notice of default of a consumer 30 days after the due date (section 286 (3) BGB)
 */
class InvoiceBuilder {}
