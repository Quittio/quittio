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
use Quittio\Core\Validation\Rule;
use Quittio\Core\Validation\Rules\CallbackRule;
use Quittio\Core\Validation\Rules\Inspect;
use Quittio\Core\Validation\Rules\RuleReporter;
use Quittio\Country\DE\CountryDE;
use Quittio\Country\DE\Formats\GermanFormatRule;
use Quittio\Country\DE\Support\Flags;
use Quittio\Country\DE\Support\LeitwegId;
use Quittio\Country\DE\Support\TaxNumber;
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
        // A line with the small business preset or the small business flag: the seller is a small business.
        $smallBusinessSeller = static fn (InvoiceData $i): bool => $i->meta->hasFlag(Flags::SMALL_BUSINESS)
            || array_filter($i->allLines(), static fn (InvoiceLine $l): bool => $l->vat->presetKey === VatPreset::SmallBusiness->value) !== [];

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
                // Prepayment invoices name the expected date or refer to the receipt of payment.
                if ($i->typeCode === InvoiceTypeCode::PrepaymentInvoice) {
                    $report->warning('BT-72', 'delivery.actualDeliveryDate');

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
                        $report->warning('BT-120', 'vatBreakdown['.$index.'].exemptionReason');
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
                if ($euro === null || $euro->isGreaterThan(Flags::SMALL_AMOUNT_LIMIT)) {
                    $report->error('BT-112', 'totals.grossTotal', ['limit' => Flags::SMALL_AMOUNT_LIMIT.' EUR']);
                }
            }),
            $r('DE-USTG-09', 'A small business (section 19 UStG) must not charge VAT in the same invoice.', function (InvoiceData $i, RuleReporter $report) use ($smallBusinessSeller): void {
                if (! $smallBusinessSeller($i)) {
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
            $r('DE-USTG-11', 'Reverse charge services in another EU member state and intra-community triangular transactions must state the VAT identifiers of the seller (BT-31) and the buyer (BT-48) (section 14a (1) and (7) UStG).', function (InvoiceData $i, RuleReporter $report): void {
                if (! self::hasReverseChargeInOtherMemberState($i, triangular: true)) {
                    return;
                }
                if (! Inspect::filled($i->seller->vatId) && ! Inspect::filled($i->sellerTaxRepresentative?->vatId)) {
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
            $r('DE-USTG-14', 'A small business cannot treat a supply as a tax-exempt intra-community supply (VAT category K) (section 19 (1) sentence 2 UStG); only the intra-community supply of a new vehicle is excepted (section 19 (6) sentence 1 UStG).', function (InvoiceData $i, RuleReporter $report) use ($smallBusinessSeller): void {
                // Section 19 (1) UStG does not apply to the intra-community supply of a new vehicle (section 19 (6) sentence 1 UStG), see DE-USTG-13.
                if ($smallBusinessSeller($i) && ! $i->meta->hasFlag(Flags::NEW_VEHICLE) && Inspect::uses($i, VatCategory::IntraCommunitySupply)) {
                    $report->error('BT-151', 'lines');
                }
            }),
            $r('DE-USTG-15', 'Invoices for intra-community supplies and for services under section 3a (2) UStG in another member state must be issued by the 15th day of the month following the supply (section 14a (1) sentence 2 and (3) sentence 1 UStG); the deadline was :deadline.', function (InvoiceData $i, RuleReporter $report): void {
                // Cancellations, corrections and re-issued invoices refer to the original (BG-3), whose deadline counted.
                if ($i->precedingInvoices !== [] || in_array($i->typeCode, [InvoiceTypeCode::CreditNote, InvoiceTypeCode::SelfBilledCreditNote, InvoiceTypeCode::CorrectedInvoice, InvoiceTypeCode::PrepaymentInvoice], true)) {
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
        ];
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
     * UStG). The supply date is the latest of the delivery date, the end of the invoicing period
     * and the ends of the line periods; null without any of them (see DE-USTG-03).
     */
    public static function issuingDeadline(InvoiceData $invoice): ?Date
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
        if ($supply === null) {
            return null;
        }

        return $supply->month === 12 ? Date::of($supply->year + 1, 1, 15) : Date::of($supply->year, $supply->month + 1, 15);
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

            return $report->context()->profileIs('xrechnung', 'xrechnung-extension')
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
                if ($number !== null && trim($number) !== '' && $i->seller->address->countryCode === CountryDE::CODE && TaxNumber::validate($number) === false) {
                    $report->warning('BT-32', 'seller.taxNumber', ['value' => $number]);
                }
            }, self::NS),
        ];
    }

    /**
     * All lines use the small business exemption (section 19 (1) UStG, invoice according to section 34a UStDV).
     */
    public static function isSmallBusinessInvoice(InvoiceData $invoice): bool
    {
        $lines = $invoice->allLines();
        foreach ($lines as $line) {
            if ($line->vat->presetKey !== VatPreset::SmallBusiness->value) {
                return false;
            }
        }

        return $lines !== [];
    }

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
