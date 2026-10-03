<?php

declare(strict_types=1);

namespace Quittio\Core\Formats;

use BackedEnum;
use Brick\Math\BigNumber;
use Brick\Money\Money;
use Quittio\Core\Codes\RawCode;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Mapping\FieldMatrix;
use Quittio\Core\Support\Date;

/**
 * Detects data that only the profile EXTENDED can carry (sub lines, EXTENDED-only fields).
 */
final class ExtendedFeatures
{
    public static function used(InvoiceData $invoice): bool
    {
        foreach ($invoice->lines as $line) {
            if ($line->subLines !== []) {
                return true;
            }
        }

        return self::walk($invoice);
    }

    private static function walk(object $dto): bool
    {
        $values = get_object_vars($dto);
        foreach (FieldMatrix::fields($dto::class) as $name => $field) {
            $value = $values[$name] ?? null;
            if ($value === null || $value === '' || $value === [] || $field->pdfOnly) {
                continue;
            }
            if ($field->extendedOnly) {
                return true;
            }
            foreach (is_array($value) ? $value : [$value] as $child) {
                if (self::isDto($child) && self::walk($child)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @phpstan-assert-if-true object $value
     */
    private static function isDto(mixed $value): bool
    {
        return is_object($value)
            && ! $value instanceof Money
            && ! $value instanceof BigNumber
            && ! $value instanceof Date
            && ! $value instanceof BackedEnum
            && ! $value instanceof RawCode;
    }
}
