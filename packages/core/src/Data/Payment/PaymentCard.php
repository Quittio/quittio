<?php

declare(strict_types=1);

namespace Quittio\Core\Data\Payment;

use InvalidArgumentException;
use Quittio\Core\Data\Concerns\Immutable;
use Quittio\Core\Mapping\Attributes as Q;

/**
 * Payment card information (BG-18). Only the last 4 to 6 digits of the card number may be stored.
 */
final readonly class PaymentCard
{
    use Immutable;

    public function __construct(
        #[Q\Bt('BT-87'), Q\Cardinality('1'), Q\Cii('ram:ApplicableTradeSettlementFinancialCard/ram:ID'), Q\Ubl('cac:CardAccount/cbc:PrimaryAccountNumberID')]
        public string $primaryAccountNumber,
        #[Q\Bt('BT-88'), Q\Cardinality('0..1'), Q\Cii('ram:ApplicableTradeSettlementFinancialCard/ram:CardholderName'), Q\Ubl('cac:CardAccount/cbc:HolderName')]
        public ?string $holderName = null,
    ) {
        $digits = preg_replace('/\D/', '', $primaryAccountNumber) ?? '';
        if (strlen($digits) > 6) {
            throw new InvalidArgumentException('Only the last 4 to 6 digits of a card number may be used (BT-87).');
        }
    }
}
