<?php

declare(strict_types=1);

namespace Quittio\Country\FR\Rules;

use Closure;
use Quittio\Core\Countries\VatTerritory;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Data\Lines\InvoiceLine;
use Quittio\Core\Data\Meta;
use Quittio\Core\Data\Party\Address;
use Quittio\Core\Enums\InvoiceTypeCode;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Support\Date;
use Quittio\Core\Validation\Rule;
use Quittio\Core\Validation\Rules\CallbackRule;
use Quittio\Core\Validation\Rules\Inspect;
use Quittio\Core\Validation\Rules\RuleReporter;
use Quittio\Country\FR\CountryFR;
use Quittio\Country\FR\Support\Siren;
use Quittio\Country\FR\Tax\VatPreset;

/**
 * Mentions of a French invoice: art. 242 nonies A of annex II to the CGI (with the mentions of the
 * e-invoicing reform from 1 September 2026) and art. L441-9 of the Code de commerce for invoices
 * between businesses.
 *
 * These rules apply to every invoice of a French seller, independent of the format. Not reviewed
 * by a tax adviser yet (see the README of the pack).
 */
final class FrenchRules
{
    private const string NS = CountryFR::TRANSLATION_NAMESPACE;

    /** The new mentions of the e-invoicing reform apply from this date. */
    public const string REFORM_DATE = '2026-09-01';

    /** Codes of the operation category (cadre de facturation, BT-23) of the French specifications. */
    public const array OPERATION_CATEGORIES = ['B1', 'S1', 'M1', 'B2', 'S2', 'M2', 'S3', 'B4', 'S4', 'M4', 'S5', 'S6', 'B7', 'S7'];

    /**
     * @return list<Rule>
     */
    public static function all(): array
    {
        CountryFR::registerTranslations();
        $r = static fn (string $code, string $fallback, Closure $check): Rule => CallbackRule::l3($code, $fallback, $check, self::NS);

        return [
            $r('FR-CGI-01', 'The seller\'s SIREN or SIRET (BT-30, scheme 0002 or 0009) must be stated (art. 242 nonies A annex II CGI, art. R123-237 Code de commerce).', function (InvoiceData $i, RuleReporter $report): void {
                $registration = $i->seller->legalRegistration?->value;
                if ($registration === null || (! Siren::isSiren($registration) && ! Siren::isSiret($registration))) {
                    $report->error('BT-30', 'seller.legalRegistration');
                }
            }),
            $r('FR-CGI-02', 'The seller\'s VAT number (BT-31) must be stated (art. 242 nonies A annex II CGI); only small businesses under the franchise do without.', function (InvoiceData $i, RuleReporter $report): void {
                if (! self::isSmallBusinessInvoice($i) && ! Inspect::filled($i->seller->vatId) && ! Inspect::filled($i->sellerTaxRepresentative?->vatId)) {
                    $report->error('BT-31', 'seller.vatId');
                }
            }),
            $r('FR-CGI-03', 'The name and address of the seller and of the buyer must be complete: street, post code and city (art. 242 nonies A annex II CGI).', function (InvoiceData $i, RuleReporter $report): void {
                foreach ([['seller', $i->seller->address, ['BT-35', 'BT-38', 'BT-37']], ['buyer', $i->buyer->address, ['BT-50', 'BT-53', 'BT-52']]] as [$party, $address, $terms]) {
                    foreach ([[$address->line1, 'line1', $terms[0]], [$address->postcode, 'postcode', $terms[1]], [$address->city, 'city', $terms[2]]] as [$value, $field, $term]) {
                        if (! Inspect::filled($value)) {
                            $report->error($term, $party.'.address.'.$field);
                        }
                    }
                }
            }),
            $r('FR-CGI-04', 'A reverse charge invoice must state "Autoliquidation" (art. 242 nonies A annex II CGI).', function (InvoiceData $i, RuleReporter $report): void {
                foreach ($i->vatBreakdown as $index => $row) {
                    if ($row->category === VatCategory::ReverseCharge && ! str_contains(mb_strtolower((string) $row->exemptionReason), 'autoliquidation')) {
                        $report->error('BT-120', 'vatBreakdown['.$index.'].exemptionReason');
                    }
                }
            }),
            $r('FR-CGI-05', 'An exemption needs the reference to the provision as text (BT-120); a code alone is not enough (art. 242 nonies A annex II CGI).', function (InvoiceData $i, RuleReporter $report): void {
                foreach ($i->vatBreakdown as $index => $row) {
                    if (in_array($row->category, [VatCategory::Exempt, VatCategory::IntraCommunitySupply, VatCategory::Export], true) && ! Inspect::filled($row->exemptionReason)) {
                        $report->error('BT-120', 'vatBreakdown['.$index.'].exemptionReason');
                    }
                }
            }),
            $r('FR-CGI-06', 'A small business under the franchise (art. 293 B CGI) must not charge VAT; VAT shown is owed by reason of the invoice (art. 283-3 CGI).', function (InvoiceData $i, RuleReporter $report): void {
                if (! self::isSmallBusinessInvoice($i)) {
                    return;
                }
                foreach ($i->vatBreakdown as $index => $row) {
                    if (! Inspect::isZero($row->taxAmount->getAmount())) {
                        $report->error('BT-117', 'vatBreakdown['.$index.'].taxAmount');
                    }
                }
            }),
            $r('FR-CGI-07', 'A self-billed invoice must state "Autofacturation" (art. 242 nonies A annex II CGI).', function (InvoiceData $i, RuleReporter $report): void {
                if (! $i->typeCode instanceof InvoiceTypeCode || ! $i->typeCode->isSelfBilled()) {
                    return;
                }
                foreach ($i->notes as $note) {
                    if (str_contains(mb_strtolower($note->content), 'autofacturation') || str_contains(mb_strtolower($note->content), 'self-billing')) {
                        return;
                    }
                }
                if ($report->context()->option('syntax') !== null) {
                    $report->warning('BT-22', 'notes');

                    return;
                }
                $report->error('BT-22', 'notes');
            }),
            $r('FR-CGI-08', 'From 1 September 2026, an invoice to a business established in France states the buyer\'s SIREN (BT-47, scheme 0002) (art. 242 nonies A annex II CGI).', function (InvoiceData $i, RuleReporter $report): void {
                if (! self::afterReform($i) || $i->buyer->isConsumer || ! self::inFrance($i->buyer->address)) {
                    return;
                }
                $registration = $i->buyer->legalRegistration?->value;
                if ($registration === null || Siren::of($registration) === null) {
                    $report->warning('BT-47', 'buyer.legalRegistration');
                }
            }),
            $r('FR-CGI-09', 'From 1 September 2026, the invoice states the category of the operation: supply of goods, services or both (art. 242 nonies A annex II CGI), as operation category (BT-23) B1, S1 or M1.', function (InvoiceData $i, RuleReporter $report): void {
                if (! self::afterReform($i) || $i->buyer->isConsumer) {
                    return;
                }
                if (! in_array(strtoupper(trim((string) $i->businessProcess)), self::OPERATION_CATEGORIES, true)) {
                    $report->warning('BT-23', 'businessProcess');
                }
            }),
            $r('FR-COM-01', 'An invoice between businesses must state the due date (BT-9) or the payment terms (BT-20) (art. L441-9 Code de commerce).', function (InvoiceData $i, RuleReporter $report): void {
                if (! $i->buyer->isConsumer && ! $i->isCreditNote() && $i->dueDate === null && ! Inspect::filled($i->paymentTerms?->text)) {
                    $report->error('BT-9', 'dueDate');
                }
            }),
            $r('FR-COM-02', 'An invoice between businesses must state the late payment penalties, the fixed indemnity of 40 EUR for recovery costs and the conditions of a discount for early payment (art. L441-9 Code de commerce).', function (InvoiceData $i, RuleReporter $report): void {
                if ($i->buyer->isConsumer || $i->isCreditNote()) {
                    return;
                }
                $texts = mb_strtolower(implode("\n", [...array_map(static fn ($note): string => $note->content, $i->notes), (string) $i->paymentTerms?->text]));
                if (! str_contains($texts, 'pénalit') && ! str_contains($texts, 'penalt')) {
                    $report->warning('BT-22', 'notes');
                }
            }),
        ];
    }

    /**
     * An invoice under the franchise: the flag of the core or a line with VatPreset::SmallBusiness.
     */
    public static function isSmallBusinessInvoice(InvoiceData $invoice): bool
    {
        return $invoice->meta->hasFlag(Meta::FLAG_SMALL_BUSINESS)
            || array_filter($invoice->allLines(), static fn (InvoiceLine $l): bool => $l->vat->presetKey === VatPreset::SmallBusiness->value) !== [];
    }

    /**
     * An address in the French VAT territory (with Monaco, without the overseas departments).
     */
    public static function inFrance(Address $address): bool
    {
        return VatTerritory::memberState($address->countryCode, $address->postcode) === CountryFR::CODE;
    }

    private static function afterReform(InvoiceData $invoice): bool
    {
        return ! $invoice->issueDate->isBefore(Date::parse(self::REFORM_DATE));
    }
}
