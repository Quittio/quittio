<?php

declare(strict_types=1);

namespace Quittio\Core\Tax;

/**
 * A preset that requires a note on the invoice, e.g. a statement the law prescribes for the
 * special scheme of the preset. The builder adds the note once (BT-22, subject REG) when a line
 * uses the preset, unless the invoice has a note with the same text already.
 */
interface StatesInvoiceNote extends TaxPreset
{
    /**
     * The text of the note in the locale, or null for none.
     */
    public function invoiceNote(?string $locale = null): ?string;
}
