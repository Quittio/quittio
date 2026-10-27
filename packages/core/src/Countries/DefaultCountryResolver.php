<?php

declare(strict_types=1);

namespace Quittio\Core\Countries;

use Quittio\Core\Data\InvoiceData;

/**
 * The seller's country (BT-40) determines the leading pack. The buyer's country (BT-55)
 * adds rules for cross-border invoices. A country without a pack of its own takes the pack of
 * the country whose VAT territory it belongs to (VatTerritory::vatCountry(): Monaco the French,
 * Liechtenstein the Swiss pack). Without an installed pack, a generic pack is used.
 */
final readonly class DefaultCountryResolver implements CountryResolver
{
    public function __construct(
        private CountryRegistry $registry,
        private ?string $defaultCountry = null,
    ) {}

    /**
     * The packs for $invoice as described above. The seller's country is $countryOverride,
     * otherwise the country of the seller's address (BT-40), otherwise the default country,
     * otherwise "XX" (generic). The buyer's pack is only set when it is installed and differs from
     * the leading pack.
     */
    public function resolve(InvoiceData $invoice, ?string $countryOverride = null): ResolvedCountries
    {
        $sellerCountry = $countryOverride
            ?? ($invoice->seller->address->countryCode !== '' ? $invoice->seller->address->countryCode : null)
            ?? $this->defaultCountry
            ?? 'XX';

        $leading = $this->registry->get($sellerCountry) ?? $this->registry->get(VatTerritory::vatCountry($sellerCountry)) ?? $this->registry->getOrGeneric($sellerCountry);

        $buyerCountry = $invoice->buyer->address->countryCode;
        $buyer = null;
        if ($buyerCountry !== '' && strtoupper($buyerCountry) !== strtoupper($leading->code())) {
            $buyer = $this->registry->get($buyerCountry) ?? $this->registry->get(VatTerritory::vatCountry($buyerCountry));
            $buyer = $buyer?->code() === $leading->code() ? null : $buyer;
        }

        return new ResolvedCountries($leading, $buyer);
    }
}
