<?php

declare(strict_types=1);

namespace Quittio\Core\Data\Delivery;

use Quittio\Core\Data\Concerns\Immutable;
use Quittio\Core\Data\Identifier;
use Quittio\Core\Data\Party\Address;
use Quittio\Core\Mapping\Attributes as Q;
use Quittio\Core\Profiles\ProfileLevel;
use Quittio\Core\Support\Date;

/**
 * Delivery information (BG-13).
 */
final readonly class Delivery
{
    use Immutable;

    public function __construct(
        #[Q\Bt('BT-70'), Q\Cardinality('0..1'), Q\Level(ProfileLevel::BasicWl), Q\Cii('ram:ShipToTradeParty/ram:Name'), Q\Ubl('cac:Delivery/cac:DeliveryParty/cac:PartyName/cbc:Name')]
        public ?string $recipientName = null,
        #[Q\Bt('BT-71'), Q\Cardinality('0..1'), Q\Level(ProfileLevel::BasicWl), Q\Cii('ram:ShipToTradeParty/ram:ID | ram:GlobalID'), Q\Ubl('cac:Delivery/cac:DeliveryLocation/cbc:ID')]
        public ?Identifier $locationId = null,
        #[Q\Bt('BT-72'), Q\Cardinality('0..1'), Q\Level(ProfileLevel::BasicWl), Q\Cii('ram:ActualDeliverySupplyChainEvent/ram:OccurrenceDateTime'), Q\Ubl('cac:Delivery/cbc:ActualDeliveryDate')]
        public ?Date $actualDeliveryDate = null,
        #[Q\Bt('BG-15'), Q\Cardinality('0..1'), Q\Level(ProfileLevel::BasicWl), Q\Cii('ram:ShipToTradeParty/ram:PostalTradeAddress'), Q\Ubl('cac:Delivery/cac:DeliveryLocation/cac:Address')]
        public ?Address $address = null,
    ) {}
}
