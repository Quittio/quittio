<?php

declare(strict_types=1);

namespace Quittio\Country\DE\Rules;

use Closure;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Enums\InvoiceTypeCode;
use Quittio\Core\Enums\PaymentMeansCode;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Profiles\Syntax;
use Quittio\Core\Validation\Plausibility\Iban;
use Quittio\Core\Validation\Rule;
use Quittio\Core\Validation\Rules\CallbackRule;
use Quittio\Core\Validation\Rules\Inspect;
use Quittio\Core\Validation\Rules\RuleReporter;
use Quittio\Country\DE\CountryDE;

/**
 * Business rules of the CIUS XRechnung 3.0 (BR-DE-…), checked on the data model.
 *
 * Severity follows the flags of the official Schematron: "muss" rules (fatal) are errors,
 * "soll" rules (warning) warnings and BR-DE-TMP-32 (information) an info. The official
 * validation (KoSIT validator) remains authoritative.
 */
final class XRechnungRules
{
    private const string NS = CountryDE::TRANSLATION_NAMESPACE;

    /**
     * @return list<Rule>
     */
    public static function all(): array
    {
        CountryDE::registerTranslations();
        $r = static fn (string $code, string $fallback, Closure $check): Rule => CallbackRule::l3($code, $fallback, $check, self::NS);

        return [
            $r('BR-DE-1', 'An invoice must contain payment instructions (BG-16).', function (InvoiceData $i, RuleReporter $report): void {
                if ($i->paymentInstructions === null) {
                    $report->error('BG-16', 'paymentInstructions');
                }
            }),
            $r('BR-DE-2', 'The seller contact (BG-6) must be provided.', function (InvoiceData $i, RuleReporter $report): void {
                if ($i->seller->contact === null) {
                    $report->error('BG-6', 'seller.contact');
                }
            }),
            $r('BR-DE-3', 'The seller city (BT-37) must be provided.', function (InvoiceData $i, RuleReporter $report): void {
                if (! Inspect::filled($i->seller->address->city)) {
                    $report->error('BT-37', 'seller.address.city');
                }
            }),
            $r('BR-DE-4', 'The seller post code (BT-38) must be provided.', function (InvoiceData $i, RuleReporter $report): void {
                if (! Inspect::filled($i->seller->address->postcode)) {
                    $report->error('BT-38', 'seller.address.postcode');
                }
            }),
            $r('BR-DE-5', 'The seller contact point (BT-41) must be provided.', function (InvoiceData $i, RuleReporter $report): void {
                if ($i->seller->contact !== null && ! Inspect::filled($i->seller->contact->name)) {
                    $report->error('BT-41', 'seller.contact.name');
                }
            }),
            $r('BR-DE-6', 'The seller contact telephone number (BT-42) must be provided.', function (InvoiceData $i, RuleReporter $report): void {
                if ($i->seller->contact !== null && ! Inspect::filled($i->seller->contact->phone)) {
                    $report->error('BT-42', 'seller.contact.phone');
                }
            }),
            $r('BR-DE-7', 'The seller contact email address (BT-43) must be provided.', function (InvoiceData $i, RuleReporter $report): void {
                if ($i->seller->contact !== null && ! Inspect::filled($i->seller->contact->email)) {
                    $report->error('BT-43', 'seller.contact.email');
                }
            }),
            $r('BR-DE-8', 'The buyer city (BT-52) must be provided.', function (InvoiceData $i, RuleReporter $report): void {
                if (! Inspect::filled($i->buyer->address->city)) {
                    $report->error('BT-52', 'buyer.address.city');
                }
            }),
            $r('BR-DE-9', 'The buyer post code (BT-53) must be provided.', function (InvoiceData $i, RuleReporter $report): void {
                if (! Inspect::filled($i->buyer->address->postcode)) {
                    $report->error('BT-53', 'buyer.address.postcode');
                }
            }),
            $r('BR-DE-10', 'The deliver to city (BT-77) must be provided if a deliver to address (BG-15) is given.', function (InvoiceData $i, RuleReporter $report): void {
                $address = $i->delivery?->address;
                if ($address !== null && ! Inspect::filled($address->city)) {
                    $report->error('BT-77', 'delivery.address.city');
                }
            }),
            $r('BR-DE-11', 'The deliver to post code (BT-78) must be provided if a deliver to address (BG-15) is given.', function (InvoiceData $i, RuleReporter $report): void {
                $address = $i->delivery?->address;
                if ($address !== null && ! Inspect::filled($address->postcode)) {
                    $report->error('BT-78', 'delivery.address.postcode');
                }
            }),
            $r('BR-DE-15', 'The buyer reference (BT-10) must be provided.', function (InvoiceData $i, RuleReporter $report): void {
                if (! Inspect::filled($i->buyerReference)) {
                    $report->error('BT-10', 'buyerReference');
                }
            }),
            $r('BR-DE-16', 'If VAT categories S, Z, E, AE, K, G, L or M are used, the seller VAT identifier (BT-31), the seller tax registration identifier (BT-32) or the seller tax representative (BG-11) must be provided.', function (InvoiceData $i, RuleReporter $report): void {
                $taxed = array_filter(Inspect::categories($i), static fn (VatCategory $c): bool => $c !== VatCategory::NotSubjectToVat);
                if ($taxed !== [] && ! Inspect::filled($i->seller->vatId) && ! Inspect::filled($i->seller->taxNumber) && $i->sellerTaxRepresentative === null) {
                    $report->error('BT-31', 'seller.vatId');
                }
            }),
            $r('BR-DE-17', 'The invoice type code (BT-3) should be one of 326, 380, 384, 389, 381, 875, 876 or 877.', function (InvoiceData $i, RuleReporter $report): void {
                if (! in_array($i->typeCodeValue(), ['326', '380', '384', '389', '381', '875', '876', '877'], true)) {
                    $report->warning('BT-3', 'typeCode');
                }
            }),
            $r('BR-DE-18', 'Cash discount lines in the payment terms (BT-20) must follow the format #SKONTO#TAGE=n#PROZENT=n.nn# and end with a line break.', function (InvoiceData $i, RuleReporter $report): void {
                $text = $i->paymentTerms?->text;
                if ($text === null) {
                    return;
                }
                $lines = preg_split('/\R/', $text);
                foreach ($lines === false ? [] : $lines as $line) {
                    if (str_starts_with(trim($line), '#') && preg_match('/^#SKONTO#TAGE=([0-9]+#PROZENT=[0-9]+\.[0-9]{2})(#BASISBETRAG=-?[0-9]+\.[0-9]{2})?#$/', trim($line)) !== 1) {
                        $report->error('BT-20', 'paymentTerms.text');
                    }
                }
            }),
            $r('BR-DE-19', 'The payment account identifier (BT-84) should contain a valid IBAN for SEPA credit transfers (code 58).', function (InvoiceData $i, RuleReporter $report): void {
                if ($i->paymentInstructions?->meansCode !== PaymentMeansCode::SepaCreditTransfer) {
                    return;
                }
                foreach ($i->paymentInstructions->creditTransfers as $index => $transfer) {
                    if (! Iban::isValid($transfer->accountId)) {
                        $report->warning('BT-84', "paymentInstructions.creditTransfers[{$index}].accountId");
                    }
                }
            }),
            $r('BR-DE-20', 'The debited account identifier (BT-91) should contain a valid IBAN for SEPA direct debit (code 59).', function (InvoiceData $i, RuleReporter $report): void {
                $account = $i->paymentInstructions?->directDebit?->debitedAccountId;
                if ($i->paymentInstructions?->meansCode === PaymentMeansCode::SepaDirectDebit && $account !== null && ! Iban::isValid($account)) {
                    $report->warning('BT-91', 'paymentInstructions.directDebit.debitedAccountId');
                }
            }),
            $r('BR-DE-22', 'The file names of attached documents (BT-125-2) must be unique.', function (InvoiceData $i, RuleReporter $report): void {
                $names = array_values(array_filter(array_map(static fn ($a): ?string => $a->filename, $i->attachments), static fn (?string $name): bool => $name !== null));
                if (count($names) !== count(array_unique($names))) {
                    $report->error('BT-125-2', 'attachments');
                }
            }),
            $r('BR-DE-23-a', 'For credit transfers (codes 30, 58), credit transfer information (BG-17) must be provided.', function (InvoiceData $i, RuleReporter $report): void {
                if (self::meansIn($i, ['30', '58']) && ($i->paymentInstructions === null ? [] : $i->paymentInstructions->creditTransfers) === []) {
                    $report->error('BG-17', 'paymentInstructions.creditTransfers');
                }
            }),
            $r('BR-DE-23-b', 'For credit transfers (codes 30, 58), payment card (BG-18) and direct debit (BG-19) information must not be provided.', function (InvoiceData $i, RuleReporter $report): void {
                if (self::meansIn($i, ['30', '58']) && ($i->paymentInstructions?->card !== null || $i->paymentInstructions?->directDebit !== null)) {
                    $report->error('BG-18', 'paymentInstructions');
                }
            }),
            $r('BR-DE-24-a', 'For card payments (codes 48, 54, 55), payment card information (BG-18) must be provided.', function (InvoiceData $i, RuleReporter $report): void {
                if (self::meansIn($i, ['48', '54', '55']) && $i->paymentInstructions?->card === null) {
                    $report->error('BG-18', 'paymentInstructions.card');
                }
            }),
            $r('BR-DE-24-b', 'For card payments (codes 48, 54, 55), credit transfer (BG-17) and direct debit (BG-19) information must not be provided.', function (InvoiceData $i, RuleReporter $report): void {
                if (self::meansIn($i, ['48', '54', '55']) && (($i->paymentInstructions === null ? [] : $i->paymentInstructions->creditTransfers) !== [] || $i->paymentInstructions?->directDebit !== null)) {
                    $report->error('BG-17', 'paymentInstructions');
                }
            }),
            $r('BR-DE-25-a', 'For direct debit (code 59), direct debit information (BG-19) must be provided.', function (InvoiceData $i, RuleReporter $report): void {
                if (self::meansIn($i, ['59']) && $i->paymentInstructions?->directDebit === null) {
                    $report->error('BG-19', 'paymentInstructions.directDebit');
                }
            }),
            $r('BR-DE-25-b', 'For direct debit (code 59), credit transfer (BG-17) and payment card (BG-18) information must not be provided.', function (InvoiceData $i, RuleReporter $report): void {
                if (self::meansIn($i, ['59']) && (($i->paymentInstructions === null ? [] : $i->paymentInstructions->creditTransfers) !== [] || $i->paymentInstructions?->card !== null)) {
                    $report->error('BG-17', 'paymentInstructions');
                }
            }),
            $r('BR-DE-26', 'A corrected invoice (type code 384) should reference the preceding invoice (BG-3).', function (InvoiceData $i, RuleReporter $report): void {
                if ($i->typeCode === InvoiceTypeCode::CorrectedInvoice && $i->precedingInvoices === []) {
                    $report->warning('BG-3', 'precedingInvoices');
                }
            }),
            $r('BR-DE-27', 'The seller contact telephone number (BT-42) should contain at least three digits.', function (InvoiceData $i, RuleReporter $report): void {
                $phone = $i->seller->contact?->phone;
                if ($phone !== null && preg_match_all('/\d/', $phone) < 3) {
                    $report->warning('BT-42', 'seller.contact.phone');
                }
            }),
            $r('BR-DE-28', 'The seller contact email address (BT-43) should contain exactly one @ with at least two characters on both sides.', function (InvoiceData $i, RuleReporter $report): void {
                $email = $i->seller->contact?->email;
                if ($email !== null && preg_match('/^[^@\s.][^@\s]+@[^@\s]+[^@\s.]$/', $email) !== 1) {
                    $report->warning('BT-43', 'seller.contact.email');
                }
            }),
            $r('BR-DE-30', 'For direct debit (BG-19), the bank assigned creditor identifier (BT-90) must be provided.', function (InvoiceData $i, RuleReporter $report): void {
                $debit = $i->paymentInstructions?->directDebit;
                if ($debit !== null && ! Inspect::filled($debit->creditorId)) {
                    $report->error('BT-90', 'paymentInstructions.directDebit.creditorId');
                }
            }),
            $r('BR-DE-31', 'For direct debit (BG-19), the debited account identifier (BT-91) must be provided.', function (InvoiceData $i, RuleReporter $report): void {
                $debit = $i->paymentInstructions?->directDebit;
                if ($debit !== null && ! Inspect::filled($debit->debitedAccountId)) {
                    $report->error('BT-91', 'paymentInstructions.directDebit.debitedAccountId');
                }
            }),
            $r('BR-DE-TMP-32', 'The invoice should state the delivery or service date: actual delivery date (BT-72), invoicing period (BG-14) or a period on every line (BG-26).', function (InvoiceData $i, RuleReporter $report): void {
                if (! self::hasServiceDate($i)) {
                    $report->info('BT-72', 'delivery.actualDeliveryDate');
                }
            }),
            $r('BR-DEX-15', 'Sub invoice lines are not supported by XRechnung in CII. Use the UBL syntax for the XRechnung extension.', function (InvoiceData $i, RuleReporter $report): void {
                $syntax = $report->context()->option('syntax');
                if ($syntax === Syntax::Cii && array_filter($i->lines, static fn ($line): bool => $line->subLines !== []) !== []) {
                    $report->error(null, 'lines');
                }
            }),
            // The official validator configuration has no scenario for credit notes of the extension in UBL,
            // so recipients would reject the document.
            $r('DE-XRECHNUNG-EXT-01', 'The XRechnung extension has no credit note in UBL. Cancel with the strategy negative_invoice (type code 380 with negative amounts).', function (InvoiceData $i, RuleReporter $report): void {
                $context = $report->context();
                if ($context->profileIs('xrechnung-extension') && $context->option('syntax') === Syntax::Ubl && $i->isCreditNote()) {
                    $report->error('BT-3', 'typeCode');
                }
            }),
        ];
    }

    /**
     * Delivery date, invoicing period or a line period on every line.
     */
    public static function hasServiceDate(InvoiceData $invoice): bool
    {
        if ($invoice->delivery?->actualDeliveryDate !== null || $invoice->invoicingPeriod !== null) {
            return true;
        }

        foreach ($invoice->lines as $line) {
            if ($line->period === null) {
                return false;
            }
        }

        return $invoice->lines !== [];
    }

    /**
     * @param  list<string>  $codes
     */
    private static function meansIn(InvoiceData $invoice, array $codes): bool
    {
        return $invoice->paymentInstructions !== null && in_array((string) $invoice->paymentInstructions->meansCode->value, $codes, true);
    }
}
