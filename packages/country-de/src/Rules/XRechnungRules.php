<?php

declare(strict_types=1);

namespace Quittio\Country\DE\Rules;

use BackedEnum;
use Brick\Math\BigDecimal;
use Closure;
use Quittio\Core\Codes\CodeList;
use Quittio\Core\Codes\RawCode;
use Quittio\Core\Data\Extensions\ThirdPartyPayment;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Data\Lines\Classification;
use Quittio\Core\Data\Lines\InvoiceLine;
use Quittio\Core\Data\Lines\ItemAttribute;
use Quittio\Core\Enums\InvoiceTypeCode;
use Quittio\Core\Enums\PaymentMeansCode;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Profiles\Capabilities\ExtendsCodeLists;
use Quittio\Core\Profiles\Syntax;
use Quittio\Core\Validation\Plausibility\Iban;
use Quittio\Core\Validation\Rule;
use Quittio\Core\Validation\Rules\CallbackRule;
use Quittio\Core\Validation\Rules\Inspect;
use Quittio\Core\Validation\Rules\RuleReporter;
use Quittio\Country\DE\CountryDE;
use Quittio\Country\DE\Profiles\XRechnung\XRechnung30Cvd;

/**
 * Business rules of the CIUS XRechnung 3.0 (BR-DE-…), its extension (BR-DEX-…) and XRechnung
 * CVD (BR-DE-CVD-…), checked on the data model.
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
            ...self::extensionRules($r),
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
            // XRechnung CVD (Clean Vehicles Directive): only with its specification identifier.
            $r('BR-DE-CVD-01', 'The contract reference (BT-12) must be provided.', function (InvoiceData $i, RuleReporter $report): void {
                if (self::isCvd($report) && ! Inspect::filled($i->contractReference)) {
                    $report->error('BT-12', 'contractReference');
                }
            }),
            $r('BR-DE-CVD-02', 'The tender or lot reference (BT-17) must be provided.', function (InvoiceData $i, RuleReporter $report): void {
                if (self::isCvd($report) && ! Inspect::filled($i->tenderReference)) {
                    $report->error('BT-17', 'tenderReference');
                }
            }),
            $r('BR-DE-CVD-03', 'At least one invoice line (BG-25) must contain an item classification identifier (BT-158) with the scheme identifier CVD and an item attribute named cva (BT-160).', function (InvoiceData $i, RuleReporter $report): void {
                if (! self::isCvd($report)) {
                    return;
                }
                foreach ($i->lines as $line) {
                    if (self::cvdClassifications($line) !== [] && self::cvaAttributes($line) !== []) {
                        return;
                    }
                }
                $report->error('BG-25', 'lines');
            }),
            $r('BR-DE-CVD-04', 'The item classification identifier (BT-158) ":value" with the scheme identifier CVD must be one of the vehicle categories M1, M2, M3, N1, N2 or N3. Line :line.', function (InvoiceData $i, RuleReporter $report): void {
                if (! self::isCvd($report)) {
                    return;
                }
                foreach ($i->lines as $line) {
                    foreach ($line->item->classifications as $index => $classification) {
                        if (trim($classification->listId->value) === XRechnung30Cvd::CLASSIFICATION_SCHEME && ! in_array(trim($classification->code), XRechnung30Cvd::VEHICLE_CATEGORIES, true)) {
                            $report->error('BT-158', "line {$line->id}.item.classifications[{$index}].code", ['value' => $classification->code, 'line' => $line->id]);
                        }
                    }
                }
            }),
            $r('BR-DE-CVD-05', 'The value ":value" (BT-161) of the item attribute cva must be clean, zero-emission or other. Line :line.', function (InvoiceData $i, RuleReporter $report): void {
                if (! self::isCvd($report)) {
                    return;
                }
                foreach ($i->lines as $line) {
                    foreach (self::cvaAttributes($line) as $index => $attribute) {
                        if (! in_array(trim($attribute->value), XRechnung30Cvd::ATTRIBUTE_VALUES, true)) {
                            $report->error('BT-161', "line {$line->id}.item.attributes[{$index}].value", ['value' => $attribute->value, 'line' => $line->id]);
                        }
                    }
                }
            }),
            $r('BR-DE-CVD-06-a', 'An invoice line with an item classification identifier (BT-158) with the scheme identifier CVD must contain exactly one item attribute named cva (BT-160). Line :line.', function (InvoiceData $i, RuleReporter $report): void {
                if (! self::isCvd($report)) {
                    return;
                }
                foreach ($i->lines as $line) {
                    if (self::cvdClassifications($line) !== [] && count(self::cvaAttributes($line)) !== 1) {
                        $report->error('BT-160', "line {$line->id}.item.attributes", ['line' => $line->id]);
                    }
                }
            }),
            $r('BR-DE-CVD-06-b', 'An invoice line with an item attribute named cva (BT-160) must contain exactly one item classification identifier (BT-158) with the scheme identifier CVD. Line :line.', function (InvoiceData $i, RuleReporter $report): void {
                if (! self::isCvd($report)) {
                    return;
                }
                foreach ($i->lines as $line) {
                    if (self::cvaAttributes($line) !== [] && count(self::cvdClassifications($line)) !== 1) {
                        $report->error('BT-158', "line {$line->id}.item.classifications", ['line' => $line->id]);
                    }
                }
            }),
        ];
    }

    /**
     * Rules of the XRechnung extension (BR-DEX-…), only for the profile xrechnung-extension.
     * BR-DEX-01 (MIME code), BR-DEX-03 (one VAT information per sub line) and BR-DEX-11 (third
     * party payment amount) cannot be violated: MimeType has only allowed codes, every line has
     * a LineVat and every ThirdPartyPayment a Money amount, which the UBL writer always writes.
     *
     * @param  Closure(string, string, Closure): Rule  $r
     * @return list<Rule>
     */
    private static function extensionRules(Closure $r): array
    {
        return [
            $r('BR-DEX-02', 'The net amount of line :line (BT-131) should equal the sum of the net amounts of its direct sub lines (:expected).', function (InvoiceData $i, RuleReporter $report): void {
                if (! self::isExtension($report, Syntax::Ubl)) {
                    return;
                }
                foreach ($i->allLines() as $line) {
                    if ($line->subLines === [] || $line->netAmount === null) {
                        continue;
                    }
                    $sum = BigDecimal::zero();
                    foreach ($line->subLines as $sub) {
                        $sum = $sum->plus($sub->netAmount?->getAmount() ?? BigDecimal::zero());
                    }
                    if (! $line->netAmount->getAmount()->isEqualTo($sum)) {
                        $report->warning('BT-131', "line {$line->id}.netAmount", ['line' => $line->id, 'expected' => $sum->toScale(2)->toString()]);
                    }
                }
            }),
            $r('BR-DEX-04', 'The party identifier scheme ":value" is not part of ISO/IEC 6523 or the schemes XR01 to XR03 of the XRechnung extension (SEPA only for seller and payee in UBL).', function (InvoiceData $i, RuleReporter $report): void {
                $schemes = ['buyer.identifier.scheme' => ['BT-46-1', $i->buyer->identifier?->scheme, false]];
                foreach ($i->seller->identifiers as $index => $identifier) {
                    $schemes["seller.identifiers[{$index}].scheme"] = ['BT-29-1', $identifier->scheme, true];
                }
                $schemes['payee.identifier.scheme'] = ['BT-60-1', $i->payee?->identifier?->scheme, true];
                self::checkSchemes($report, CodeList::ICD, $schemes);
            }),
            $r('BR-DEX-05', 'The legal registration identifier scheme ":value" is not part of ISO/IEC 6523 or the schemes XR01 to XR03 of the XRechnung extension.', function (InvoiceData $i, RuleReporter $report): void {
                self::checkSchemes($report, CodeList::ICD, [
                    'seller.legalRegistration.scheme' => ['BT-30-1', $i->seller->legalRegistration?->scheme, false],
                    'buyer.legalRegistration.scheme' => ['BT-47-1', $i->buyer->legalRegistration?->scheme, false],
                    'payee.legalRegistration.scheme' => ['BT-61-1', $i->payee?->legalRegistration?->scheme, false],
                ]);
            }),
            $r('BR-DEX-06', 'The item standard identifier scheme ":value" is not part of ISO/IEC 6523 or the schemes XR01 to XR03 of the XRechnung extension.', function (InvoiceData $i, RuleReporter $report): void {
                $schemes = [];
                foreach ($i->allLines() as $line) {
                    $schemes["line {$line->id}.item.standardId.scheme"] = ['BT-157-1', $line->item->standardId?->scheme, false];
                }
                self::checkSchemes($report, CodeList::ICD, $schemes);
            }),
            $r('BR-DEX-07', 'The electronic address scheme ":value" is not part of the EAS code list or the schemes XR01 to XR03 of the XRechnung extension.', function (InvoiceData $i, RuleReporter $report): void {
                self::checkSchemes($report, CodeList::EAS, [
                    'seller.electronicAddress.scheme' => ['BT-34-1', $i->seller->electronicAddress?->scheme, false],
                    'buyer.electronicAddress.scheme' => ['BT-49-1', $i->buyer->electronicAddress?->scheme, false],
                ]);
            }),
            $r('BR-DEX-08', 'The delivery location identifier scheme ":value" is not part of ISO/IEC 6523 or the schemes XR01 to XR03 of the XRechnung extension.', function (InvoiceData $i, RuleReporter $report): void {
                self::checkSchemes($report, CodeList::ICD, ['delivery.locationId.scheme' => ['BT-71-1', $i->delivery?->locationId?->scheme, false]]);
            }),
            $r('BR-DEX-09', 'Amount due for payment (BT-115) = invoice total amount with VAT (BT-112) − paid amount (BT-113) + rounding amount (BT-114) + sum of the third party payment amounts (BT-DEX-002).', function (InvoiceData $i, RuleReporter $report): void {
                $t = $i->totals;
                if ($t === null || ! self::isExtension($report, Syntax::Ubl)) {
                    return;
                }
                $expected = $t->grossTotal->getAmount()
                    ->minus($t->prepaidAmount?->getAmount() ?? BigDecimal::zero())
                    ->plus($t->roundingAmount?->getAmount() ?? BigDecimal::zero());
                foreach ($i->xrechnungExtension->thirdPartyPayments ?? [] as $payment) {
                    $expected = $expected->plus($payment->amount->getAmount());
                }
                if (! $t->amountDue->getAmount()->isEqualTo($expected)) {
                    $report->error('BT-115', 'totals.amountDue');
                }
            }),
            $r('BR-DEX-10', 'The third party payment type (BT-DEX-001) must be provided for every third party payment (BG-DEX-09).', function (InvoiceData $i, RuleReporter $report): void {
                foreach (self::thirdPartyPayments($i, $report) as $index => $payment) {
                    if (! Inspect::filled($payment->type)) {
                        $report->error('BT-DEX-001', "xrechnungExtension.thirdPartyPayments[{$index}].type");
                    }
                }
            }),
            $r('BR-DEX-12', 'The third party payment description (BT-DEX-003) must be provided for every third party payment (BG-DEX-09).', function (InvoiceData $i, RuleReporter $report): void {
                foreach (self::thirdPartyPayments($i, $report) as $index => $payment) {
                    if (! Inspect::filled($payment->description)) {
                        $report->error('BT-DEX-003', "xrechnungExtension.thirdPartyPayments[{$index}].description");
                    }
                }
            }),
            $r('BR-DEX-13', 'The third party payment amount (BT-DEX-002) may have at most two decimals, :value given.', function (InvoiceData $i, RuleReporter $report): void {
                foreach (self::thirdPartyPayments($i, $report) as $index => $payment) {
                    $amount = $payment->amount->getAmount();
                    if ($amount->strippedOfTrailingZeros()->getScale() > 2) {
                        $report->error('BT-DEX-002', "xrechnungExtension.thirdPartyPayments[{$index}].amount", ['value' => $amount->toString()]);
                    }
                }
            }),
            $r('BR-DEX-14', 'The third party payment amount (BT-DEX-002) is in :value, but must be in the invoice currency (BT-5) :expected.', function (InvoiceData $i, RuleReporter $report): void {
                foreach (self::thirdPartyPayments($i, $report) as $index => $payment) {
                    $currency = $payment->amount->getCurrency()->getCurrencyCode();
                    if ($currency !== $i->currency) {
                        $report->error('BT-DEX-002', "xrechnungExtension.thirdPartyPayments[{$index}].amount", ['value' => $currency, 'expected' => $i->currency]);
                    }
                }
            }),
        ];
    }

    /**
     * Whether the profile is the XRechnung extension and, with $syntax, the XML is written in
     * that syntax (an unknown syntax counts as any).
     */
    private static function isExtension(RuleReporter $report, ?Syntax $syntax = null): bool
    {
        $context = $report->context();
        $written = $context->option('syntax');

        return $context->profileIs('xrechnung-extension') && ($syntax === null || ! $written instanceof Syntax || $written === $syntax);
    }

    /**
     * The third party payments the official rules check: only UBL invoices of the extension
     * carry them (otherwise QUITTIO-THIRD-PARTY-PAYMENTS).
     *
     * @return array<int, ThirdPartyPayment>
     */
    private static function thirdPartyPayments(InvoiceData $invoice, RuleReporter $report): array
    {
        return self::isExtension($report, Syntax::Ubl) ? ($invoice->xrechnungExtension->thirdPartyPayments ?? []) : [];
    }

    /**
     * Reports schemes that are neither in the official code list nor additional codes of the
     * extension (XR01 to XR03); SEPA is accepted where allowed, except in CII.
     *
     * @param  array<string, array{0: string, 1: BackedEnum|RawCode|null, 2: bool}>  $schemes  path => [business term, scheme, SEPA allowed]
     */
    private static function checkSchemes(RuleReporter $report, string $list, array $schemes): void
    {
        if (! self::isExtension($report)) {
            return;
        }
        $context = $report->context();
        $additional = $context->profile instanceof ExtendsCodeLists ? $context->profile->additionalCodes($list) : [];
        $sepa = $context->option('syntax') !== Syntax::Cii;
        foreach ($schemes as $path => [$bt, $scheme, $sepaAllowed]) {
            $value = $scheme === null ? '' : (string) $scheme->value;
            if ($value === '' || CodeList::contains($list, $value) || in_array($value, $additional, true) || ($sepa && $sepaAllowed && $value === 'SEPA')) {
                continue;
            }
            $report->error($bt, $path, ['value' => $value]);
        }
    }

    /**
     * The CVD rules apply only with the specification identifier of XRechnung CVD.
     */
    private static function isCvd(RuleReporter $report): bool
    {
        return $report->context()->profile->specificationId() === XRechnung30Cvd::SPECIFICATION_ID;
    }

    /**
     * Item classifications of a line with the scheme identifier CVD (BT-158-1).
     *
     * @return array<int, Classification>
     */
    private static function cvdClassifications(InvoiceLine $line): array
    {
        return array_filter($line->item->classifications, static fn (Classification $c): bool => $c->listId->value === XRechnung30Cvd::CLASSIFICATION_SCHEME);
    }

    /**
     * Item attributes of a line named cva (BT-160).
     *
     * @return array<int, ItemAttribute>
     */
    private static function cvaAttributes(InvoiceLine $line): array
    {
        return array_filter($line->item->attributes, static fn (ItemAttribute $a): bool => $a->name === XRechnung30Cvd::ATTRIBUTE);
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
