<?php

declare(strict_types=1);

namespace Quittio\Core\Data\Totals;

use Brick\Money\Money;
use Quittio\Core\Data\Concerns\Immutable;
use Quittio\Core\Mapping\Attributes as Q;
use Quittio\Core\Profiles\ProfileLevel;

/**
 * Document totals (BG-22), calculated by the calculator.
 */
final readonly class Totals
{
    use Immutable;

    public function __construct(
        #[Q\Bt('BT-106'), Q\Cardinality('1'), Q\Level(ProfileLevel::BasicWl), Q\Computed, Q\Cii('ram:LineTotalAmount'), Q\Ubl('cac:LegalMonetaryTotal/cbc:LineExtensionAmount')]
        public Money $lineNetSum,
        #[Q\Bt('BT-109'), Q\Cardinality('1'), Q\Level(ProfileLevel::Minimum), Q\Computed, Q\Cii('ram:TaxBasisTotalAmount'), Q\Ubl('cac:LegalMonetaryTotal/cbc:TaxExclusiveAmount')]
        public Money $netTotal,
        #[Q\Bt('BT-112'), Q\Cardinality('1'), Q\Level(ProfileLevel::Minimum), Q\Computed, Q\Cii('ram:GrandTotalAmount'), Q\Ubl('cac:LegalMonetaryTotal/cbc:TaxInclusiveAmount')]
        public Money $grossTotal,
        #[Q\Bt('BT-115'), Q\Cardinality('1'), Q\Level(ProfileLevel::Minimum), Q\Computed, Q\Cii('ram:DuePayableAmount'), Q\Ubl('cac:LegalMonetaryTotal/cbc:PayableAmount')]
        public Money $amountDue,
        #[Q\Bt('BT-107'), Q\Cardinality('0..1'), Q\Level(ProfileLevel::BasicWl), Q\Computed, Q\Cii('ram:AllowanceTotalAmount'), Q\Ubl('cac:LegalMonetaryTotal/cbc:AllowanceTotalAmount')]
        public ?Money $allowanceSum = null,
        #[Q\Bt('BT-108'), Q\Cardinality('0..1'), Q\Level(ProfileLevel::BasicWl), Q\Computed, Q\Cii('ram:ChargeTotalAmount'), Q\Ubl('cac:LegalMonetaryTotal/cbc:ChargeTotalAmount')]
        public ?Money $chargeSum = null,
        #[Q\Bt('BT-110'), Q\Cardinality('0..1'), Q\Level(ProfileLevel::Minimum), Q\Computed, Q\Cii('ram:TaxTotalAmount[@currencyID=BT-5]'), Q\Ubl('cac:TaxTotal/cbc:TaxAmount')]
        public ?Money $vatTotal = null,
        #[Q\Bt('BT-111'), Q\Cardinality('0..1'), Q\Level(ProfileLevel::BasicWl), Q\Computed, Q\TaxCurrency, Q\Cii('ram:TaxTotalAmount[@currencyID=BT-6]'), Q\Ubl('cac:TaxTotal/cbc:TaxAmount')]
        public ?Money $vatTotalInTaxCurrency = null,
        #[Q\Bt('BT-113'), Q\Cardinality('0..1'), Q\Level(ProfileLevel::BasicWl), Q\Cii('ram:TotalPrepaidAmount'), Q\Ubl('cac:LegalMonetaryTotal/cbc:PrepaidAmount')]
        public ?Money $prepaidAmount = null,
        #[Q\Bt('BT-114'), Q\Cardinality('0..1'), Q\Level(ProfileLevel::BasicWl), Q\Cii('ram:RoundingAmount'), Q\Ubl('cac:LegalMonetaryTotal/cbc:PayableRoundingAmount')]
        public ?Money $roundingAmount = null,
    ) {}
}
