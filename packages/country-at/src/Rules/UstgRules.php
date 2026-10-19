<?php

declare(strict_types=1);

namespace Quittio\Country\AT\Rules;

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
use Quittio\Country\AT\CountryAT;
use Quittio\Country\AT\Tax\TaxTables;
use Quittio\Country\AT\Tax\VatPreset;

/**
 * Mandatory invoice information according to the Austrian VAT act (section 11 UStG 1994), with
 * the simplified invoice of section 11 (6) UStG up to 400 EUR and for small businesses.
 *
 * These rules apply to every invoice of an Austrian seller, independent of the format. Not
 * reviewed by a tax adviser yet (see the README of the pack).
 */
final class UstgRules
{
    private const string NS = CountryAT::TRANSLATION_NAMESPACE;

    /** "Gutschrift" and its equivalents in other official EU languages. */
    private const array SELF_BILLING_LABELS = [
        'gutschrift', 'self-billing', 'self billing', 'self-billed invoice', 'autofacturation', 'autofactura',
        'autofatturazione', 'autofacturação', 'zelffacturering', 'samofakturowanie',
    ];

    /** Words of a statement that the recipient owes the VAT (section 11 (1a) UStG). */
    private const array REVERSE_CHARGE_STATEMENTS = ['steuerschuld', 'reverse charge', 'reverse-charge', 'autoliquidation', 'inversione contabile'];

    /**
     * @return list<Rule>
     */
    public static function all(): array
    {
        CountryAT::registerTranslations();
        $r = static fn (string $code, string $fallback, Closure $check): Rule => CallbackRule::l3($code, $fallback, $check, self::NS);

        return [
            $r('AT-USTG-01', 'The name and address of the seller must be complete: street (BT-35), post code (BT-38) and city (BT-37) (section 11 (1) no. 3 lit. a UStG).', function (InvoiceData $i, RuleReporter $report): void {
                self::reportMissingAddress($i->seller->address, 'seller', postcodeRequired: true, report: $report);
            }),
            $r('AT-USTG-02', 'The name and address of the buyer must be complete: street (BT-50), post code (BT-53) and city (BT-52) (section 11 (1) no. 3 lit. b UStG); only simplified invoices up to 400 EUR and of small businesses may leave them out (section 11 (6) UStG).', function (InvoiceData $i, RuleReporter $report): void {
                if (! self::isSimplifiedInvoice($i)) {
                    self::reportMissingAddress($i->buyer->address, 'buyer', postcodeRequired: $i->buyer->address->countryCode === CountryAT::CODE, report: $report);
                }
            }),
            $r('AT-USTG-03', 'The seller\'s VAT identifier (UID, BT-31) must be stated (section 11 (1) no. 3 lit. i UStG).', function (InvoiceData $i, RuleReporter $report): void {
                // Only for supplies with the right to deduct input tax: not for invoices that are
                // exempt without that right or not taxable in Austria only.
                if (self::isSimplifiedInvoice($i) || self::onlyWithoutInputTaxDeduction($i)) {
                    return;
                }
                if (! Inspect::filled($i->seller->vatId) && ! Inspect::filled($i->sellerTaxRepresentative?->vatId)) {
                    $report->error('BT-31', 'seller.vatId');
                }
            }),
            $r('AT-USTG-04', 'The date of the supply or service must be stated: delivery date (BT-72), invoicing period (BG-14) or periods on all lines (BG-26) (section 11 (1) no. 3 lit. d UStG).', function (InvoiceData $i, RuleReporter $report): void {
                if (self::hasServiceDate($i)) {
                    return;
                }
                // A prepayment invoice names the date of a supply that is still to come, if it is known (section 11 (1) no. 4 UStG).
                if ($i->typeCode === InvoiceTypeCode::PrepaymentInvoice) {
                    $report->warning('BT-72', 'delivery.actualDeliveryDate');

                    return;
                }
                $report->error('BT-72', 'delivery.actualDeliveryDate');
            }),
            $r('AT-USTG-05', 'Invoices to a business above 10,000 EUR must state the buyer\'s VAT identifier (UID, BT-48) (section 11 (1) no. 3 lit. b UStG).', function (InvoiceData $i, RuleReporter $report): void {
                if (self::needsBuyerVatId($i) && ! Inspect::filled($i->buyer->vatId)) {
                    $report->error('BT-48', 'buyer.vatId');
                }
            }),
            $r('AT-USTG-06', 'Reverse charge invoices must point out that the recipient owes the VAT ("Übergang der Steuerschuld auf den Leistungsempfänger") (section 11 (1a) UStG).', function (InvoiceData $i, RuleReporter $report): void {
                $accepted = null;
                foreach ($i->vatBreakdown as $index => $row) {
                    if ($row->category !== VatCategory::ReverseCharge) {
                        continue;
                    }
                    $accepted ??= self::reverseChargeStatements([$i->meta->locale, $report->context()->locale]);
                    if (! self::containsAny((string) $row->exemptionReason, $accepted)) {
                        $report->error('BT-120', 'vatBreakdown['.$index.'].exemptionReason');
                    }
                }
            }),
            $r('AT-USTG-07', 'A tax exemption needs a note on the exemption as text (BT-120); a code alone is not enough (section 11 (1) no. 3 lit. e UStG).', function (InvoiceData $i, RuleReporter $report): void {
                foreach ($i->vatBreakdown as $index => $row) {
                    if (in_array($row->category, [VatCategory::Exempt, VatCategory::IntraCommunitySupply, VatCategory::Export], true) && ! Inspect::filled($row->exemptionReason)) {
                        $report->error('BT-120', 'vatBreakdown['.$index.'].exemptionReason');
                    }
                }
            }),
            $r('AT-USTG-08', 'A small business that applies the exemption of section 6 (1) no. 27 UStG must not show VAT; VAT shown is owed by reason of the invoice (section 11 (12) UStG).', function (InvoiceData $i, RuleReporter $report): void {
                if (! self::isSmallBusinessInvoice($i)) {
                    return;
                }
                foreach ($i->vatBreakdown as $index => $row) {
                    if (! Inspect::isZero($row->taxAmount->getAmount()) || ($row->category === VatCategory::StandardRate && ! Inspect::isZero($row->rate))) {
                        $report->error('BT-117', 'vatBreakdown['.$index.'].taxAmount');
                    }
                }
            }),
            $r('AT-USTG-09', 'A self-billed invoice must be labelled "Gutschrift" (section 11 (8) no. 3 UStG).', function (InvoiceData $i, RuleReporter $report): void {
                if (! $i->typeCode instanceof InvoiceTypeCode || ! $i->typeCode->isSelfBilled()) {
                    return;
                }
                foreach (self::SELF_BILLING_LABELS as $label) {
                    if (self::noteContains($i, $label)) {
                        return;
                    }
                }
                // In an e-invoice the type code labels it; a document without XML needs the word.
                if ($report->context()->option('syntax') !== null) {
                    $report->warning('BT-22', 'notes');

                    return;
                }
                $report->error('BT-22', 'notes');
            }),
            $r('AT-USTG-10', 'An invoice in another currency than euro must state the VAT amount in euro as well (VAT accounting currency BT-6 "EUR" and BT-111), or how it is converted (section 11 (1) no. 3 lit. f UStG).', function (InvoiceData $i, RuleReporter $report): void {
                if ($i->currency === 'EUR' || self::isSimplifiedInvoice($i)) {
                    return;
                }
                $vat = $i->totals?->vatTotal;
                if ($vat === null || $vat->isZero()) {
                    return;
                }
                // The conversion method can only be stated in a note, which the rule cannot read: a warning.
                if ($i->taxCurrency !== 'EUR' || $i->totals?->vatTotalInTaxCurrency === null) {
                    $report->warning('BT-6', 'taxCurrency');
                }
            }),
            $r('AT-USTG-11', 'The invoice is issued more than six months after the supply (:supply); invoices to businesses and legal persons must be issued within six months (section 11 (1) no. 1 UStG).', function (InvoiceData $i, RuleReporter $report): void {
                if ($i->buyer->isConsumer || self::refersToOriginal($i)) {
                    return;
                }
                $supply = self::supplyDate($i);
                // Late invoices stay valid: only a warning.
                if ($supply !== null && $i->issueDate->isAfter(Date::parse($supply->toCarbon('UTC')->addMonthsNoOverflow(6)))) {
                    $report->warning('BT-2', 'issueDate', ['supply' => $supply->toIsoString()]);
                }
            }),
            $r('AT-USTG-12', 'Invoices for services in another member state for which the recipient owes the VAT must be issued by the 15th day of the month following the service (section 11 (1) no. 2 UStG); the deadline was :deadline.', function (InvoiceData $i, RuleReporter $report): void {
                if (self::refersToOriginal($i) || ! self::hasReverseChargeInOtherMemberState($i)) {
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
        ];
    }

    /**
     * Whether the simplifications of section 11 (6) UStG apply: an invoice in euro of up to 400 EUR
     * gross, or of a small business. Not for reverse charge and intra-community supplies, whose
     * invoices need the VAT identifiers (section 11 (1a) and (6) UStG, Art. 11 UStG).
     */
    public static function isSimplifiedInvoice(InvoiceData $invoice): bool
    {
        if (Inspect::uses($invoice, VatCategory::ReverseCharge) || Inspect::uses($invoice, VatCategory::IntraCommunitySupply)) {
            return false;
        }
        if (self::isSmallBusinessInvoice($invoice)) {
            return true;
        }
        $gross = $invoice->totals?->grossTotal;

        return $gross !== null && $invoice->currency === 'EUR'
            && ! $gross->getAmount()->isGreaterThan(TaxTables::simplifiedInvoiceLimitOn($invoice->issueDate));
    }

    /**
     * An invoice of a small business (section 6 (1) no. 27 UStG): the flag of the core or a line
     * with VatPreset::SmallBusiness.
     */
    public static function isSmallBusinessInvoice(InvoiceData $invoice): bool
    {
        return $invoice->meta->hasFlag(Meta::FLAG_SMALL_BUSINESS)
            || array_filter($invoice->allLines(), static fn (InvoiceLine $l): bool => $l->vat->presetKey === VatPreset::SmallBusiness->value) !== [];
    }

    /**
     * Whether the buyer's VAT identifier is required (section 11 (1) no. 3 lit. b UStG): an invoice
     * in euro above 10,000 EUR gross of a seller in Austria to a business.
     */
    public static function needsBuyerVatId(InvoiceData $invoice): bool
    {
        $gross = $invoice->totals?->grossTotal;

        return ! $invoice->buyer->isConsumer && $invoice->currency === 'EUR' && $gross !== null
            && $gross->getAmount()->isGreaterThan(TaxTables::BUYER_VAT_ID_LIMIT)
            && VatTerritory::memberState($invoice->seller->address->countryCode, $invoice->seller->address->postcode) === CountryAT::CODE
            && ! self::isSimplifiedInvoice($invoice);
    }

    /**
     * Whether the invoice states the date of the supply: a delivery date, an invoicing period or
     * a period on every line.
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
     * The latest date of the supply: delivery date, end of the invoicing period or of a line
     * period; null without any.
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

    private static function reportMissingAddress(Address $address, string $party, bool $postcodeRequired, RuleReporter $report): void
    {
        $terms = $party === 'seller' ? ['line1' => 'BT-35', 'postcode' => 'BT-38', 'city' => 'BT-37'] : ['line1' => 'BT-50', 'postcode' => 'BT-53', 'city' => 'BT-52'];
        $missing = array_filter([
            'line1' => ! Inspect::filled($address->line1),
            'postcode' => $postcodeRequired && ! Inspect::filled($address->postcode),
            'city' => ! Inspect::filled($address->city),
        ]);
        foreach (array_keys($missing) as $field) {
            $report->error($terms[$field], $party.'.address.'.$field);
        }
    }

    /**
     * Only exempt supplies without input tax deduction (category E) and supplies not taxable in
     * Austria (category O).
     */
    private static function onlyWithoutInputTaxDeduction(InvoiceData $invoice): bool
    {
        $lines = $invoice->allLines();

        return $lines !== [] && array_filter($lines, static fn (InvoiceLine $l): bool => ! in_array($l->vat->category, [VatCategory::Exempt, VatCategory::NotSubjectToVat], true)) === [];
    }

    /**
     * Services to a business in another member state that owes the VAT there: lines with
     * VatPreset::ReverseChargeEu, or reverse charge lines without preset to a buyer in another
     * member state.
     */
    private static function hasReverseChargeInOtherMemberState(InvoiceData $invoice): bool
    {
        $state = VatTerritory::memberState($invoice->buyer->address->countryCode, $invoice->buyer->address->postcode, goods: false);
        $buyerInOtherMemberState = $state !== null && $state !== CountryAT::CODE;
        foreach ($invoice->allLines() as $line) {
            if ($line->vat->presetKey === VatPreset::ReverseChargeEu->value
                || ($line->vat->presetKey === null && $line->vat->category === VatCategory::ReverseCharge && $buyerInOtherMemberState)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Credit notes, corrections and prepayment invoices, and invoices that refer to an earlier one
     * without deducting a prepayment: the supply date of the original decides, not theirs.
     */
    private static function refersToOriginal(InvoiceData $invoice): bool
    {
        if (in_array($invoice->typeCode, [InvoiceTypeCode::CreditNote, InvoiceTypeCode::SelfBilledCreditNote, InvoiceTypeCode::CorrectedInvoice, InvoiceTypeCode::PrepaymentInvoice], true)) {
            return true;
        }
        $prepaid = $invoice->totals?->prepaidAmount;

        return $invoice->precedingInvoices !== [] && ($prepaid === null || ! $prepaid->isPositive());
    }

    /**
     * The words that state reverse charge: REVERSE_CHARGE_STATEMENTS and the texts of the reverse
     * charge presets in every locale of the pack, the invoice and the run.
     *
     * @param  list<string|null>  $locales
     * @return list<string>
     */
    private static function reverseChargeStatements(array $locales): array
    {
        $statements = self::REVERSE_CHARGE_STATEMENTS;
        $locales = array_unique([...CountryAT::locales(), ...array_filter($locales, static fn (?string $l): bool => $l !== null && $l !== '')]);
        foreach (VatPreset::cases() as $preset) {
            if ($preset->category() !== VatCategory::ReverseCharge) {
                continue;
            }
            foreach ($locales as $locale) {
                $text = trim((string) $preset->exemptionReason($locale));
                if ($text !== '') {
                    $statements[] = $text;
                }
            }
        }

        return array_values(array_unique($statements));
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

    private static function noteContains(InvoiceData $invoice, string $needle): bool
    {
        foreach ($invoice->notes as $note) {
            if (str_contains(mb_strtolower($note->content), $needle)) {
                return true;
            }
        }

        return false;
    }
}
