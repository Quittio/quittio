<?php

declare(strict_types=1);

namespace Quittio\Core\Data\Totals;

use Brick\Math\BigDecimal;
use Brick\Money\Money;
use Quittio\Core\Codes\RawCode;
use Quittio\Core\Data\Concerns\Immutable;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Enums\VatExemptionReasonCode;
use Quittio\Core\Mapping\Attributes as Q;
use Quittio\Core\Profiles\ProfileLevel;

/**
 * VAT breakdown (BG-23), one entry per combination of VAT category and rate.
 */
final readonly class VatBreakdown
{
    use Immutable;

    public function __construct(
        #[Q\Bt('BT-116'), Q\Cardinality('1'), Q\Level(ProfileLevel::BasicWl), Q\Computed, Q\Cii('ram:ApplicableTradeTax/ram:BasisAmount'), Q\Ubl('cac:TaxSubtotal/cbc:TaxableAmount')]
        public Money $taxableAmount,
        #[Q\Bt('BT-117'), Q\Cardinality('1'), Q\Level(ProfileLevel::BasicWl), Q\Computed, Q\Cii('ram:ApplicableTradeTax/ram:CalculatedAmount'), Q\Ubl('cac:TaxSubtotal/cbc:TaxAmount')]
        public Money $taxAmount,
        #[Q\Bt('BT-118'), Q\Cardinality('1'), Q\Level(ProfileLevel::BasicWl), Q\Computed, Q\Cii('ram:ApplicableTradeTax/ram:CategoryCode'), Q\Ubl('cac:TaxSubtotal/cac:TaxCategory/cbc:ID')]
        public VatCategory $category,
        #[Q\Bt('BT-119'), Q\Cardinality('0..1'), Q\Level(ProfileLevel::BasicWl), Q\Computed, Q\Cii('ram:ApplicableTradeTax/ram:RateApplicablePercent'), Q\Ubl('cac:TaxSubtotal/cac:TaxCategory/cbc:Percent')]
        public ?BigDecimal $rate = null,
        #[Q\Bt('BT-120'), Q\Cardinality('0..1'), Q\Level(ProfileLevel::BasicWl), Q\Cii('ram:ApplicableTradeTax/ram:ExemptionReason'), Q\Ubl('cac:TaxSubtotal/cac:TaxCategory/cbc:TaxExemptionReason')]
        public ?string $exemptionReason = null,
        #[Q\Bt('BT-121'), Q\Cardinality('0..1'), Q\Level(ProfileLevel::BasicWl), Q\Cii('ram:ApplicableTradeTax/ram:ExemptionReasonCode'), Q\Ubl('cac:TaxSubtotal/cac:TaxCategory/cbc:TaxExemptionReasonCode')]
        public VatExemptionReasonCode|RawCode|null $exemptionReasonCode = null,
    ) {}
}
