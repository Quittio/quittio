<?php

declare(strict_types=1);

namespace Quittio\Core\Validation\Rules;

use Brick\Math\BigDecimal;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Data\Lines\InvoiceLine;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Profiles\ProfileLevel;

/**
 * Helpers shared by the rule sets.
 */
final class Inspect
{
    /**
     * VAT categories used by lines, document allowances and charges.
     *
     * @return list<VatCategory>
     */
    public static function categories(InvoiceData $invoice): array
    {
        $categories = [];
        foreach ($invoice->lines as $line) {
            $categories[$line->vat->category->value] = $line->vat->category;
        }
        foreach ([...$invoice->allowances, ...$invoice->charges] as $entry) {
            $categories[$entry->vatCategory->value] = $entry->vatCategory;
        }

        return array_values($categories);
    }

    /**
     * MINIMUM and BASIC WL carry no invoice lines (BG-25): line based rules do not apply to
     * documents of these profiles without lines, e.g. when they are read.
     */
    public static function withoutLinesByProfile(InvoiceData $invoice, RuleReporter $report): bool
    {
        return $invoice->lines === [] && ! $report->context()->profile->level()->includes(ProfileLevel::Basic);
    }

    /**
     * Whether a line, a document allowance or a document charge has the VAT category $category.
     */
    public static function uses(InvoiceData $invoice, VatCategory $category): bool
    {
        return in_array($category, self::categories($invoice), true);
    }

    /**
     * @return list<InvoiceLine>
     */
    public static function linesIn(InvoiceData $invoice, VatCategory $category): array
    {
        return array_values(array_filter($invoice->lines, static fn (InvoiceLine $l): bool => $l->vat->category === $category));
    }

    /**
     * Whether $value is not null and not empty after trimming whitespace.
     */
    public static function filled(?string $value): bool
    {
        return $value !== null && trim($value) !== '';
    }

    /**
     * Whether $value is null or zero.
     */
    public static function isZero(?BigDecimal $value): bool
    {
        return $value === null || $value->isZero();
    }

    /**
     * Country prefix of a VAT identifier; Greece uses "EL".
     */
    public static function vatPrefix(string $vatId): string
    {
        return strtoupper(substr(trim($vatId), 0, 2));
    }
}
