<?php

declare(strict_types=1);

namespace Quittio\Core\Data\Lines;

use Brick\Math\BigDecimal;
use Quittio\Core\Codes\RawCode;
use Quittio\Core\Data\Concerns\Immutable;
use Quittio\Core\Enums\UnitCode;
use Quittio\Core\Mapping\Attributes as Q;
use Quittio\Core\Profiles\ProfileLevel;

/**
 * Price details (BG-29). Prices may have more than two decimals.
 *
 * Note: BT-148 "item gross price" is the list price before the price discount,
 * not a price including VAT. Prices including VAT are an input for the gross
 * price mode ($priceInclVat) and are converted by the calculator.
 */
final readonly class Price
{
    use Immutable;

    public function __construct(
        #[Q\Bt('BT-146'), Q\Cardinality('1'), Q\Level(ProfileLevel::Basic), Q\Cii('ram:NetPriceProductTradePrice/ram:ChargeAmount'), Q\Ubl('cac:Price/cbc:PriceAmount')]
        public ?BigDecimal $netPrice,
        #[Q\Bt('BT-147'), Q\Cardinality('0..1'), Q\Level(ProfileLevel::Basic), Q\Cii('ram:GrossPriceProductTradePrice/ram:AppliedTradeAllowanceCharge/ram:ActualAmount'), Q\Ubl('cac:Price/cac:AllowanceCharge/cbc:Amount')]
        public ?BigDecimal $discount = null,
        #[Q\Bt('BT-148'), Q\Cardinality('0..1'), Q\Level(ProfileLevel::Basic), Q\Cii('ram:GrossPriceProductTradePrice/ram:ChargeAmount'), Q\Ubl('cac:Price/cac:AllowanceCharge/cbc:BaseAmount')]
        public ?BigDecimal $grossPrice = null,
        #[Q\Bt('BT-149'), Q\Cardinality('0..1'), Q\Level(ProfileLevel::Basic), Q\Cii('ram:NetPriceProductTradePrice/ram:BasisQuantity'), Q\Ubl('cac:Price/cbc:BaseQuantity')]
        public ?BigDecimal $baseQuantity = null,
        #[Q\Bt('BT-150'), Q\Cardinality('0..1'), Q\Level(ProfileLevel::Basic), Q\Cii('ram:NetPriceProductTradePrice/ram:BasisQuantity/@unitCode'), Q\Ubl('cac:Price/cbc:BaseQuantity/@unitCode')]
        public UnitCode|RawCode|null $baseQuantityUnit = null,
        #[Q\Internal]
        public ?BigDecimal $priceInclVat = null,
    ) {}

    /**
     * Whether the price is given including VAT (gross price mode), which the calculator converts
     * into a net price (GrossPriceConverter).
     */
    public function isGrossMode(): bool
    {
        return $this->priceInclVat !== null;
    }
}
