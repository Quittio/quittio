<?php

declare(strict_types=1);

namespace Quittio\Core\Validation\Rules;

use Quittio\Core\Countries\VatTerritory;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Data\Party\Address;
use Quittio\Core\Enums\ElectronicAddressScheme;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Validation\Plausibility\Iban;
use Quittio\Core\Validation\Plausibility\VatIdFormat;
use Quittio\Core\Validation\Plausibility\VatIdVerification;
use Quittio\Core\Validation\Plausibility\VatIdVerifier;
use Quittio\Core\Validation\Rule;

/**
 * Plausibility checks (level L7): IBAN check digits, BIC and VAT identifier formats,
 * e-mail addresses, date logic and the VAT category against the destination country.
 * The VIES check is added only if a verifier is given.
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

        $rules = [...$rules, ...self::destinationRules()];

        if ($verifier !== null) {
            // Both VIES rules look at the same identifiers: ask the verifier once per identifier
            // and validation run, also when it answers without a result (which it does not cache).
            $results = [];
            $verify = static function (string $vatId) use ($verifier, &$results): VatIdVerification {
                $entry = $results[$vatId] ?? null;
                if ($entry === null || $entry[0] < time() - 60) {
                    $entry = $results[$vatId] = [time(), $verifier->verify($vatId)];
                }

                return $entry[1];
            };

            $rules[] = $r('QUITTIO-VIES', 'The VAT identifier ":value" is not valid according to VIES.', function (InvoiceData $i, RuleReporter $report) use ($verify): void {
                foreach (['seller.vatId' => ['BT-31', $i->seller->vatId], 'buyer.vatId' => ['BT-48', $i->buyer->vatId]] as $path => [$bt, $vatId]) {
                    if ($vatId === null || VatIdFormat::isValid($vatId) !== true) {
                        continue;
                    }
                    if ($verify($vatId)->valid === false) {
                        $report->error($bt, $path, ['value' => $vatId]);
                    }
                }
            });
            $rules[] = $r('QUITTIO-VIES-UNAVAILABLE', 'The VAT identifier ":value" could not be checked because VIES is not reachable.', function (InvoiceData $i, RuleReporter $report) use ($verify): void {
                foreach (['seller.vatId' => ['BT-31', $i->seller->vatId], 'buyer.vatId' => ['BT-48', $i->buyer->vatId]] as $path => [$bt, $vatId]) {
                    if ($vatId === null || VatIdFormat::isValid($vatId) !== true) {
                        continue;
                    }
                    $result = $verify($vatId);
                    if ($result->valid === null && $result->supported) {
                        $report->info($bt, $path, ['value' => $vatId]);
                    }
                }
            });
        }

        return $rules;
    }

    /**
     * VAT category against the destination of the supply: deliver to country (BT-80),
     * otherwise the buyer's address (BT-55).
     *
     * @return list<Rule>
     */
    private static function destinationRules(): array
    {
        $r = CallbackRule::l7(...);

        return [
            $r('QUITTIO-VAT-K-DOMESTIC', 'VAT category "K" (intra-community supply) is used, but the destination :value lies in the seller\'s member state :expected. An intra-community supply requires a destination in another EU member state.', function (InvoiceData $i, RuleReporter $report): void {
                $destination = self::destination($i);
                $origin = self::originMemberState($i);
                if ($destination === null || $origin === null || ! Inspect::uses($i, VatCategory::IntraCommunitySupply)) {
                    return;
                }
                if (VatTerritory::memberState($destination->countryCode, $destination->postcode) === $origin) {
                    $report->warning('BT-80', self::destinationPath($i), ['value' => self::label($destination), 'expected' => $origin]);
                }
            }),
            $r('QUITTIO-VAT-K-OUTSIDE-EU', 'VAT category "K" (intra-community supply) is used, but the destination :value lies outside the EU VAT territory. Supplies to third countries are exports (category "G") or not taxable in the EU (category "O").', function (InvoiceData $i, RuleReporter $report): void {
                $destination = self::destination($i);
                if ($destination === null || ! Inspect::uses($i, VatCategory::IntraCommunitySupply)) {
                    return;
                }
                if (! VatTerritory::isEuVatTerritory($destination->countryCode, $destination->postcode)) {
                    $report->warning('BT-80', self::destinationPath($i), ['value' => self::label($destination)]);
                }
            }),
            $r('QUITTIO-VAT-G-INSIDE-EU', 'VAT category "G" (export) is used, but the destination :value lies in the EU VAT territory. Supplies within the EU are intra-community supplies (category "K") or taxable in the member state.', function (InvoiceData $i, RuleReporter $report): void {
                $destination = self::destination($i);
                if ($destination === null || ! Inspect::uses($i, VatCategory::Export)) {
                    return;
                }
                if (VatTerritory::isEuVatTerritory($destination->countryCode, $destination->postcode)) {
                    $report->warning('BT-80', self::destinationPath($i), ['value' => self::label($destination)]);
                }
            }),
        ];
    }

    private static function destination(InvoiceData $invoice): ?Address
    {
        $delivery = $invoice->delivery?->address;
        if ($delivery !== null && Inspect::filled($delivery->countryCode)) {
            return $delivery;
        }

        return Inspect::filled($invoice->buyer->address->countryCode) ? $invoice->buyer->address : null;
    }

    private static function destinationPath(InvoiceData $invoice): string
    {
        return $invoice->delivery?->address !== null && Inspect::filled($invoice->delivery->address->countryCode)
            ? 'delivery.address.countryCode'
            : 'buyer.address.countryCode';
    }

    /**
     * Member state the seller supplies from: the prefix of its VAT identifier (BT-31) or of its
     * tax representative (BT-63), otherwise its address (BT-40).
     */
    private static function originMemberState(InvoiceData $invoice): ?string
    {
        foreach ([$invoice->seller->vatId, $invoice->sellerTaxRepresentative?->vatId] as $vatId) {
            if (Inspect::filled($vatId) && ($state = VatTerritory::memberStateOfVatId((string) $vatId)) !== null) {
                return $state;
            }
        }

        $address = $invoice->seller->address;

        return VatTerritory::memberState($address->countryCode, $address->postcode);
    }

    private static function label(Address $address): string
    {
        $country = strtoupper($address->countryCode);
        $territory = VatTerritory::excludedTerritory($country, $address->postcode);

        return $territory === null ? $country : sprintf('%s (%s)', $country, $territory);
    }
}
