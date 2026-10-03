<?php

declare(strict_types=1);

namespace Quittio\Core\Data\Party;

use Quittio\Core\Data\Concerns\Immutable;
use Quittio\Core\Mapping\Attributes as Q;
use Quittio\Core\Profiles\ProfileLevel;

/**
 * Seller tax representative (BG-11).
 */
final readonly class TaxRepresentativeParty
{
    use Immutable;

    public function __construct(
        #[Q\Bt('BT-62'), Q\Cardinality('1'), Q\Level(ProfileLevel::BasicWl), Q\Cii('ram:SellerTaxRepresentativeTradeParty/ram:Name'), Q\Ubl('cac:TaxRepresentativeParty/cac:PartyName/cbc:Name')]
        public string $name,
        #[Q\Bt('BT-63'), Q\Cardinality('1'), Q\Level(ProfileLevel::BasicWl), Q\Cii('ram:SpecifiedTaxRegistration/ram:ID[@schemeID="VA"]'), Q\Ubl('cac:PartyTaxScheme/cbc:CompanyID')]
        public string $vatId,
        #[Q\Bt('BG-12'), Q\Cardinality('1'), Q\Level(ProfileLevel::BasicWl), Q\Cii('ram:PostalTradeAddress'), Q\Ubl('cac:PostalAddress')]
        public Address $address,
    ) {}
}
