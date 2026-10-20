<?php

declare(strict_types=1);

namespace Quittio\Core\Countries;

use Closure;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\I18n\ArrayTranslator;

/**
 * Default handling of cancellations and corrections in a country, and the titles of these
 * documents. Quittio::cancellation() takes the cancellation strategy and the titles
 * "cancellation" and "cancellation_self_billed"; Quittio::correction() the title "correction",
 * Quittio::priceReduction() the title "price_reduction"; the title "self_billed" is the title of
 * self-billed invoices in PDF and HTML (InvoiceTitle::policyTitle()); the Managed layer takes
 * the correction strategy.
 */
final readonly class CorrectionPolicy
{
    public const string CANCELLATION_CREDIT_NOTE = 'credit_note';

    public const string CANCELLATION_NEGATIVE_INVOICE = 'negative_invoice';

    public const string CORRECTION_CANCEL_AND_REISSUE = 'cancel_and_reissue';

    public const string CORRECTION_CORRECTED_INVOICE = 'corrected_invoice';

    /**
     * @param  array<string, array<string, string>>  $titles  document kind (cancellation, cancellation_self_billed,
     *         correction, price_reduction, self_billed) => [locale => title]; a missing locale falls back to "en"
     * @param  (Closure(InvoiceData): ?string)|null  $strategyResolver  the cancellation strategy for a particular
     *         original, e.g. by the format it is sent in; null or a null result = $cancellationStrategy
     */
    public function __construct(
        public string $cancellationStrategy = self::CANCELLATION_CREDIT_NOTE,
        public string $correctionStrategy = self::CORRECTION_CANCEL_AND_REISSUE,
        public array $titles = [],
        public ?Closure $strategyResolver = null,
    ) {}

    /**
     * The cancellation strategy for the original: the one of the resolver, otherwise
     * $cancellationStrategy.
     */
    public function cancellationStrategyFor(InvoiceData $original): string
    {
        return ($this->strategyResolver === null ? null : ($this->strategyResolver)($original)) ?? $this->cancellationStrategy;
    }

    /**
     * The title in the locale, else in its language ("de" for "de_CH"), else in English.
     */
    public function title(string $kind, string $locale = 'en'): ?string
    {
        return $this->titles[$kind][$locale] ?? $this->titles[$kind][ArrayTranslator::language($locale)] ?? $this->titles[$kind]['en'] ?? null;
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
