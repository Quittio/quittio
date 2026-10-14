<?php

declare(strict_types=1);

namespace Quittio\Country\DE\Formats;

use Quittio\Core\Countries\VatTerritory;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Data\Party\Address;
use Quittio\Core\Formats\FormatChoice;
use Quittio\Core\Formats\FormatRegistry;
use Quittio\Core\Formats\FormatRule;
use Quittio\Country\DE\CountryDE;
use Quittio\Country\DE\Rules\UstgRules;
use Quittio\Country\DE\Support\LeitwegId;

/**
 * Formats that German law prescribes:
 *
 * - public buyers (Leitweg-ID) receive XRechnung, whatever the seller's country;
 * - small amount invoices between parties in the German Inland (section 1 (2) UStG) may be
 *   issued as a PDF (section 33 UStDV).
 *
 * Everything else is left to the format resolver: consumers, the Peppol lookup, domestic business
 * invoices (ZUGFeRD with the configured profile) and cross-border invoices. The e-invoicing
 * obligation of section 14 (2) UStG only covers supplies between businesses established in
 * Germany, so sellers abroad and buyers in Büsingen or Heligoland get the resolver's choice.
 */
final readonly class GermanFormatRule implements FormatRule
{
    /** Electronic address scheme of the Leitweg-ID (BT-49). */
    public const string LEITWEG_SCHEME = '0204';

    public function resolve(InvoiceData $invoice): ?FormatChoice
    {
        $endpoint = $invoice->buyer->electronicAddress;
        // Only a Leitweg-ID with valid check digits counts: ordinary order numbers such as "4711-01" share its shape.
        if (LeitwegId::isValid($invoice->buyerReference) || ($endpoint !== null && (string) $endpoint->scheme->value === self::LEITWEG_SCHEME)) {
            return new FormatChoice(FormatRegistry::XRECHNUNG_CII, 'xrechnung', 'Public buyer with Leitweg-ID: XRechnung (E-Rechnungsverordnung).');
        }

        if (UstgRules::isSmallAmountInvoice($invoice) && self::inland($invoice->seller->address) && self::inland($invoice->buyer->address)) {
            return new FormatChoice(FormatRegistry::PDF, null, 'Small amount invoice (section 33 UStDV): no e-invoice obligation.');
        }

        return null;
    }

    /**
     * An address in the German Inland (without Büsingen and Heligoland); one without a country
     * code counts as domestic, as in the format resolver.
     */
    private static function inland(Address $address): bool
    {
        return $address->countryCode === '' || VatTerritory::memberState($address->countryCode, $address->postcode) === CountryDE::CODE;
    }
}
