<?php

declare(strict_types=1);

namespace Quittio\Core\View\Internal\Sections;

use Brick\Math\BigDecimal;
use Quittio\Core\Codes\RawCode;
use Quittio\Core\Data\Presentation\Presentation;
use Quittio\Core\Documents\DocumentType;
use Quittio\Core\Exceptions\ConfigurationException;
use Quittio\Core\Pdf\Qr\EpcGiroCodeGenerator;
use Quittio\Core\Pdf\Qr\PaymentQrGenerator;
use Quittio\Core\Pdf\Qr\QrImage;
use Quittio\Core\Support\Amount;
use Quittio\Core\Validation\Plausibility\Iban;
use Quittio\Core\View\FormattedValue;
use Quittio\Core\View\Internal\RunScope;
use Quittio\Core\View\ViewModelFactory;

/**
 * Payment instructions with the payment QR code, and the payment terms.
 *
 * @internal
 */
final readonly class PaymentSection
{
    public function __construct(private RunScope $scope) {}

    /**
     * @return array<string, mixed> payment, paymentTerms
     */
    public function build(): array
    {
        return [
            'payment' => $this->payment(),
            'paymentTerms' => $this->paymentTerms(),
        ];
    }

    /**
     * @param  BigDecimal|null  $value  amount instead of the amount due (payment reminders)
     * @param  string|null  $remittance  remittance information instead of BT-83 (payment reminders)
     * @return array{type: string, payload: string, dataUri: string, amount: ?FormattedValue, note: ?string}|null
     */
    public function qrCode(?BigDecimal $value = null, ?string $remittance = null): ?array
    {
        $scope = $this->scope;
        $invoice = $scope->invoice;
        $mode = $scope->presentation->paymentQr;
        if (! in_array($mode, ViewModelFactory::PAYMENT_QR_MODES, true)) {
            throw new ConfigurationException(sprintf('Unknown payment QR code type "%s". Known types: %s.', $mode, implode(', ', ViewModelFactory::PAYMENT_QR_MODES)));
        }
        // No payment request for refunds and self-billed invoices (the issuer pays out).
        if ($mode === Presentation::PAYMENT_QR_NONE || $scope->isRefund() || $scope->selfBilled) {
            return null;
        }

        $generator = match (true) {
            $mode === Presentation::PAYMENT_QR_AUTO => $scope->context->paymentQr ?? $this->countryPaymentQr(),
            $mode === Presentation::PAYMENT_QR_GIROCODE => new EpcGiroCodeGenerator,
            default => $scope->context->paymentQr ?? throw new ConfigurationException(sprintf(
                'The payment QR code type "%s" needs a generator, and Quittio ships none. Register one with setPaymentQrGenerator() (Laravel: quittio.pdf.payment_qr.generator), or use the type "auto", "girocode" or "none".',
                $mode,
            )),
        };

        if ($generator === null || ($value === null && ! $generator->supports($invoice))) {
            return null;
        }

        $amountOption = $value === null ? $scope->context->options['girocode_amount'] ?? EpcGiroCodeGenerator::AMOUNT_DUE : EpcGiroCodeGenerator::AMOUNT_DUE;
        $payload = $generator->payload($invoice, ['amount' => $amountOption, 'value' => $value, 'remittance' => $remittance]);
        if ($payload === null) {
            return null;
        }

        $amount = null;
        $note = null;
        if ($value !== null) {
            $amount = $scope->money(Amount::fromDecimal($value, $invoice->currency));
        } elseif ($generator instanceof EpcGiroCodeGenerator) {
            $discount = $invoice->paymentTerms->discounts[0] ?? null;
            $discounted = $amountOption === EpcGiroCodeGenerator::AMOUNT_DISCOUNTED && $discount !== null;
            $value = $generator->amount($invoice, $discounted);
            $amount = $value === null ? null : $scope->money(Amount::fromDecimal($value, $invoice->currency));
            if ($discounted && $amount !== null) {
                // The code contains the amount after cash discount; it is only valid until the discount ends.
                $note = str_replace([':amount', ':date'], [$amount->formatted, (string) $scope->date($invoice->issueDate->addDays($discount->days))], $scope->labels['girocode_discounted'] ?? '');
            }
        }

        return [
            'type' => $generator->key(),
            'payload' => $payload,
            'dataUri' => QrImage::dataUri($payload),
            'amount' => $amount,
            'note' => $note,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function payment(): ?array
    {
        $scope = $this->scope;
        $instructions = $scope->invoice->paymentInstructions;
        if ($instructions === null) {
            return null;
        }

        $qr = $this->qrCode();

        return [
            'meansCode' => (string) $instructions->meansCode->value,
            'means' => $instructions->meansCode instanceof RawCode ? $instructions->meansCode->value : $instructions->meansCode->label($scope->locale),
            'meansText' => $instructions->meansText,
            'remittance' => ViewModelFactory::remittance($scope->invoice),
            'accounts' => array_map(static fn ($t): array => [
                'iban' => $t->isIban() ? trim(chunk_split(Iban::normalize($t->accountId), 4, ' ')) : $t->accountId,
                'bic' => $t->serviceProviderId,
                'name' => $t->accountName,
            ], $instructions->creditTransfers),
            'card' => $instructions->card === null ? null : ['number' => '•••• '.$instructions->card->primaryAccountNumber, 'holder' => $instructions->card->holderName],
            'directDebit' => $instructions->directDebit === null ? null : [
                'mandate' => $instructions->directDebit->mandateReference,
                'creditorId' => $instructions->directDebit->creditorId,
                'account' => $instructions->directDebit->debitedAccountId,
            ],
            'qrCode' => $qr,
            'isRefund' => $scope->isRefund(),
            // Self-billed: the accounts are the seller's accounts the amount is paid out to.
            'isPayout' => $scope->selfBilled,
        ];
    }

    /**
     * Generator of the payment QR code type "auto" after a registered one: the type the country
     * pack names (CountryDefaults::$paymentQr; GiroCode without pack), "none" prints no code;
     * otherwise the pack's generator (CountryPack::paymentQr()), else the EPC GiroCode for
     * "girocode". Other types without a generator of the pack print no code.
     */
    private function countryPaymentQr(): ?PaymentQrGenerator
    {
        $country = $this->scope->context->country;
        $type = $country?->defaults()->paymentQr ?? Presentation::PAYMENT_QR_GIROCODE;
        if ($type === Presentation::PAYMENT_QR_NONE) {
            return null;
        }

        return $country?->paymentQr() ?? ($type === Presentation::PAYMENT_QR_GIROCODE ? new EpcGiroCodeGenerator : null);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function paymentTerms(): ?array
    {
        $scope = $this->scope;
        $invoice = $scope->invoice;
        $labels = $scope->baseLabels;
        $terms = $invoice->paymentTerms;
        $due = $invoice->totals?->amountDue;
        if ($terms === null && $invoice->dueDate === null) {
            return null;
        }

        $lines = [];
        if ($terms?->text !== null) {
            $lines[] = $terms->text;
        }
        // Credit notes are refunds and offers are no request to pay: no due date, no amount after discount.
        $offer = in_array($scope->document?->type, [DocumentType::Quote, DocumentType::OrderConfirmation], true);
        $payable = $due !== null && $due->isPositive() && ! $invoice->isCreditNote() && ! $offer;
        if ($invoice->dueDate !== null && $payable) {
            // Self-billed: the issuer (buyer) pays the amount out, the recipient is not asked to pay.
            // "Bitte bis zum … überweisen." only for a credit transfer without direct debit.
            $instructions = $invoice->paymentInstructions;
            $transfer = $instructions !== null && $instructions->creditTransfers !== [] && $instructions->directDebit === null && isset($labels['transfer_until']);
            $lines[] = str_replace(':date', (string) $scope->date($invoice->dueDate), $labels[match (true) {
                $scope->selfBilled => 'payout_until',
                $transfer => 'transfer_until',
                default => 'payable_until',
            }]);
        }

        $discounts = [];
        $generator = new EpcGiroCodeGenerator;
        foreach ($terms->discounts ?? [] as $discount) {
            $percent = $scope->context->formatter->percent($discount->percent, $scope->locale);
            $until = null;
            $amount = null;
            if ($payable) {
                $until = $scope->date($invoice->issueDate->addDays($discount->days));
                $value = $generator->discountedAmount($invoice, $discount);
                $amount = $value === null ? null : $scope->money(Amount::fromDecimal($value, $invoice->currency));
                $text = str_replace([':date', ':percent', ':amount'], [(string) $until, $percent, (string) $amount], $labels[$scope->selfBilled ? 'cash_discount_payout' : 'cash_discount']);
            } else {
                // The agreement as stated in BT-20, without a date or an amount to pay.
                $text = str_replace([':days', ':percent'], [(string) $discount->days, $percent], $labels['cash_discount_terms']);
            }
            $discounts[] = ['days' => $discount->days, 'percent' => $percent, 'until' => $until, 'amount' => $amount, 'text' => $text];
            $lines[] = $text;
        }
        if ($terms?->lateFeeTerms?->text !== null) {
            $lines[] = $terms->lateFeeTerms->text;
        }

        return $lines === [] ? null : ['lines' => $lines, 'discounts' => $discounts, 'dueDate' => $scope->date($invoice->dueDate)];
    }
}
