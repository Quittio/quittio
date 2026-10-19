<?php

declare(strict_types=1);

namespace Quittio\Country\AT\Rules;

use Closure;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Data\Party\Address;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Validation\Rule;
use Quittio\Core\Validation\Rules\CallbackRule;
use Quittio\Core\Validation\Rules\Inspect;
use Quittio\Core\Validation\Rules\RuleReporter;
use Quittio\Country\AT\CountryAT;

/**
 * Rules of the Austrian CIUS of EN 16931: CIUS-AT-NAT 1.0.0 (statutory rules, AT-NAT-…) and
 * CIUS-AT-GOV 1.1.0 (rules of e-Rechnung.gv.at for the federal government, AT-GOV-…), as
 * published by the BRZ for the BMF (e-rechnung.gv.at/go/cius_at_nat, /go/cius_at_gov).
 *
 * The CIUS documents state no severity; every rule is an error. Where EN 16931 forbids a value
 * that the CIUS makes mandatory (the VAT rate of category O, BR-O-05 to BR-O-07), EN 16931 wins.
 */
final class CiusAtRules
{
    private const string NS = CountryAT::TRANSLATION_NAMESPACE;

    /** Payment means codes e-Rechnung.gv.at accepts (BT-81). */
    public const array GOV_PAYMENT_MEANS = ['30', '31', '42', '49', '58', '59'];

    /** Mime codes of attachments e-Rechnung.gv.at accepts (BT-125). */
    public const array GOV_MIME_CODES = [
        'application/pdf', 'image/png', 'text/xml', 'application/xml', 'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    /** File name extensions of attachments e-Rechnung.gv.at accepts (BT-125). */
    public const array GOV_EXTENSIONS = ['pdf', 'png', 'xml', 'xls', 'xlsx'];

    /** Most attachments e-Rechnung.gv.at accepts (BG-24). */
    public const int GOV_MAX_ATTACHMENTS = 200;

    /** Largest size of all attachments together in bytes (15 MB). */
    public const int GOV_MAX_ATTACHMENT_BYTES = 15 * 1024 * 1024;

    /** Most invoice lines e-Rechnung.gv.at accepts (BG-25). */
    public const int GOV_MAX_LINES = 999;

    /** Largest amount due (BT-115). */
    public const string GOV_MAX_AMOUNT_DUE = '999999999.99';

    /** Largest line net amount, positive or negative (BT-131). */
    public const string GOV_MAX_LINE_AMOUNT = '999999999999.99';

    /**
     * The rules of CIUS-AT-NAT.
     *
     * @return list<Rule>
     */
    public static function national(): array
    {
        CountryAT::registerTranslations();
        $r = static fn (string $code, string $fallback, Closure $check): Rule => CallbackRule::l3($code, $fallback, $check, self::NS);

        return [
            $r('AT-NAT-01', 'The address line 1 of every postal address must be provided (BT-35, BT-50, BT-64, BT-75).', function (InvoiceData $i, RuleReporter $report): void {
                foreach (self::addresses($i) as [$path, $address, $terms]) {
                    if (! Inspect::filled($address->line1)) {
                        $report->error($terms[0], $path.'.line1');
                    }
                }
            }),
            $r('AT-NAT-02', 'The city of every postal address must be provided (BT-37, BT-52, BT-66, BT-77).', function (InvoiceData $i, RuleReporter $report): void {
                foreach (self::addresses($i) as [$path, $address, $terms]) {
                    if (! Inspect::filled($address->city)) {
                        $report->error($terms[1], $path.'.city');
                    }
                }
            }),
            $r('AT-NAT-03', 'The post code of every postal address must be provided (BT-38, BT-53, BT-67, BT-78).', function (InvoiceData $i, RuleReporter $report): void {
                foreach (self::addresses($i) as [$path, $address, $terms]) {
                    if (! Inspect::filled($address->postcode)) {
                        $report->error($terms[2], $path.'.postcode');
                    }
                }
            }),
            $r('AT-NAT-04', 'The actual delivery date (BT-72) or the invoicing period (BG-14) must be provided.', function (InvoiceData $i, RuleReporter $report): void {
                if ($i->delivery?->actualDeliveryDate === null && $i->invoicingPeriod === null) {
                    $report->error('BT-72', 'delivery.actualDeliveryDate');
                }
            }),
            $r('AT-NAT-05', 'The VAT rate of a document level allowance (BT-96) must be provided.', function (InvoiceData $i, RuleReporter $report): void {
                foreach ($i->allowances as $index => $allowance) {
                    if ($allowance->vatRate === null && $allowance->vatCategory !== VatCategory::NotSubjectToVat) {
                        $report->error('BT-96', 'allowances['.$index.'].vatRate');
                    }
                }
            }),
            $r('AT-NAT-06', 'The VAT rate of a document level charge (BT-103) must be provided.', function (InvoiceData $i, RuleReporter $report): void {
                foreach ($i->charges as $index => $charge) {
                    if ($charge->vatRate === null && $charge->vatCategory !== VatCategory::NotSubjectToVat) {
                        $report->error('BT-103', 'charges['.$index.'].vatRate');
                    }
                }
            }),
            $r('AT-NAT-07', 'An external document location (BT-124) is not supported; embed the attachment instead.', function (InvoiceData $i, RuleReporter $report): void {
                foreach ($i->attachments as $index => $attachment) {
                    if (Inspect::filled($attachment->externalUri)) {
                        $report->error('BT-124', 'attachments['.$index.'].externalUri');
                    }
                }
            }),
            $r('AT-NAT-08', 'The VAT rate of every invoice line (BT-152) must be provided.', function (InvoiceData $i, RuleReporter $report): void {
                foreach ($i->lines as $index => $line) {
                    if ($line->vat->rate === null && $line->vat->category !== VatCategory::NotSubjectToVat) {
                        $report->error('BT-152', 'lines['.$index.'].vat.rate');
                    }
                }
            }),
        ];
    }

    /**
     * The rules of CIUS-AT-GOV, which adds them to those of CIUS-AT-NAT.
     *
     * @return list<Rule>
     */
    public static function government(): array
    {
        CountryAT::registerTranslations();
        $r = static fn (string $code, string $fallback, Closure $check): Rule => CallbackRule::l3($code, $fallback, $check, self::NS);

        return [
            $r('AT-GOV-01', 'The buyer reference (BT-10) must be provided: for the federal government the order reference (Auftragsreferenz).', function (InvoiceData $i, RuleReporter $report): void {
                if (! Inspect::filled($i->buyerReference)) {
                    $report->error('BT-10', 'buyerReference');
                }
            }),
            $r('AT-GOV-02', 'The seller contact (BG-6) with an email address (BT-43) must be provided.', function (InvoiceData $i, RuleReporter $report): void {
                if ($i->seller->contact === null) {
                    $report->error('BG-6', 'seller.contact');
                } elseif (! Inspect::filled($i->seller->contact->email)) {
                    $report->error('BT-43', 'seller.contact.email');
                }
            }),
            $r('AT-GOV-03', 'The payment means type code (BT-81) must be one of 30, 31, 42, 49, 58 or 59.', function (InvoiceData $i, RuleReporter $report): void {
                $code = $i->paymentInstructions?->meansCode;
                if ($code === null) {
                    return;
                }
                if (! in_array($code->value, self::GOV_PAYMENT_MEANS, true)) {
                    $report->error('BT-81', 'paymentInstructions.meansCode');
                }
            }),
            $r('AT-GOV-04', 'The amount due for payment (BT-115) must not exceed 999,999,999.99.', function (InvoiceData $i, RuleReporter $report): void {
                $due = $i->totals?->amountDue;
                if ($due !== null && $due->getAmount()->isGreaterThan(self::GOV_MAX_AMOUNT_DUE)) {
                    $report->error('BT-115', 'totals.amountDue');
                }
            }),
            $r('AT-GOV-05', 'At most 200 attachments (BG-24) of together at most 15 MB can be handled.', function (InvoiceData $i, RuleReporter $report): void {
                $bytes = 0;
                foreach ($i->attachments as $attachment) {
                    $bytes += $attachment->size();
                }
                if (count($i->attachments) > self::GOV_MAX_ATTACHMENTS || $bytes > self::GOV_MAX_ATTACHMENT_BYTES) {
                    $report->error('BG-24', 'attachments');
                }
            }),
            $r('AT-GOV-06', 'Attachments (BT-125) must be PDF, PNG, XML or Excel files (mime code and file name extension .pdf, .png, .xml, .xls or .xlsx).', function (InvoiceData $i, RuleReporter $report): void {
                foreach ($i->attachments as $index => $attachment) {
                    if ($attachment->content === null) {
                        continue;
                    }
                    $extension = strtolower(pathinfo((string) $attachment->filename, PATHINFO_EXTENSION));
                    if (! in_array($attachment->mimeType?->value, self::GOV_MIME_CODES, true) || ! in_array($extension, self::GOV_EXTENSIONS, true)) {
                        $report->error('BT-125', 'attachments['.$index.']');
                    }
                }
            }),
            $r('AT-GOV-07', 'An invoice can have at most 999 lines (BG-25).', function (InvoiceData $i, RuleReporter $report): void {
                if (count($i->lines) > self::GOV_MAX_LINES) {
                    $report->error('BG-25', 'lines');
                }
            }),
            $r('AT-GOV-08', 'The net amount of an invoice line (BT-131) must lie between -999,999,999,999.99 and 999,999,999,999.99.', function (InvoiceData $i, RuleReporter $report): void {
                foreach ($i->lines as $index => $line) {
                    if ($line->netAmount !== null && $line->netAmount->getAmount()->abs()->isGreaterThan(self::GOV_MAX_LINE_AMOUNT)) {
                        $report->error('BT-131', 'lines['.$index.'].netAmount');
                    }
                }
            }),
            $r('AT-GOV-09', 'With a ten digit order number of the federal government (BT-13) every line must reference its order line (BT-132).', function (InvoiceData $i, RuleReporter $report): void {
                if (preg_match('/^\d{10}$/', trim((string) $i->purchaseOrderReference)) !== 1) {
                    return;
                }
                foreach ($i->lines as $index => $line) {
                    if (! Inspect::filled($line->orderLineReference)) {
                        $report->error('BT-132', 'lines['.$index.'].orderLineReference');
                    }
                }
            }),
        ];
    }

    /**
     * The postal addresses of the invoice with their path and the business terms of line 1, city
     * and post code: seller, buyer, tax representative and deliver-to address.
     *
     * @return list<array{0: string, 1: Address, 2: array{0: string, 1: string, 2: string}}>
     */
    private static function addresses(InvoiceData $invoice): array
    {
        $addresses = [
            ['seller.address', $invoice->seller->address, ['BT-35', 'BT-37', 'BT-38']],
            ['buyer.address', $invoice->buyer->address, ['BT-50', 'BT-52', 'BT-53']],
        ];
        if ($invoice->sellerTaxRepresentative !== null) {
            $addresses[] = ['sellerTaxRepresentative.address', $invoice->sellerTaxRepresentative->address, ['BT-64', 'BT-66', 'BT-67']];
        }
        if ($invoice->delivery?->address !== null) {
            $addresses[] = ['delivery.address', $invoice->delivery->address, ['BT-75', 'BT-77', 'BT-78']];
        }

        return $addresses;
    }
}
