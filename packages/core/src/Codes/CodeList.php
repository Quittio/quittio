<?php

declare(strict_types=1);

namespace Quittio\Core\Codes;

use BackedEnum;
use InvalidArgumentException;

/**
 * Access to the official code lists generated from the EN 16931 rules
 * (resources/codelists/*.json, see tools/codelists/update.php).
 */
final class CodeList
{
    public const string INVOICE_TYPES = 'invoice-type-codes';

    public const string CURRENCIES = 'currency-codes';

    public const string COUNTRIES = 'country-codes';

    public const string ICD = 'icd-schemes';

    public const string EAS = 'eas-codes';

    public const string UNITS = 'unit-codes';

    public const string VATEX = 'vatex-codes';

    public const string PAYMENT_MEANS = 'payment-means-codes';

    public const string ALLOWANCE_REASONS = 'allowance-reason-codes';

    public const string CHARGE_REASONS = 'charge-reason-codes';

    public const string NOTE_SUBJECTS = 'note-subject-codes';

    public const string ITEM_CLASSIFICATIONS = 'item-classification-schemes';

    public const string OBJECT_IDENTIFIERS = 'object-identifier-schemes';

    public const string MIME_TYPES = 'mime-codes';

    public const string VAT_CATEGORIES = 'vat-category-codes';

    /** @var array<string, array<string, true>> */
    private static array $cache = [];

    public static function contains(string $list, BackedEnum|RawCode|string $code): bool
    {
        $value = match (true) {
            $code instanceof BackedEnum => (string) $code->value,
            $code instanceof RawCode => $code->value,
            default => $code,
        };

        return isset(self::load($list)[$value]);
    }

    /**
     * @return list<string>
     */
    public static function codes(string $list): array
    {
        return array_map(strval(...), array_keys(self::load($list)));
    }

    /**
     * @return array<string, true>
     */
    private static function load(string $list): array
    {
        if (isset(self::$cache[$list])) {
            return self::$cache[$list];
        }

        $file = dirname(__DIR__, 2).'/resources/codelists/'.$list.'.json';
        if (! is_file($file)) {
            throw new InvalidArgumentException(sprintf('Unknown code list "%s".', $list));
        }

        $data = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        $codes = is_array($data) && isset($data['codes']) && is_array($data['codes']) ? $data['codes'] : [];

        $set = [];
        foreach ($codes as $code) {
            if (is_string($code)) {
                $set[$code] = true;
            }
        }

        return self::$cache[$list] = $set;
    }
}
