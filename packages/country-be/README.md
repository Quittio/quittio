# Quittio country pack Belgium

> **Not released.** This pack lives in the Quittio monorepo only. Its legal wording, rules and defaults have **not been reviewed by a Belgian tax adviser** yet; until they are, it is not split into its own repository, not published on Packagist and not part of `quittio/quittio`. Do not rely on it for real invoices.

The pack implements the invoice mentions of the Belgian VAT Code and of Royal Decree No. 1, the e-invoicing obligation of 2026 and the law on late payment of 2 August 2002. Its class is `Quittio\Country\BE\CountryBE` (code `BE`, translation namespace `quittio-be`). Texts exist in Dutch, French, German and English; the core has Dutch labels as well.

```php
use Quittio\Core\Quittio;
use Quittio\Country\BE\CountryBE;

$quittio = Quittio::create(['country' => 'BE'], [new CountryBE]);
```

## E-invoicing obligation

Since 1 January 2026 invoices between businesses established in Belgium are structured e-invoices, by default Peppol BIS Billing 3.0 (law of 6 February 2024). `BelgianFormatRule` chooses the format `peppol` for domestic invoices to businesses; consumers and sellers that only perform exempt supplies (Art. 44 VAT Code) keep the automatic choice of the core.

- Peppol identifies Belgian businesses by their enterprise number (scheme `0208`). A seller or buyer without electronic address gets the enterprise number of its Belgian VAT number as endpoint (BT-34, BT-49); the setting `peppol_endpoints = false` (`quittio.countries.be`) turns this off. `EnterpriseNumber` checks, normalises and formats the numbers.
- Peppol requires a buyer reference (BT-10) or a purchase order reference (BT-13) (`PEPPOL-EN16931-R003`).
- Sending through a Peppol access point is not part of this pack.

## Tax presets

`Quittio\Country\BE\Tax\VatPreset`:

| Case | Key | Category | Rate | Mention (Dutch / French) |
|---|---|---|---|---|
| `Standard` | `be.standard` | `S` | 21 | – |
| `Intermediate` | `be.intermediate` | `S` | 12 | – |
| `Reduced` | `be.reduced` | `S` | 6 | – |
| `Accommodation` | `be.accommodation` | `S` | 12 from 1 March 2026, 6 before (by supply date) | – |
| `ZeroRated` | `be.zero_rated` | `Z` | 0 | – |
| `SmallBusiness` | `be.small_business` | `E` | 0 | Bijzondere vrijstellingsregeling kleine ondernemingen / Régime particulier de franchise des petites entreprises |
| `ReverseChargeDomestic` | `be.reverse_charge_domestic` | `AE` | 0 | Verlegging van heffing – KB nr. 1, art. 20 / Autoliquidation – A.R. n° 1, art. 20 |
| `ReverseChargeEu` | `be.reverse_charge_eu` | `AE` | 0 | … artikel 196 Richtlijn 2006/112/EG |
| `IntraCommunitySupply` | `be.intra_community_supply` | `K` | 0 | … artikel 39bis Wetboek btw |
| `Export` | `be.export` | `G` | 0 | … artikel 39 Wetboek btw |
| `NotTaxableInBelgium` | `be.not_taxable` | `O` | – | place of supply abroad |
| `MarginSecondHand`, `MarginArt`, `MarginAntiques`, `TravelAgency` | `be.margin_…`, `be.travel_agency` | `E` | 0 | Bijzondere regeling – … |
| `VatPreset::exempt('artikel 44, § 2, 1° Wetboek btw')` | `be.exempt` | `E` | 0 | Vrijgesteld van btw – … |

## Rules

| Code | Severity | Check |
|---|---|---|
| `BE-VAT-01` | error | VAT number of the seller (Art. 5 § 1, 3° KB nr. 1) |
| `BE-VAT-02` | error | name and address of seller and buyer (5°) |
| `BE-VAT-03` | warning | VAT number of a Belgian business buyer (4°) |
| `BE-VAT-04` | error | reverse charge mention (10°) |
| `BE-VAT-05` | error | exemption reference as text for categories E, K, G (10°) |
| `BE-VAT-06` | error | no VAT under the small business scheme (Art. 56bis VAT Code) |
| `BE-VAT-07` | error (warning in XML) | self-billing mention (12°) |
| `BE-VAT-08` | warning | issued after the 15th of the month following the supply (Art. 53 § 2 VAT Code) |
| `BE-LANG-01` | warning | a business in the Dutch, French or German language region invoices Belgian customers in the language of its region (language decrees); Brussels is free |

## Late payment

Law of 2 August 2002: between businesses the reference rate plus 8 points, rounded up to the next half point (10.5 % in 2026), and a flat rate of 40 EUR. `ReferenceRateTable` holds the rates from 2023 as published rate minus 8 points; add later half-years with `withRate()`. Consumers owe the legal interest rate, 4.5 % in 2026 (`b2cFixedRate`; it changes every year).

## Other defaults

- Locale `nl_BE`, A4, GiroCode (EPC QR code), profile `peppol`.
- Retention: seven years from the end of the calendar year (Art. 60 § 3 VAT Code as amended by the law of 18 December 2025).
- Numbering: `F-{Y}-{seq:4}`, sequential (Art. 5 § 1, 2° KB nr. 1).
- Titles: "Creditnota" / "Note de crédit", "Verbeterde factuur" / "Facture rectificative", "Zelffacturering" / "Autofacturation".

## Not yet included

- A locale chosen from the seller's language region (the warning `BE-LANG-01` points it out instead).
- Structured communication (OGM/VCS, `+++…+++`) checks, the Belgian payment QR code, a Laravel service provider with settings.
