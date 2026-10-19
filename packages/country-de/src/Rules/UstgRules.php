<?php

declare(strict_types=1);

namespace Quittio\Country\DE\Rules;

use Closure;
use Quittio\Core\Countries\VatTerritory;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Data\Lines\InvoiceLine;
use Quittio\Core\Data\Party\Address;
use Quittio\Core\Enums\InvoiceTypeCode;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Support\Date;
use Quittio\Core\Tax\SupplyDate;
use Quittio\Core\Validation\Plausibility\VatIdFormat;
use Quittio\Core\Validation\Rule;
use Quittio\Core\Validation\Rules\CallbackRule;
use Quittio\Core\Validation\Rules\Inspect;
use Quittio\Core\Validation\Rules\RuleReporter;
use Quittio\Country\DE\CountryDE;
use Quittio\Country\DE\Formats\GermanFormatRule;
use Quittio\Country\DE\Support\Flags;
use Quittio\Country\DE\Support\LeitwegId;
use Quittio\Country\DE\Support\TaxNumber;
use Quittio\Country\DE\Tax\TaxTables;
use Quittio\Country\DE\Tax\VatPreset;

/**
 * Mandatory invoice information according to the German VAT act:
 * section 14 (4) and 14a UStG, section 33 UStDV for small amount invoices and section 34a UStDV
 * for small business invoices.
 *
 * These rules apply to every invoice of a German seller, independent of the format.
 */
final class UstgRules
{
    private const string NS = CountryDE::TRANSLATION_NAMESPACE;

    /** Words of a note that states that a corrected invoice (384) corrects and replaces the original. */
    private const array CORRECTION_STATEMENTS = ['berichtigt', 'ersetzt', 'korrigiert', 'corrects', 'replaces', 'rectifie', 'remplace'];

    /** "Gutschrift" and its equivalents in other official EU languages (UStAE 14.5 Abs. 24). */
    private const array SELF_BILLING_LABELS = [
        'gutschrift', 'self-billing', 'self billing', 'autofacturation', 'autofactura', 'autofatturazione',
        'autofacturação', 'zelffacturering', 'samofakturowanie', 'self-billed invoice',
    ];

    /**
     * @return list<Rule>
     */
    public static function all(): array
    {
        CountryDE::registerTranslations();
        $r = static fn (string $code, string $fallback, Closure $check): Rule => CallbackRule::l3($code, $fallback, $check, self::NS);
        $small = static fn (InvoiceData $i): bool => self::isSmallAmountInvoice($i);
        $smallBusiness = static fn (InvoiceData $i): bool => self::isSmallBusinessInvoice($i);

        return [
            $r('DE-USTG-01', 'The address :party is incomplete; missing: :fields (section 14 (4) no. 1 UStG).', function (InvoiceData $i, RuleReporter $report) use ($small): void {
                $seller = $i->seller->address;
                $missing = self::missingAddressFields($seller->line1, $seller->postcode, $seller->city, postcodeRequired: true);
                if ($missing !== []) {
                    $report->error('BG-5', 'seller.address', self::addressReplacements($report, 'seller', $missing));
                }
                $buyer = $i->buyer->address;
                $missing = self::missingAddressFields($buyer->line1, $buyer->postcode, $buyer->city, postcodeRequired: $buyer->countryCode === CountryDE::CODE);
                if (! $small($i) && $missing !== []) {
                    $report->error('BG-8', 'buyer.address', self::addressReplacements($report, 'buyer', $missing));
                }
            }),
            $r('DE-USTG-02', 'The seller\'s tax number (BT-32) or VAT identifier (BT-31) must be stated (section 14 (4) no. 2 UStG).', function (InvoiceData $i, RuleReporter $report) use ($small): void {
                if ($small($i)) {
                    return;
                }
                $s = $i->seller;
                if (! Inspect::filled($s->vatId) && ! Inspect::filled($s->taxNumber) && $i->sellerTaxRepresentative === null) {
                    $report->error('BT-31', 'seller.vatId');
                }
            }),
            $r('DE-USTG-03', 'The date of the supply or service must be stated: delivery date (BT-72), invoicing period (BG-14) or periods on all lines (BG-26) (section 14 (4) no. 6 UStG).', function (InvoiceData $i, RuleReporter $report) use ($small, $smallBusiness): void {
                // Small amount invoices (section 33 UStDV) and small business invoices (section 34a UStDV) need no service date.
                if ($small($i) || $smallBusiness($i) || self::hasServiceDate($i)) {
                    return;
                }
                // Prepayment invoices name the expected date of the service, or state that it is
                // not agreed yet (section 14.8 (4) sentences 3 and 5 UStAE).
                if ($i->typeCode === InvoiceTypeCode::PrepaymentInvoice) {
                    if (! $i->meta->hasFlag(Flags::SERVICE_DATE_OPEN) && ! $i->meta->hasFlag(Flags::EXPECTED_SERVICE_DATE)) {
                        $report->warning('BT-72', 'delivery.actualDeliveryDate');
                    }

                    return;
                }
                $report->error('BT-72', 'delivery.actualDeliveryDate');
            }),
            $r('DE-USTG-04', 'A self-billed invoice must be labelled "Gutschrift" (section 14 (4) no. 10 UStG).', function (InvoiceData $i, RuleReporter $report): void {
                // 389 self-billed invoice, 261 self-billed credit note, 527 self-billed debit note.
                if (! $i->typeCode instanceof InvoiceTypeCode || ! $i->typeCode->isSelfBilled()) {
                    return;
                }
                // The label may also be given in another official EU language (UStAE 14.5 Abs. 24).
                foreach (self::SELF_BILLING_LABELS as $label) {
                    if (self::noteContains($i, $label)) {
                        return;
                    }
                }
                // In an e-invoice the type code is label enough (section 14.5 (24) sentence 1
                // UStAE); a document without XML needs the word.
                if ($report->context()->option('syntax') !== null) {
                    $report->warning('BT-22', 'notes');

                    return;
                }
                $report->error('BT-22', 'notes');
            }),
            $r('DE-USTG-05', 'Services in connection with a property to a non-business buyer require the notice on the buyer\'s retention obligation (section 14 (4) no. 9 UStG).', function (InvoiceData $i, RuleReporter $report): void {
                if ($i->meta->hasFlag(Flags::PROPERTY_RELATED_SERVICE) && ! self::noteContains($i, '14b')) {
                    $report->error('BT-22', 'notes');
                }
            }),
            $r('DE-USTG-06', 'Reverse charge invoices must state "Steuerschuldnerschaft des Leistungsempfängers" (section 14a (1) and (5) UStG).', function (InvoiceData $i, RuleReporter $report): void {
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
            $r('DE-USTG-07', 'The intra-community supply of a new vehicle requires the vehicle details (BG-32) on every line (section 14a (4) UStG).', function (InvoiceData $i, RuleReporter $report): void {
                if (! $i->meta->hasFlag(Flags::NEW_VEHICLE)) {
                    return;
                }
                foreach ($i->lines as $index => $line) {
                    if ($line->item->attributes === []) {
                        $report->error('BG-32', 'lines['.$index.'].item.attributes');
                    }
                }
            }),
            $r('DE-USTG-08', 'A small amount invoice must not exceed :limit gross (section 33 UStDV).', function (InvoiceData $i, RuleReporter $report): void {
                if (! $i->meta->hasFlag(Flags::SMALL_AMOUNT) || $i->totals === null) {
                    return;
                }
                // Foreign currencies count with their EUR equivalent at the stated exchange rate.
                $gross = $i->totals->grossTotal->getAmount();
                $rate = $i->meta->exchangeRate;
                $euro = $i->currency === 'EUR' ? $gross : ($rate !== null && $i->taxCurrency === 'EUR' ? $gross->multipliedBy($rate) : null);
                // The limit of the supply date: a raised limit applies to supplies made from its
                // start (250 EUR from 1 January 2017, BMF letter of 15 November 2017).
                $limit = TaxTables::smallAmountLimitOn(self::supplyDate($i) ?? $i->issueDate);
                if ($euro === null || $euro->isGreaterThan($limit)) {
                    $report->error('BT-112', 'totals.grossTotal', ['limit' => $limit.' EUR']);
                }
            }),
            $r('DE-USTG-09', 'A small business (section 19 UStG) must not charge VAT in the same invoice.', function (InvoiceData $i, RuleReporter $report) use ($smallBusiness): void {
                if (! $smallBusiness($i)) {
                    return;
                }
                foreach ($i->allLines() as $line) {
                    if ($line->vat->category === VatCategory::StandardRate) {
                        $report->error('BT-151', 'lines');

                        return;
                    }
                }
                // VAT on document level allowances or charges is shown VAT as well (section 14c (2) UStG).
                foreach ([...$i->allowances, ...$i->charges] as $entry) {
                    if ($entry->vatCategory === VatCategory::StandardRate) {
                        $report->error('BT-102', 'charges');

                        return;
                    }
                }
            }),
            $r('DE-USTG-10', 'For invoices in a foreign currency, the VAT amount should also be stated in EUR (BT-111 with BT-6 = EUR) (Article 230 VAT Directive, section 16 (6) UStG).', function (InvoiceData $i, RuleReporter $report): void {
                if ($i->currency === 'EUR' || $i->totals === null || $i->totals->vatTotal === null || $i->totals->vatTotal->isZero()) {
                    return;
                }
                if ($i->taxCurrency !== 'EUR') {
                    $report->warning('BT-6', 'taxCurrency');
                }
            }),
            $r('DE-USTG-11', 'Reverse charge services in another EU member state and intra-community triangular transactions must state the VAT identifiers of the seller (BT-31) and the buyer (BT-48) (section 14a (1) and (7) UStG).', function (InvoiceData $i, RuleReporter $report) use ($smallBusiness): void {
                if (! self::hasReverseChargeInOtherMemberState($i, triangular: true)) {
                    return;
                }
                // Section 14a (1) sentence 3 and (7) sentence 2 UStG do not apply to small
                // businesses (section 19 (1) sentence 2 UStG): their tax number is enough.
                if (! $smallBusiness($i) && ! Inspect::filled($i->seller->vatId) && ! Inspect::filled($i->sellerTaxRepresentative?->vatId)) {
                    $report->error('BT-31', 'seller.vatId');
                }
                if (! Inspect::filled($i->buyer->vatId)) {
                    $report->error('BT-48', 'buyer.vatId');
                }
            }),
            $r('DE-USTG-12', 'The simplifications for small amount invoices do not apply to distance sales, intra-community supplies and reverse charge supplies (section 33 sentence 3 UStDV).', function (InvoiceData $i, RuleReporter $report): void {
                if (! $i->meta->hasFlag(Flags::SMALL_AMOUNT)) {
                    return;
                }
                if (self::excludedFromSmallAmount($i)) {
                    $report->error('BT-151', 'lines');
                } elseif (self::possibleDistanceSale($i)) {
                    // Section 3c UStG covers goods transported to the consumer only, not services
                    // (e.g. OSS e-services) or sales on the premises: only a hint.
                    $report->warning('BT-151', 'lines');
                }
            }),
            $r('DE-USTG-13', 'The intra-community supply of a new vehicle (section 14a (4) UStG) is marked, but no line uses the VAT category K (intra-community supply).', function (InvoiceData $i, RuleReporter $report): void {
                // A new vehicle supplied to another member state is an intra-community supply (section 6a (1) UStG);
                // only a warning, as the exemption may not apply in the individual case.
                if ($i->meta->hasFlag(Flags::NEW_VEHICLE) && ! Inspect::uses($i, VatCategory::IntraCommunitySupply)) {
                    $report->warning('BT-151', 'lines');
                }
            }),
            $r('DE-USTG-14', 'A small business cannot treat a supply as a tax-exempt intra-community supply (VAT category K) (section 19 (1) sentence 2 UStG); only the intra-community supply of a new vehicle is excepted (section 19 (6) sentence 1 UStG).', function (InvoiceData $i, RuleReporter $report) use ($smallBusiness): void {
                // Section 19 (1) UStG does not apply to the intra-community supply of a new vehicle (section 19 (6) sentence 1 UStG), see DE-USTG-13.
                if ($smallBusiness($i) && ! $i->meta->hasFlag(Flags::NEW_VEHICLE) && Inspect::uses($i, VatCategory::IntraCommunitySupply)) {
                    $report->error('BT-151', 'lines');
                }
            }),
            $r('DE-USTG-15', 'Invoices for intra-community supplies and for services under section 3a (2) UStG in another member state must be issued by the 15th day of the month following the supply (section 14a (1) sentence 2 and (3) sentence 1 UStG); the deadline was :deadline.', function (InvoiceData $i, RuleReporter $report): void {
                if (self::refersToOriginal($i)) {
                    return;
                }
                if (! Inspect::uses($i, VatCategory::IntraCommunitySupply) && ! self::hasReverseChargeInOtherMemberState($i, triangular: false)) {
                    return;
                }
                $deadline = self::issuingDeadline($i);
                // Late invoices stay valid: only a warning.
                if ($deadline !== null && $i->issueDate->isAfter($deadline)) {
                    $report->warning('BT-2', 'issueDate', ['deadline' => $deadline->toIsoString()]);
                }
            }),
            $r('DE-USTG-16', 'The invoice is issued more than six months after the supply (:supply); invoices to businesses and for services in connection with a property must be issued within six months (section 14 (2) sentence 2 UStG).', function (InvoiceData $i, RuleReporter $report): void {
                // The six months apply where an invoice is obligatory: to a business or a legal
                // person, or for a service in connection with a property (section 14 (2) sentence 1 UStG).
                if (self::refersToOriginal($i) || ($i->buyer->isConsumer && ! $i->meta->hasFlag(Flags::PROPERTY_RELATED_SERVICE))) {
                    return;
                }
                $supply = self::supplyDate($i);
                // Late invoices stay valid: only a warning.
                if ($supply !== null && $i->issueDate->isAfter(Date::parse($supply->toCarbon('UTC')->addMonthsNoOverflow(6)))) {
                    $report->warning('BT-2', 'issueDate', ['supply' => $supply->toIsoString()]);
                }
            }),
            $r('DE-USTG-17', 'A prepayment invoice that states an amount already paid (BT-113) must state the date the payment was received as VAT point date (BT-7) if it differs from the issue date (section 14 (4) sentence 1 no. 6 UStG).', function (InvoiceData $i, RuleReporter $report): void {
                // Only a warning: a payment received on the issue date needs no date of its own.
                $paid = $i->totals?->prepaidAmount;
                if ($i->typeCode === InvoiceTypeCode::PrepaymentInvoice && $paid !== null && $paid->isPositive() && $i->taxPointDate === null) {
                    $report->warning('BT-7', 'taxPointDate');
                }
            }),
            $r('DE-USTG-18', 'A corrected invoice (384) should state in a note that it corrects and replaces the original invoice (section 31 (5) UStDV, section 15.2a (7) UStAE).', function (InvoiceData $i, RuleReporter $report): void {
                if ($i->typeCode !== InvoiceTypeCode::CorrectedInvoice) {
                    return;
                }
                foreach (self::CORRECTION_STATEMENTS as $word) {
                    if (self::noteContains($i, $word)) {
                        return;
                    }
                }
                $report->warning('BT-22', 'notes');
            }),
            $r('DE-USTG-20', 'The final invoice deducts an advance invoice in full without the amount received being confirmed. Only advance payments actually received are deducted (section 14 (5) sentence 2 UStG, section 14.8 (2), (5) and (10) UStAE).', function (InvoiceData $i, RuleReporter $report): void {
                if ($i->meta->hasFlag(Flags::ADVANCE_RECEIPT_UNCONFIRMED)) {
                    $report->warning('BT-113', 'prepaidAmount');
                }
            }),
            $r('DE-EINVOICE-01', 'The invoice must be issued as e-invoice: :reason (section 14 (1) and section 27 (38) UStG). A PDF, MINIMUM or BASIC WL is an "other invoice".', function (InvoiceData $i, RuleReporter $report): void {
                if ($report->context()->option('e_invoice') === false && ($reason = self::eInvoiceObligation($i, $report)) !== null && $reason[0]) {
                    $report->error(null, null, ['reason' => $reason[1]]);
                }
            }),
            $r('DE-EINVOICE-02', 'Until :until an "other invoice" is allowed for this supply instead of an e-invoice, as PDF only with the consent of the buyer (section 27 (38) UStG, section 14 (1) sentence 5 UStG).', function (InvoiceData $i, RuleReporter $report): void {
                if ($report->context()->option('e_invoice') === false && ($reason = self::eInvoiceObligation($i, $report)) !== null && ! $reason[0]) {
                    $report->warning(null, null, ['until' => $reason[1]]);
                }
            }),
            $r('DE-USTG-19', 'The final invoice deducts an advance that was settled by self-billing (Gutschrift), but is no self-billed invoice itself. The advance must be deducted (section 14 (2) sentence 5 UStG); changing the way of invoicing is allowed but unusual.', function (InvoiceData $i, RuleReporter $report): void {
                if ($i->meta->hasFlag(Flags::SELF_BILLED_ADVANCE) && ! ($i->typeCode instanceof InvoiceTypeCode && $i->typeCode->isSelfBilled())) {
                    $report->info('BT-3', 'typeCode');
                }
            }),
        ];
    }

    /**
     * Whether a domestic B2B invoice must be an e-invoice (section 14 (1), (2) and section 27 (38)
     * UStG): [true, reason] when it must, [false, last day of the transition] when another invoice
     * is still allowed, null when the obligation does not apply. Not for consumers or buyers or
     * sellers outside the Inland, supplies exempt under section 4 no. 8 to 29 UStG only, small
     * amount invoices up to the limit (section 33 sentence 4 UStDV), small businesses (section
     * 34a sentence 4 UStDV), tickets (section 34 UStDV), cancellations and corrections of earlier
     * invoices (section 14.11 (1) sentence 9 UStAE) and before 2025. A prepayment invoice counts
     * with its issue date (BMF letter of 15 October 2026, marginal no. 2), other invoices with the
     * supply date. Prior-year turnover above 800,000 EUR (setting
     * prior_year_turnover_above_800k of quittio.countries.de) ends the transition for 2027.
     *
     * @return array{bool, string}|null
     */
    private static function eInvoiceObligation(InvoiceData $invoice, RuleReporter $report): ?array
    {
        $inland = static fn (Address $address): bool => $address->countryCode === '' || VatTerritory::memberState($address->countryCode, $address->postcode) === CountryDE::CODE;
        if ($invoice->buyer->isConsumer || ! $inland($invoice->seller->address) || ! $inland($invoice->buyer->address)
            || self::isSmallBusinessInvoice($invoice) || $invoice->meta->hasFlag(Flags::TICKET)
            || ($invoice->typeCode !== InvoiceTypeCode::PrepaymentInvoice && self::refersToOriginal($invoice))
            || self::onlyExemptFinancialAndSocial($invoice)) {
            return null;
        }
        $date = $invoice->typeCode === InvoiceTypeCode::PrepaymentInvoice ? $invoice->issueDate : (self::supplyDate($invoice) ?? $invoice->issueDate);
        $gross = $invoice->totals?->grossTotal;
        if ($gross !== null && $invoice->currency === 'EUR' && ! $gross->getAmount()->isGreaterThan(TaxTables::smallAmountLimitOn($date))) {
            return null;
        }
        $settings = $report->context()->option('countries');
        $above800k = is_array($settings) && is_array($settings['de'] ?? null) && ($settings['de']['prior_year_turnover_above_800k'] ?? false) === true;

        return match (true) {
            $date->isBefore(Date::of(2025, 1, 1)) => null,
            $date->isBefore(Date::of(2027, 1, 1)) => $invoice->issueDate->isAfter(Date::of(2026, 12, 31))
                ? [true, 'the transition for supplies of 2025 and 2026 ended on 31 December 2026']
                : [false, '2026-12-31'],
            $date->isBefore(Date::of(2028, 1, 1)) => match (true) {
                $above800k => [true, 'supply of 2027 by a business with a prior-year turnover above 800,000 EUR'],
                $invoice->issueDate->isAfter(Date::of(2027, 12, 31)) => [true, 'the transition for supplies of 2027 ended on 31 December 2027'],
                default => [false, '2027-12-31'],
            },
            default => [true, 'supply from 2028'],
        };
    }

    /**
     * All lines are exempt under section 4 no. 8 to 29 UStG (financial, insurance, medical, social
     * and similar supplies), told by the legal reference or the VATEX code of Article 132 or 135.
     */
    private static function onlyExemptFinancialAndSocial(InvoiceData $invoice): bool
    {
        $lines = $invoice->allLines();
        foreach ($lines as $line) {
            $code = $line->vat->exemptionReasonCode === null ? '' : (string) $line->vat->exemptionReasonCode->value;
            $paragraph = preg_match('/§\s*4\s*Nr\.?\s*(\d+)/u', (string) $line->vat->exemptionReason, $match) === 1 ? (int) $match[1] : 0;
            if ($line->vat->category !== VatCategory::Exempt || (($paragraph < 8 || $paragraph > 29) && ! str_starts_with($code, 'VATEX-EU-132') && ! str_starts_with($code, 'VATEX-EU-135'))) {
                return false;
            }
        }

        return $lines !== [];
    }

    /**
     * Wordings accepted for reverse charge (section 14a (5) UStG): "Steuerschuldnerschaft", and the
     * texts of the pack's reverse charge presets in every locale it ships and in $locales.
     *
     * @param  list<?string>  $locales
     * @return list<string>
     */
    private static function reverseChargeStatements(array $locales): array
    {
        $statements = ['steuerschuldnerschaft'];
        $locales = array_unique([...CountryDE::locales(), ...array_filter($locales, static fn (?string $l): bool => $l !== null && $l !== '')]);
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

    /**
     * Small amount invoice according to section 33 UStDV whose simplifications apply: not for
     * supplies under sections 6a and 13b UStG (section 33 sentence 3 UStDV). Possible distance
     * sales (section 3c UStG) keep the simplifications and get a DE-USTG-12 warning.
     */
    public static function isSmallAmountInvoice(InvoiceData $invoice): bool
    {
        return $invoice->meta->hasFlag(Flags::SMALL_AMOUNT) && ! self::excludedFromSmallAmount($invoice);
    }

    /**
     * Reverse charge (section 13b UStG) or intra-community supply (section 6a UStG), told by the
     * VAT category. Distance sales (section 3c UStG) cannot be told from the data, see
     * possibleDistanceSale().
     */
    private static function excludedFromSmallAmount(InvoiceData $invoice): bool
    {
        return Inspect::uses($invoice, VatCategory::ReverseCharge) || Inspect::uses($invoice, VatCategory::IntraCommunitySupply);
    }

    /**
     * A consumer destination (deliver to address, otherwise the buyer's address) in another member
     * state: a distance sale if goods are transported there (section 3c UStG).
     */
    private static function possibleDistanceSale(InvoiceData $invoice): bool
    {
        $delivery = $invoice->delivery?->address;
        $destination = $delivery !== null && Inspect::filled($delivery->countryCode) ? $delivery : $invoice->buyer->address;

        return $invoice->buyer->isConsumer && self::isOtherMemberState($destination, goods: true);
    }

    /**
     * Reverse charge in another member state: a line with VatPreset::ReverseChargeEu (or
     * VatPreset::TriangularTransaction if $triangular), or of category AE without preset for a
     * buyer in another member state (not section 13b UStG).
     */
    private static function hasReverseChargeInOtherMemberState(InvoiceData $invoice, bool $triangular): bool
    {
        $presets = $triangular ? [VatPreset::ReverseChargeEu->value, VatPreset::TriangularTransaction->value] : [VatPreset::ReverseChargeEu->value];
        $buyerInOtherMemberState = self::isOtherMemberState($invoice->buyer->address, goods: false);
        foreach ($invoice->allLines() as $line) {
            if (in_array($line->vat->presetKey, $presets, true)
                || ($line->vat->presetKey === null && $line->vat->category === VatCategory::ReverseCharge && $buyerInOtherMemberState)) {
                return true;
            }
        }

        return false;
    }

    /** English words of DE-USTG-01 for locales without them in the catalog. */
    private const array ADDRESS_WORDS = [
        'party.seller' => 'of the seller (BG-5)',
        'party.buyer' => 'of the buyer (BG-8)',
        'fields.line1' => 'street and number',
        'fields.postcode' => 'post code',
        'fields.city' => 'city',
        'advice.seller' => 'Add street, post code and city to the seller.',
        'advice.buyer' => 'Add street, post code and city to the buyer.',
    ];

    /**
     * The address fields of section 14 (4) no. 1 UStG that are empty: street, post code (for
     * the buyer only in Germany) and city.
     *
     * @return list<string>
     */
    private static function missingAddressFields(?string $line1, ?string $postcode, ?string $city, bool $postcodeRequired): array
    {
        return array_keys(array_filter([
            'line1' => ! Inspect::filled($line1),
            'postcode' => $postcodeRequired && ! Inspect::filled($postcode),
            'city' => ! Inspect::filled($city),
        ]));
    }

    /**
     * The party, the missing fields and the advice of DE-USTG-01 in the locale of the run.
     *
     * @param  list<string>  $missing
     * @return array<string, string>
     */
    private static function addressReplacements(RuleReporter $report, string $party, array $missing): array
    {
        $translator = $report->context()->translator();
        $locale = $report->context()->messageLocale();
        $word = static function (string $key) use ($translator, $locale): string {
            $catalog = CountryDE::TRANSLATION_NAMESPACE.'::rules.DE-USTG-01.'.$key;
            foreach ([$locale, 'en'] as $candidate) {
                if ($translator->has($catalog, $candidate)) {
                    return $translator->get($catalog, [], $candidate);
                }
            }

            return self::ADDRESS_WORDS[$key] ?? $key;
        };

        return [
            'party' => $word('party.'.$party),
            'fields' => implode(', ', array_map(static fn (string $field): string => $word('fields.'.$field), $missing)),
            'advice' => $word('advice.'.$party),
        ];
    }

    /**
     * The 15th day of the month after the supply (section 14a (1) sentence 2 and (3) sentence 1
     * UStG), see supplyDate(); null without a supply date (see DE-USTG-03).
     */
    public static function issuingDeadline(InvoiceData $invoice): ?Date
    {
        $supply = self::supplyDate($invoice);
        if ($supply === null) {
            return null;
        }

        return $supply->month === 12 ? Date::of($supply->year + 1, 1, 15) : Date::of($supply->year, $supply->month + 1, 15);
    }

    /**
     * The date of the supply that the invoice states: the latest of the delivery date, the end
     * of the invoicing period and the ends of the line periods; null without any of them.
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

    /**
     * Cancellations (381, 261), corrections (384), prepayment invoices (386) and re-issued
     * invoices (BG-3): their issuing deadline is the one of the original or of the final invoice.
     * A final invoice refers to its advance invoices (BG-3) but is the invoice for the supply.
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
     * Address in the VAT territory of another member state (Monaco counts as France, Northern
     * Ireland for goods only, Büsingen and Heligoland are outside).
     */
    private static function isOtherMemberState(Address $address, bool $goods): bool
    {
        $state = VatTerritory::memberState($address->countryCode, $address->postcode, $goods);

        return $state !== null && $state !== CountryDE::CODE;
    }

    /**
     * Plausibility of the Leitweg-ID (level L7). For public buyers (XRechnung or a Leitweg-ID
     * endpoint, BT-49 scheme 0204) wrong check digits are a warning. Other business buyers in
     * Germany get an info for a buyer reference shaped like a Leitweg-ID with wrong check digits: it may be a
     * mistyped Leitweg-ID, which does not lead to XRechnung, or an ordinary order number.
     *
     * @return list<Rule>
     */
    public static function plausibility(): array
    {
        CountryDE::registerTranslations();
        $publicBuyer = static function (InvoiceData $i, RuleReporter $report): bool {
            $endpoint = $i->buyer->electronicAddress;

            return $report->context()->profileIs('xrechnung', 'xrechnung-extension', 'xrechnung-cvd')
                || ($endpoint !== null && (string) $endpoint->scheme->value === GermanFormatRule::LEITWEG_SCHEME);
        };
        $wrongCheckDigits = static fn (?string $reference): bool => LeitwegId::looksLike($reference) && ! LeitwegId::isValid($reference);

        return [
            CallbackRule::l7('DE-LEITWEG-ID', 'The check digits of the Leitweg-ID ":value" (BT-10) are wrong.', function (InvoiceData $i, RuleReporter $report) use ($publicBuyer, $wrongCheckDigits): void {
                if ($publicBuyer($i, $report) && $wrongCheckDigits($i->buyerReference)) {
                    $report->warning('BT-10', 'buyerReference', ['value' => (string) $i->buyerReference]);
                }
            }, self::NS),
            CallbackRule::l7('DE-LEITWEG-ID-SUSPECT', 'The buyer reference ":value" (BT-10) is shaped like a Leitweg-ID, but its check digits are wrong. It is not treated as a Leitweg-ID, so the invoice is not issued as XRechnung automatically.', function (InvoiceData $i, RuleReporter $report) use ($publicBuyer, $wrongCheckDigits): void {
                $country = $i->buyer->address->countryCode;
                if (($country === '' || $country === CountryDE::CODE) && ! $i->buyer->isConsumer && ! $publicBuyer($i, $report) && $wrongCheckDigits($i->buyerReference)) {
                    $report->info('BT-10', 'buyerReference', ['value' => (string) $i->buyerReference]);
                }
            }, self::NS),
            CallbackRule::l7('DE-TAX-NUMBER', 'The tax number ":value" (BT-32) of the seller is not a valid German tax number: it matches neither the 13-digit ELSTER format nor the notation of a federal state, or its check digit is wrong.', function (InvoiceData $i, RuleReporter $report): void {
                $number = $i->seller->taxNumber;
                // A small business may state its small business identification number (suffix
                // "EX", section 34a sentence 1 no. 2 UStDV) instead of the tax number.
                if ($number !== null && self::isSmallBusinessInvoice($i) && VatIdFormat::isSmallBusinessId($number)) {
                    return;
                }
                if ($number !== null && trim($number) !== '' && $i->seller->address->countryCode === CountryDE::CODE && TaxNumber::validate($number) === false) {
                    $report->warning('BT-32', 'seller.taxNumber', ['value' => $number]);
                }
            }, self::NS),
            CallbackRule::l7('DE-VAT-RATE', 'The VAT rate :rate % of line :line is neither the standard rate (:standard %) nor the reduced rate (:reduced %) that applied on its supply date :date.', function (InvoiceData $i, RuleReporter $report): void {
                // Only supplies within the German VAT territory: distance sales carry the rates of the
                // country of destination, Büsingen and Heligoland are outside of it.
                foreach (array_filter([$i->buyer->address, $i->delivery?->address]) as $address) {
                    if ($address->countryCode !== '' && VatTerritory::memberState($address->countryCode, $address->postcode) !== CountryDE::CODE) {
                        return;
                    }
                }
                foreach ($i->lines as $index => $line) {
                    $rate = $line->vat->rate;
                    if ($line->vat->category !== VatCategory::StandardRate || $rate === null) {
                        continue;
                    }
                    $date = SupplyDate::ofLine($i, $line);
                    $standard = TaxTables::standardRateOn($date);
                    $reduced = TaxTables::reducedRateOn($date);
                    // A preset of the pack names its rate; any other rate is a change after the build.
                    $expected = match (VatPreset::fromKey($line->vat->presetKey ?? '')?->canonical()) {
                        VatPreset::Standard => [$standard],
                        VatPreset::Reduced => [$reduced],
                        default => [$standard, $reduced],
                    };
                    if (array_filter($expected, static fn ($allowed): bool => $rate->isEqualTo($allowed)) === []) {
                        $report->warning('BT-152', 'lines['.$index.'].vat.rate', [
                            'rate' => (string) $rate->strippedOfTrailingZeros(), 'line' => $line->id, 'date' => $date->toIsoString(),
                            'standard' => (string) $standard, 'reduced' => (string) $reduced,
                        ]);
                    }
                }
            }, self::NS),
        ];
    }

    /**
     * Invoice of a small business (section 19 UStG, invoice according to section 34a UStDV): the
     * small business flag is set or a line uses VatPreset::SmallBusiness.
     */
    public static function isSmallBusinessInvoice(InvoiceData $invoice): bool
    {
        return $invoice->meta->hasFlag(Flags::SMALL_BUSINESS)
            || array_filter($invoice->allLines(), static fn (InvoiceLine $l): bool => $l->vat->presetKey === VatPreset::SmallBusiness->value) !== [];
    }

    /**
     * Whether the invoice states the date of the supply (section 14 (4) no. 6 UStG): a delivery
     * date, an invoicing period or a period on every line.
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
