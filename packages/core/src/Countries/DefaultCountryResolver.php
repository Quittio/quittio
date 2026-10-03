<?php

declare(strict_types=1);

namespace Quittio\Core\Countries;

use Quittio\Core\Data\InvoiceData;

/**
 * The seller's country (BT-40) determines the leading pack. The buyer's country (BT-55)
 * adds rules for cross-border invoices. Without an installed pack, a generic pack is used.
 */
final readonly class DefaultCountryResolver implements CountryResolver
{
    public function __construct(
        private CountryRegistry $registry,
        private ?string $defaultCountry = null,
    ) {}

    public function resolve(InvoiceData $invoice, ?string $countryOverride = null): ResolvedCountries
    {
        $sellerCountry = $countryOverride
            ?? ($invoice->seller->address->countryCode !== '' ? $invoice->seller->address->countryCode : null)
            ?? $this->defaultCountry
            ?? 'XX';

        $leading = $this->registry->getOrGeneric($sellerCountry);

        $buyerCountry = $invoice->buyer->address->countryCode;
        $buyer = null;
        if ($buyerCountry !== '' && strtoupper($buyerCountry) !== strtoupper($leading->code())) {
            $buyer = $this->registry->get($buyerCountry);
        }

        return new ResolvedCountries($leading, $buyer);
    }
}
