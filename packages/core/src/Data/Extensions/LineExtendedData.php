<?php

declare(strict_types=1);

namespace Quittio\Core\Data\Extensions;

use Quittio\Core\Data\Concerns\Immutable;
use Quittio\Core\Mapping\Attributes as Q;
use Quittio\Core\Support\Date;

/**
 * Line level data that exists only in the Factur-X/ZUGFeRD EXTENDED profile.
 */
final readonly class LineExtendedData
{
    use Immutable;

    public const string STATUS_DETAIL = 'DETAIL';

    public const string STATUS_GROUP = 'GROUP';

    public const string STATUS_INFORMATION = 'INFORMATION';

    /**
     * @param  list<IncludedProduct>  $includedProducts
     */
    public function __construct(
        #[Q\ExtendedOnly]
        public ?string $lineStatusReasonCode = null,
        #[Q\ExtendedOnly]
        public ?ExtendedParty $shipTo = null,
        #[Q\ExtendedOnly]
        public ?Date $deliveryDate = null,
        #[Q\ExtendedOnly, Q\ListOf(IncludedProduct::class)]
        public array $includedProducts = [],
    ) {}
}
