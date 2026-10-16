<?php

declare(strict_types=1);

namespace Quittio\Core\Builder\Concerns;

use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Builder\Party;
use Quittio\Core\Data\Delivery\Delivery;
use Quittio\Core\Data\Extensions\ExtendedData;
use Quittio\Core\Data\Extensions\XRechnungExtensionData;

/**
 * Data of the EXTENDED profile and of the XRechnung extension.
 *
 * Part of {@see InvoiceBuilder}, which holds the state; use the builder, not the trait.
 */
trait BuildsExtensions
{
    /**
     * Data of the profile EXTENDED of ZUGFeRD/Factur-X (further parties, delivery terms,
     * instalments …); other profiles do not write it.
     */
    public function extended(ExtendedData $extended): static
    {
        $this->extended = $extended;

        return $this;
    }

    /**
     * Data of the XRechnung extension (e.g. third party payments), written only with the
     * profile xrechnung-extension.
     */
    public function xrechnungExtension(XRechnungExtensionData $extension): static
    {
        $this->xrechnungExtension = $extension;

        return $this;
    }
}
