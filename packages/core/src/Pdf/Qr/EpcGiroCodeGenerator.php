<?php

declare(strict_types=1);

namespace Quittio\Core\Pdf\Qr;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Enums\PaymentMeansCode;
use Quittio\Core\Support\Decimal;
use Quittio\Core\Validation\Plausibility\Iban;

/**
 * EPC QR code ("GiroCode") for SEPA credit transfers, guideline EPC069-12 version 002.
 *
 * Created when the currency is EUR, the payment means is a credit transfer with an
 * IBAN and the amount due is positive.
 */
final class EpcGiroCodeGenerator implements PaymentQrGenerator
{
    public const string AMOUNT_DUE = 'due';

    public const string AMOUNT_DISCOUNTED = 'discounted';

    public function key(): string
    {
        return 'girocode';
    }

    public function supports(InvoiceData $invoice): bool
    {
        $instructions = $invoice->paymentInstructions;
        $transfer = $instructions?->primaryCreditTransfer();

        return $invoice->currency === 'EUR'
            && $instructions !== null
            && in_array($instructions->meansCode, [PaymentMeansCode::SepaCreditTransfer, PaymentMeansCode::CreditTransfer], true)
            && $transfer !== null
            && Iban::isValid($transfer->accountId)
            && $invoice->totals !== null
            && $invoice->totals->amountDue->isPositive();
    }

    public function payload(InvoiceData $invoice, array $options = []): ?string
    {
        if (! $this->supports($invoice)) {
            return null;
        }

        $transfer = $invoice->paymentInstructions?->primaryCreditTransfer();
        $amount = $this->amount($invoice, ($options['amount'] ?? self::AMOUNT_DUE) === self::AMOUNT_DISCOUNTED);
        if ($transfer === null || $amount === null || $amount->isLessThan('0.01') || $amount->isGreaterThan('999999999.99')) {
            return null;
        }

        $name = $invoice->payee->name ?? ($transfer->accountName ?? $invoice->seller->name);
        $remittance = $invoice->paymentInstructions->remittanceInformation ?? $invoice->number;

        $lines = [
            'BCD',
            '002',
            '1',
            'SCT',
            strtoupper((string) $transfer->serviceProviderId),
            self::truncate($name, 70),
            Iban::normalize($transfer->accountId),
            'EUR'.$amount->toScale(2)->toString(),
            '',
            '',
            self::truncate($remittance, 140),
        ];

        return implode("\n", $lines);
    }

    /**
     * Amount due, or the amount after the first cash discount.
     */
    public function amount(InvoiceData $invoice, bool $discounted = false): ?BigDecimal
    {
        $due = $invoice->totals?->amountDue->getAmount();
        if ($due === null) {
            return null;
        }

        $discount = $invoice->paymentTerms->discounts[0] ?? null;
        if (! $discounted || $discount === null) {
            return $due;
        }

        $base = $discount->baseAmount?->getAmount() ?? $due;
        $reduction = Decimal::round($base->multipliedBy($discount->percent)->dividedBy(100, 10, RoundingMode::HalfUp), 2);

        return $due->minus($reduction);
    }

    private static function truncate(string $value, int $length): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? $value);

        return mb_substr($value, 0, $length);
    }
}
