<?php

declare(strict_types=1);

namespace Quittio\Core\Data\Party;

use Quittio\Core\Data\Concerns\Immutable;
use Quittio\Core\Data\Identifier;
use Quittio\Core\Mapping\Attributes as Q;
use Quittio\Core\Profiles\ProfileLevel;

/**
 * Payee (BG-10), only when different from the seller (factoring, collection, group company).
 */
final readonly class PayeeParty
{
    use Immutable;

    public function __construct(
        #[Q\Bt('BT-59'), Q\Cardinality('1'), Q\Level(ProfileLevel::BasicWl), Q\Cii('ram:PayeeTradeParty/ram:Name'), Q\Ubl('cac:PayeeParty/cac:PartyName/cbc:Name')]
        public string $name,
        #[Q\Bt('BT-60'), Q\Cardinality('0..1'), Q\Level(ProfileLevel::BasicWl), Q\Cii('ram:PayeeTradeParty/ram:ID | ram:GlobalID'), Q\Ubl('cac:PayeeParty/cac:PartyIdentification/cbc:ID')]
        public ?Identifier $identifier = null,
        #[Q\Bt('BT-61'), Q\Cardinality('0..1'), Q\Level(ProfileLevel::BasicWl), Q\Cii('ram:PayeeTradeParty/ram:SpecifiedLegalOrganization/ram:ID'), Q\Ubl('cac:PayeeParty/cac:PartyLegalEntity/cbc:CompanyID')]
        public ?Identifier $legalRegistration = null,
    ) {}
}
