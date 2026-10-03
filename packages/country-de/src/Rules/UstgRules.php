<?php

declare(strict_types=1);

namespace Quittio\Country\DE\Rules;

use Closure;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Data\Lines\InvoiceLine;
use Quittio\Core\Enums\InvoiceTypeCode;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Validation\Rule;
use Quittio\Core\Validation\Rules\CallbackRule;
use Quittio\Core\Validation\Rules\Inspect;
use Quittio\Core\Validation\Rules\RuleReporter;
use Quittio\Country\DE\CountryDE;
use Quittio\Country\DE\Support\Flags;
use Quittio\Country\DE\Support\LeitwegId;
use Quittio\Country\DE\Tax\VatPreset;

/**
 * Mandatory invoice information according to the German VAT act (specification 2.3, 8.3):
 * section 14 (4) and 14a UStG, section 33 UStDV for small amount invoices.
 *
 * These rules apply to every invoice of a German seller, independent of the format.
 */
final class UstgRules
{
    private const string NS = CountryDE::TRANSLATION_NAMESPACE;

    /**
     * @return list<Rule>
     */
    public static function all(): array
    {
        CountryDE::registerTranslations();
        $r = static fn (string $code, string $fallback, Closure $check): Rule => CallbackRule::l3($code, $fallback, $check, self::NS);
        $small = static fn (InvoiceData $i): bool => $i->meta->hasFlag(Flags::SMALL_AMOUNT);
        $smallBusiness = static fn (InvoiceData $i): bool => self::isSmallBusinessInvoice($i);

        return [
            $r('DE-USTG-01', 'Name and full address of the seller and the buyer must be stated (section 14 (4) no. 1 UStG).', function (InvoiceData $i, RuleReporter $report) use ($small): void {
                $seller = $i->seller->address;
                if (! Inspect::filled($seller->line1) || ! Inspect::filled($seller->city) || ! Inspect::filled($seller->postcode)) {
                    $report->error('BG-5', 'seller.address');
                }
                $buyer = $i->buyer->address;
                if (! $small($i) && (! Inspect::filled($buyer->line1) || ! Inspect::filled($buyer->city))) {
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
                if ($i->typeCode !== InvoiceTypeCode::SelfBilledInvoice) {
                    return;
                }
                if (! self::noteContains($i, 'gutschrift')) {
                    $report->error('BT-22', 'notes');
                }
            }),
            $r('DE-USTG-05', 'Services in connection with a property to a non-business buyer require the notice on the buyer\'s retention obligation (section 14 (4) no. 9 UStG).', function (InvoiceData $i, RuleReporter $report): void {
                if ($i->meta->hasFlag(Flags::PROPERTY_RELATED_SERVICE) && ! self::noteContains($i, '14b')) {
                    $report->error('BT-22', 'notes');
                }
            }),
            $r('DE-USTG-06', 'Reverse charge invoices must state "Steuerschuldnerschaft des Leistungsempfängers" (section 14a (5) UStG).', function (InvoiceData $i, RuleReporter $report): void {
                foreach ($i->vatBreakdown as $index => $row) {
                    if ($row->category === VatCategory::ReverseCharge && ! str_contains(mb_strtolower((string) $row->exemptionReason), 'steuerschuldnerschaft')) {
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
            $r('DE-USTG-08', 'A small amount invoice must not exceed 250 EUR gross (section 33 UStDV).', function (InvoiceData $i, RuleReporter $report) use ($small): void {
                if (! $small($i) || $i->totals === null) {
                    return;
                }
                if ($i->currency !== 'EUR' || $i->totals->grossTotal->getAmount()->isGreaterThan(Flags::SMALL_AMOUNT_LIMIT)) {
                    $report->error('BT-112', 'totals.grossTotal', ['limit' => '250,00 EUR']);
                }
            }),
            $r('DE-USTG-09', 'A small business (section 19 UStG) must not charge VAT in the same invoice.', function (InvoiceData $i, RuleReporter $report): void {
                $lines = $i->allLines();
                $smallBusiness = array_filter($lines, static fn (InvoiceLine $l): bool => $l->vat->presetKey === VatPreset::SmallBusiness->value);
                if ($smallBusiness === []) {
                    return;
                }
                foreach ($lines as $line) {
                    if ($line->vat->category === VatCategory::StandardRate) {
                        $report->error('BT-151', 'lines');

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
        ];
    }

    /**
     * Plausibility of the Leitweg-ID of public buyers (level L7).
     *
     * @return list<Rule>
     */
    public static function plausibility(): array
    {
        CountryDE::registerTranslations();

        return [
            CallbackRule::l7('DE-LEITWEG-ID', 'The check digits of the Leitweg-ID ":value" (BT-10) are wrong.', function (InvoiceData $i, RuleReporter $report): void {
                $reference = $i->buyerReference;
                if (LeitwegId::looksLike($reference) && ! LeitwegId::isValid($reference)) {
                    $report->warning('BT-10', 'buyerReference', ['value' => (string) $reference]);
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
