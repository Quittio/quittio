<?php

declare(strict_types=1);

namespace Quittio\Core\Data\Payment;

use Quittio\Core\Codes\RawCode;
use Quittio\Core\Data\Concerns\Immutable;
use Quittio\Core\Enums\PaymentMeansCode;
use Quittio\Core\Mapping\Attributes as Q;
use Quittio\Core\Profiles\ProfileLevel;

/**
 * Payment instructions (BG-16).
 */
final readonly class PaymentInstructions
{
    use Immutable;

    /**
     * @param  list<CreditTransfer>  $creditTransfers
     */
    public function __construct(
        #[Q\Bt('BT-81'), Q\Cardinality('1'), Q\Level(ProfileLevel::BasicWl), Q\Cii('ram:SpecifiedTradeSettlementPaymentMeans/ram:TypeCode'), Q\Ubl('cac:PaymentMeans/cbc:PaymentMeansCode')]
        public PaymentMeansCode|RawCode $meansCode,
        #[Q\Bt('BT-82'), Q\Cardinality('0..1'), Q\Cii('ram:SpecifiedTradeSettlementPaymentMeans/ram:Information'), Q\Ubl('cac:PaymentMeans/cbc:PaymentMeansCode/@name')]
        public ?string $meansText = null,
        #[Q\Bt('BT-83'), Q\Cardinality('0..1'), Q\Level(ProfileLevel::BasicWl), Q\Cii('ram:PaymentReference'), Q\Ubl('cac:PaymentMeans/cbc:PaymentID')]
        public ?string $remittanceInformation = null,
        #[Q\Bt('BG-17'), Q\Cardinality('0..n'), Q\Level(ProfileLevel::BasicWl), Q\ListOf(CreditTransfer::class), Q\Cii('ram:SpecifiedTradeSettlementPaymentMeans'), Q\Ubl('cac:PaymentMeans')]
        public array $creditTransfers = [],
        #[Q\Bt('BG-18'), Q\Cardinality('0..1'), Q\Cii('ram:SpecifiedTradeSettlementPaymentMeans/ram:ApplicableTradeSettlementFinancialCard'), Q\Ubl('cac:PaymentMeans/cac:CardAccount')]
        public ?PaymentCard $card = null,
        #[Q\Bt('BG-19'), Q\Cardinality('0..1'), Q\Level(ProfileLevel::BasicWl), Q\Cii('ram:SpecifiedTradeSettlementPaymentMeans/ram:PayerPartyDebtorFinancialAccount'), Q\Ubl('cac:PaymentMeans/cac:PaymentMandate')]
        public ?DirectDebit $directDebit = null,
    ) {}

    /**
     * The first credit transfer account (BG-17), e.g. for a payment QR code; null without one.
     */
    public function primaryCreditTransfer(): ?CreditTransfer
    {
        return $this->creditTransfers[0] ?? null;
    }
}
