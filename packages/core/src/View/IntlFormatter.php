<?php

declare(strict_types=1);

namespace Quittio\Core\View;

use Brick\Math\BigDecimal;
use Brick\Money\Money;
use IntlDateFormatter;
use NumberFormatter;
use Quittio\Core\Support\Date;
use Quittio\Core\Support\Decimal;

/**
 * Locale aware formatting with ext-intl and a fallback for German and English conventions.
 *
 * Numbers are formatted from their exact decimal string, never via float.
 */
final class IntlFormatter implements Formatter
{
    /**
     * Formats the amount with two decimals, grouped by the locale, and the currency: a symbol for
     * EUR, USD and GBP (in front for USD and GBP in English locales, otherwise behind), else the
     * code.
     */
    public function money(Money $money, string $locale): string
    {
        return $this->withCurrency(
            $this->decimal($money->getAmount(), 2, 2, $locale),
            $money->getCurrency()->getCurrencyCode(),
            $locale,
        );
    }

    public function price(BigDecimal $price, string $currency, string $locale): string
    {
        $scale = max(2, $price->strippedOfTrailingZeros()->getScale());

        return $this->withCurrency($this->decimal($price, $scale, $scale, $locale), $currency, $locale);
    }

    /**
     * Formats the quantity with the locale's separators and as many decimals as it has without
     * trailing zeros, e.g. "1.250,5" for locale de.
     */
    public function quantity(BigDecimal $quantity, string $locale): string
    {
        $scale = max(0, $quantity->strippedOfTrailingZeros()->getScale());

        return $this->decimal($quantity, 0, $scale, $locale);
    }

    /**
     * Formats the percentage without trailing zeros, e.g. "19%" in English locales and "19 %" (with
     * a no-break space) in others.
     */
    public function percent(BigDecimal $percent, string $locale): string
    {
        $scale = max(0, $percent->strippedOfTrailingZeros()->getScale());
        $number = $this->decimal($percent, 0, $scale, $locale);

        return str_starts_with($locale, 'en') ? $number.'%' : $number."\u{00A0}%";
    }

    /**
     * Formats the date as dd.MM.yyyy for de, dd/MM/yyyy for fr, it, es, pt, nl and en, and MM/dd/yyyy
     * for en_US; other locales use the ICU medium format. Without ext-intl d.m.Y for de, otherwise
     * d/m/Y.
     */
    public function date(Date $date, string $locale): string
    {
        if (class_exists(IntlDateFormatter::class)) {
            $pattern = match (substr($locale, 0, 2)) {
                'de', 'at', 'ch' => 'dd.MM.yyyy',
                'fr', 'it', 'es', 'pt', 'nl', 'be' => 'dd/MM/yyyy',
                'en' => $locale === 'en_US' ? 'MM/dd/yyyy' : 'dd/MM/yyyy',
                default => null,
            };
            $formatter = new IntlDateFormatter($locale, IntlDateFormatter::MEDIUM, IntlDateFormatter::NONE, 'UTC', null, $pattern);
            $formatted = $formatter->format($date->toCarbon('UTC')->getTimestamp());
            if (is_string($formatted)) {
                return $formatted;
            }
        }

        return str_starts_with($locale, 'de') ? $date->format('d.m.Y') : $date->format('d/m/Y');
    }

    /**
     * Groups and localises a decimal string without converting it to float.
     *
     * @param  int<0, max>  $minScale
     * @param  int<0, max>  $maxScale
     */
    private function decimal(BigDecimal $value, int $minScale, int $maxScale, string $locale): string
    {
        $rounded = Decimal::round($value, $maxScale);
        $plain = Decimal::format($rounded, $minScale);
        $negative = str_starts_with($plain, '-');
        $plain = ltrim($plain, '-');
        [$integer, $fraction] = array_pad(explode('.', $plain, 2), 2, '');

        [$group, $separator] = $this->separators($locale);
        // Groups of three digits from the right; the separator may be multibyte (not reversed).
        $integer = implode($group, array_reverse(array_map(strrev(...), str_split(strrev($integer), 3))));

        return ($negative ? '-' : '').$integer.($fraction === '' ? '' : $separator.$fraction);
    }

    /**
     * @return array{0: string, 1: string} grouping and decimal separator
     */
    private function separators(string $locale): array
    {
        if (class_exists(NumberFormatter::class)) {
            $formatter = new NumberFormatter($locale, NumberFormatter::DECIMAL);
            $group = $formatter->getSymbol(NumberFormatter::GROUPING_SEPARATOR_SYMBOL);
            $decimal = $formatter->getSymbol(NumberFormatter::DECIMAL_SEPARATOR_SYMBOL);
            if ($group !== false && $decimal !== false && $decimal !== '') {
                // ICU groups French numbers with a narrow no-break space, which not every font has.
                return [str_replace("\u{202F}", "\u{00A0}", $group), $decimal];
            }
        }

        return str_starts_with($locale, 'de') ? ['.', ','] : [',', '.'];
    }

    private function withCurrency(string $number, string $currency, string $locale): string
    {
        $symbol = match ($currency) {
            'EUR' => '€',
            'USD' => '$',
            'GBP' => '£',
            'CHF' => 'CHF',
            default => $currency,
        };

        if (str_starts_with($locale, 'en') && in_array($currency, ['USD', 'GBP'], true)) {
            return str_starts_with($number, '-') ? '-'.$symbol.substr($number, 1) : $symbol.$number;
        }

        return $number."\u{00A0}".$symbol;
    }
}
