<?php

declare(strict_types=1);

namespace Quittio\Core\Data\Payment;

use Quittio\Core\Data\Concerns\Immutable;
use Quittio\Core\Mapping\Attributes as Q;
use Quittio\Core\Profiles\ProfileLevel;

/**
 * Direct debit (BG-19).
 */
final readonly class DirectDebit
{
    use Immutable;

    public function __construct(
        #[Q\Bt('BT-89'), Q\Cardinality('0..1'), Q\Level(ProfileLevel::BasicWl), Q\Cii('ram:SpecifiedTradePaymentTerms/ram:DirectDebitMandateID'), Q\Ubl('cac:PaymentMandate/cbc:ID')]
        public ?string $mandateReference = null,
        #[Q\Bt('BT-90'), Q\Cardinality('0..1'), Q\Level(ProfileLevel::BasicWl), Q\Cii('ram:CreditorReferenceID'), Q\Ubl('cac:AccountingSupplierParty/cac:Party/cac:PartyIdentification/cbc:ID[@schemeID="SEPA"]')]
        public ?string $creditorId = null,
        #[Q\Bt('BT-91'), Q\Cardinality('0..1'), Q\Level(ProfileLevel::BasicWl), Q\Cii('ram:PayerPartyDebtorFinancialAccount/ram:IBANID'), Q\Ubl('cac:PaymentMandate/cac:PayerFinancialAccount/cbc:ID')]
        public ?string $debitedAccountId = null,
    ) {}
}
