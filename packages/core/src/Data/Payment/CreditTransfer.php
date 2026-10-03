<?php

declare(strict_types=1);

namespace Quittio\Core\Data\Payment;

use Quittio\Core\Data\Concerns\Immutable;
use Quittio\Core\Mapping\Attributes as Q;
use Quittio\Core\Profiles\ProfileLevel;

/**
 * Credit transfer (BG-17).
 */
final readonly class CreditTransfer
{
    use Immutable;

    public function __construct(
        #[Q\Bt('BT-84'), Q\Cardinality('1'), Q\Level(ProfileLevel::BasicWl), Q\Cii('ram:PayeePartyCreditorFinancialAccount/ram:IBANID | ram:ProprietaryID'), Q\Ubl('cac:PayeeFinancialAccount/cbc:ID')]
        public string $accountId,
        #[Q\Bt('BT-85'), Q\Cardinality('0..1'), Q\Cii('ram:PayeePartyCreditorFinancialAccount/ram:AccountName'), Q\Ubl('cac:PayeeFinancialAccount/cbc:Name')]
        public ?string $accountName = null,
        #[Q\Bt('BT-86'), Q\Cardinality('0..1'), Q\Cii('ram:PayeeSpecifiedCreditorFinancialInstitution/ram:BICID'), Q\Ubl('cac:PayeeFinancialAccount/cac:FinancialInstitutionBranch/cbc:ID')]
        public ?string $serviceProviderId = null,
    ) {}

    /**
     * Whether the account identifier looks like an IBAN (CII uses IBANID instead of ProprietaryID).
     */
    public function isIban(): bool
    {
        return preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/', str_replace(' ', '', $this->accountId)) === 1;
    }
}
