<?php

declare(strict_types=1);

namespace Quittio\Core\View;

use Quittio\Core\Countries\CountryPack;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Enums\InvoiceTypeCode;

/**
 * Label key of the document title for a type code (BT-3).
 *
 * Codes without an own title use the title of their family: self-billed documents are a
 * "Gutschrift" (section 14 (2) sentence 5 UStG), commercial credit notes share the title of
 * code 381, which must not be "Gutschrift" (BMF letter of 25 October 2013).
 */
final class InvoiceTitle
{
    /** Type codes with an own title label ("title_{code}"). */
    public const array CODES = ['380', '381', '384', '389', '325', '326', '386', '875', '876', '877'];

    public static function key(InvoiceData $invoice): string
    {
        return self::keyFor($invoice->typeCodeValue());
    }

    public static function keyFor(string $typeCode): string
    {
        if (in_array($typeCode, self::CODES, true)) {
            return 'title_'.$typeCode;
        }

        $type = InvoiceTypeCode::tryFrom($typeCode);

        return match (true) {
            $type?->isSelfBilled() === true => 'title_389',
            $type?->isCreditNote() === true => 'title_381',
            default => 'title_default',
        };
    }

    /**
     * Title of a self-billed document from the correction policy of the country pack
     * (CorrectionPolicy title "self_billed", e.g. "Gutschrift"), like the titles of cancellations
     * and corrections; null for other documents or without such a title (then the label of the
     * type code applies). A title of the presentation wins over both.
     */
    public static function policyTitle(InvoiceData $invoice, ?CountryPack $country, string $locale): ?string
    {
        if (! $invoice->typeCode instanceof InvoiceTypeCode || ! $invoice->typeCode->isSelfBilled()) {
            return null;
        }

        return $country?->correctionPolicy()->title('self_billed', $locale);
    }

    /**
     * @return list<string> all title label keys
     */
    public static function keys(): array
    {
        return [...array_map(static fn (string $code): string => 'title_'.$code, self::CODES), 'title_default'];
    }
}
