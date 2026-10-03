<?php

declare(strict_types=1);

namespace Quittio\Core\Data\Party;

use Quittio\Core\Data\Concerns\Immutable;
use Quittio\Core\Data\Identifier;
use Quittio\Core\Mapping\Attributes as Q;
use Quittio\Core\Profiles\ProfileLevel;

/**
 * Seller (BG-4).
 */
final readonly class SellerParty
{
    use Immutable;

    /**
     * @param  list<Identifier>  $identifiers
     */
    public function __construct(
        #[Q\Bt('BT-27'), Q\Cardinality('1'), Q\Level(ProfileLevel::Minimum), Q\Cii('ram:SellerTradeParty/ram:Name'), Q\Ubl('cac:AccountingSupplierParty/cac:Party/cac:PartyLegalEntity/cbc:RegistrationName')]
        public string $name,
        #[Q\Bt('BG-5'), Q\Cardinality('1'), Q\Level(ProfileLevel::Minimum), Q\Cii('ram:SellerTradeParty/ram:PostalTradeAddress'), Q\Ubl('cac:AccountingSupplierParty/cac:Party/cac:PostalAddress')]
        public Address $address,
        #[Q\Bt('BT-28'), Q\Cardinality('0..1'), Q\Level(ProfileLevel::BasicWl), Q\Cii('ram:SellerTradeParty/ram:SpecifiedLegalOrganization/ram:TradingBusinessName'), Q\Ubl('cac:PartyName/cbc:Name')]
        public ?string $tradingName = null,
        #[Q\Bt('BT-29'), Q\Cardinality('0..n'), Q\Level(ProfileLevel::BasicWl), Q\ListOf(Identifier::class), Q\Cii('ram:SellerTradeParty/ram:ID | ram:GlobalID'), Q\Ubl('cac:PartyIdentification/cbc:ID')]
        public array $identifiers = [],
        #[Q\Bt('BT-30'), Q\Cardinality('0..1'), Q\Level(ProfileLevel::Minimum), Q\Cii('ram:SellerTradeParty/ram:SpecifiedLegalOrganization/ram:ID'), Q\Ubl('cac:PartyLegalEntity/cbc:CompanyID')]
        public ?Identifier $legalRegistration = null,
        #[Q\Bt('BT-31'), Q\Cardinality('0..1'), Q\Level(ProfileLevel::Minimum), Q\Cii('ram:SpecifiedTaxRegistration/ram:ID[@schemeID="VA"]'), Q\Ubl('cac:PartyTaxScheme[cac:TaxScheme/cbc:ID="VAT"]/cbc:CompanyID')]
        public ?string $vatId = null,
        #[Q\Bt('BT-32'), Q\Cardinality('0..1'), Q\Level(ProfileLevel::Minimum), Q\Cii('ram:SpecifiedTaxRegistration/ram:ID[@schemeID="FC"]'), Q\Ubl('cac:PartyTaxScheme[cac:TaxScheme/cbc:ID!="VAT"]/cbc:CompanyID')]
        public ?string $taxNumber = null,
        #[Q\Bt('BT-33'), Q\Cardinality('0..1'), Q\Cii('ram:SellerTradeParty/ram:Description'), Q\Ubl('cac:PartyLegalEntity/cbc:CompanyLegalForm')]
        public ?string $additionalLegalInfo = null,
        #[Q\Bt('BT-34'), Q\Cardinality('0..1'), Q\Level(ProfileLevel::BasicWl), Q\RequiredIn('xrechnung', 'peppol'), Q\Cii('ram:SellerTradeParty/ram:URIUniversalCommunication/ram:URIID'), Q\Ubl('cbc:EndpointID')]
        public ?ElectronicAddress $electronicAddress = null,
        #[Q\Bt('BG-6'), Q\Cardinality('0..1'), Q\RequiredIn('xrechnung'), Q\Cii('ram:SellerTradeParty/ram:DefinedTradeContact'), Q\Ubl('cac:Contact')]
        public ?Contact $contact = null,
    ) {}
}
