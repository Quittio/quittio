<?php

declare(strict_types=1);

namespace Quittio\Core\Xml;

use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Data\Payment\CashDiscount;
use Quittio\Core\I18n\Translations;
use Quittio\Core\Profiles\Capabilities\StructuredCashDiscountText;
use Quittio\Core\Profiles\Profile;
use Quittio\Core\Support\Amount;

/**
 * Builds the payment terms text (BT-20) from structured payment terms.
 *
 * Cash discounts are a mandatory statement (agreed reduction of the consideration),
 * so they must appear in the XML and not only in the PDF.
 */
final class PaymentTermsText
{
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

        if ($profile instanceof StructuredCashDiscountText) {
            $text = $lines === [] ? '' : implode("\n", $lines)."\n";
            foreach ($terms->discounts as $discount) {
                $text .= self::structured($discount)."\n";
            }

            return $text === '' ? null : $text;
        }

        if ($terms->text === null && $terms->dueDays !== null) {
            $lines[] = Translations::translator()->get('quittio::terms.due_in_days', ['days' => $terms->dueDays], $locale);
        }
        foreach ($terms->discounts as $discount) {
            $lines[] = Translations::translator()->get('quittio::terms.cash_discount', [
                'percent' => Format::percent($discount->percent),
                'days' => $discount->days,
                'until' => $invoice->issueDate->addDays($discount->days)->toIsoString(),
            ], $locale);
        }
        if ($terms->lateFeeTerms?->text !== null) {
            $lines[] = $terms->lateFeeTerms->text;
        }

        return $lines === [] ? null : implode("\n", $lines);
    }

    /**
     * XRechnung notation: #SKONTO#TAGE=14#PROZENT=2.00# with optional #BASISBETRAG=…#.
     */
    public static function structured(CashDiscount $discount): string
    {
        $line = sprintf('#SKONTO#TAGE=%d#PROZENT=%s#', $discount->days, Format::percent($discount->percent));
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
