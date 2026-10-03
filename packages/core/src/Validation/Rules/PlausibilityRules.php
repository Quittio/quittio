<?php

declare(strict_types=1);

namespace Quittio\Core\Validation\Rules;

use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Enums\ElectronicAddressScheme;
use Quittio\Core\Validation\Plausibility\Iban;
use Quittio\Core\Validation\Plausibility\VatIdFormat;
use Quittio\Core\Validation\Plausibility\VatIdVerifier;
use Quittio\Core\Validation\Rule;

/**
 * Plausibility checks (level L7): IBAN check digits, BIC and VAT identifier formats,
 * e-mail addresses and date logic. The VIES check is added only if a verifier is given.
 */
final class PlausibilityRules
{
    /**
     * @return list<Rule>
     */
    public static function all(?VatIdVerifier $verifier = null): array
    {
        $r = CallbackRule::l7(...);

        $rules = [
            $r('QUITTIO-IBAN', 'The IBAN ":value" has an invalid check digit or format.', function (InvoiceData $i, RuleReporter $report): void {
                foreach ($i->paymentInstructions->creditTransfers ?? [] as $index => $transfer) {
                    if (preg_match('/^[A-Z]{2}[0-9]{2}/', $transfer->accountId) === 1 && ! Iban::isValid($transfer->accountId)) {
                        $report->error('BT-84', "paymentInstructions.creditTransfers[{$index}].accountId", ['value' => $transfer->accountId]);
                    }
                }
                $debited = $i->paymentInstructions?->directDebit?->debitedAccountId;
                if ($debited !== null && preg_match('/^[A-Z]{2}[0-9]{2}/', $debited) === 1 && ! Iban::isValid($debited)) {
                    $report->error('BT-91', 'paymentInstructions.directDebit.debitedAccountId', ['value' => $debited]);
                }
            }),
            $r('QUITTIO-BIC', 'The BIC ":value" has an invalid format.', function (InvoiceData $i, RuleReporter $report): void {
                foreach ($i->paymentInstructions->creditTransfers ?? [] as $index => $transfer) {
                    $bic = $transfer->serviceProviderId;
                    if ($bic !== null && preg_match('/^[A-Z]{4}[A-Z]{2}[A-Z0-9]{2}([A-Z0-9]{3})?$/', strtoupper($bic)) !== 1) {
                        $report->warning('BT-86', "paymentInstructions.creditTransfers[{$index}].serviceProviderId", ['value' => $bic]);
                    }
                }
            }),
            $r('QUITTIO-VATID-FORMAT', 'The VAT identifier ":value" does not match the format of its country.', function (InvoiceData $i, RuleReporter $report): void {
                foreach ([
                    'seller.vatId' => ['BT-31', $i->seller->vatId],
                    'buyer.vatId' => ['BT-48', $i->buyer->vatId],
                    'sellerTaxRepresentative.vatId' => ['BT-63', $i->sellerTaxRepresentative?->vatId],
                ] as $path => [$bt, $vatId]) {
                    if ($vatId !== null && VatIdFormat::isValid($vatId) === false) {
                        $report->warning($bt, $path, ['value' => $vatId]);
                    }
                }
            }),
            $r('QUITTIO-EMAIL', 'The e-mail address ":value" is not valid.', function (InvoiceData $i, RuleReporter $report): void {
                $addresses = [
                    'seller.electronicAddress.value' => ['BT-34', $i->seller->electronicAddress],
                    'buyer.electronicAddress.value' => ['BT-49', $i->buyer->electronicAddress],
                ];
                foreach ($addresses as $path => [$bt, $address]) {
                    if ($address !== null && $address->scheme === ElectronicAddressScheme::Email
                        && filter_var($address->value, FILTER_VALIDATE_EMAIL) === false) {
                        $report->error($bt, $path, ['value' => $address->value]);
                    }
                }
                foreach (['seller' => ['BT-43', $i->seller->contact?->email], 'buyer' => ['BT-58', $i->buyer->contact?->email]] as $party => [$bt, $email]) {
                    if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                        $report->warning($bt, $party.'.contact.email', ['value' => $email]);
                    }
                }
            }),
            $r('QUITTIO-DUE-DATE', 'The payment due date (BT-9) lies before the invoice issue date (BT-2).', function (InvoiceData $i, RuleReporter $report): void {
                if ($i->dueDate !== null && $i->dueDate->isBefore($i->issueDate)) {
                    $report->warning('BT-9', 'dueDate');
                }
            }),
        ];

        if ($verifier !== null) {
            $rules[] = $r('QUITTIO-VIES', 'The VAT identifier ":value" is not valid according to VIES.', function (InvoiceData $i, RuleReporter $report) use ($verifier): void {
                foreach (['seller.vatId' => ['BT-31', $i->seller->vatId], 'buyer.vatId' => ['BT-48', $i->buyer->vatId]] as $path => [$bt, $vatId]) {
                    if ($vatId === null || VatIdFormat::isValid($vatId) !== true) {
                        continue;
                    }
                    if ($verifier->verify($vatId)->valid === false) {
                        $report->error($bt, $path, ['value' => $vatId]);
                    }
                }
            });
            $rules[] = $r('QUITTIO-VIES-UNAVAILABLE', 'The VAT identifier ":value" could not be checked because VIES is not reachable.', function (InvoiceData $i, RuleReporter $report) use ($verifier): void {
                foreach (['seller.vatId' => ['BT-31', $i->seller->vatId], 'buyer.vatId' => ['BT-48', $i->buyer->vatId]] as $path => [$bt, $vatId]) {
                    if ($vatId !== null && VatIdFormat::isValid($vatId) === true && $verifier->verify($vatId)->valid === null) {
                        $report->info($bt, $path, ['value' => $vatId]);
                    }
                }
            });
        }

        return $rules;
    }
}
