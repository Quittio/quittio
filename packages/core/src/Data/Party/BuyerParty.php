<?php

declare(strict_types=1);

namespace Quittio\Core\Data\Party;

use Quittio\Core\Data\Concerns\Immutable;
use Quittio\Core\Data\Identifier;
use Quittio\Core\Mapping\Attributes as Q;
use Quittio\Core\Profiles\ProfileLevel;

/**
 * Buyer (BG-7).
 */
final readonly class BuyerParty
{
    use Immutable;

    public function __construct(
        #[Q\Bt('BT-44'), Q\Cardinality('1'), Q\Level(ProfileLevel::Minimum), Q\Cii('ram:BuyerTradeParty/ram:Name'), Q\Ubl('cac:AccountingCustomerParty/cac:Party/cac:PartyLegalEntity/cbc:RegistrationName')]
        public string $name,
        #[Q\Bt('BG-8'), Q\Cardinality('1'), Q\Level(ProfileLevel::BasicWl), Q\Cii('ram:BuyerTradeParty/ram:PostalTradeAddress'), Q\Ubl('cac:AccountingCustomerParty/cac:Party/cac:PostalAddress')]
        public Address $address,
        #[Q\Bt('BT-45'), Q\Cardinality('0..1'), Q\Cii('ram:BuyerTradeParty/ram:SpecifiedLegalOrganization/ram:TradingBusinessName'), Q\Ubl('cac:PartyName/cbc:Name')]
        public ?string $tradingName = null,
        #[Q\Bt('BT-46'), Q\Cardinality('0..1'), Q\Level(ProfileLevel::BasicWl), Q\Cii('ram:BuyerTradeParty/ram:ID | ram:GlobalID'), Q\Ubl('cac:PartyIdentification/cbc:ID')]
        public ?Identifier $identifier = null,
        #[Q\Bt('BT-47'), Q\Cardinality('0..1'), Q\Level(ProfileLevel::Minimum), Q\Cii('ram:BuyerTradeParty/ram:SpecifiedLegalOrganization/ram:ID'), Q\Ubl('cac:PartyLegalEntity/cbc:CompanyID')]
        public ?Identifier $legalRegistration = null,
        #[Q\Bt('BT-48'), Q\Cardinality('0..1'), Q\Level(ProfileLevel::BasicWl), Q\Cii('ram:BuyerTradeParty/ram:SpecifiedTaxRegistration/ram:ID[@schemeID="VA"]'), Q\Ubl('cac:PartyTaxScheme/cbc:CompanyID')]
        public ?string $vatId = null,
        #[Q\Bt('BT-49'), Q\Cardinality('0..1'), Q\Level(ProfileLevel::BasicWl), Q\RequiredIn('xrechnung', 'peppol'), Q\Cii('ram:BuyerTradeParty/ram:URIUniversalCommunication/ram:URIID'), Q\Ubl('cbc:EndpointID')]
        public ?ElectronicAddress $electronicAddress = null,
        #[Q\Bt('BG-9'), Q\Cardinality('0..1'), Q\Cii('ram:BuyerTradeParty/ram:DefinedTradeContact'), Q\Ubl('cac:Contact')]
        public ?Contact $contact = null,
        #[Q\Internal]
        public bool $isConsumer = false,
    ) {}
}
