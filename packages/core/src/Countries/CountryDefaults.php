<?php

declare(strict_types=1);

namespace Quittio\Core\Countries;

/**
 * Defaults of a country.
 *
 * Applied by the core: $locale (locale of a run that names none), $paper (paper size unless the
 * presentation or the configuration sets one), $letterLayout (letter layout unless the
 * presentation names one), $paymentQr (what the payment QR code type "auto" prints) and
 * $vatPreset and $smallBusinessVatPreset (VAT of lines without one, see CountryDefaultVat).
 * Informational metadata for applications, not applied by the core: $currency, $timezone,
 * $profile and $requiredLocales.
 */
final readonly class CountryDefaults
{
    /**
     * @param  string  $currency  usual currency of the country; an invoice names its own (BT-5)
     * @param  string  $timezone  time zone of the country, e.g. as default for an application's issuers; invoices
     *         use their own (InvoiceBuilder::timezone(), default UTC)
     * @param  string  $profile  usual profile of the country; the profile of a run comes from the call, the
     *         configuration, the format rules of the pack or the format
     * @param  list<string>  $requiredLocales  languages an invoice must (also) be issued in; a run renders one locale
     * @param  string  $paymentQr  payment QR code of the type "auto": girocode (the pack's generator, otherwise the
     *         EPC GiroCode), swiss-qr-bill or another type (the pack's generator, otherwise none) or none
     * @param  string|null  $vatPreset  key of the preset of the pack for lines without VAT treatment, usually the
     *         standard rate
     * @param  string|null  $smallBusinessVatPreset  key of that preset for invoices of small businesses
     *         (Meta::FLAG_SMALL_BUSINESS)
     */
    public function __construct(
        public string $currency = 'EUR',
        public string $timezone = 'UTC',
        public string $locale = 'en',
        public string $paper = 'A4',
        public string $letterLayout = 'free',
        public string $profile = 'en16931',
        public array $requiredLocales = [],
        public string $paymentQr = 'girocode',
        public ?string $vatPreset = null,
        public ?string $smallBusinessVatPreset = null,
    ) {}
}
