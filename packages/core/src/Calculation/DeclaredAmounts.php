<?php

declare(strict_types=1);

namespace Quittio\Core\Calculation;

use Brick\Math\BigDecimal;
use Brick\Money\Money;
use Exception;
use Quittio\Core\Data\AllowanceCharge\DocumentAllowanceCharge;
use Quittio\Core\Data\AllowanceCharge\LineAllowanceCharge;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Data\Lines\InvoiceLine;
use Quittio\Core\Data\Lines\LineVat;
use Quittio\Core\Data\Meta;
use Quittio\Core\Data\Totals\VatBreakdown;
use Quittio\Core\Enums\VatCategory;

/**
 * Declared amounts of an existing document (Meta::FLAG_DECLARED_AMOUNTS): whether they are
 * needed at all, and the fingerprint of the amount inputs they belong to.
 *
 * The fingerprint (Meta::amountsFingerprint(), set by the calculator on every result and by the
 * builder on data with amounts) covers per line (with sub lines) the identifier, quantity,
 * prices, base quantity, VAT category and rate, line allowances and charges and the net amount,
 * the document allowances and charges, the VAT breakdown and the currency. Data whose
 * fingerprint no longer matches was changed afterwards (e.g. with with() or in stored JSON):
 * its amounts are stale and never kept. Data without fingerprint (e.g. read from XML) is taken
 * as declared by its issuer.
 */
final class DeclaredAmounts
{
    /** Tolerance of a declared line net amount (BT-131), as PEPPOL-EN16931-R120 tolerates it. */
    public const string LINE_TOLERANCE = '0.02';

    /**
     * Tolerance of a declared VAT total in accounting currency (BT-111) against round2(BT-110 ×
     * exchange rate): EN 16931 does not check it, the cumulative rounding of partial
     * cancellations (Corrections\CancellationHistory) needs one cent.
     */
    public const string TAX_CURRENCY_TOLERANCE = '0.01';

    /**
     * Whether the recalculation does not reproduce the declared line net amounts (BT-131), the
     * declared VAT breakdown (BT-116, BT-117) or the declared VAT total in accounting currency
     * (BT-111) exactly, e.g. because another system rounded per line or a partial cancellation
     * aligns its VAT with earlier ones. Data calculated by Quittio never needs declared amounts.
     */
    public static function needed(InvoiceData $data, TotalsCalculator $calculator = new En16931Calculator): bool
    {
        if (! self::hasAmounts($data)) {
            return false;
        }

        try {
            $calculated = $calculator->calculate($data->with([
                'meta' => DeclaredAmountsState::fromMeta($data->meta)->withDeclared(false)->withFingerprint(null)->applyTo($data->meta),
            ]));
        } catch (Exception) {
            // Data the calculator rejects (e.g. amounts in another currency) cannot be reproduced;
            // errors such as a TypeError are programming errors and are not hidden.
            return true;
        }

        if (self::linesDiffer($data->lines, $calculated->lines) || self::taxCurrencyDiffers($data, $calculated)) {
            return true;
        }
        if ($data->vatBreakdown === []) {
            return false;
        }

        $declared = [];
        foreach ($data->vatBreakdown as $entry) {
            $declared[self::breakdownKey($entry->category, $entry->rate)] = $entry;
        }
        if (count($declared) !== count($data->vatBreakdown) || count($declared) !== count($calculated->vatBreakdown)) {
            return true;
        }
        foreach ($calculated->vatBreakdown as $entry) {
            $match = $declared[self::breakdownKey($entry->category, $entry->rate)] ?? null;
            if ($match === null
                || ! $match->taxableAmount->getAmount()->isEqualTo($entry->taxableAmount->getAmount())
                || ! $match->taxAmount->getAmount()->isEqualTo($entry->taxAmount->getAmount())) {
                return true;
            }
        }

        return false;
    }

    /**
     * Data whose amount inputs changed after Quittio calculated or declared its amounts (fingerprint
     * mismatch), or flagged with declared amounts without fingerprint.
     */
    public static function isStale(InvoiceData $data): bool
    {
        $state = DeclaredAmountsState::fromMeta($data->meta);

        return $state->fingerprint === null
            ? $state->isDeclared()
            : $state->fingerprint !== self::fingerprint($data);
    }

    /**
     * Data flagged with declared amounts whose amount inputs still match the fingerprint.
     */
    public static function isTrusted(InvoiceData $data): bool
    {
        $state = DeclaredAmountsState::fromMeta($data->meta);

        return $state->isDeclared() && $state->fingerprint === self::fingerprint($data);
    }

    public static function fingerprint(InvoiceData $data): string
    {
        $input = [
            'currency' => $data->currency,
            'lines' => array_map(self::line(...), $data->lines),
            'allowances' => array_map(self::documentAllowanceCharge(...), $data->allowances),
            'charges' => array_map(self::documentAllowanceCharge(...), $data->charges),
            'vatBreakdown' => array_map(static fn (VatBreakdown $entry): array => [
                self::breakdownKey($entry->category, $entry->rate),
                self::money($entry->taxableAmount),
                self::money($entry->taxAmount),
            ], $data->vatBreakdown),
        ];

        return hash('xxh128', (string) json_encode($input, JSON_THROW_ON_ERROR));
    }

    /**
     * VAT group key of a breakdown; categories without rate (O) ignore a rate written as 0.
     */
    public static function breakdownKey(VatCategory $category, ?BigDecimal $rate): string
    {
        return LineVat::key($category, $category->requiresRate() ? ($rate ?? BigDecimal::zero()) : null);
    }

    /**
     * Whether the data carries line net amounts or a VAT breakdown.
     */
    public static function hasAmounts(InvoiceData $data): bool
    {
        return $data->vatBreakdown !== [] || self::hasLineAmounts($data->lines);
    }

    /**
     * @param  list<InvoiceLine>  $lines
     */
    private static function hasLineAmounts(array $lines): bool
    {
        foreach ($lines as $line) {
            if ($line->netAmount !== null || self::hasLineAmounts($line->subLines)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A declared BT-111 (Meta::declaredVatInTaxCurrency(), or of the totals as long as the
     * recalculation reproduces their BT-110) that the recalculation does not reproduce.
     */
    private static function taxCurrencyDiffers(InvoiceData $declared, InvoiceData $calculated): bool
    {
        $amount = DeclaredAmountsState::fromMeta($declared->meta)->vatInTaxCurrency;
        $vat = $declared->totals?->vatTotal;
        $recalculated = $calculated->totals?->vatTotal;
        if ($amount === null && $vat !== null && $recalculated !== null && $vat->getAmount()->isEqualTo($recalculated->getAmount())) {
            $amount = $declared->totals?->vatTotalInTaxCurrency?->getAmount();
        }
        $other = $calculated->totals?->vatTotalInTaxCurrency;

        return $amount !== null && ($other === null || ! $other->getAmount()->isEqualTo($amount));
    }

    /**
     * @param  list<InvoiceLine>  $declared
     * @param  list<InvoiceLine>  $calculated
     */
    private static function linesDiffer(array $declared, array $calculated): bool
    {
        foreach ($declared as $index => $line) {
            $other = $calculated[$index] ?? null;
            if ($other === null) {
                return true;
            }
            if ($line->netAmount !== null && ($other->netAmount === null || ! $line->netAmount->getAmount()->isEqualTo($other->netAmount->getAmount()))) {
                return true;
            }
            if (self::linesDiffer($line->subLines, $other->subLines)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int|string, mixed>
     */
    private static function line(InvoiceLine $line): array
    {
        $price = $line->price;

        return [
            $line->id,
            self::decimal($line->quantity),
            self::decimal($price->netPrice),
            self::decimal($price->grossPrice),
            self::decimal($price->discount),
            self::decimal($price->priceInclVat),
            self::decimal($price->baseQuantity),
            $line->vat->groupKey(),
            array_map(self::lineAllowanceCharge(...), $line->allowances),
            array_map(self::lineAllowanceCharge(...), $line->charges),
            self::money($line->netAmount),
            array_map(self::line(...), $line->subLines),
        ];
    }

    /**
     * @return list<string|null>
     */
    private static function lineAllowanceCharge(LineAllowanceCharge $entry): array
    {
        return [self::money($entry->amount), self::money($entry->baseAmount), self::decimal($entry->percent)];
    }

    /**
     * @return list<string|null>
     */
    private static function documentAllowanceCharge(DocumentAllowanceCharge $entry): array
    {
        return [self::money($entry->amount), self::money($entry->baseAmount), self::decimal($entry->percent), LineVat::key($entry->vatCategory, $entry->vatRate)];
    }

    private static function decimal(?BigDecimal $value): ?string
    {
        return $value?->strippedOfTrailingZeros()->toString();
    }

    private static function money(?Money $value): ?string
    {
        return $value === null ? null : $value->getAmount()->strippedOfTrailingZeros()->toString().' '.$value->getCurrency()->getCurrencyCode();
    }
}
