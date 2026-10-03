<?php

declare(strict_types=1);

namespace Quittio\Core\Data\Lines;

use Quittio\Core\Data\Concerns\Immutable;
use Quittio\Core\Data\Identifier;
use Quittio\Core\Mapping\Attributes as Q;
use Quittio\Core\Profiles\ProfileLevel;

/**
 * Item information (BG-31).
 */
final readonly class Item
{
    use Immutable;

    /**
     * @param  list<Classification>  $classifications
     * @param  list<ItemAttribute>  $attributes
     */
    public function __construct(
        #[Q\Bt('BT-153'), Q\Cardinality('1'), Q\Level(ProfileLevel::Basic), Q\Cii('ram:SpecifiedTradeProduct/ram:Name'), Q\Ubl('cac:Item/cbc:Name')]
        public string $name,
        #[Q\Bt('BT-154'), Q\Cardinality('0..1'), Q\Cii('ram:SpecifiedTradeProduct/ram:Description'), Q\Ubl('cac:Item/cbc:Description')]
        public ?string $description = null,
        #[Q\Bt('BT-155'), Q\Cardinality('0..1'), Q\Cii('ram:SpecifiedTradeProduct/ram:SellerAssignedID'), Q\Ubl('cac:Item/cac:SellersItemIdentification/cbc:ID')]
        public ?string $sellerId = null,
        #[Q\Bt('BT-156'), Q\Cardinality('0..1'), Q\Cii('ram:SpecifiedTradeProduct/ram:BuyerAssignedID'), Q\Ubl('cac:Item/cac:BuyersItemIdentification/cbc:ID')]
        public ?string $buyerId = null,
        #[Q\Bt('BT-157'), Q\Cardinality('0..1'), Q\Level(ProfileLevel::Basic), Q\Cii('ram:SpecifiedTradeProduct/ram:GlobalID'), Q\Ubl('cac:Item/cac:StandardItemIdentification/cbc:ID')]
        public ?Identifier $standardId = null,
        #[Q\Bt('BT-158'), Q\Cardinality('0..n'), Q\ListOf(Classification::class), Q\Cii('ram:SpecifiedTradeProduct/ram:DesignatedProductClassification'), Q\Ubl('cac:Item/cac:CommodityClassification')]
        public array $classifications = [],
        #[Q\Bt('BT-159'), Q\Cardinality('0..1'), Q\Cii('ram:SpecifiedTradeProduct/ram:OriginTradeCountry/ram:ID'), Q\Ubl('cac:Item/cac:OriginCountry/cbc:IdentificationCode')]
        public ?string $originCountry = null,
        #[Q\Bt('BG-32'), Q\Cardinality('0..n'), Q\ListOf(ItemAttribute::class), Q\Cii('ram:SpecifiedTradeProduct/ram:ApplicableProductCharacteristic'), Q\Ubl('cac:Item/cac:AdditionalItemProperty')]
        public array $attributes = [],
    ) {}
}
