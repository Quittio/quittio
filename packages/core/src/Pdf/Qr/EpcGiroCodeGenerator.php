<?php

declare(strict_types=1);

namespace Quittio\Core\Pdf\Qr;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Data\Payment\CashDiscount;
use Quittio\Core\Enums\InvoiceTypeCode;
use Quittio\Core\Enums\PaymentMeansCode;
use Quittio\Core\Support\Decimal;
use Quittio\Core\Validation\Plausibility\Iban;

/**
 * EPC QR code ("GiroCode") for SEPA credit transfers, guideline EPC069-12 version 002.
 *
 * Created when the currency is EUR, the payment means is a credit transfer with an
 * IBAN and the amount due is positive; never for credit notes and self-billed invoices.
 *
 * Options of payload(): "amount" (due|discounted), "value" (a BigDecimal that replaces
 * the amount, e.g. the total of a payment reminder) and "remittance" (replaces BT-83).
 */
final class EpcGiroCodeGenerator implements PaymentQrGenerator
{
    public const string AMOUNT_DUE = 'due';

    public const string AMOUNT_DISCOUNTED = 'discounted';

    /** Maximum size of the payload in bytes (EPC069-12, section 5.2). */
    public const int MAX_BYTES = 331;

    /**
     * The payment QR code type "girocode".
     */
    public function key(): string
    {
        return 'girocode';
    }

    /**
     * Whether a GiroCode can be printed: EUR, a (SEPA) credit transfer to a valid IBAN, a positive
     * amount due (BT-115), and neither a credit note nor a self-billed invoice.
     */
    public function supports(InvoiceData $invoice): bool
    {
        return $this->transferable($invoice)
            && $invoice->totals !== null
            && $invoice->totals->amountDue->isPositive();
    }

    public function payload(InvoiceData $invoice, array $options = []): ?string
    {
        $value = $options['value'] ?? null;
        if (! $this->transferable($invoice) || ($value === null && ! $this->supports($invoice))) {
            return null;
        }

        $transfer = $invoice->paymentInstructions?->primaryCreditTransfer();
        $amount = $value instanceof BigDecimal ? $value : $this->amount($invoice, ($options['amount'] ?? self::AMOUNT_DUE) === self::AMOUNT_DISCOUNTED);
        if ($transfer === null || $amount === null || $amount->isLessThan('0.01') || $amount->isGreaterThan('999999999.99')) {
            return null;
        }

        // The beneficiary name is mandatory: a blank payee or account name falls back to the seller.
        $name = $invoice->seller->name;
        foreach ([$invoice->payee?->name, $transfer->accountName] as $candidate) {
            if ($candidate !== null && trim($candidate) !== '') {
                $name = $candidate;
                break;
            }
        }
        $remittance = is_string($options['remittance'] ?? null) && trim($options['remittance']) !== '' ? $options['remittance'] : $invoice->paymentInstructions?->remittanceInformation;
        if ($remittance === null || trim($remittance) === '') {
            $remittance = $invoice->number;
        }

        $fixed = [
            'BCD',
            '002',
            '1',
            'SCT',
            strtoupper((string) $transfer->serviceProviderId),
            '',
            Iban::normalize($transfer->accountId),
            'EUR'.$amount->toScale(2)->toString(),
            '',
            '',
            '',
        ];
        // Field lengths count characters, the whole payload must not exceed 331 bytes (UTF-8):
        // the beneficiary name comes first, the remittance gets the remaining bytes.
        $budget = self::MAX_BYTES - strlen(implode("\n", $fixed));
        $lines = $fixed;
        $lines[5] = mb_strcut(self::truncate($name, 70), 0, max(0, $budget), 'UTF-8');
        $lines[10] = mb_strcut(self::truncate($remittance, 140), 0, max(0, $budget - strlen($lines[5])), 'UTF-8');

        // No separator after the last element that has a value.
        while ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }

        return implode("\n", $lines);
    }

    /**
     * Amount due, or the amount after the first cash discount.
     */
    public function amount(InvoiceData $invoice, bool $discounted = false): ?BigDecimal
    {
        $discount = $invoice->paymentTerms->discounts[0] ?? null;
        if (! $discounted || $discount === null) {
            return $invoice->totals?->amountDue->getAmount();
        }

        return $this->discountedAmount($invoice, $discount);
    }

    /**
     * Amount due after the given cash discount (on its base amount, or on the amount due).
     */
    public function discountedAmount(InvoiceData $invoice, CashDiscount $discount): ?BigDecimal
    {
        $due = $invoice->totals?->amountDue->getAmount();
        if ($due === null) {
            return null;
        }

        $base = $discount->baseAmount?->getAmount() ?? $due;
        $reduction = Decimal::round($base->multipliedBy($discount->percent)->dividedBy(100, 10, RoundingMode::HalfUp), 2);

        return $due->minus($reduction);
    }

    /**
     * EUR credit transfer to a valid IBAN that the buyer is asked to pay: credit notes are
     * refunds, self-billed invoices are paid out by the issuer (the buyer).
     */
    private function transferable(InvoiceData $invoice): bool
    {
        $instructions = $invoice->paymentInstructions;
        $transfer = $instructions?->primaryCreditTransfer();

        return $invoice->currency === 'EUR'
            && ! $invoice->isCreditNote()
            && ! ($invoice->typeCode instanceof InvoiceTypeCode && $invoice->typeCode->isSelfBilled())
            && $instructions !== null
            && in_array($instructions->meansCode, [PaymentMeansCode::SepaCreditTransfer, PaymentMeansCode::CreditTransfer], true)
            && $transfer !== null
            && Iban::isValid($transfer->accountId);
    }

    private static function truncate(string $value, int $length): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? $value);

        return mb_substr($value, 0, $length);
    }
}
