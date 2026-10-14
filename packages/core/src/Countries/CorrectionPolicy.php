<?php

declare(strict_types=1);

namespace Quittio\Core\Countries;

/**
 * Default handling of cancellations and corrections in a country, and the titles of these
 * documents. Quittio::cancellation() takes the cancellation strategy and the titles
 * "cancellation" and "cancellation_self_billed"; the title "self_billed" is the title of
 * self-billed invoices in PDF and HTML (InvoiceTitle::policyTitle()); the Managed layer takes
 * the correction strategy and the title "correction".
 */
final readonly class CorrectionPolicy
{
    public const string CANCELLATION_CREDIT_NOTE = 'credit_note';

    public const string CANCELLATION_NEGATIVE_INVOICE = 'negative_invoice';

    public const string CORRECTION_CANCEL_AND_REISSUE = 'cancel_and_reissue';

    public const string CORRECTION_CORRECTED_INVOICE = 'corrected_invoice';

    /**
     * @param  array<string, array<string, string>>  $titles  document kind (cancellation, cancellation_self_billed,
     *         correction, self_billed) => [locale => title]; a missing locale falls back to "en"
     */
    public function __construct(
        public string $cancellationStrategy = self::CANCELLATION_CREDIT_NOTE,
        public string $correctionStrategy = self::CORRECTION_CANCEL_AND_REISSUE,
        public array $titles = [],
    ) {}

    public function title(string $kind, string $locale = 'en'): ?string
    {
        return $this->titles[$kind][$locale] ?? $this->titles[$kind]['en'] ?? null;
    }

    /**
     * Title of a cancellation; self-billed originals use "cancellation_self_billed" if the
     * country defines it, otherwise "cancellation".
     */
    public function cancellationTitle(bool $selfBilled, string $locale = 'en'): ?string
    {
        return ($selfBilled ? $this->title('cancellation_self_billed', $locale) : null) ?? $this->title('cancellation', $locale);
    }
}
