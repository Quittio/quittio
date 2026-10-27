<?php

declare(strict_types=1);

namespace Quittio\Core\Data\AllowanceCharge;

use Brick\Math\BigDecimal;
use Brick\Money\Money;
use Quittio\Core\Codes\RawCode;
use Quittio\Core\Enums\ChargeReasonCode;
use Quittio\Core\Mapping\Attributes as Q;
use Quittio\Core\Profiles\ProfileLevel;

/**
 * Invoice line charge (BG-28).
 */
final readonly class LineCharge extends LineAllowanceCharge
{
    public function __construct(
        #[Q\Bt('BT-141'), Q\Cardinality('1'), Q\Level(ProfileLevel::Basic), Q\Cii('ram:SpecifiedTradeAllowanceCharge/ram:ActualAmount'), Q\Ubl('cac:AllowanceCharge/cbc:Amount')]
        ?Money $amount,
        #[Q\Bt('BT-142'), Q\Cardinality('0..1'), Q\Level(ProfileLevel::Basic), Q\Cii('ram:BasisAmount'), Q\Ubl('cbc:BaseAmount')]
        ?Money $baseAmount = null,
        #[Q\Bt('BT-143'), Q\Cardinality('0..1'), Q\Level(ProfileLevel::Basic), Q\Cii('ram:CalculationPercent'), Q\Ubl('cbc:MultiplierFactorNumeric')]
        ?BigDecimal $percent = null,
        #[Q\Bt('BT-144'), Q\Cardinality('0..1'), Q\Level(ProfileLevel::Basic), Q\Cii('ram:Reason'), Q\Ubl('cbc:AllowanceChargeReason')]
        ?string $reason = null,
        #[Q\Bt('BT-145'), Q\Cardinality('0..1'), Q\Level(ProfileLevel::Basic), Q\Cii('ram:ReasonCode'), Q\Ubl('cbc:AllowanceChargeReasonCode')]
        ChargeReasonCode|RawCode|null $reasonCode = null,
    ) {
        parent::__construct($amount, $baseAmount, $percent, $reason, $reasonCode);
    }

    /**
     * Always true: an invoice line charge (BG-28).
     */
    public function isCharge(): bool
    {
        return true;
    }
}
