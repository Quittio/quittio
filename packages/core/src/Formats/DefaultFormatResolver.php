<?php

declare(strict_types=1);

namespace Quittio\Core\Formats;

use Closure;
use Quittio\Core\Countries\ResolvedCountries;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Data\Party\BuyerParty;
use Quittio\Core\Exceptions\ConfigurationException;

/**
 * Format resolution in the order of specification 9.3:
 *
 * 1. explicit choice (handled by the caller),
 * 2. known Peppol participant of the buyer (optional lookup),
 * 3. domestic invoice: rules of the seller's country pack,
 * 4. cross-border within the EU: rules of the buyer's country pack, otherwise ZUGFeRD EN 16931,
 * 5. outside the EU or to consumers: PDF,
 * 6. EXTENDED features used: profile EXTENDED (or an error without auto upgrade).
 */
final class DefaultFormatResolver implements FormatResolver
{
    public const array EU_COUNTRIES = [
        'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GR', 'HR', 'HU',
        'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK',
    ];

    /**
     * @param  (Closure(BuyerParty): bool)|null  $peppolLookup  returns true if the buyer is reachable via Peppol
     */
    public function __construct(
        private readonly string $defaultProfile = 'en16931',
        private readonly bool $autoUpgradeProfile = true,
        private readonly ?Closure $peppolLookup = null,
    ) {}

    public function resolve(InvoiceData $invoice, ResolvedCountries $countries): FormatChoice
    {
        return $this->upgrade($invoice, $this->choose($invoice, $countries));
    }

    private function choose(InvoiceData $invoice, ResolvedCountries $countries): FormatChoice
    {
        $sellerCountry = strtoupper($invoice->seller->address->countryCode);
        $buyerCountry = strtoupper($invoice->buyer->address->countryCode);
        $domestic = $buyerCountry === '' || $buyerCountry === $sellerCountry;

        // Country packs may require their own format first (e.g. B2G in Germany).
        if ($domestic) {
            foreach ($countries->leading->formatRules() as $rule) {
                $choice = $rule->resolve($invoice);
                if ($choice !== null) {
                    return $choice;
                }
            }
        }

        if (! $invoice->buyer->isConsumer && $this->peppolLookup !== null && ($this->peppolLookup)($invoice->buyer)) {
            return new FormatChoice(FormatRegistry::PEPPOL, 'peppol', 'The buyer is reachable via Peppol.');
        }

        if ($invoice->buyer->isConsumer) {
            return new FormatChoice(FormatRegistry::PDF, null, 'Invoice to a consumer.');
        }

        if ($domestic) {
            return new FormatChoice(FormatRegistry::ZUGFERD, $this->defaultProfile, 'Domestic invoice without a country specific rule.');
        }

        if (in_array($buyerCountry, self::EU_COUNTRIES, true) && in_array($sellerCountry, self::EU_COUNTRIES, true)) {
            foreach ($countries->buyer?->formatRules() ?? [] as $rule) {
                $choice = $rule->resolve($invoice);
                if ($choice !== null) {
                    return $choice;
                }
            }

            return new FormatChoice(FormatRegistry::ZUGFERD, $this->defaultProfile, 'Cross-border invoice within the EU.');
        }

        return new FormatChoice(FormatRegistry::PDF, null, 'Invoice to a buyer outside the EU.');
    }

    private function upgrade(InvoiceData $invoice, FormatChoice $choice): FormatChoice
    {
        if ($choice->format !== FormatRegistry::ZUGFERD && $choice->format !== FormatRegistry::CII) {
            return $choice;
        }
        if ($choice->profile === 'extended' || ! ExtendedFeatures::used($invoice)) {
            return $choice;
        }
        if (! $this->autoUpgradeProfile) {
            throw new ConfigurationException('The invoice uses features of profile EXTENDED (e.g. sub lines), but the automatic profile upgrade is switched off.');
        }

        return new FormatChoice($choice->format, 'extended', trim(($choice->reason ?? '').' Upgraded to EXTENDED because EXTENDED features are used.'));
    }
}
