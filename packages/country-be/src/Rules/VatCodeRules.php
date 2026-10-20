<?php

declare(strict_types=1);

namespace Quittio\Country\BE\Rules;

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
use Quittio\Country\BE\CountryBE;
use Quittio\Country\BE\Support\LanguageRegion;
use Quittio\Country\BE\Tax\VatPreset;

/**
 * Mentions of a Belgian invoice: Art. 53 § 2 of the VAT Code and Art. 5 of Royal Decree No. 1,
 * and the use of languages in the Dutch, French and German language regions.
 *
 * These rules apply to every invoice of a Belgian seller, independent of the format. Not
 * reviewed by a tax adviser yet (see the README of the pack).
 */
final class VatCodeRules
{
    private const string NS = CountryBE::TRANSLATION_NAMESPACE;

    /** Words of a statement that the customer owes the VAT. */
    private const array REVERSE_CHARGE_STATEMENTS = ['verlegging', 'autoliquidation', 'steuerschuld', 'reverse charge', 'reverse-charge'];

    /** "Self-billing" in the languages of Belgium and English. */
    private const array SELF_BILLING_LABELS = ['self-billing', 'selfbilling', 'self billing', 'autofacturation', 'zelffacturering', 'gutschrift', 'factuur uitgereikt door de afnemer', 'facture émise par le preneur'];

    /**
     * @return list<Rule>
     */
    public static function all(): array
    {
        CountryBE::registerTranslations();
        $r = static fn (string $code, string $fallback, Closure $check): Rule => CallbackRule::l3($code, $fallback, $check, self::NS);

        return [
            $r('BE-VAT-01', 'The seller\'s VAT number (BT-31) must be stated (Art. 5 § 1, 3° Royal Decree No. 1).', function (InvoiceData $i, RuleReporter $report): void {
                if (! Inspect::filled($i->seller->vatId) && ! Inspect::filled($i->sellerTaxRepresentative?->vatId)) {
                    $report->error('BT-31', 'seller.vatId');
                }
            }),
            $r('BE-VAT-02', 'The name and address of the seller and of the buyer must be complete: street, post code and city (Art. 5 § 1, 5° Royal Decree No. 1).', function (InvoiceData $i, RuleReporter $report): void {
                self::reportMissingAddress($i->seller->address, 'seller', ['BT-35', 'BT-38', 'BT-37'], $report);
                self::reportMissingAddress($i->buyer->address, 'buyer', ['BT-50', 'BT-53', 'BT-52'], $report);
            }),
            $r('BE-VAT-03', 'An invoice to a business in Belgium should state the buyer\'s VAT number (BT-48) if the buyer files VAT returns (Art. 5 § 1, 4° Royal Decree No. 1).', function (InvoiceData $i, RuleReporter $report): void {
                if (! $i->buyer->isConsumer && self::inBelgium($i->buyer->address) && ! Inspect::filled($i->buyer->vatId)) {
                    $report->warning('BT-48', 'buyer.vatId');
                }
            }),
            $r('BE-VAT-04', 'A reverse charge invoice must state "Verlegging van heffing" or "Autoliquidation" (Art. 5 § 1, 10° Royal Decree No. 1).', function (InvoiceData $i, RuleReporter $report): void {
                $accepted = null;
                foreach ($i->vatBreakdown as $index => $row) {
                    if ($row->category !== VatCategory::ReverseCharge) {
                        continue;
                    }
                    $accepted ??= self::reverseChargeStatements();
                    if (! self::containsAny((string) $row->exemptionReason, $accepted)) {
                        $report->error('BT-120', 'vatBreakdown['.$index.'].exemptionReason');
                    }
                }
            }),
            $r('BE-VAT-05', 'An exemption needs the reference to the provision as text (BT-120); a code alone is not enough (Art. 5 § 1, 10° Royal Decree No. 1).', function (InvoiceData $i, RuleReporter $report): void {
                foreach ($i->vatBreakdown as $index => $row) {
                    if (in_array($row->category, [VatCategory::Exempt, VatCategory::IntraCommunitySupply, VatCategory::Export], true) && ! Inspect::filled($row->exemptionReason)) {
                        $report->error('BT-120', 'vatBreakdown['.$index.'].exemptionReason');
                    }
                }
            }),
            $r('BE-VAT-06', 'A small business under the exemption scheme (Art. 56bis VAT Code) must not charge VAT.', function (InvoiceData $i, RuleReporter $report): void {
                if (! self::isSmallBusinessInvoice($i)) {
                    return;
                }
                foreach ($i->vatBreakdown as $index => $row) {
                    if (! Inspect::isZero($row->taxAmount->getAmount())) {
                        $report->error('BT-117', 'vatBreakdown['.$index.'].taxAmount');
                    }
                }
            }),
            $r('BE-VAT-07', 'A self-billed invoice must state "self-billing", "autofacturation" or "zelffacturering" (Art. 5 § 1, 12° Royal Decree No. 1).', function (InvoiceData $i, RuleReporter $report): void {
                if (! $i->typeCode instanceof InvoiceTypeCode || ! $i->typeCode->isSelfBilled()) {
                    return;
                }
                foreach ($i->notes as $note) {
                    if (self::containsAny($note->content, self::SELF_BILLING_LABELS)) {
                        return;
                    }
                }
                // In an e-invoice the type code labels it; a document without XML needs the words.
                if ($report->context()->option('syntax') !== null) {
                    $report->warning('BT-22', 'notes');

                    return;
                }
                $report->error('BT-22', 'notes');
            }),
            $r('BE-VAT-08', 'The invoice must be issued by the 15th day of the month following the supply (Art. 53 § 2 VAT Code); the deadline was :deadline.', function (InvoiceData $i, RuleReporter $report): void {
                if ($i->buyer->isConsumer || in_array($i->typeCode, [InvoiceTypeCode::CreditNote, InvoiceTypeCode::CorrectedInvoice, InvoiceTypeCode::PrepaymentInvoice], true)) {
                    return;
                }
                $supply = self::supplyDate($i);
                if ($supply === null) {
                    return;
                }
                $deadline = $supply->month === 12 ? Date::of($supply->year + 1, 1, 15) : Date::of($supply->year, $supply->month + 1, 15);
                // Late invoices stay valid: only a warning.
                if ($i->issueDate->isAfter($deadline)) {
                    $report->warning('BT-2', 'issueDate', ['deadline' => $deadline->toIsoString()]);
                }
            }),
            $r('BE-LANG-01', 'A business in the Dutch, French or German language region should issue its invoices to Belgian customers in the language of the region (:language; language decrees of the Flemish and French Communities, for the German language region Art. 52 of the laws on the use of languages in administrative matters of 18 July 1966); the invoice is in :locale.', function (InvoiceData $i, RuleReporter $report): void {
                if (! self::inBelgium($i->seller->address) || ! self::inBelgium($i->buyer->address)) {
                    return;
                }
                $region = LanguageRegion::of($i->seller->address->postcode);
                if (! in_array($region, [LanguageRegion::DUTCH, LanguageRegion::FRENCH, LanguageRegion::GERMAN], true)) {
                    return;
                }
                $locale = $i->meta->locale ?? $report->context()->locale;
                $language = strtolower(explode('_', str_replace('-', '_', $locale))[0]);
                if ($language !== $region) {
                    $report->warning(null, 'meta.locale', ['language' => $region, 'locale' => $locale]);
                }
            }),
        ];
    }

    /**
     * An invoice under the small business exemption scheme: the flag of the core or a line with
     * VatPreset::SmallBusiness.
     */
    public static function isSmallBusinessInvoice(InvoiceData $invoice): bool
    {
        return $invoice->meta->hasFlag(Meta::FLAG_SMALL_BUSINESS)
            || array_filter($invoice->allLines(), static fn (InvoiceLine $l): bool => $l->vat->presetKey === VatPreset::SmallBusiness->value) !== [];
    }

    /**
     * The latest date of the supply: delivery date, end of the invoicing period or of a line period.
     */
    public static function supplyDate(InvoiceData $invoice): ?Date
    {
        $dates = [$invoice->delivery?->actualDeliveryDate, $invoice->invoicingPeriod->end ?? $invoice->invoicingPeriod?->start];
        foreach ($invoice->allLines() as $line) {
            $dates[] = $line->period->end ?? $line->period?->start;
        }
        $supply = null;
        foreach ($dates as $date) {
            if ($date !== null && ($supply === null || $date->isAfter($supply))) {
                $supply = $date;
            }
        }

        return $supply;
    }

    public static function inBelgium(Address $address): bool
    {
        return VatTerritory::memberState($address->countryCode, $address->postcode) === CountryBE::CODE;
    }

    /**
     * @param  array{0: string, 1: string, 2: string}  $terms  business terms of street, post code and city
     */
    private static function reportMissingAddress(Address $address, string $party, array $terms, RuleReporter $report): void
    {
        foreach ([[$address->line1, 'line1', $terms[0]], [$address->postcode, 'postcode', $terms[1]], [$address->city, 'city', $terms[2]]] as [$value, $field, $term]) {
            if (! Inspect::filled($value)) {
                $report->error($term, $party.'.address.'.$field);
            }
        }
    }

    /**
     * @return list<string>
     */
    private static function reverseChargeStatements(): array
    {
        $statements = self::REVERSE_CHARGE_STATEMENTS;
        foreach ([VatPreset::ReverseChargeDomestic, VatPreset::ReverseChargeEu] as $preset) {
            foreach (CountryBE::locales() as $locale) {
                $statements[] = (string) $preset->exemptionReason($locale);
            }
        }

        return array_values(array_unique(array_filter($statements, static fn (string $s): bool => $s !== '')));
    }

    /**
     * @param  list<string>  $needles
     */
    private static function containsAny(string $haystack, array $needles): bool
    {
        $haystack = mb_strtolower($haystack);
        foreach ($needles as $needle) {
            if (str_contains($haystack, mb_strtolower($needle))) {
                return true;
            }
        }

        return false;
    }
}
