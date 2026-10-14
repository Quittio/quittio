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
 * Mandatory invoice information according to the German VAT act (specification 2.3, 8.3):
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

        return [
            $r('DE-USTG-01', 'Name and full address of the seller and the buyer must be stated (section 14 (4) no. 1 UStG).', function (InvoiceData $i, RuleReporter $report) use ($small): void {
                $seller = $i->seller->address;
                if (! Inspect::filled($seller->line1) || ! Inspect::filled($seller->city) || ! Inspect::filled($seller->postcode)) {
                    $report->error('BG-5', 'seller.address');
                }
                $buyer = $i->buyer->address;
                $postcodeMissing = $buyer->countryCode === CountryDE::CODE && ! Inspect::filled($buyer->postcode);
                if (! $small($i) && (! Inspect::filled($buyer->line1) || ! Inspect::filled($buyer->city) || $postcodeMissing)) {
                    $report->error('BG-8', 'buyer.address');
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
            $r('DE-USTG-03', 'The date of the supply or service must be stated: delivery date (BT-72), invoicing period (BG-14) or line periods (BG-26) (section 14 (4) no. 6 UStG).', function (InvoiceData $i, RuleReporter $report) use ($small, $smallBusiness): void {
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
            $r('DE-USTG-08', 'A small amount invoice must not exceed 250 EUR gross (section 33 UStDV).', function (InvoiceData $i, RuleReporter $report): void {
                if (! $i->meta->hasFlag(Flags::SMALL_AMOUNT) || $i->totals === null) {
                    return;
                }
                // Foreign currencies count with their EUR equivalent at the stated exchange rate.
                $gross = $i->totals->grossTotal->getAmount();
                $rate = $i->meta->exchangeRate;
                $euro = $i->currency === 'EUR' ? $gross : ($rate !== null && $i->taxCurrency === 'EUR' ? $gross->multipliedBy($rate) : null);
                if ($euro === null || $euro->isGreaterThan(Flags::SMALL_AMOUNT_LIMIT)) {
                    $report->error('BT-112', 'totals.grossTotal', ['limit' => '250,00 EUR']);
                }
            }),
            $r('DE-USTG-09', 'A small business (section 19 UStG) must not charge VAT in the same invoice.', function (InvoiceData $i, RuleReporter $report): void {
                $lines = $i->allLines();
                $smallBusiness = array_filter($lines, static fn (InvoiceLine $l): bool => $l->vat->presetKey === VatPreset::SmallBusiness->value);
                if ($smallBusiness === [] && ! $i->meta->hasFlag(Flags::SMALL_BUSINESS)) {
                    return;
                }
                foreach ($lines as $line) {
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
            $r('DE-USTG-10', 'The VAT amount should also be stated in EUR (BT-111 with BT-6 = EUR) for invoices in a foreign currency.', function (InvoiceData $i, RuleReporter $report): void {
                if ($i->currency === 'EUR' || $i->totals === null || $i->totals->vatTotal === null || $i->totals->vatTotal->isZero()) {
                    return;
                }
                if ($i->taxCurrency !== 'EUR') {
                    $report->warning('BT-6', 'taxCurrency');
                }
            }),
            $r('DE-USTG-11', 'Reverse charge services in another EU member state and triangular transactions must state the VAT identifiers of the seller (BT-31) and the buyer (BT-48) (section 14a (1) and (7) UStG).', function (InvoiceData $i, RuleReporter $report): void {
                $presets = [VatPreset::ReverseChargeEu->value, VatPreset::TriangularTransaction->value];
                // Reverse charge without a preset counts when the buyer is in another member state (not section 13b UStG).
                $buyerInOtherMemberState = self::isOtherMemberState($i->buyer->address, goods: false);
                $crossBorder = array_filter($i->allLines(), static fn (InvoiceLine $l): bool => in_array($l->vat->presetKey, $presets, true)
                    || ($l->vat->presetKey === null && $l->vat->category === VatCategory::ReverseCharge && $buyerInOtherMemberState));
                if ($crossBorder === []) {
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
