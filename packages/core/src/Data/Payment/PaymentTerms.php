<?php

declare(strict_types=1);

namespace Quittio\Core\Data\Payment;

use Quittio\Core\Data\Concerns\Immutable;
use Quittio\Core\Mapping\Attributes as Q;
use Quittio\Core\Profiles\ProfileLevel;

/**
 * Structured payment terms. Serialised into BT-20 text (and structured elements where the profile allows).
 */
final readonly class PaymentTerms
{
    use Immutable;

    /**
     * @param  list<CashDiscount>  $discounts
     */
    public function __construct(
        #[Q\Bt('BT-20'), Q\Cardinality('0..1'), Q\Level(ProfileLevel::BasicWl), Q\Cii('ram:SpecifiedTradePaymentTerms/ram:Description'), Q\Ubl('cac:PaymentTerms/cbc:Note')]
        public ?string $text = null,
        #[Q\Internal]
        public ?int $dueDays = null,
        #[Q\Internal, Q\ListOf(CashDiscount::class)]
        public array $discounts = [],
        #[Q\Internal]
        public ?PenaltyTerms $lateFeeTerms = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return (new \Quittio\Core\Serialization\ArrayNormalizer)->normalize($this);
    }

    /**
     * Whether neither text (BT-20), due days, cash discounts nor late payment terms are set.
     */
    public function isEmpty(): bool
    {
        return $this->text === null && $this->dueDays === null && $this->discounts === [] && $this->lateFeeTerms === null;
    }
}
