<?php

declare(strict_types=1);

namespace Quittio\Core\Data\Extensions;

use Quittio\Core\Data\Concerns\Immutable;
use Quittio\Core\Mapping\Attributes as Q;

/**
 * Data of the XRechnung extension. Sub invoice lines are modelled via InvoiceLine::$subLines.
 */
final readonly class XRechnungExtensionData
{
    use Immutable;

    /**
     * @param  list<ThirdPartyPayment>  $thirdPartyPayments
     */
    public function __construct(
        #[Q\XRechnungExtensionOnly, Q\ListOf(ThirdPartyPayment::class)]
        public array $thirdPartyPayments = [],
    ) {}
}
