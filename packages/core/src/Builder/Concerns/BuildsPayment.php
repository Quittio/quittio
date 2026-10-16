<?php

declare(strict_types=1);

namespace Quittio\Core\Builder\Concerns;

use Brick\Math\BigDecimal;
use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Builder\PaymentTermsBuilder;
use Quittio\Core\Codes\RawCode;
use Quittio\Core\Data\Identifier;
use Quittio\Core\Data\Payment\CreditTransfer;
use Quittio\Core\Data\Payment\DirectDebit;
use Quittio\Core\Data\Payment\PaymentCard;
use Quittio\Core\Data\Payment\PaymentInstructions;
use Quittio\Core\Data\Payment\PaymentTerms;
use Quittio\Core\Enums\PaymentMeansCode;
use Quittio\Core\Exceptions\InvalidArgumentException;
use Quittio\Core\Support\Amount;
use Quittio\Core\Support\Date;
use Quittio\Core\Support\Decimal;

/**
 * Payment instructions (BG-16 to BG-19), payment terms and the prepaid and rounding amounts (BT-113, BT-114).
 *
 * @internal part of {@see InvoiceBuilder}, which holds the state
 */
trait BuildsPayment
{
    /**
     * Payment instructions (BG-16) with an optional credit transfer account (BG-17).
     *
     * A second call replaces the payment instructions: an account given here replaces the
     * accounts, and data of other payment means is dropped (e.g. bank accounts when switching
     * to direct debit). Without an account, existing accounts are kept for credit transfers
     * (e.g. a seller default account); likewise card (BG-18) and direct debit (BG-19) data for
     * card payments and direct debits. The remittance information (BT-83) is kept unless a new
     * one is given. Further accounts are added with creditTransfer().
     */
    public function payment(
        PaymentMeansCode|RawCode|string $means,
        ?string $iban = null,
        ?string $bic = null,
        ?string $accountName = null,
        ?string $remittanceInformation = null,
        ?string $meansText = null,
    ): static {
        $means = is_string($means) ? (PaymentMeansCode::tryFrom($means) ?? new RawCode($means)) : $means;
        $previous = $this->paymentInstructions;
        $code = (string) $means->value;

        $transfers = match (true) {
            $iban !== null => [new CreditTransfer(strtoupper(str_replace(' ', '', $iban)), $accountName, $bic)],
            in_array($code, self::CREDIT_TRANSFER_MEANS, true) => $previous === null ? [] : $previous->creditTransfers,
            default => [],
        };

        $this->paymentInstructions = new PaymentInstructions(
            meansCode: $means,
            meansText: $meansText,
            remittanceInformation: $remittanceInformation ?? $previous?->remittanceInformation,
            creditTransfers: $transfers,
            card: in_array($code, self::CARD_MEANS, true) ? $previous?->card : null,
            directDebit: in_array($code, self::DIRECT_DEBIT_MEANS, true) ? $previous?->directDebit : null,
        );

        return $this;
    }

    /**
     * Adds a further bank account (BG-17).
     */
    public function creditTransfer(string $accountId, ?string $bic = null, ?string $accountName = null): static
    {
        $instructions = $this->paymentInstructions ?? new PaymentInstructions(PaymentMeansCode::SepaCreditTransfer);
        $this->paymentInstructions = $instructions->with([
            'creditTransfers' => [...$instructions->creditTransfers, new CreditTransfer(strtoupper(str_replace(' ', '', $accountId)), $accountName, $bic)],
        ]);

        return $this;
    }

    /**
     * Payment card (BG-18): the last 4 to 6 digits of the card number (BT-87) and the holder
     * (BT-88). Without payment instructions it starts them with BankCard (48).
     *
     * @throws InvalidArgumentException for more or fewer digits
     */
    public function paymentCard(string $lastDigits, ?string $holderName = null): static
    {
        $instructions = $this->paymentInstructions ?? new PaymentInstructions(PaymentMeansCode::BankCard);
        $this->paymentInstructions = $instructions->with(['card' => new PaymentCard($lastDigits, $holderName)]);

        return $this;
    }

    /**
     * SEPA direct debit (BG-19): mandate reference (BT-89), creditor identifier (BT-90) and the
     * debited IBAN (BT-91, spaces removed). Without payment instructions it starts them with
     * SepaDirectDebit (59).
     */
    public function directDebit(?string $mandateReference, ?string $creditorId = null, ?string $debitedIban = null): static
    {
        $instructions = $this->paymentInstructions ?? new PaymentInstructions(PaymentMeansCode::SepaDirectDebit);
        $this->paymentInstructions = $instructions->with([
            'directDebit' => new DirectDebit($mandateReference, $creditorId, $debitedIban === null ? null : strtoupper(str_replace(' ', '', $debitedIban))),
        ]);

        return $this;
    }

    /**
     * Payment target: the due date (BT-9) is the issue date plus $days unless dueDate() is set,
     * and the payment terms say "Payable within … days without deduction." Continues the
     * payment terms set before.
     */
    public function dueInDays(int $days): static
    {
        return $this->paymentTerms(static fn (PaymentTermsBuilder $terms) => $terms->dueInDays($days));
    }

    /**
     * Cash discount ("Skonto") of $percent for payment within $days days after the issue date,
     * optionally on a base amount; repeatable for several tiers. Continues the payment terms.
     */
    public function cashDiscount(int $days, string $percent, ?string $baseAmount = null): static
    {
        return $this->paymentTerms(static fn (PaymentTermsBuilder $terms) => $terms->cashDiscount($days, $percent, $baseAmount));
    }

    /**
     * Late payment terms: days, percentage and text. Continues the payment terms.
     */
    public function lateFee(?int $days = null, ?string $percent = null, ?string $text = null): static
    {
        return $this->paymentTerms(static fn (PaymentTermsBuilder $terms) => $terms->lateFee($days, $percent, $text));
    }

    /**
     * Payment terms (BT-20): a PaymentTerms object or a text replace earlier terms; a callable
     * receives a PaymentTermsBuilder that continues the earlier terms, also those of a
     * PaymentTerms object or of from(), like seller() and buyer().
     *
     * @param  PaymentTerms|string|callable(PaymentTermsBuilder): mixed  $terms
     */
    public function paymentTerms(PaymentTerms|string|callable $terms): static
    {
        if ($terms instanceof PaymentTerms) {
            $this->paymentTerms = $terms;

            return $this;
        }

        if (is_string($terms)) {
            $this->paymentTerms = (new PaymentTermsBuilder)->text($terms);

            return $this;
        }

        $builder = $this->paymentTerms instanceof PaymentTerms ? PaymentTermsBuilder::fromTerms($this->paymentTerms) : ($this->paymentTerms ?? new PaymentTermsBuilder);
        $terms($builder);
        $this->paymentTerms = $builder;

        return $this;
    }

    /**
     * Amount already paid (BT-113), e.g. received advance payments.
     */
    public function prepaid(BigDecimal|int|float|string $amount): static
    {
        $this->prepaid = (string) Decimal::rejectFloat($amount);

        return $this;
    }

    /**
     * Adds to the amount already paid (BT-113), e.g. one of several advance payments.
     */
    public function addPrepaid(BigDecimal|int|float|string $amount): static
    {
        $this->prepaid = (string) Decimal::of($this->prepaid ?? '0')->plus(Decimal::of(Decimal::rejectFloat($amount)));

        return $this;
    }

    /**
     * Rounding amount (BT-114), e.g. cash rounding. Never use it to fix wrong totals.
     */
    public function rounding(BigDecimal|int|float|string $amount): static
    {
        $this->rounding = (string) Decimal::rejectFloat($amount);

        return $this;
    }
}
