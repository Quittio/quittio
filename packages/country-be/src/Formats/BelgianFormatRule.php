<?php

declare(strict_types=1);

namespace Quittio\Country\BE\Formats;

use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Data\Lines\InvoiceLine;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Formats\FormatChoice;
use Quittio\Core\Formats\FormatRegistry;
use Quittio\Core\Formats\FormatRule;
use Quittio\Country\BE\Rules\VatCodeRules;
use Quittio\Country\BE\Tax\VatPreset;

/**
 * Since 1 January 2026 invoices between businesses established in Belgium are structured
 * e-invoices, by default Peppol BIS Billing 3.0 (law of 6 February 2024): domestic invoices to
 * businesses get the format "peppol". Not for consumers and not for businesses that only perform
 * exempt supplies without input tax deduction (Art. 44 VAT Code).
 */
final readonly class BelgianFormatRule implements FormatRule
{
    public function resolve(InvoiceData $invoice): ?FormatChoice
    {
        if ($invoice->buyer->isConsumer || ! VatCodeRules::inBelgium($invoice->seller->address) || ! VatCodeRules::inBelgium($invoice->buyer->address) || self::onlyExempt($invoice)) {
            return null;
        }

        return new FormatChoice(FormatRegistry::PEPPOL, 'peppol', 'Domestic invoice between businesses in Belgium: Peppol BIS Billing 3.0 (e-invoicing obligation since 2026).');
    }

    /**
     * Only supplies exempt without input tax deduction (category E), except the small business scheme.
     */
    private static function onlyExempt(InvoiceData $invoice): bool
    {
        $lines = $invoice->allLines();

        return $lines !== [] && array_filter($lines, static fn (InvoiceLine $l): bool => $l->vat->category !== VatCategory::Exempt || $l->vat->presetKey === VatPreset::SmallBusiness->value) === [];
    }
}
