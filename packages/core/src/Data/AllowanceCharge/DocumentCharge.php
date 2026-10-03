<?php

declare(strict_types=1);

namespace Quittio\Core\Data\AllowanceCharge;

use Brick\Math\BigDecimal;
use Brick\Money\Money;
use Quittio\Core\Codes\RawCode;
use Quittio\Core\Enums\ChargeReasonCode;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Mapping\Attributes as Q;
use Quittio\Core\Profiles\ProfileLevel;

/**
 * Document level charge (BG-21).
 */
final readonly class DocumentCharge extends DocumentAllowanceCharge
{
    public function __construct(
        #[Q\Bt('BT-99'), Q\Cardinality('1'), Q\Level(ProfileLevel::BasicWl), Q\Cii('ram:SpecifiedTradeAllowanceCharge/ram:ActualAmount'), Q\Ubl('cac:AllowanceCharge/cbc:Amount')]
        ?Money $amount,
        #[Q\Bt('BT-102'), Q\Cardinality('1'), Q\Level(ProfileLevel::BasicWl), Q\Cii('ram:CategoryTradeTax/ram:CategoryCode'), Q\Ubl('cac:TaxCategory/cbc:ID')]
        VatCategory $vatCategory,
        #[Q\Bt('BT-103'), Q\Cardinality('0..1'), Q\Level(ProfileLevel::BasicWl), Q\Cii('ram:CategoryTradeTax/ram:RateApplicablePercent'), Q\Ubl('cac:TaxCategory/cbc:Percent')]
        ?BigDecimal $vatRate = null,
        #[Q\Bt('BT-100'), Q\Cardinality('0..1'), Q\Level(ProfileLevel::BasicWl), Q\Cii('ram:BasisAmount'), Q\Ubl('cbc:BaseAmount')]
        ?Money $baseAmount = null,
        #[Q\Bt('BT-101'), Q\Cardinality('0..1'), Q\Level(ProfileLevel::BasicWl), Q\Cii('ram:CalculationPercent'), Q\Ubl('cbc:MultiplierFactorNumeric')]
        ?BigDecimal $percent = null,
        #[Q\Bt('BT-104'), Q\Cardinality('0..1'), Q\Level(ProfileLevel::BasicWl), Q\Cii('ram:Reason'), Q\Ubl('cbc:AllowanceChargeReason')]
        ?string $reason = null,
        #[Q\Bt('BT-105'), Q\Cardinality('0..1'), Q\Level(ProfileLevel::BasicWl), Q\Cii('ram:ReasonCode'), Q\Ubl('cbc:AllowanceChargeReasonCode')]
        ChargeReasonCode|RawCode|null $reasonCode = null,
    ) {
        parent::__construct($amount, $vatCategory, $vatRate, $baseAmount, $percent, $reason, $reasonCode);
    }

    public function isCharge(): bool
    {
        return true;
    }
}
