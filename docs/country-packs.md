# Country packs

A country pack implements `Quittio\Core\Countries\CountryPack` (usually by extending `AbstractCountryPack`) and provides everything that differs per country: defaults (currency, locale, letter layout, default profile), tax presets, additional rules, national profiles, format rules, mandatory PDF output, legal texts, numbering, retention, dunning law, correction policy, payment QR codes, address formatting and letter layouts. Packs may only add or tighten rules, never relax EN 16931.

Register a pack via Composer metadata (discovered automatically by the Laravel integration):

```json
"extra": { "quittio": { "country-packs": ["Acme\\CountryAT\\CountryAT"] } }
```

or in `config/quittio.php` (`countries`) or with `Quittio::registerCountry(new CountryAT)`. Without a pack for the seller's country, `CountryGeneric` applies EN 16931 only and adds a warning.

The seller's country (BT-40) selects the leading pack; for cross-border invoices the buyer's pack adds its buyer rules.

## Germany (`quittio/country-de`)

- Tax presets `VatPreset::Standard19`, `Reduced7`, `ZeroRatedPv`, `SmallBusiness`, `ReverseChargeDomestic`, `ReverseChargeEu`, `IntraCommunitySupply`, `Export`, `NotTaxableInGermany`, margin schemes, `TravelAgency`, `VatPreset::exempt('§ 4 Nr. 14 UStG')`.
- XRechnung 3.0 (CII and UBL) with BR-DE rules and the XRechnung extension.
- UStG rules `DE-USTG-01` … `DE-USTG-10`: full addresses, tax number or VAT identifier, service date, "Gutschrift", property notice, reverse charge wording, vehicle details, small amount limit, small business without VAT, VAT in EUR; Leitweg-ID check digits.
- Small business invoices (`VatPreset::SmallBusiness` on all lines) follow section 34a UStDV: note on the small business exemption, no service date required.
- Builder helpers: `->smallAmount()`, `->propertyRelatedService()`, `->newVehicle()`, `->selfBilled()`, `->consumerDefaultNotice()`.
- Format rules, DIN 5008 layouts, BMF error classes, retention of 8 years, dunning law (§ 288 BGB) and base rate table.

The legal wording and references are proposals that must be confirmed by a tax adviser (see the open decisions in the README).
