<?php

declare(strict_types=1);

namespace Quittio\Core\Formats;

use Closure;
use Quittio\Core\Countries\CountryPack;
use Quittio\Core\Countries\ResolvedCountries;
use Quittio\Core\Countries\VatTerritory;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Data\Party\BuyerParty;
use Quittio\Core\Exceptions\ConfigurationException;

/**
 * Format resolution in the order of specification 9.3:
 *
 * 1. explicit choice (handled by the caller),
 * 2. known Peppol participant of the buyer (optional lookup),
 * 3. domestic invoice: rules of the seller's country pack,
 * 4. cross-border to a business: rules of the buyer's country pack (also for sellers outside the EU,
 *    e.g. XRechnung for German public buyers), otherwise ZUGFeRD EN 16931 within the EU VAT territory,
 * 5. outside the EU VAT territory or to consumers: PDF,
 * 6. EXTENDED features used: profile EXTENDED (or an error without auto upgrade).
 *
 * "Domestic" and "EU" refer to the VAT territory (see VatTerritory): Monaco counts as France,
 * Northern Ireland as part of the EU, Büsingen and Heligoland are outside of it.
 */
final class DefaultFormatResolver implements FormatResolver
{
    /** @deprecated use VatTerritory::EU_MEMBER_STATES */
    public const array EU_COUNTRIES = VatTerritory::EU_MEMBER_STATES;

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
        $seller = $invoice->seller->address;
        $buyer = $invoice->buyer->address;
        $domestic = $buyer->countryCode === ''
            || VatTerritory::sameVatTerritory($seller->countryCode, $seller->postcode, $buyer->countryCode, $buyer->postcode);

        // Country packs may require their own format first (e.g. B2G in Germany).
        if ($domestic) {
            $choice = $this->firstChoice($countries->leading, $invoice);
            if ($choice !== null) {
                return $choice;
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

        // The buyer's country may require a format whatever the seller's country is
        // (e.g. XRechnung for German public buyers). Without an own buyer pack, a buyer in an
        // excluded territory of the seller's country (e.g. Büsingen) falls under the leading pack.
        $buyerPack = $countries->buyer
            ?? (VatTerritory::vatCountry($buyer->countryCode) === strtoupper($countries->leading->code()) ? $countries->leading : null);
        if ($buyerPack !== null) {
            $choice = $this->firstChoice($buyerPack, $invoice);
            if ($choice !== null) {
                return $choice;
            }
        }

        $sellerInEu = VatTerritory::isEuVatTerritory($seller->countryCode, $seller->postcode);
        $buyerInEu = VatTerritory::isEuVatTerritory($buyer->countryCode, $buyer->postcode);
        if ($sellerInEu && $buyerInEu) {
            return new FormatChoice(FormatRegistry::ZUGFERD, $this->defaultProfile, 'Cross-border invoice within the EU.');
        }

        return new FormatChoice(FormatRegistry::PDF, null, match (true) {
            ! $buyerInEu && ! $sellerInEu => 'Invoice between parties outside the EU VAT territory.',
            ! $buyerInEu => 'Invoice to a buyer outside the EU VAT territory.',
            default => 'Invoice from a seller outside the EU VAT territory.',
        });
    }

    private function firstChoice(CountryPack $pack, InvoiceData $invoice): ?FormatChoice
    {
        foreach ($pack->formatRules() as $rule) {
            $choice = $rule->resolve($invoice);
            if ($choice !== null) {
                return $choice;
            }
        }

        return null;
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
