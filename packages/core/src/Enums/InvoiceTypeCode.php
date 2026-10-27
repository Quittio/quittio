<?php

declare(strict_types=1);

namespace Quittio\Core\Enums;

use Quittio\Core\Codes\CodeList;
use Quittio\Core\Enums\Concerns\HasLabel;

/**
 * Document type code (BT-3), UNTDID 1001 subset allowed by EN 16931.
 */
enum InvoiceTypeCode: string
{
    use HasLabel;

    case RequestForPayment = '71';
    case DebitNoteGoodsServices = '80';
    case CreditNoteGoodsServices = '81';
    case MeteredServicesInvoice = '82';
    case CreditNoteFinancialAdjustment = '83';
    case DebitNoteFinancialAdjustment = '84';
    case TaxNotification = '102';
    case InvoicingDataSheet = '130';
    case DirectPaymentValuation = '202';
    case ProvisionalPaymentValuation = '203';
    case PaymentValuation = '204';
    case InterimApplicationForPayment = '211';
    case FinalPaymentRequest = '218';
    case PaymentRequestForCompletedUnits = '219';
    case SelfBilledCreditNote = '261';
    case ConsolidatedCreditNote = '262';
    case PriceVariationInvoice = '295';
    case CreditNoteForPriceVariation = '296';
    case DelcredereCreditNote = '308';
    case ProformaInvoice = '325';
    case PartialInvoice = '326';
    case CommercialInvoiceWithPackingList = '331';
    case CommercialInvoice = '380';
    case CreditNote = '381';
    case CommissionNote = '382';
    case DebitNote = '383';
    case CorrectedInvoice = '384';
    case ConsolidatedInvoice = '385';
    case PrepaymentInvoice = '386';
    case HireInvoice = '387';
    case TaxInvoice = '388';
    case SelfBilledInvoice = '389';
    case DelcredereInvoice = '390';
    case FactoredInvoice = '393';
    case LeaseInvoice = '394';
    case ConsignmentInvoice = '395';
    case FactoredCreditNote = '396';
    case OcrPaymentCreditNote = '420';
    case DebitAdvice = '456';
    case ReversalOfDebit = '457';
    case ReversalOfCredit = '458';
    case SelfBilledDebitNote = '527';
    case ForwardersCreditNote = '532';
    case ForwardersInvoiceDiscrepancyReport = '553';
    case InsurersInvoice = '575';
    case ForwardersInvoice = '623';
    case PortChargesDocument = '633';
    case InvoiceInformationForAccounting = '751';
    case FreightInvoice = '780';
    case ClaimNotification = '817';
    case ConsularInvoice = '870';
    case PartialConstructionInvoice = '875';
    case PartialFinalConstructionInvoice = '876';
    case FinalConstructionInvoice = '877';
    case CustomsInvoice = '935';

    /**
     * The labels of these codes are under "invoice_type" in resources/lang/{locale}/codes.php (see
     * label()).
     */
    public static function labelGroup(): string
    {
        return 'invoice_type';
    }

    /**
     * Whether a raw code value is valid according to the official code list.
     */
    public static function isValidCode(string $code): bool
    {
        return CodeList::contains(CodeList::INVOICE_TYPES, $code);
    }

    /**
     * Codes that EN 16931 and Peppol treat as credit notes (UBL document type CreditNote).
     */
    public function isCreditNote(): bool
    {
        return in_array($this, [
            self::CreditNoteGoodsServices,
            self::CreditNoteFinancialAdjustment,
            self::SelfBilledCreditNote,
            self::ConsolidatedCreditNote,
            self::CreditNoteForPriceVariation,
            self::DelcredereCreditNote,
            self::CreditNote,
            self::FactoredCreditNote,
            self::OcrPaymentCreditNote,
            self::ReversalOfCredit,
            self::ForwardersCreditNote,
        ], true);
    }

    /**
     * Whether the code is a self-billed document (389, 261, 527), issued by the buyer instead of
     * the seller.
     */
    public function isSelfBilled(): bool
    {
        return in_array($this, [self::SelfBilledInvoice, self::SelfBilledCreditNote, self::SelfBilledDebitNote], true);
    }
}
