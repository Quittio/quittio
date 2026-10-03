<?php

declare(strict_types=1);

namespace Quittio\Core\Countries;

/**
 * Defaults of a country: currency, time zone, locale, paper, letter layout and default profile.
 */
final readonly class CountryDefaults
{
    /**
     * @param  list<string>  $requiredLocales  languages an invoice must (also) be issued in
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
    ) {}
}
