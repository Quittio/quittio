<?php

declare(strict_types=1);

namespace Quittio\Core\Reading;

use Brick\Math\BigDecimal;
use Quittio\Core\Data\AllowanceCharge\DocumentAllowanceCharge;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Data\Lines\InvoiceLine;
use Quittio\Core\Data\Lines\LineVat;
use Quittio\Core\Data\Totals\VatBreakdown;
use Quittio\Core\Enums\VatCategory;

/**
 * EN 16931 carries the VAT exemption reason (BT-120, BT-121) only on the VAT breakdown,
 * the data model takes it from the lines (LineVat) and document allowances and charges.
 * Readers copy the declared reasons back, so that a recalculation keeps them.
 */
final class DeclaredExemptionReasons
{
    /**
     * Copies the VAT exemption reasons (BT-120, BT-121) of the VAT breakdown to the lines, sub
     * lines and document allowances and charges of the same category and rate that have none.
     * Returns the invoice unchanged if the breakdown declares no reason.
     */
    public static function apply(InvoiceData $invoice): InvoiceData
    {
        /** @var array<string, VatBreakdown> $reasons */
        $reasons = [];
        foreach ($invoice->vatBreakdown as $breakdown) {
            if (! $breakdown->category->requiresExemptionReason()
                || ($breakdown->exemptionReason === null && $breakdown->exemptionReasonCode === null)) {
                continue;
            }
            $reasons[self::key($breakdown->category, $breakdown->rate)] ??= $breakdown;
        }

        if ($reasons === []) {
            return $invoice;
        }

        return $invoice->with([
            'lines' => array_map(static fn (InvoiceLine $line): InvoiceLine => self::line($line, $reasons), $invoice->lines),
            'allowances' => array_map(static fn (DocumentAllowanceCharge $entry) => self::entry($entry, $reasons), $invoice->allowances),
            'charges' => array_map(static fn (DocumentAllowanceCharge $entry) => self::entry($entry, $reasons), $invoice->charges),
        ]);
    }

    /**
     * @param  array<string, VatBreakdown>  $reasons
     */
    private static function line(InvoiceLine $line, array $reasons): InvoiceLine
    {
        $vat = $line->vat;
        $breakdown = $reasons[self::key($vat->category, $vat->rate)] ?? null;
        if ($breakdown !== null && $vat->exemptionReason === null && $vat->exemptionReasonCode === null) {
            $vat = $vat->with([
                'exemptionReason' => $breakdown->exemptionReason,
                'exemptionReasonCode' => $breakdown->exemptionReasonCode,
            ]);
        }

        return $line->with([
            'vat' => $vat,
            'subLines' => array_map(static fn (InvoiceLine $sub): InvoiceLine => self::line($sub, $reasons), $line->subLines),
        ]);
    }

    /**
     * @template T of DocumentAllowanceCharge
     *
     * @param  T  $entry
     * @param  array<string, VatBreakdown>  $reasons
     * @return T
     */
    private static function entry(DocumentAllowanceCharge $entry, array $reasons): DocumentAllowanceCharge
    {
        $breakdown = $reasons[self::key($entry->vatCategory, $entry->vatRate)] ?? null;
        if ($breakdown === null || $entry->exemptionReason !== null || $entry->exemptionReasonCode !== null) {
            return $entry;
        }

        return $entry->with([
            'exemptionReason' => $breakdown->exemptionReason,
            'exemptionReasonCode' => $breakdown->exemptionReasonCode,
        ]);
    }

    /**
     * Group key; category "O" has no rate, even if a profile wrote BT-119 as 0.
     */
    private static function key(VatCategory $category, ?BigDecimal $rate): string
    {
        return LineVat::key($category, $category->requiresRate() ? $rate : null);
    }
}
