<?php

declare(strict_types=1);

namespace Quittio\Core\Documents;

/**
 * Commercial documents that share templates and calculation with invoices but carry no
 * e-invoice XML.
 */
enum DocumentType: string
{
    case Quote = 'quote';
    case OrderConfirmation = 'order_confirmation';
    case DeliveryNote = 'delivery_note';
    case Proforma = 'proforma';
    case PaymentReminder = 'payment_reminder';
    case AccountStatement = 'account_statement';

    /**
     * Reduction of the consideration of the supplies of a period, e.g. an annual bonus, per VAT
     * rate (in Germany section 17 (4) UStG); its lines are the reductions.
     */
    case BonusStatement = 'bonus_statement';

    /**
     * Whether line prices and totals are shown.
     */
    public function showsPrices(): bool
    {
        return $this !== self::DeliveryNote;
    }

    /**
     * Whether line totals are calculated (reminders and statements list open items instead).
     */
    public function hasLineTotals(): bool
    {
        return in_array($this, [self::Quote, self::OrderConfirmation, self::Proforma, self::BonusStatement], true);
    }

    public function labelKey(): string
    {
        return 'document_'.$this->value;
    }
}
