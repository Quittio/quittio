<?php

declare(strict_types=1);

namespace Quittio\Core\Xml;

use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Data\Payment\CashDiscount;
use Quittio\Core\I18n\Translations;
use Quittio\Core\Profiles\Capabilities\StructuredCashDiscountText;
use Quittio\Core\Profiles\Profile;
use Quittio\Core\Support\Amount;
use Quittio\Core\Support\Decimal;
use Quittio\Core\View\IntlFormatter;

/**
 * Builds the payment terms text (BT-20) from structured payment terms.
 *
 * Cash discounts are a mandatory statement (agreed reduction of the consideration),
 * so they must appear in the XML and not only in the PDF.
 */
final class PaymentTermsText
{
    /**
     * Payment terms text (BT-20) for the XML in $locale: the free text, otherwise "due in n days",
     * then one line per cash discount and the late fee terms. Profiles with
     * StructuredCashDiscountText (e.g. XRechnung) get the cash discounts last, as #SKONTO# lines
     * each ended by a line break. Null without payment terms or without any text.
     */
    public static function render(InvoiceData $invoice, Profile $profile, string $locale): ?string
    {
        $terms = $invoice->paymentTerms;
        if ($terms === null) {
            return null;
        }

        $lines = [];
        if ($terms->text !== null && trim($terms->text) !== '') {
            $lines[] = trim($terms->text);
        }
        if ($terms->text === null && $terms->dueDays !== null) {
            $lines[] = Translations::translator()->get('quittio::terms.due_in_days', ['days' => $terms->dueDays], $locale);
        }

        if ($profile instanceof StructuredCashDiscountText) {
            // Free text first, then one line per cash discount, each closed by a line break (BR-DE-18).
            if ($terms->lateFeeTerms?->text !== null && trim($terms->lateFeeTerms->text) !== '') {
                $lines[] = trim($terms->lateFeeTerms->text);
            }
            $text = $lines === [] ? '' : implode("\n", $lines)."\n";
            foreach ($terms->discounts as $discount) {
                $text .= self::structured($discount)."\n";
            }

            return $text === '' ? null : $text;
        }

        // German texts use German number and date formats; other texts keep the neutral
        // notation (2.00, 2026-10-17). The #SKONTO# lines above keep their fixed syntax.
        $german = str_starts_with(strtolower($locale), 'de') && Translations::translator()->has('quittio::terms.cash_discount', $locale);
        $formatter = new IntlFormatter;
        foreach ($terms->discounts as $discount) {
            $until = $invoice->issueDate->addDays($discount->days);
            $lines[] = Translations::translator()->get('quittio::terms.cash_discount', [
                'percent' => $german ? $formatter->quantity($discount->percent, $locale) : Format::percent($discount->percent),
                'days' => $discount->days,
                'until' => $german ? $formatter->date($until, $locale) : $until->toIsoString(),
            ], $locale);
        }
        if ($terms->lateFeeTerms?->text !== null) {
            $lines[] = $terms->lateFeeTerms->text;
        }

        return $lines === [] ? null : implode("\n", $lines);
    }

    /**
     * XRechnung notation: #SKONTO#TAGE=14#PROZENT=2.00# with optional #BASISBETRAG=…#.
     * The percentage has exactly two decimals (BR-DE-18).
     */
    public static function structured(CashDiscount $discount): string
    {
        $line = sprintf('#SKONTO#TAGE=%d#PROZENT=%s#', $discount->days, Decimal::round($discount->percent, 2)->toString());
        if ($discount->baseAmount !== null) {
            $line .= 'BASISBETRAG='.Amount::format($discount->baseAmount).'#';
        }

        return $line;
    }

    /**
     * Parses structured cash discount lines from a BT-20 text.
     *
     * @return array{text: ?string, discounts: list<array{days: int, percent: string, base: ?string}>}
     */
    public static function parse(?string $text): array
    {
        if ($text === null) {
            return ['text' => null, 'discounts' => []];
        }

        $discounts = [];
        $remaining = [];
        $lines = preg_split('/\R/', $text);
        foreach ($lines === false ? [] : $lines as $line) {
            if (preg_match('/^#SKONTO#TAGE=(\d+)#PROZENT=(\d+(?:\.\d+)?)#(?:BASISBETRAG=(-?\d+(?:\.\d+)?)#)?\s*$/', trim($line), $m) === 1) {
                $discounts[] = ['days' => (int) $m[1], 'percent' => $m[2], 'base' => $m[3] ?? null];
            } elseif (trim($line) !== '') {
                $remaining[] = $line;
            }
        }

        return ['text' => $remaining === [] ? null : implode("\n", $remaining), 'discounts' => $discounts];
    }
}
