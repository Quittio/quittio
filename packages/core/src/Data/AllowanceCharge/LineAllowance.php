<?php

declare(strict_types=1);

namespace Quittio\Core\Data\AllowanceCharge;

use Brick\Math\BigDecimal;
use Brick\Money\Money;
use Quittio\Core\Codes\RawCode;
use Quittio\Core\Enums\AllowanceReasonCode;
use Quittio\Core\Mapping\Attributes as Q;
use Quittio\Core\Profiles\ProfileLevel;

/**
 * Invoice line allowance (BG-27).
 */
final readonly class LineAllowance extends LineAllowanceCharge
{
    public function __construct(
        #[Q\Bt('BT-136'), Q\Cardinality('1'), Q\Level(ProfileLevel::Basic), Q\Cii('ram:SpecifiedTradeAllowanceCharge/ram:ActualAmount'), Q\Ubl('cac:AllowanceCharge/cbc:Amount')]
        ?Money $amount,
        #[Q\Bt('BT-137'), Q\Cardinality('0..1'), Q\Level(ProfileLevel::Basic), Q\Cii('ram:BasisAmount'), Q\Ubl('cbc:BaseAmount')]
        ?Money $baseAmount = null,
        #[Q\Bt('BT-138'), Q\Cardinality('0..1'), Q\Level(ProfileLevel::Basic), Q\Cii('ram:CalculationPercent'), Q\Ubl('cbc:MultiplierFactorNumeric')]
        ?BigDecimal $percent = null,
        #[Q\Bt('BT-139'), Q\Cardinality('0..1'), Q\Level(ProfileLevel::Basic), Q\Cii('ram:Reason'), Q\Ubl('cbc:AllowanceChargeReason')]
        ?string $reason = null,
        #[Q\Bt('BT-140'), Q\Cardinality('0..1'), Q\Level(ProfileLevel::Basic), Q\Cii('ram:ReasonCode'), Q\Ubl('cbc:AllowanceChargeReasonCode')]
        AllowanceReasonCode|RawCode|null $reasonCode = null,
    ) {
        parent::__construct($amount, $baseAmount, $percent, $reason, $reasonCode);
    }

    /**
     * Always false: an invoice line allowance (BG-27).
     */
    public function isCharge(): bool
    {
        return false;
    }
}
