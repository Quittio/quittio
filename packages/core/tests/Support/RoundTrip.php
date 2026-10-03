<?php

declare(strict_types=1);

namespace Quittio\Core\Tests\Support;

use Quittio\Core\Data\InvoiceData;

/**
 * Projection of invoice data onto what an XML round trip can preserve.
 *
 * Package-internal inputs (presets, locale, PDF data, gross price inputs) and the
 * structured payment terms (written as text) are not part of the XML.
 */
final class RoundTrip
{
    /**
     * @return array<string, mixed>
     */
    public static function comparable(InvoiceData $invoice): array
    {
        $data = $invoice->toArray();
        unset($data['meta'], $data['presentation'], $data['paymentTerms'], $data['prepaidAmount'], $data['roundingAmount']);
        unset($data['buyer']['isConsumer']);

        $data['lines'] = array_map(self::line(...), $data['lines']);

        return self::numbers($data);
    }

    /**
     * Decimal strings are compared by value ("19" equals "19.00").
     */
    public static function numbers(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(self::numbers(...), $value);
        }
        if (is_string($value) && preg_match('/^-?\d+\.\d+$/', $value) === 1) {
            return rtrim(rtrim($value, '0'), '.');
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private static function line(array $line): array
    {
        unset($line['vat']['exemptionReason'], $line['vat']['exemptionReasonCode'], $line['vat']['presetKey'], $line['price']['priceInclVat'], $line['presentation']);
        if (isset($line['subLines'])) {
            $line['subLines'] = array_map(self::line(...), $line['subLines']);
        }

        return $line;
    }
}
