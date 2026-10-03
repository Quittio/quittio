<?php

declare(strict_types=1);

namespace Quittio\Core\Data\Lines;

use Brick\Math\BigDecimal;
use Quittio\Core\Codes\RawCode;
use Quittio\Core\Data\Concerns\Immutable;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Enums\VatExemptionReasonCode;
use Quittio\Core\Mapping\Attributes as Q;
use Quittio\Core\Profiles\ProfileLevel;

/**
 * Line VAT information (BG-30).
 *
 * The exemption reason and code are package-internal inputs: EN 16931 carries
 * them on the VAT breakdown (BT-120, BT-121), the calculator copies them there.
 */
final readonly class LineVat
{
    use Immutable;

    public function __construct(
        #[Q\Bt('BT-151'), Q\Cardinality('1'), Q\Level(ProfileLevel::Basic), Q\Cii('ram:ApplicableTradeTax/ram:CategoryCode'), Q\Ubl('cac:ClassifiedTaxCategory/cbc:ID')]
        public VatCategory $category,
        #[Q\Bt('BT-152'), Q\Cardinality('0..1'), Q\Level(ProfileLevel::Basic), Q\Cii('ram:ApplicableTradeTax/ram:RateApplicablePercent'), Q\Ubl('cac:ClassifiedTaxCategory/cbc:Percent')]
        public ?BigDecimal $rate = null,
        #[Q\Internal]
        public ?string $exemptionReason = null,
        #[Q\Internal]
        public VatExemptionReasonCode|RawCode|null $exemptionReasonCode = null,
        #[Q\Internal]
        public ?string $presetKey = null,
    ) {}

    /**
     * Key of the VAT group (category and rate) this line belongs to.
     */
    public function groupKey(): string
    {
        return self::key($this->category, $this->rate);
    }

    public static function key(VatCategory $category, ?BigDecimal $rate): string
    {
        return $category->value.'|'.($rate === null ? '' : $rate->strippedOfTrailingZeros()->toScale(max(2, $rate->strippedOfTrailingZeros()->getScale()))->toString());
    }
}
