<?php

declare(strict_types=1);

namespace Quittio\Country\DE\Builder;

use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Enums\InvoiceTypeCode;

/**
 * Registers the builder macro de() of the German pack, which leads to the German special cases
 * (GermanInvoice):
 *
 *     InvoiceBuilder::make()->de()->smallAmount()->...
 *     InvoiceBuilder::make()->de()->deductAdvance($advanceInvoice)->...
 *
 * The macros of earlier versions (smallAmount(), propertyRelatedService(), newVehicle(),
 * selfBilled(), deductAdvance(), consumerDefaultNotice() directly on the builder) still work and
 * report a deprecation (E_USER_DEPRECATED).
 */
final class GermanInvoiceMacros
{
    /**
     * @deprecated use GermanInvoice::ADVANCE_TYPES
     *
     * @var list<InvoiceTypeCode>
     */
    public const array ADVANCE_TYPES = GermanInvoice::ADVANCE_TYPES;

    /**
     * Registers the macros once; further calls do nothing.
     */
    public static function register(): void
    {
        if (InvoiceBuilder::hasMacro('de')) {
            return;
        }

        InvoiceBuilder::macro('de', function (): GermanInvoice {
            return new GermanInvoice($this);
        });

        $deprecated = static function (string $name): void {
            @trigger_error(sprintf('The builder macro %1$s() is deprecated since quittio/country-de 0.3, use ->de()->%1$s() instead.', $name), E_USER_DEPRECATED);
        };

        InvoiceBuilder::macro('smallAmount', function () use ($deprecated): InvoiceBuilder {
            $deprecated('smallAmount');

            return (new GermanInvoice($this))->smallAmount();
        });

        InvoiceBuilder::macro('propertyRelatedService', function (string $locale = 'de') use ($deprecated): InvoiceBuilder {
            $deprecated('propertyRelatedService');

            return (new GermanInvoice($this))->propertyRelatedService($locale);
        });

        InvoiceBuilder::macro('newVehicle', function (string $locale = 'de') use ($deprecated): InvoiceBuilder {
            $deprecated('newVehicle');

            return (new GermanInvoice($this))->newVehicle($locale);
        });

        InvoiceBuilder::macro('selfBilled', function (string $locale = 'de') use ($deprecated): InvoiceBuilder {
            $deprecated('selfBilled');

            return (new GermanInvoice($this))->selfBilled($locale);
        });

        InvoiceBuilder::macro('deductAdvance', function (InvoiceData $advance, string $locale = 'de') use ($deprecated): InvoiceBuilder {
            $deprecated('deductAdvance');

            return (new GermanInvoice($this))->deductAdvance($advance, $locale);
        });

        InvoiceBuilder::macro('consumerDefaultNotice', function (string $locale = 'de') use ($deprecated): InvoiceBuilder {
            $deprecated('consumerDefaultNotice');

            return (new GermanInvoice($this))->consumerDefaultNotice($locale);
        });
    }
}
