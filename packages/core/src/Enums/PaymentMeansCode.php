<?php

declare(strict_types=1);

namespace Quittio\Core\Enums;

use Quittio\Core\Codes\CodeList;
use Quittio\Core\Enums\Concerns\HasLabel;

/**
 * Payment means type code (BT-81), UNTDID 4461. Further codes can be used as RawCode.
 */
enum PaymentMeansCode: string
{
    use HasLabel;

    case NotDefined = '1';
    case Cash = '10';
    case Cheque = '20';
    case CreditTransfer = '30';
    case DebitTransfer = '31';
    case PaymentToBankAccount = '42';
    case BankCard = '48';
    case DirectDebit = '49';
    case CreditCard = '54';
    case DebitCard = '55';
    case StandingAgreement = '57';
    case SepaCreditTransfer = '58';
    case SepaDirectDebit = '59';
    case OnlinePaymentService = '68';
    case Clearing = '97';
    case MutuallyDefined = 'ZZZ';

    /**
     * The labels of these codes are under "payment_means" in resources/lang/{locale}/codes.php (see
     * label()).
     */
    public static function labelGroup(): string
    {
        return 'payment_means';
    }

    /**
     * Whether a raw code value is valid according to the official code list.
     */
    public static function isValidCode(string $code): bool
    {
        return CodeList::contains(CodeList::PAYMENT_MEANS, $code);
    }
}
