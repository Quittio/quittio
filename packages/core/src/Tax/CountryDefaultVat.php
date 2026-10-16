<?php

declare(strict_types=1);

namespace Quittio\Core\Tax;

use Quittio\Core\Countries\CountryRegistry;

/**
 * Default VAT of lines without VAT treatment, for InvoiceBuilder::useDefaultVat():
 *
 * - invoices of small businesses (Meta::FLAG_SMALL_BUSINESS): the small business preset of the
 *   seller's country pack (CountryDefaults::$smallBusinessVatPreset, Germany: de.small_business),
 *   otherwise $preset;
 * - all other invoices: $preset, otherwise the preset of the seller's country pack
 *   (CountryDefaults::$vatPreset, Germany: de.standard).
 *
 * InvoiceBuilder::useDefaultVat(new CountryDefaultVat($quittio->countries()));
 */
final readonly class CountryDefaultVat
{
    /**
     * @param  TaxPreset|null  $preset  the configured default (Laravel: quittio.defaults.vat)
     */
    public function __construct(
        private CountryRegistry $countries,
        private ?TaxPreset $preset = null,
    ) {}

    public function __invoke(?string $sellerCountry, bool $smallBusiness): ?TaxPreset
    {
        $defaults = $sellerCountry === null ? null : $this->countries->get($sellerCountry)?->defaults();
        $key = $smallBusiness ? $defaults?->smallBusinessVatPreset : null;
        if ($key === null && ($smallBusiness || $this->preset !== null)) {
            return $this->preset;
        }
        $key ??= $defaults?->vatPreset;

        return $key === null ? null : $this->countries->taxPreset($key);
    }
}
