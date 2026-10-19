<?php

declare(strict_types=1);

namespace Quittio\Country\CH\Rules;

use Closure;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Data\Lines\InvoiceLine;
use Quittio\Core\Validation\Rule;
use Quittio\Core\Validation\Rules\CallbackRule;
use Quittio\Core\Validation\Rules\Inspect;
use Quittio\Core\Validation\Rules\RuleReporter;
use Quittio\Country\CH\CountryCH;
use Quittio\Country\CH\Tax\TaxTables;
use Quittio\Country\CH\Tax\VatPreset;

/**
 * Elements of an invoice according to the Swiss VAT act (Art. 26 (2) MWSTG), with the till
 * receipt of Art. 57 MWSTV up to CHF 400 that needs no recipient.
 *
 * These rules apply to every invoice of a Swiss seller, independent of the format. Not reviewed
 * by a tax adviser yet (see the README of the pack).
 */
final class MwstgRules
{
    private const string NS = CountryCH::TRANSLATION_NAMESPACE;

    /** A UID with the note of the VAT registration, e.g. "CHE-123.456.789 MWST". */
    private const string UID_WITH_NOTE = '/^CHE-?\d{3}\.?\d{3}\.?\d{3}\s*(MWST|TVA|IVA)$/i';

    /**
     * @return list<Rule>
     */
    public static function all(): array
    {
        CountryCH::registerTranslations();
        $r = static fn (string $code, string $fallback, Closure $check): Rule => CallbackRule::l3($code, $fallback, $check, self::NS);

        return [
            $r('CH-MWSTG-01', 'The seller\'s name and place (BT-37) must be stated (Art. 26 (2) lit. a MWSTG).', function (InvoiceData $i, RuleReporter $report): void {
                if (! Inspect::filled($i->seller->address->city)) {
                    $report->error('BT-37', 'seller.address.city');
                }
            }),
            $r('CH-MWSTG-02', 'An invoice that charges VAT must state the seller\'s UID number under which it is registered for VAT (BT-31, Art. 26 (2) lit. a MWSTG).', function (InvoiceData $i, RuleReporter $report): void {
                if (self::chargesVat($i) && ! Inspect::filled($i->seller->vatId) && ! Inspect::filled($i->sellerTaxRepresentative?->vatId)) {
                    $report->error('BT-31', 'seller.vatId');
                }
            }),
            $r('CH-MWSTG-03', 'The UID number should carry the note of the VAT registration, e.g. "CHE-123.456.789 MWST" (Art. 26 (2) lit. a MWSTG).', function (InvoiceData $i, RuleReporter $report): void {
                $vatId = trim((string) $i->seller->vatId);
                if ($vatId !== '' && str_starts_with(strtoupper($vatId), 'CHE') && preg_match(self::UID_WITH_NOTE, $vatId) !== 1) {
                    $report->warning('BT-31', 'seller.vatId');
                }
            }),
            $r('CH-MWSTG-04', 'The buyer\'s name and place (BT-52) must be stated (Art. 26 (2) lit. b MWSTG); only till receipts up to CHF 400 may leave them out (Art. 57 MWSTV).', function (InvoiceData $i, RuleReporter $report): void {
                if (! self::isTillReceipt($i) && ! Inspect::filled($i->buyer->address->city)) {
                    $report->error('BT-52', 'buyer.address.city');
                }
            }),
            $r('CH-MWSTG-05', 'A seller that is not registered for VAT must not charge VAT; VAT shown is owed by reason of the invoice (Art. 27 (2) MWSTG).', function (InvoiceData $i, RuleReporter $report): void {
                $notRegistered = array_filter($i->allLines(), static fn (InvoiceLine $l): bool => $l->vat->presetKey === VatPreset::NotRegistered->value) !== [];
                if (! $notRegistered) {
                    return;
                }
                foreach ($i->vatBreakdown as $index => $row) {
                    if (! Inspect::isZero($row->taxAmount->getAmount())) {
                        $report->error('BT-117', 'vatBreakdown['.$index.'].taxAmount');
                    }
                }
            }),
        ];
    }

    /**
     * A till receipt (Art. 57 MWSTV): an invoice in CHF of up to CHF 400 gross.
     */
    public static function isTillReceipt(InvoiceData $invoice): bool
    {
        $gross = $invoice->totals?->grossTotal;

        return $gross !== null && $invoice->currency === 'CHF' && ! $gross->getAmount()->isGreaterThan(TaxTables::TILL_RECEIPT_LIMIT);
    }

    /**
     * Whether the invoice shows VAT: a VAT amount other than zero.
     */
    private static function chargesVat(InvoiceData $invoice): bool
    {
        foreach ($invoice->vatBreakdown as $row) {
            if (! Inspect::isZero($row->taxAmount->getAmount())) {
                return true;
            }
        }

        return false;
    }
}
