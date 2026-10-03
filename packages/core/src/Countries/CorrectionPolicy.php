<?php

declare(strict_types=1);

namespace Quittio\Core\Countries;

/**
 * Default handling of cancellations and corrections in a country (used by the Managed layer).
 */
final readonly class CorrectionPolicy
{
    public const string CANCELLATION_CREDIT_NOTE = 'credit_note';

    public const string CANCELLATION_NEGATIVE_INVOICE = 'negative_invoice';

    public const string CORRECTION_CANCEL_AND_REISSUE = 'cancel_and_reissue';

    public const string CORRECTION_CORRECTED_INVOICE = 'corrected_invoice';

    /**
     * @param  array<string, array<string, string>>  $titles  document kind => [locale => title]
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
}
