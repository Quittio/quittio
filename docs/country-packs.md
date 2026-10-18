# Country packs

A country pack holds everything in Quittio that differs between countries: VAT rates and exemption texts, national invoice rules and profiles, the formats a country prescribes, legal notices, letter layouts, numbering and retention periods, and the law on late payment. The core of Quittio stays country-neutral. Quittio ships a pack for Germany, and `quittio/quittio` installs it.

Read this page when you invoice from Germany and need more than a standard invoice: an exemption or reverse charge, a small business invoice, XRechnung for a public buyer, a reminder with default interest or a letter layout after DIN 5008 (the German standard for business letters). Read it also when you want to support another country. If terms such as EN 16931, XRechnung, profile or `BR-DE` codes are new to you, read [E-invoicing basics](concepts.md) first.

The page has two parts:

| Part | For | Start with |
|---|---|---|
| **Using the German pack** | Everyone who invoices as a seller in Germany (most readers) | [Germany (`quittio/country-de`)](#germany-quittiocountry-de) |
| **Writing a country pack** | Developers who add another country or change how a pack behaves | [How a pack is selected](#how-a-pack-is-selected), [Writing your own country pack](#writing-your-own-country-pack), [The `CountryPack` contract](#the-countrypack-contract) |

The legal wording and references of the packs are proposals that must be confirmed by a tax adviser (see the legal review in the README).

## Germany (`quittio/country-de`)

The German pack implements German VAT law (the VAT Act UStG and its implementing ordinance UStDV), XRechnung and German business letter conventions. Its class is `Quittio\Country\DE\CountryDE` (code `DE`, translation namespace `quittio-de`).

- **Installation.** Laravel discovers the pack automatically, so there is nothing to register. Without Laravel, pass `new CountryDE` to `Quittio::create()` (see [Registering packs](#registering-packs)). The builder macro `de()` leads to the German special cases (`Quittio\Country\DE\Builder\GermanInvoice`), e.g. `->de()->smallAmount()`; it is available as soon as Composer loads the package. Each method returns the builder, so the chain continues there. The macros of version 0.2 directly on the builder (`->smallAmount()`, `->selfBilled()` …) still work, but are deprecated and report an `E_USER_DEPRECATED`. `stubs/InvoiceBuilder.stub.php` describes `de()` for IDEs and PHPStan.
- **When it applies.** The pack leads every invoice whose seller is in Germany (seller country code `DE`). It then checks the German rules, chooses the format and provides the defaults: the German locale (unless the invoice or the configuration sets one), A4 paper and the DIN 5008 form B layout. For an invoice from another country to a business in Germany, only its format rules apply, so that German public buyers receive XRechnung. Details are in [How a pack is selected](#how-a-pack-is-selected).
- **Legal references.** This page writes German legal references in English form: "section 14 (4) no. 1 UStG" stands for "§ 14 Abs. 4 Nr. 1 UStG". Other laws cited are the Civil Code (BGB), the Fiscal Code (AO) and the VAT application decree (UStAE). Values that the pack stores or prints in German keep their German form. German terms such as Gutschrift, Kleinunternehmer or Leitweg-ID are explained on first use and in the [glossary](concepts.md#german-terms).

Common tasks:

| You want to | Read |
|---|---|
| Set the VAT rate or an exemption on a line | [Tax presets](#tax-presets) |
| Invoice as a small business without VAT (Kleinunternehmer) | [Small businesses](#small-businesses) |
| Invoice a business in another EU country | [Reverse charge, intra-community supplies and VAT identifiers](#reverse-charge-intra-community-supplies-and-vat-identifiers) |
| Know which format Quittio picks (ZUGFeRD, XRechnung, PDF) | [Format choice](#format-choice) |
| Invoice a German public authority | [Format choice](#format-choice), [XRechnung profiles](#xrechnung-profiles), [XRechnung rules](#xrechnung-rules) |
| Issue an invoice of up to 250 € with fewer details | [Small amount invoices](#small-amount-invoices) |
| Deduct advance payments in a final invoice | [Final invoices](#final-invoices-deductadvance) |
| Issue a self-billed invoice (Gutschrift) | [Self-billing](#self-billing-gutschrift) |
| Cancel or correct an invoice | [Cancellations and corrections](#cancellations-and-corrections) |
| Send a reminder with default interest | [Dunning law](#dunning-law), [Default interest](#default-interest) |
| Choose the letter layout | [DIN 5008 letter layouts](#din-5008-letter-layouts) |
| Look up a validation code such as `DE-USTG-03` or `BR-DE-15` | [UStG rules](#ustg-rules), [XRechnung rules](#xrechnung-rules) |

Complete invoices for these cases are on [Examples](examples.md#invoices-in-germany).

### Tax presets

A tax preset is a ready-made VAT treatment for an invoice line. It sets the VAT category (BT-151) and the rate, and for exempt supplies also the exemption reason code (BT-121) and text (BT-120). Use a preset instead of a bare category and rate: the line then carries the wording German law requires, and the German rules recognise the case. Lines without `vat:` take the [default VAT](building-invoices.md#default-vat); in Laravel that is 19 % for a German seller and `VatPreset::SmallBusiness` for a small business.

The categories are the codes of EN 16931: `S` standard rate, `Z` zero rated, `E` exempt, `AE` reverse charge, `K` intra-community supply, `G` export outside the EU, `O` not subject to VAT (see [Categories](building-invoices.md#categories)).

`Quittio\Country\DE\Tax\VatPreset` is a string-backed enum that implements `TaxPreset`. Apply it per line:

```php
use Quittio\Core\Enums\UnitCode;
use Quittio\Country\DE\Tax\VatPreset;
use Quittio\Laravel\Facades\Quittio;

$builder = Quittio::invoice()
    ->line('Web development', netPrice: '95.00', quantity: 24, unit: UnitCode::Hour, vat: VatPreset::Standard)
    // an exemption without its own case: legal reference and VATEX code;
    // the invoice prints "Steuerfrei nach § 4 Nr. 14 UStG" (exempt under section 4 no. 14 UStG)
    ->line('Medical treatment', netPrice: '90.00', unit: UnitCode::Piece, vat: VatPreset::exempt('§ 4 Nr. 14 UStG', 'VATEX-EU-132-1C'));
```

| Case | Key | Category | Rate | Code (BT-121) | Use |
|---|---|---|---|---|---|
| `Standard` | `de.standard` | `S` | 19 (of the supply date) | – | Standard rate |
| `Reduced` | `de.reduced` | `S` | 7 (of the supply date) | – | Reduced rate |
| `ZeroRatedPv` | `de.zero_rated_pv` | `Z` | 0 | – | 0 % rate for photovoltaic systems (section 12 (3) UStG). Category `Z` allows no exemption reason (BR-Z-10), so the preset adds the note "Steuersatz 0 % nach § 12 Abs. 3 UStG." (BT-22). Quittio does not check the conditions of the zero rate (operator, place of the system). |
| `SmallBusiness` | `de.small_business` | `E` | 0 | – | Small business exemption (Kleinunternehmer, section 19 (1) UStG); see [Small businesses](#small-businesses) |
| `ReverseChargeDomestic` | `de.reverse_charge_domestic` | `AE` | 0 | `VATEX-EU-AE` | Domestic reverse charge (section 13b UStG): the buyer in Germany owes the VAT |
| `ReverseChargeEu` | `de.reverse_charge_eu` | `AE` | 0 | `VATEX-EU-AE` | Service to a business in another member state, which owes the VAT there. Both VAT identifiers are required (`DE-USTG-11`). Issue the invoice by the 15th of the following month (`DE-USTG-15`). |
| `IntraCommunitySupply` | `de.intra_community_supply` | `K` | 0 | `VATEX-EU-IC` | VAT-exempt intra-community supply (section 4 no. 1b with section 6a UStG), also of new vehicles (`newVehicle()`). Not for small businesses, except new vehicles (`DE-USTG-14`). Issue the invoice by the 15th of the following month (`DE-USTG-15`). |
| `TriangularTransaction` | `de.triangular_transaction` | `AE` | 0 | `VATEX-EU-AE` | Intra-community triangular transaction (section 25b UStG), in which the last customer owes the VAT; wording of section 14a (7) UStG, also as note (BT-22). Both VAT identifiers are required. |
| `Export` | `de.export` | `G` | 0 | `VATEX-EU-G` | VAT-exempt export supply (section 4 no. 1a with section 6 UStG) |
| `NotTaxableInGermany` | `de.not_taxable` | `O` | none | `VATEX-EU-O` | Supply not taxable in Germany (place of supply abroad). EN 16931 allows no VAT identifiers with category `O` (BR-O-02): state the tax number (BT-32) instead. |
| `BusinessTransfer` | `de.business_transfer` | `O` | none | `VATEX-EU-O` | Transfer of a business as a whole (Geschäftsveräußerung im Ganzen, section 1 (1a) UStG), not subject to VAT. As with `NotTaxableInGermany`, state the tax numbers instead of VAT identifiers (BR-O-02). VAT shown on such an invoice would be owed under section 14c (1) UStG; category `O` cannot carry any. |
| `MarginSecondHand` | `de.margin_second_hand` | `E` | 0 | `VATEX-EU-F` | Margin scheme, second-hand goods; wording of section 14a (6) UStG, also as note (BT-22) |
| `MarginArt` | `de.margin_art` | `E` | 0 | `VATEX-EU-I` | Margin scheme, works of art; also as note (BT-22) |
| `MarginAntiques` | `de.margin_antiques` | `E` | 0 | `VATEX-EU-J` | Margin scheme, collectors' items and antiques; also as note (BT-22) |
| `TravelAgency` | `de.travel_agency` | `E` | 0 | `VATEX-EU-D` | Special scheme for travel agents; also as note (BT-22) |
| `VatPreset::exempt(string $paragraph, $code = null)` | `de.exempt` | `E` | 0 | `$code` (enum, raw code or string) | Any other exemption, e.g. `§ 4 Nr. 14 UStG` |

The codes in column BT-121 are VATEX codes: the standard codes for the reason of an exemption.

`Standard` and `Reduced` take the rate that applied on the supply date of the line (`TaxTables::STANDARD_RATE`, `TaxTables::REDUCED_RATE`). The supply date is the end of the line period (BT-135), its start, the VAT point date (BT-7), the delivery date (BT-72), the end of the invoicing period (BT-74) or the issue date, in this order (`Quittio\Core\Tax\SupplyDate`). A delivery in August 2020 gets 16 % and 5 %, a correction of it issued in 2021 as well:

```php
use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Country\DE\Tax\VatPreset;

InvoiceBuilder::make()
    ->issueDate('2021-01-20')
    ->deliveryDate('2020-08-15')
    ->line('Desk', netPrice: '400.00', vat: VatPreset::Standard);   // 16 %
```

`VatPreset::Standard->rate()` returns the rate of today. The plausibility rule `DE-VAT-RATE` warns when a line of category `S` within the German VAT territory has a rate that applied neither as standard nor as reduced rate on its supply date. Average rates of agriculture and forestry (section 24 UStG) are a legitimate exception; switch the rule off for them with `validation.rules.disabled` ([Switching rules off](validation.md#switching-rules-off-and-changing-their-severity)).

Up to version 0.2 the presets were called `Standard19` (`de.standard_19`) and `Reduced7` (`de.reduced_7`). These cases are deprecated and follow the dates as well; their keys stay readable: `taxPresets()->get('de.standard_19')` and `quittio.defaults.vat` find `Standard`, and lines of stored invoices keep their key. `VatPreset::fromKey($key)?->canonical()` turns an old key into the current case.

`Standard`, `Reduced` and `ZeroRatedPv` carry no text, because EN 16931 forbids an exemption reason for these categories (BR-S-10, BR-Z-10). The other presets print an exemption reason (BT-120) in German or English, from `resources/lang/{de,en}/tax.php`:

| Key | German | English |
|---|---|---|
| `de.small_business` | Steuerfreie Leistung nach § 19 Abs. 1 UStG (Steuerbefreiung für Kleinunternehmer). | VAT-exempt supply under section 19 (1) UStG (small business exemption). |
| `de.reverse_charge_domestic` | Steuerschuldnerschaft des Leistungsempfängers (§ 13b UStG) | Reverse charge, the recipient is liable for VAT (section 13b UStG) |
| `de.reverse_charge_eu` | Steuerschuldnerschaft des Leistungsempfängers | Reverse charge, the recipient is liable for VAT |
| `de.intra_community_supply` | Steuerfreie innergemeinschaftliche Lieferung (§ 4 Nr. 1b i. V. m. § 6a UStG) | VAT-exempt intra-Community supply (section 4 no. 1b in conjunction with section 6a UStG) |
| `de.triangular_transaction` | Innergemeinschaftliches Dreiecksgeschäft nach § 25b UStG; Steuerschuldnerschaft des letzten Abnehmers | Intra-Community triangular transaction (section 25b UStG); the last customer is liable for the VAT |
| `de.export` | Steuerfreie Ausfuhrlieferung (§ 4 Nr. 1a i. V. m. § 6 UStG) | VAT-exempt export supply (section 4 no. 1a in conjunction with section 6 UStG) |
| `de.not_taxable` | Nicht im Inland steuerbare Leistung (Leistungsort im Ausland) | Service not taxable in Germany (place of supply abroad) |
| `de.business_transfer` | Nicht steuerbare Geschäftsveräußerung im Ganzen (§ 1 Abs. 1a UStG). | Transfer of a business as a whole, not subject to VAT (section 1 (1a) UStG). |
| `de.margin_second_hand` | Gebrauchtgegenstände/Sonderregelung | Second-hand goods/special arrangements |
| `de.margin_art` | Kunstgegenstände/Sonderregelung | Works of art/special arrangements |
| `de.margin_antiques` | Sammlungsstücke und Antiquitäten/Sonderregelung | Collectors’ items and antiques/special arrangements |
| `de.travel_agency` | Sonderregelung für Reisebüros | Special arrangements for travel agents |
| `de.exempt` | Steuerfrei nach :paragraph | Exempt from VAT according to :paragraph |

The English texts follow the terms of Directive 2006/112/EC ("exempt", "intra-Community supply", "Reverse charge"). The [legal texts](#legal-texts) of the same notices use the same wording.

How presets behave:

- **Language.** The text is taken in the invoice locale (`->locale()` on the builder) when the line is built. The enum presets and `VatPreset::exempt()` (a `Quittio\Country\DE\Tax\CustomVatPreset`) use German when the invoice has no locale. Other locales without a text fall back to English.
- **Preset key.** The key stays on the line (`$line->vat->presetKey`). `DE-USTG-09`, `DE-USTG-11`, `DE-USTG-14`, `DE-USTG-15` and the small business detection rely on it.
- **VAT breakdown.** Category, rate, code and text flow into the VAT breakdown (BG-23). When lines of one category and rate have different texts, the texts are joined in BT-120. The code (BT-121) is kept only when all codes agree; otherwise Quittio warns with `QUITTIO-EXEMPTION-CONFLICT`.
- **Invoice notes.** The mapping table of KoSIT and FeRD (recommended by the BMF letter of 15 October 2026) puts the statements of section 14a (6) and (7) UStG into the invoice note (BG-1). The margin scheme, travel agency and triangular presets therefore add their text once as note (BT-22, subject `REG`) as well, and `ZeroRatedPv` its reference to section 12 (3) UStG. A note with the same text is not added twice. Presets of other packs can do the same by implementing `Quittio\Core\Tax\StatesInvoiceNote`.
- **PDF.** The PDF lists the BT-120 texts of all categories other than the standard rate in its legal notes (`legalNotes`), together with the regulatory notes and without duplicates. Margin scheme and travel agency rows show no VAT amount (section 14a (6) UStG).
- **Allowances and charges.** Document level allowances and charges take a category and a rate, not a preset: `->charge(VatCategory::StandardRate, '19', amount: '10.00', reason: 'Shipping')`.
- **Lookup by key**, e.g. from a database column: `VatPreset::from('de.standard')`, `(new CountryDE)->taxPresets()->get('de.standard')` or, across all installed packs, `$quittio->countries()->taxPreset('de.standard')`. `de.exempt` is not part of the collection.

### Small businesses

A small business (Kleinunternehmer, section 19 UStG) charges no VAT. Its invoices state the exemption instead. Three steps make an invoice a correct small business invoice:

1. **Mark the seller as a small business.** Set `quittio.seller.small_business = true` (applied by `Quittio::invoice()`), the Managed issuer setting `tax_settings.small_business`, or `->flag(Meta::FLAG_SMALL_BUSINESS)`. `Flags::SMALL_BUSINESS` is the same flag (`small_business`). With the flag, a standard rated line, allowance or charge is an error (`DE-USTG-09`). The flag does not set presets or texts.
2. **Use `VatPreset::SmallBusiness` on every line.** Lines without `->vat()` get it automatically when the invoice has the small business flag and the default VAT comes from the country pack (`CountryDefaultVat`; in Laravel always, see [Default VAT](building-invoices.md#default-vat)). The preset's text (BT-120) appears in the PDF.
3. **Give a seller identifier if you have no VAT identifier.** Without a VAT identifier, EN 16931 needs a seller identifier (BT-29) or a legal registration (BT-30) (rule BR-CO-26). In Laravel set `quittio.seller.identifiers` or `legal_registration` (see [Configuration](configuration.md)).

```php
use Quittio\Core\Enums\UnitCode;
use Quittio\Laravel\Facades\Quittio;

$builder = Quittio::invoice()                      // quittio.seller.small_business = true
    ->line('Consulting', netPrice: '80.00', unit: UnitCode::Hour);   // VatPreset::SmallBusiness by default
```

What the pack checks:

- An invoice with the small business flag, or with a line that uses the preset, is a small business invoice under section 34a UStDV. It needs no service date (`DE-USTG-03`), and the PDF is not checked for one. The tax number or VAT identifier is still required (`DE-USTG-02`).
- A small business needs no VAT identifier of its own for reverse charge services in another member state (`DE-USTG-11`; section 19 (1) sentence 2 UStG). The buyer's VAT identifier is still required.
- A small business that takes part in the cross-border small business scheme may state its small business identification number (Kleinunternehmer-Identifikationsnummer, suffix `EX`) instead of the tax number (section 34a sentence 1 no. 2 UStDV). It is no VAT identifier and belongs to the tax number (BT-32): `->de()->smallBusinessId('DE123456789EX')` sets it there and sets the small business flag. `DE-TAX-NUMBER` accepts it. In a VAT identifier field (BT-31) it gets the info `QUITTIO-SME-ID` instead of a format warning.
- A small business cannot use the exemption for intra-community supplies (category `K`; `DE-USTG-14`, section 19 (1) sentence 2 UStG). The only exception is the intra-community supply of a new vehicle (`->de()->newVehicle()`, section 19 (6) sentence 1 UStG).

A complete invoice is in [Examples: Small business](examples.md#small-business).

### Reverse charge, intra-community supplies and VAT identifiers

With reverse charge (German: Steuerschuldnerschaft des Leistungsempfängers), the buyer owes the VAT instead of the seller. The invoice shows no VAT and must say why. An intra-community supply (innergemeinschaftliche Lieferung) is a VAT-exempt delivery of goods to a business in another EU member state. Use the presets `ReverseChargeDomestic`, `ReverseChargeEu`, `TriangularTransaction` and `IntraCommunitySupply` for these cases ([Tax presets](#tax-presets)). The pack then checks:

- **Wording.** Reverse charge rows (category `AE`) must name "Steuerschuldnerschaft des Leistungsempfängers" (`DE-USTG-06`, error; the buyer owes the VAT all the same). The texts of the reverse charge presets are accepted in every locale the pack ships, so English invoices with the presets pass as well. Your own texts must contain "Steuerschuldnerschaft".
- **VAT identifiers.** Reverse charge in another member state and triangular transactions need the VAT identifiers of seller and buyer (`DE-USTG-11`). The rule does not apply to `ReverseChargeDomestic` (section 13b UStG), and a small business needs none of its own (section 19 (1) sentence 2 UStG).
- **Deadline.** Invoices for intra-community supplies and for services in another member state (`ReverseChargeEu`) must be issued by the 15th of the month following the supply (`DE-USTG-15`, warning; section 14a (1) sentence 2 and (3) sentence 1 UStG). This includes final invoices: advance payments trigger no VAT on intra-community supplies, so the invoice for the supply is the one with the deadline.
- **PDF.** The PDF must show the reverse charge or exemption note and the VAT identifiers (see [Mandatory PDF output](#mandatory-pdf-output)).
- **Tax representative.** A tax representative of the seller (BG-11, `->taxRepresentative($name, $vatId, $address)`) satisfies `DE-USTG-02` and `BR-DE-16`. Its VAT identifier satisfies `DE-USTG-11` and the mandatory output.
- **Number formats.** The tax number (Steuernummer, BT-32) of a seller in Germany is checked for format and check digit (`DE-TAX-NUMBER`, see [Tax number](#tax-number)). VAT identifiers are checked by the core plausibility rules: `QUITTIO-VATID-FORMAT` (for `DE` nine digits) and optionally `QUITTIO-VIES`, an online check against the EU's VIES service. The same rules warn about category `K` with a domestic or non-EU destination, and about `G` within the EU (see [Validation](validation.md#plausibility-l7)).

Complete invoices are in [Examples: EU business: services](examples.md#eu-business-services), [EU business: goods](examples.md#eu-business-goods) and [Export](examples.md#export).

### Format choice

Without `->format()`, Quittio chooses the format for each invoice ([Automatic choice](formats.md#automatic-choice)). The German pack adds only the cases in which German law prescribes a format. `Quittio\Country\DE\Formats\GermanFormatRule` returns a choice for these cases and null (no opinion) otherwise:

| Condition | Format | Profile |
|---|---|---|
| The buyer reference (BT-10) is a Leitweg-ID with valid check digits, or the buyer's electronic address (BT-49) uses scheme `0204` (`GermanFormatRule::LEITWEG_SCHEME`) | `xrechnung-cii` | `xrechnung` |
| A small amount invoice whose simplifications apply (`UstgRules::isSmallAmountInvoice()`), with seller and buyer in the German Inland (domestic territory under section 1 (2) UStG, i.e. without Büsingen and Heligoland; an address without country code counts as domestic) | `pdf` | – |
| otherwise | – (null) | – |

A Leitweg-ID is the routing ID of a German public buyer, such as an authority or a municipality. The buyer names it in the order, and it goes into the buyer reference (BT-10). Its last two digits are check digits.

```php
use Quittio\Core\Builder\Party;
use Quittio\Laravel\Facades\Quittio;

$builder = Quittio::invoice()
    ->buyerReference('04011000-12345-03')                // a Leitweg-ID: XRechnung
    ->buyer(Party::make('Stadt Beispielstadt')
        ->electronicAddress('04011000-12345-03', '0204'));  // or the same ID as electronic address (scheme 0204)
```

- Order numbers shaped like a Leitweg-ID (e.g. `4711-01`) do not switch to XRechnung. A Leitweg-ID with a typo does not either; validation reports it as `DE-LEITWEG-ID-SUSPECT` (info, see [Leitweg-ID](#leitweg-id)). Correct it, set the `0204` endpoint as well, or choose the format explicitly (`->format('xrechnung-ubl')`).
- An explicit `->format()` or a configured `quittio.defaults.format` always wins.

Everything else follows the [general order of the resolver](formats.md#automatic-choice):

- **Domestic business invoices:** the Peppol lookup of the resolver, if one is configured, then `zugferd` with the configured profile (`en16931` by default; EXTENDED when EXTENDED features are used).
- **Consumers:** `pdf`.
- **Cross-border invoices:** the e-invoicing obligation of section 14 (2) UStG covers only supplies between businesses established in Germany. Sellers outside the EU invoicing a German business get `pdf` from the resolver, sellers in other member states `zugferd`. Buyers in Büsingen or Heligoland are outside the EU VAT territory and get `pdf`.
- **German public buyers** receive XRechnung whatever the seller's country: for cross-border invoices to a buyer in Germany, the rule runs as the rule of the buyer's pack.

A complete invoice to a public buyer is in [Examples: Public sector](examples.md#public-sector).

### XRechnung profiles

XRechnung is the German e-invoice standard for public buyers: EN 16931 with additional German rules, as an XML file in the CII or UBL syntax (see [Formats](concepts.md#formats)). The pack registers three profiles for it. XRechnung 3.0 is the one you normally use. The extension allows data that XRechnung 3.0 does not, such as sub lines. XRechnung CVD is XRechnung 3.0 for the procurement of road vehicles under the Clean Vehicles Directive (CVD, Directive (EU) 2019/1161). How Quittio writes XRechnung files is described in [Formats: XRechnung](formats.md#xrechnung).

| | `XRechnung30` | `XRechnung30Extension` | `XRechnung30Cvd` |
|---|---|---|---|
| Key, name | `xrechnung`, XRechnung 3.0 | `xrechnung-extension`, XRechnung 3.0 Extension | `xrechnung-cvd`, XRechnung 3.0 CVD |
| Specification identifier (BT-24), which names the profile in the XML | `urn:cen.eu:en16931:2017#compliant#urn:xeinkauf.de:kosit:xrechnung_3.0` | `urn:cen.eu:en16931:2017#compliant#urn:xeinkauf.de:kosit:xrechnung_3.0#conformant#urn:xeinkauf.de:kosit:extension:xrechnung_3.0` | `urn:cen.eu:en16931:2017#compliant#urn:xeinkauf.de:kosit:xrechnung_3.0#compliant#urn:xeinkauf.de:kosit:xrechnung:cvd_0.9` |
| Level, syntaxes | EN 16931; CII and UBL | as XRechnung 3.0; sub lines only in UBL | as XRechnung 3.0 |
| Default business process (BT-23) | `urn:fdc:peppol.eu:2017:poacc:billing:01:1.0` | same | same |
| Additionally required | buyer reference (BT-10), seller electronic address (BT-34), buyer electronic address (BT-49), seller contact (BG-6), payment instructions (BG-16) | same | as XRechnung 3.0, plus contract reference (BT-12), tender or lot reference (BT-17) and at least one vehicle line (`BR-DE-CVD-…`) |
| Embedded in a PDF | `xrechnung.xml`, XMP conformance level `XRECHNUNG`, `fx:Version` `3.0` | same | same |
| Extras | VAT rate written in every breakdown, 0 for category `O` (BR-DE-14); cash discounts as `#SKONTO#` lines in the payment terms (BT-20); the [XRechnung rules](#xrechnung-rules) | sub lines, third party payments (BG-DEX-09, UBL invoices only), identifier schemes `XR01` to `XR03` in the ICD and EAS code lists | the item classification scheme `CVD` (BT-158-1) in the UNTDID 7143 code list; the [CVD rules](#xrechnung-cvd) |

The profiles exist only when the pack is registered. Without it, the formats `xrechnung-cii` and `xrechnung-ubl` cannot be rendered, and incoming XRechnung files are reported as an unknown profile (`QUITTIO-READER-UNKNOWN-PROFILE`).

```php
use Quittio\Laravel\Facades\Quittio;

Quittio::make($invoice)->format('xrechnung-ubl')->render();                                   // XRechnung 3.0, UBL
Quittio::make($invoice)->format('xrechnung-ubl')->profile('xrechnung-extension')->render();   // extension
Quittio::make($invoice)->format('xrechnung-cii')->profile('xrechnung-cvd')->render();         // Clean Vehicles Directive
Quittio::make($invoice)->format('zugferd')->profile('xrechnung')->render();                   // PDF/A-3 with xrechnung.xml
```

The automatic choice always uses `xrechnung`, also when the invoice carries extension data: the specification identifier never switches by itself. Choose `xrechnung-extension` explicitly for extension data. With `xrechnung`, third party payments fail with `QUITTIO-PROFILE-XRECHNUNG-EXTENSION-ONLY`. The same holds for `xrechnung-cvd`: request it when the buyer asks for an XRechnung CVD.

#### XRechnung CVD

A public buyer that procures road vehicles reports them under the Clean Vehicles Directive. The invoice then names the contract and the tender or lot, and every vehicle line carries the vehicle category as item classification with the scheme `CVD` (BT-158, `M1`, `M2`, `M3`, `N1`, `N2` or `N3`) and the vehicle type as item attribute `cva` (BT-160, BT-161: `clean`, `zero-emission` or `other`):

```php
use Quittio\Core\Enums\UnitCode;
use Quittio\Country\DE\Tax\VatPreset;
use Quittio\Laravel\Facades\Quittio;

$invoice = Quittio::invoice()
    ->buyerReference('04011000-12345-03')
    ->contractReference('V-2026-0017')                       // BT-12
    ->tenderReference('LOS-3')                               // BT-17
    ->line(fn ($line) => $line->name('Elektro-Kleinbus')->quantity(1)->netPrice('68000.00')->vat(VatPreset::Standard)
        ->classification('M2', 'CVD')                        // vehicle category (BT-158)
        ->attribute('cva', 'zero-emission'))                 // vehicle type (BT-160, BT-161)
    ->line(fn ($line) => $line->name('Einweisung')->quantity(2, UnitCode::Hour)->netPrice('95.00')->vat(VatPreset::Standard));
    // … seller, buyer, payment as for every XRechnung

Quittio::make($invoice)->format('xrechnung-cii')->profile('xrechnung-cvd')->render();
```

The constants of `XRechnung30Cvd` hold the values (`CLASSIFICATION_SCHEME`, `VEHICLE_CATEGORIES`, `ATTRIBUTE`, `ATTRIBUTE_VALUES`). Lines without a vehicle, such as services, need neither. Credit notes are possible in both syntaxes.

### XRechnung rules

`Quittio\Country\DE\Rules\XRechnungRules` (level L3) are the business rules of the XRechnung profiles, with codes `BR-DE-…`. They apply whenever the profile is `xrechnung`, `xrechnung-extension` or `xrechnung-cvd`, whatever the seller's country; the rules `BR-DE-CVD-…` only with the specification identifier of XRechnung CVD.

The severity follows the flags of the official Schematron, the rule file of the official validator. Rules flagged `fatal` ("muss", must) are errors, rules flagged `warning` ("soll", should) are warnings, and `BR-DE-TMP-32` (flagged `information`) is an info. The KoSIT validator (the official XRechnung validator) remains authoritative (see [Validation](validation.md)).

| Rule | Severity | Reported when |
|---|---|---|
| `BR-DE-1` | error | no payment instructions (BG-16) |
| `BR-DE-2` | error | no seller contact (BG-6) |
| `BR-DE-3`, `BR-DE-4` | error | seller city (BT-37) or postcode (BT-38) missing |
| `BR-DE-5`, `BR-DE-6`, `BR-DE-7` | error | seller contact without name (BT-41), phone (BT-42) or e-mail (BT-43) |
| `BR-DE-8`, `BR-DE-9` | error | buyer city (BT-52) or postcode (BT-53) missing |
| `BR-DE-10`, `BR-DE-11` | error | a deliver-to address (BG-15) without city (BT-77) or postcode (BT-78) |
| `BR-DE-15` | error | no buyer reference (BT-10); for public buyers this is the Leitweg-ID |
| `BR-DE-16` | error | a category other than `O` is used and the seller has no VAT identifier (BT-31), tax number (BT-32) or tax representative (BG-11) |
| `BR-DE-17` | warning | type code (BT-3) not one of 326, 380, 384, 389, 381, 875, 876, 877 |
| `BR-DE-18` | error | a line of the payment terms text (BT-20) starts with `#` but does not match `#SKONTO#TAGE=n#PROZENT=n.nn#` (optionally `BASISBETRAG=n.nn#`) |
| `BR-DE-19` | warning | SEPA credit transfer (58) with an invalid IBAN (BT-84) |
| `BR-DE-20` | warning | SEPA direct debit (59) with an invalid debited IBAN (BT-91) |
| `BR-DE-22` | error | attachment file names (BT-125-2) not unique |
| `BR-DE-23-a`, `BR-DE-23-b` | error | credit transfer (30, 58) without credit transfer data (BG-17), or with card (BG-18) or direct debit (BG-19) data |
| `BR-DE-24-a`, `BR-DE-24-b` | error | card payment (48, 54, 55) without card data (BG-18), or with BG-17 or BG-19 data |
| `BR-DE-25-a`, `BR-DE-25-b` | error | direct debit (59) without direct debit data (BG-19), or with BG-17 or BG-18 data |
| `BR-DE-26` | warning | corrected invoice (384) without preceding invoice reference (BG-3) |
| `BR-DE-27` | warning | seller phone (BT-42) with fewer than three digits |
| `BR-DE-28` | warning | seller e-mail (BT-43) without exactly one `@` surrounded by at least two characters |
| `BR-DE-30`, `BR-DE-31` | error | direct debit (BG-19) without creditor identifier (BT-90) or debited account (BT-91) |
| `BR-DE-TMP-32` | info (flag `information` in the Schematron) | no delivery date (BT-72), invoicing period (BG-14) or period on every line (BG-26) |
| `BR-DEX-01` | not checked | guaranteed by `MimeType`: its codes are exactly those the extension allows; `BR-CL-24` accepts `MimeType::Xml` (application/xml) only in the extension |
| `BR-DEX-02` | warning | extension, UBL: the net amount of a line (BT-131) with sub lines is not the sum of the net amounts of its direct sub lines; replaces `QUITTIO-SUBLINES` |
| `BR-DEX-03` | not checked | guaranteed by the data model: every sub line has exactly one `LineVat`, which the UBL writer writes as one VAT information (BG-DEX-06) |
| `BR-DEX-04` | error | extension: scheme of a seller (BT-29-1), buyer (BT-46-1) or payee (BT-60-1) identifier neither in ISO/IEC 6523 nor `XR01` to `XR03`; `SEPA` is accepted for seller and payee, except in CII; replaces `BR-CL-10` |
| `BR-DEX-05` | error | extension: scheme of a legal registration identifier (BT-30-1, BT-47-1, BT-61-1) neither in ISO/IEC 6523 nor `XR01` to `XR03`; replaces `BR-CL-11` |
| `BR-DEX-06` | error | extension: scheme of an item standard identifier (BT-157-1) neither in ISO/IEC 6523 nor `XR01` to `XR03`; replaces `BR-CL-21` |
| `BR-DEX-07` | error | extension: scheme of an electronic address (BT-34-1, BT-49-1) neither in the EAS code list nor `XR01` to `XR03`; replaces `BR-CL-25` |
| `BR-DEX-08` | error | extension: scheme of the delivery location identifier (BT-71-1) neither in ISO/IEC 6523 nor `XR01` to `XR03`; replaces `BR-CL-26` |
| `BR-DEX-09` | error | extension, UBL: amount due (BT-115) is not BT-112 − BT-113 + BT-114 + the third party payment amounts (BT-DEX-002); replaces `BR-CO-16` |
| `BR-DEX-10` | error | extension, UBL: a third party payment (BG-DEX-09) without type (BT-DEX-001) |
| `BR-DEX-11` | not checked | guaranteed by the data model: `ThirdPartyPayment` requires an amount (BT-DEX-002), and the reader skips payments without one |
| `BR-DEX-12` | error | extension, UBL: a third party payment (BG-DEX-09) without description (BT-DEX-003) |
| `BR-DEX-13` | error | extension, UBL: a third party payment amount (BT-DEX-002) with more than two decimals; amounts created with `Amount::of()` always have two, and a `Money` with more cannot be written |
| `BR-DEX-14` | error | extension, UBL: a third party payment amount (BT-DEX-002) in another currency than the invoice currency (BT-5) |
| `BR-DEX-15` | error | sub lines in XRechnung CII (sub lines need the extension in UBL) |
| `DE-XRECHNUNG-EXT-01` | error | a credit note in profile `xrechnung-extension` with syntax UBL; the official validator configuration has no scenario for it. Cancel with the strategy `negative_invoice` (type code 380, negative amounts). |
| `BR-DE-CVD-01`, `BR-DE-CVD-02` | error | XRechnung CVD without contract reference (BT-12) or tender or lot reference (BT-17) |
| `BR-DE-CVD-03` | error | XRechnung CVD without a line that has an item classification (BT-158) with scheme `CVD` and an item attribute `cva` (BT-160) |
| `BR-DE-CVD-04` | error | an item classification with scheme `CVD` that is not `M1`, `M2`, `M3`, `N1`, `N2` or `N3` |
| `BR-DE-CVD-05` | error | an item attribute `cva` whose value (BT-161) is not `clean`, `zero-emission` or `other` |
| `BR-DE-CVD-06-a`, `BR-DE-CVD-06-b` | error | a line with a `CVD` classification that has not exactly one `cva` attribute, or a line with a `cva` attribute that has not exactly one `CVD` classification |

The `BR-DEX-…` rules apply only to the profile `xrechnung-extension`; those marked UBL are skipped when the XML is written in CII, where the official Schematron does not have them. As in the official validator configuration, where the replaced rules are only an information, the extension replaces `BR-CL-10`, `-11`, `-21`, `-25` and `-26` with `BR-DEX-04` to `BR-DEX-08` and, in UBL, `BR-CO-16` with `BR-DEX-09` and `QUITTIO-SUBLINES` with `BR-DEX-02` (`XRechnung30Extension::replacedRules()`), so a violation is reported once, with the official code.

`#SKONTO#` lines state a cash discount (Skonto) in a form software can read: `TAGE` is the number of days, `PROZENT` the percentage and `BASISBETRAG` the base amount. Quittio writes structured cash discounts itself (`#SKONTO#TAGE=7#PROZENT=2.00#`), so `BR-DE-18` only concerns lines you put into the payment terms text yourself.

### Small amount invoices

German law allows a simplified invoice for small amounts (section 33 UStDV). `->de()->smallAmount()` marks an invoice of at most 250.00 EUR gross as such a small amount invoice. The gross total counts, including exempt or non-taxable parts (section 14.6 (4) UStAE). The limit of the supply date applies (`TaxTables::SMALL_AMOUNT_LIMIT`: 150.00 EUR from 2007, 250.00 EUR from 2017; the raise applies to supplies from 1 January 2017), or of the issue date if the invoice states no supply date. As long as no reverse charge (`AE`) or intra-community supply (`K`) is involved, it relaxes:

- `DE-USTG-01`: the buyer's address is not required (the seller's still is);
- `DE-USTG-02`: no tax number or VAT identifier is required;
- `DE-USTG-03`: no service date is required;
- the mandatory PDF output for the tax number and the service date;
- the format: `pdf` instead of `zugferd` when seller and buyer are in the German Inland. A Leitweg-ID still leads to XRechnung (see [Format choice](#format-choice)).

`DE-USTG-08` enforces the limit. `DE-USTG-12` excludes reverse charge and intra-community supplies (section 33 sentence 3 UStDV): such invoices need all mandatory information and are not rendered as plain PDF. Possible distance sales (goods sent to a consumer in another member state, section 3c UStG) keep the simplifications, with a warning.

### Builder macros

`Quittio\Country\DE\Builder\GermanInvoiceMacros` adds methods for German special cases to `InvoiceBuilder`, and so to `Quittio::invoice()`:

| Macro | Use it for | Writes into the invoice |
|---|---|---|
| `smallAmount()` | an invoice of up to 250 € gross ([Small amount invoices](#small-amount-invoices)) | flag `de.small_amount` |
| `propertyRelatedService(string $locale = 'de')` | a service in connection with a property for a non-business buyer, who must keep the invoice for two years | flag `de.property_related_service` and the note `LegalTexts::PROPERTY_RETENTION` (subject `REG`) |
| `newVehicle(string $locale = 'de')` | the intra-community supply of a new vehicle | flag `de.new_vehicle` and the note `LegalTexts::NEW_VEHICLE` (subject `REG`). The vehicle details are your line attributes (`DE-USTG-07`). The vehicle lines use `VatPreset::IntraCommunitySupply` (`DE-USTG-13`), also for small businesses (`DE-USTG-14`). |
| `selfBilled(string $locale = 'de')` | a self-billed invoice ([Self-billing](#self-billing-gutschrift)) | type code 389 and the note `LegalTexts::SELF_BILLING` (subject `REG`) |
| `deductAdvance(InvoiceData $advance, string $locale = 'de')` | a final invoice that deducts an advance invoice ([Final invoices](#final-invoices-deductadvance)) | preceding invoice (BG-3), prepaid amount (BT-113) and the note `LegalTexts::ADVANCE_DEDUCTION` (subject `REG`) |
| `consumerDefaultNotice(string $locale = 'de')` | the notice that a consumer is in default 30 days after the due date (section 286 (3) BGB) | the note `LegalTexts::CONSUMER_DEFAULT` (subject `PMT`) |
| `discountAgreement(string $agreement, string $locale = 'de')` (only as `->de()->discountAgreement()`) | an agreed bonus, rebate or other reduction whose amount is not known yet (section 14 (4) no. 7 UStG); naming the agreement is enough (section 14.5 (19) UStAE) | the note `LegalTexts::DISCOUNT_AGREEMENT` (subject `REG`) |
| `smallBusinessId(string $id)` (only as `->de()->smallBusinessId()`) | a small business that states its small business identification number (suffix `EX`) instead of the tax number ([Small businesses](#small-businesses)) | the number as the seller's tax number (BT-32) and the flag `small_business` |
| `serviceDateOpen(string $locale = 'de')` (only as `->de()->serviceDateOpen()`) | a prepayment invoice (386) whose date of the service is not agreed yet (section 14.8 (4) sentence 5 UStAE) | flag `de.service_date_open` and the note `LegalTexts::SERVICE_DATE_OPEN` (subject `REG`); no `DE-USTG-03` warning |

The notes and their texts are listed in [Legal texts](#legal-texts).

Macros are invisible to IDEs and static analysis. The pack ships a stub with their signatures, `stubs/InvoiceBuilder.stub.php`; it is never loaded at runtime. Add it to PHPStan (`vendor/quittio/quittio/packages/country-de/stubs/…` with the complete package):

```yaml
parameters:
    stubFiles:
        - vendor/quittio/country-de/stubs/InvoiceBuilder.stub.php
```

- The `$locale` parameter selects the language of the note. It is independent of the invoice locale.
- A locale with a region uses the text of its language (`en_GB` → English). Locales without a text of the pack get the German text (`LegalTexts::text()`).
- Notes with subject `REG` (regulatory information) are listed in the PDF's legal notes (`legalNotes`). Notes with subject `PMT` (payment information) appear with the other notes.

#### Final invoices (`deductAdvance`)

A final invoice (Schlussrechnung, section 14 (5) sentence 2 UStG) states the full service with its full VAT and deducts the advance payments received. `->de()->deductAdvance($advance)` does this for one advance invoice:

```php
use Quittio\Core\Enums\UnitCode;
use Quittio\Country\DE\Tax\VatPreset;
use Quittio\Laravel\Facades\Quittio;

$advance = $advanceResult->data();                 // the rendered advance invoice, already calculated

$final = Quittio::invoice()
    // number, dates, buyer, payment …
    ->line('Website', netPrice: '3000.00', unit: UnitCode::Piece, vat: VatPreset::Standard)
    ->de()->deductAdvance($advance);
```

The advance invoice must be calculated. The data of a rendered invoice (`$result->data()`) already is; calculate data you built yourself with `(new En16931Calculator)->calculate($advanceInvoice)` (`Quittio\Core\Calculation\En16931Calculator`).

With an advance invoice RE-2026-0040 of 1,000.00 EUR net plus 19 % VAT, the final invoice shows a gross total of 3,570.00 EUR, VAT 570.00 EUR, prepaid 1,190.00 EUR and an amount due of 2,380.00 EUR, with the note:

> Abzüglich Anzahlung gemäß Rechnung RE-2026-0040 vom 01.09.2026: Entgelt 1.000,00 € zzgl. 19 % Umsatzsteuer 190,00 € (§ 14 Abs. 5 Satz 2 UStG).

With `->de()->deductAdvance($advance, 'en')` the note is in English: "Less advance payment according to invoice RE-2026-0040 of 01/09/2026: net 1,000.00 € plus 19% VAT 190.00 € (section 14 (5) sentence 2 UStG)."

- **Checks.** The advance invoice must be calculated (`totals` set), have a positive gross total and be in the currency of the final invoice. Otherwise an `InvalidArgumentException` is thrown. Set `->currency()` before calling the macro.
- **Accepted documents.** Only documents that can be advance invoices are accepted (`GermanInvoiceMacros::ADVANCE_TYPES`): type codes 380 (commercial invoice), 386 (prepayment invoice), 326 (partial invoice), 875 (partial construction invoice) and 389 (self-billed invoice). Credit notes, debit notes, corrected invoices (384), final invoices (876, 877) and cancellations by negative invoice are rejected.
- **Amount.** The deducted amount is the advance invoice's gross total plus its cash rounding (BT-114).
- **Itemisation.** Every VAT row of the advance invoice is itemised. Standard rated rows read "Entgelt … zzgl. … Umsatzsteuer …" ("net … plus … VAT …", `LegalTexts::ADVANCE_DEDUCTION_TAXED`), other rows "Entgelt … ohne Umsatzsteuer" ("net … without VAT", `LegalTexts::ADVANCE_DEDUCTION_UNTAXED`). Rows are separated by semicolons.
- **Language.** The whole note is in one language: the language of `$locale` if the pack has it (`de`, `en`), otherwise German. Amounts and dates are formatted in `$locale` when the note is in its language (e.g. `de_AT`). Otherwise they are formatted in the language of the note: `fr` gives the German note with German formatting.
- **Several advance invoices.** Call the macro once per advance invoice; the prepaid amounts add up.
- **Payment.** The macro does not check whether the advance invoice was paid. Deduct only payments you received.

A complete example with the advance invoice is in [Examples: Advance payment and final invoice](examples.md#advance-payment-and-final-invoice).

### Self-billing (Gutschrift)

In self-billing, the buyer issues the invoice for the seller's supply. German law requires the word "Gutschrift" on such an invoice (section 14 (4) no. 10 UStG). Quittio uses "Gutschrift" only for self-billing; commercial credit notes and cancellations carry other titles.

- `->de()->selfBilled()` sets type code 389 and adds the note "Gutschrift" (English variant: "Gutschrift (self-billed invoice)").
- The PDF is titled "Gutschrift" in German and "Self-billed invoice (Gutschrift)" in English, and shows the buyer as issuer.
- `DE-USTG-04` requires the label on every self-billed type code (389, 261, 527).
- The mandatory output requires the title and the "Gutschrift" note in the PDF.

A complete example is in [Examples: Self-billing](examples.md#self-billing).

### Cancellations and corrections

`CountryDE::correctionPolicy()` sets how invoices of a German seller are cancelled and corrected (see [Building invoices: cancellations and corrections](building-invoices.md#cancellations-and-corrections)):

- **Cancellation strategy `credit_note`:** a cancellation is a credit note (type code 381) with positive amounts.
- **Correction strategy `cancel_and_reissue`:** the Managed layer corrects an invoice by cancelling it and issuing a new one.

Both cancellation strategies are permitted in Germany. Change them with `quittio.corrections.cancellation_strategy` and `quittio-managed.correction.strategy`. For the XRechnung extension in UBL use `negative_invoice` (`DE-XRECHNUNG-EXT-01`).

A self-billed invoice (389) with a Leitweg-ID (BT-10), i.e. sent as XRechnung, is cancelled by a negative self-billed invoice (389) instead of a self-billed credit note (261): XRechnung lists 261 not among its type codes and warns (`BR-DE-17`). Receiving portals of public buyers may treat that warning strictly. An explicit `$strategy` or `corrections.cancellation_strategy` still wins. ZUGFeRD keeps 261.

A corrected invoice (384) should state that it corrects and replaces the original (`DE-USTG-18`); `Quittio::correction()` adds the note. A price reduction (`Quittio::priceReduction()`) is titled "Entgeltminderung".

The policy also sets the document titles:

| Kind | German | English |
|---|---|---|
| `cancellation` | Stornorechnung | Cancellation invoice |
| `cancellation_self_billed` | Storno-Gutschrift | Cancellation of the self-billed invoice (Storno-Gutschrift) |
| `correction` | Rechnungskorrektur | Corrected invoice |
| `price_reduction` | Entgeltminderung | Price reduction |
| `self_billed` | Gutschrift | Self-billed invoice (Gutschrift) |

- `cancellation` and `cancellation_self_billed` title the documents of `Quittio::cancellation()`.
- `correction` titles a corrected invoice (384) of `Quittio::correction()` and of the Managed layer.
- `price_reduction` titles the documents of `Quittio::priceReduction()`.
- `self_billed` titles self-billed invoices (389, 261, 527) unless the presentation sets a title. Locales without a title of their own fall back to English ("Self-billed invoice (Gutschrift)"), which keeps the word "Gutschrift" required by section 14 (4) no. 10 UStG.

### Dunning law

When a customer pays late, German law (section 288 BGB) gives the seller default interest (Verzugszinsen) and, from business debtors, a flat rate of 40 EUR. The pack provides these values and a calculator for the interest. Quittio's documents do not apply them by themselves: calculate the interest with [`DefaultInterest`](#default-interest) and pass the results to a payment reminder (see [Documents: payment reminders](documents.md#payment-reminders)).

`CountryDE::dunningLaw()` returns these values:

| Value | Germany |
|---|---|
| Interest for business debtors | base rate plus 9 percentage points |
| Interest for consumers | base rate plus 5 percentage points |
| Flat rate for business debtors | 40.00 EUR |
| Day count | 365 |
| Consumer default | 30 days after due date and receipt of the invoice, if the invoice points this out (`->de()->consumerDefaultNotice()`) |
| Reference rate | "Basiszinssatz (§ 247 BGB)", the base rate of section 247 BGB |

#### Default interest

`Quittio\Country\DE\Dunning\DefaultInterest` calculates the default interest on an open amount:

| Method | Result |
|---|---|
| `__construct(?BaseRateTable $rates = null, ?DunningLaw $law = null)` | Without a table, `BaseRates::table()` is used (shipped and registered rates). Without a law, `CountryDE::dunningLaw()` (points, flat rate and day count). |
| `calculate(Money $amount, Date $from, Date $until, bool $consumer = false, string $locale = 'de'): list<InterestCharge>` | One charge per base rate period, from `$from` (first day with interest) to `$until` (exclusive). Rate = base rate + 9 points, or + 5 points with `$consumer`. Empty for a non-positive amount or when `$until` is not after `$from`. `InvalidArgumentException` for days the [base rate table](#base-rate-table) does not cover. |
| `flatRate(string $currency = 'EUR'): ?Money` | The flat rate of 40.00 for business debtors (section 288 (5) BGB), once per claim |

```php
use Brick\Money\Money;
use Quittio\Core\Support\Amount;
use Quittio\Core\Support\Date;
use Quittio\Country\DE\Dunning\DefaultInterest;
use Quittio\Laravel\Facades\Quittio;

$interest = new DefaultInterest;
$reminder = Quittio::paymentReminder(1)                          // first reminder ("1. Mahnung"); seller and bank account from the config
    ->number('M-2026-0003')
    ->buyer($buyer)
    ->openItem('RE-2026-0042', '2026-06-01', '1190.00', dueDate: '2026-06-15');

// first day with interest: the day after the due date; 2026-10-04 is the first day without interest
foreach ($interest->calculate(Money::of('1190.00', 'EUR'), Date::parse('2026-06-16'), Date::parse('2026-10-04')) as $charge) {
    $reminder->interest(Amount::format($charge->base), $charge->ratePercent, $charge->from, $charge->until, $charge->description, dayCount: $charge->dayCount);
}
$reminder->fee('Pauschale nach § 288 Abs. 5 BGB', Amount::format($interest->flatRate()));   // "flat rate under section 288 (5) BGB"
```

- **Descriptions.** They name the rate, the base rate, the points and the last day with interest. With the locale `de` (the default) a description reads "Verzugszinsen 10,27 % p. a. (Basiszinssatz 1,27 % + 9,00 Prozentpunkte) vom 16.06.2026 bis 30.06.2026". Any other locale gives "Default interest 10.27 % p.a. (base rate 1.27 % + 9.00 points) from 2026-06-16 to 2026-06-30".
- **Day count.** The charges use the day count of the dunning law (`InterestCharge::$dayCount`; 365 for `CountryDE::dunningLaw()`, so a leap year yields 366/365 of the annual rate). Pass it to `interest()` as above (`dayCount: $charge->dayCount`); without it the reminder counts actual/actual.
- **Periods.** A charge covers a whole period with the same base rate. Interest from 1 December 2025 to 28 February 2026 is one charge, although the table has an entry for 1 January 2026.
- **Flat rate.** It is due only from business debtors, once per claim. The documents layer does not track which claims already carried it.
- **Consumers.** For consumers, `->de()->consumerDefaultNotice()` adds the notice on default after 30 days to the invoice.

A complete reminder is in [Examples: Payment reminder](examples.md#payment-reminder).

#### Base rate table

The base rate (Basiszinssatz, section 247 BGB) changes on 1 January and 1 July. `Quittio\Country\DE\Dunning\BaseRateTable` holds the rates. Quittio ships these (`BaseRateTable::PUBLISHED`):

| Valid from | Rate in % |
|---|---|
| 2023-01-01 | 1.62 |
| 2023-07-01 | 3.12 |
| 2024-01-01 | 3.62 |
| 2024-07-01 | 3.37 |
| 2025-01-01 | 2.27 |
| 2025-07-01 | 1.27 |
| 2026-01-01 | 1.27 |
| 2026-07-01 | 1.52 |

Rates after the last entry are unknown until they are published. Interest for days from 1 January 2027 is therefore rejected instead of silently using the old rate. There are three ways to add new rates without a new Quittio release:

1. **Laravel configuration.** Add the rate to `quittio.countries.de.base_rates` in `config/quittio/countries/de.php` (publish it with `php artisan vendor:publish --tag=quittio-de-config`; see [Configuration](configuration.md#country-pack-germany-quittiocountriesde)). Up to 0.3 this was `config/quittio-de.php`, which is still read until 1.0.

   ```php
   'base_rates' => ['2027-01-01' => '1.77'],
   ```

2. **Automatic update from the Deutsche Bundesbank (Laravel).** `php artisan quittio:base-rate:update` fetches the series `BBIN1.M.DE.BBK.BBKBAS2.EUR.ME` ("Basiszinssatz gemäß BGB", the base rate under the BGB) from the Bundesbank's statistics API ([command](commands.md#quittiobase-rateupdate)).

   - It stores the rates the shipped table does not contain in `quittio.countries.de.base_rate_file` (default `storage/app/quittio/base-rates.json`), also those before 2023 for older claims.
   - It writes the file only when something changed; `--dry-run` shows the rates without writing.
   - A value that differs from the shipped table is reported, and the Bundesbank value is used.
   - Without a file (`base_rate_file` set to null) the command fails, except with `--dry-run`.

   Schedule it, e.g. weekly, so that the rate of 1 January and 1 July is there as soon as the Bundesbank has published it:

   ```php
   // routes/console.php
   Schedule::command('quittio:base-rate:update')->weekly();
   ```

3. **Without Laravel.** Call `BaseRates::register(['2027-01-01' => '1.77'])` once at start-up, fetch the rates yourself with `(new BundesbankBaseRates)->fetch()`, or use a table for one calculation:

   ```php
   $rates = (new BaseRateTable)->withRate('2027-01-01', '1.77');
   $interest = new DefaultInterest($rates);
   ```

`DefaultInterest` without a table uses `BaseRates::table()`: the shipped table, then the rates of the file, then those of `quittio.countries.de.base_rates`. The configuration wins for the same date. The service provider `Quittio\Country\DE\Laravel\GermanyServiceProvider` registers file and configuration at boot; Laravel discovers it automatically.

`BaseRateTable`:

| Method | Result |
|---|---|
| `__construct(array $rates = self::PUBLISHED)` | ISO date => rate in percent, sorted by date |
| `withRate(Date\|string $from, string $rate): self` | new table with an additional or replaced rate |
| `rateOn(Date $date): BigDecimal` | rate on a day; `InvalidArgumentException` before the first entry and from `validUntil()` on |
| `periods(Date $from, Date $until): list<array{from: Date, until: Date, rate: BigDecimal}>` | periods with a constant rate (start inclusive, end exclusive), split where the rate changes. A table entry that keeps the rate (e.g. 2026-01-01) starts no new period. `InvalidArgumentException` if `$until` is after `validUntil()`. |
| `lastChange(): Date` | date of the last entry, e.g. to warn when the table is outdated |
| `validUntil(): Date` | first day without a known rate: the next 1 January or 1 July after the last entry (with the published table: 2027-01-01) |

`Quittio\Country\DE\Dunning\BaseRates`:

| Method | Result |
|---|---|
| `register(array $rates): void` | adds or replaces rates (ISO date => percent). Only 1 January and 1 July, and values such as `'1.77'` or `'-0.88'`; otherwise `InvalidArgumentException`. |
| `table(): BaseRateTable` | the shipped table with the registered rates |
| `registered(): array` / `reset(): void` | the registered rates; removes them (tests) |

`Quittio\Country\DE\Dunning\BundesbankBaseRates`:

| Method | Result |
|---|---|
| `__construct(?Closure $http = null, int $timeout = 15)` | `$http` receives URL and timeout and returns the response body (for tests or your own HTTP client); default `file_get_contents()` |
| `fetch(string $since = '2002-01'): array` | the January and July values from the given month on, ISO date => percent; `RuntimeException` when the API cannot be reached or returns no rates |
| `parse(string $csv): array` | reads the CSV download (`2026-07;1,52;…`) |

### DIN 5008 letter layouts

DIN 5008 is the German standard for business letters. It fixes where the address field sits, so that the address shows in the window of a window envelope, and where the fold and punch marks are. `Quittio\Country\DE\Layouts\Din5008` provides two forms: form A (`din5008a`) with a low letterhead and form B (`din5008b`) with a higher one. Form B is the German default.

`Din5008` has the methods `formA()`, `formB()` and `all()` (form A, form B and `free`). Values are in millimetres, as in DIN 5008:2020. They are unchanged since the 2011 edition, which enlarged the address field of both forms from 40 to 45 mm and moved the return address line (Rücksendeangabe) into the zone for additional information and notes (Zusatz- und Vermerkzone).

| | `din5008a` (`Din5008::FORM_A`) | `din5008b` (`Din5008::FORM_B`) |
|---|---|---|
| Address field from top / left | 27 / 20 | 45 / 20 |
| Address field width × height | 85 × 45 | 85 × 45 |
| Zone for additional information and notes (Zusatz- und Vermerkzone), above the recipient | 17.7 (five lines, filled from the bottom); its last 5 mm hold the sender line (Rücksendeangabe) | same |
| Recipient zone (Anschriftzone, six lines) | 27.3, from 44.7 | 27.3, from 62.7 |
| Fold marks | 87 and 192 | 105 and 210 |
| Punch mark | 148.5 | 148.5 |
| Letterhead height in the default templates (top margin 15) | 12 | 30 |

- The address field keeps its position when the invoice has a logo.
- The default templates print the sender line at the top of the address field and the recipient directly below it, inside the envelope window. The zones above are for your own templates.
- Select a layout per invoice with `->presentation(['letterLayout' => 'din5008a'])`, or for all invoices with `quittio.pdf.layout`.
- Fold and punch marks are printed unless `quittio.pdf.fold_marks` (core option `fold_marks`) is `false`.

See [Templates: letter layouts](templates.md#letter-layouts).

### UStG rules

`Quittio\Country\DE\Rules\UstgRules::all()` (level L3) checks the mandatory content of an invoice under the German VAT Act (sections 14 (4) and 14a UStG) and the simplifications of sections 33 and 34a UStDV. The rules apply to every invoice of a German seller, in every format including a plain PDF. Messages and hints are in German and English.

You rarely need this table up front: a failing rule names its code, the field and a hint. Look the code up here when the hint is not enough.

| Rule | Severity (BT, path) | Reported when | Legal basis | Fix |
|---|---|---|---|---|
| `DE-USTG-01` | error (BG-5 `seller.address`, BG-8 `buyer.address`) | One message per party that names the missing fields. The seller lacks street (address line 1), postcode or city. Or the buyer lacks street or city, or a buyer in `DE` lacks the postcode. The buyer part is skipped for small amount invoices. | section 14 (4) no. 1 UStG | Add street, postcode and city for seller and buyer. |
| `DE-USTG-02` | error (BT-31 `seller.vatId`) | The seller has neither a VAT identifier (BT-31) nor a tax number (BT-32), and there is no tax representative (BG-11). Skipped for small amount invoices. | section 14 (4) no. 2 UStG | `->vatId()` or `->taxNumber()` on the seller. |
| `DE-USTG-03` | error (BT-72 `delivery.actualDeliveryDate`); warning for prepayment invoices (386), none with `->de()->serviceDateOpen()` | No delivery date (BT-72), no invoicing period (BG-14), and not every line has a period (BG-26). Skipped for small amount invoices (section 33 UStDV) and small business invoices (section 34a UStDV: the small business flag, or a line with `VatPreset::SmallBusiness`). | section 14 (4) no. 6 UStG | `->deliveryDate()` (also when it equals the invoice date), `->invoicingPeriod()` or a `->period()` on every line. |
| `DE-USTG-04` | error (BT-22 `notes`); warning when the XML is written (option `syntax`), as the type code labels an e-invoice (section 14.5 (24) sentence 1 UStAE) | A self-billed type code (389, 261, 527), and no note contains "Gutschrift" or one of: self-billing, self billing, self-billed invoice, autofacturation, autofactura, autofatturazione, autofacturação, zelffacturering, samofakturowanie (case-insensitive). | section 14 (4) no. 10 UStG; other EU languages: section 14.5 (24) UStAE | `->de()->selfBilled()` adds the note. |
| `DE-USTG-05` | error (BT-22 `notes`) | The flag `de.property_related_service` is set and no note contains "14b". | section 14 (4) no. 9 UStG | `->de()->propertyRelatedService()` adds the note. |
| `DE-USTG-06` | error per VAT row (BT-120 `vatBreakdown[i].exemptionReason`) | A VAT breakdown row of category `AE` whose exemption reason contains neither "Steuerschuldnerschaft" nor the text of one of the presets `ReverseChargeDomestic`, `ReverseChargeEu` and `TriangularTransaction`. The preset texts count in every locale the pack ships (`de`, `en`), the invoice locale and the validation locale (case-insensitive). | section 14a (1) and (5) UStG | Use `ReverseChargeDomestic`, `ReverseChargeEu` or `TriangularTransaction`. |
| `DE-USTG-07` | error per line (BG-32 `lines[i].item.attributes`) | The flag `de.new_vehicle` is set and a line has no item attributes. | section 14a (4) UStG | `->attribute('Erstzulassung', …)` (first registration), `->attribute('Kilometerstand', …)` (mileage) on every line. |
| `DE-USTG-08` | error (BT-112 `totals.grossTotal`) | The flag `de.small_amount` is set and the gross total exceeds the limit of the supply date (`UstgRules::supplyDate()`, otherwise the issue date): 250.00 EUR from 2017, 150.00 EUR before. Other currencies count with their EUR equivalent: gross total × exchange rate of `->taxCurrency('EUR', $rate)`. Without that rate the rule always fails. | section 33 UStDV | Remove `->de()->smallAmount()` and issue a full invoice. |
| `DE-USTG-09` | error (BT-151 `lines`, or BT-102 `charges` for document level entries) | A line uses `VatPreset::SmallBusiness` or the invoice has the small business flag, and a line (sub lines included) or a document level allowance or charge has category `S`. | section 19 UStG; VAT shown on charges counts too (section 14c (2) UStG) | `VatPreset::SmallBusiness` on every line, no standard rated charges. |
| `DE-USTG-10` | warning (BT-6 `taxCurrency`) | The invoice currency is not EUR, the VAT total is not zero, and the VAT accounting currency (BT-6) is not EUR. | Article 230 VAT Directive, section 16 (6) UStG | `->taxCurrency('EUR', $rate, $date)`. |
| `DE-USTG-11` | error (BT-31 `seller.vatId`, BT-48 `buyer.vatId`) | A line uses `ReverseChargeEu` or `TriangularTransaction`, or a line of category `AE` without preset has a buyer in the VAT territory of another member state (for services Monaco counts as France, and Northern Ireland does not count). Then the VAT identifiers of the seller (or of the tax representative) and of the buyer are required; each missing one is reported. A tax number is not sufficient. A small business (flag or `VatPreset::SmallBusiness`) needs no VAT identifier of its own (section 19 (1) sentence 2 UStG). | section 14a (1) and (7) UStG | `->vatId()` for seller and buyer. |
| `DE-USTG-12` | error (BT-151 `lines`); warning for possible distance sales | The flag `de.small_amount` is set and category `AE` or `K` is used (error). Or the buyer is a consumer and the destination lies in another member state (warning: a distance sale only if goods are transported there). The destination is the deliver-to address if it has a country, otherwise the buyer's address. | section 33 sentence 3 UStDV; section 3c UStG | Remove `->de()->smallAmount()` and issue a full invoice. |
| `DE-USTG-13` | warning (BT-151 `lines`) | The flag `de.new_vehicle` is set and no line, allowance or charge uses category `K`. A warning, as the exemption may not apply in the individual case. | section 14a (4) UStG | `VatPreset::IntraCommunitySupply` on the vehicle lines, or remove `->de()->newVehicle()`. |
| `DE-USTG-14` | error (BT-151 `lines`) | A line uses `VatPreset::SmallBusiness` or the invoice has the small business flag, a line, allowance or charge uses category `K`, and the flag `de.new_vehicle` is not set. | section 19 (1) sentence 2, (6) sentence 1 UStG | `VatPreset::SmallBusiness` on the lines; `->de()->newVehicle()` for the intra-community supply of a new vehicle. |
| `DE-USTG-15` | warning (BT-2 `issueDate`) | A line, allowance or charge uses category `K`, or a line uses `ReverseChargeEu` (or category `AE` without preset for a buyer in another member state), and the invoice date is after the 15th of the month following the supply. The supply date is the latest of the delivery date (BT-72), the end of the invoicing period (BG-14) and the ends of the line periods (BG-26). Skipped without a supply date, for the type codes 381, 261, 384 and 386, and for re-issued invoices (BG-3 without a prepaid amount). Final invoices (BG-3 with a prepaid amount BT-113) are checked. A warning, as a late invoice stays valid. | section 14a (1) sentence 2 and (3) sentence 1 UStG | Issue these invoices by the 15th of the following month. |
| `DE-USTG-16` | warning (BT-2 `issueDate`) | The invoice date is more than six months after the supply (`UstgRules::supplyDate()`). Only for business buyers and for services in connection with a property (flag `de.property_related_service`), where an invoice is obligatory. Skipped without a supply date and for the same documents as `DE-USTG-15`. A warning, as a late invoice stays valid. | section 14 (2) sentences 1 and 2 UStG | Issue invoices within six months of the supply. |
| `DE-USTG-17` | warning (BT-7 `taxPointDate`) | A prepayment invoice (386) states an amount already paid (BT-113 above zero) but no VAT point date (BT-7). A warning, as a payment received on the issue date needs no date of its own. | section 14 (4) sentence 1 no. 6 UStG | `->taxPointDate($received)`. |
| `DE-USTG-18` | warning (BT-22 `notes`) | A corrected invoice (384) has no note that contains "berichtigt", "ersetzt", "korrigiert", "corrects", "replaces", "rectifie" or "remplace". | section 31 (5) UStDV, section 15.2a (7) UStAE | `Quittio::correction()` adds "Diese Rechnung berichtigt und ersetzt die Rechnung … vom …". |
| `DE-USTG-19` | info (BT-3 `typeCode`) | A final invoice deducted an advance settled by self-billing (389, flag `de.self_billed_advance` set by `deductAdvance()`), but is no self-billed invoice. The deduction is required; changing the way of invoicing is allowed but unusual. | section 14 (2) sentence 5, (5) sentence 2 UStG | Check with the buyer who settles the final payment. |

Helpers used by the rules and available to you:

- `UstgRules::isSmallAmountInvoice($invoice)`: the invoice has the small amount flag and its simplifications apply (no category `AE` or `K`);
- `UstgRules::isSmallBusinessInvoice($invoice)`: the small business flag is set or a line uses `VatPreset::SmallBusiness`;
- `UstgRules::hasServiceDate($invoice)`: delivery date, invoicing period or a period on every line;
- `UstgRules::supplyDate($invoice)`: the latest of the delivery date, the end of the invoicing period and the ends of the line periods, or `null` without any of them;
- `UstgRules::issuingDeadline($invoice)`: the 15th of the month after the supply, or `null` without a supply date.

#### Leitweg-ID

`UstgRules::plausibility()` (level L7) checks buyer references (BT-10 `buyerReference`) that are shaped like a Leitweg-ID but have wrong check digits:

| Rule | Severity | Reported when |
|---|---|---|
| `DE-LEITWEG-ID` | warning | The buyer is a public buyer: the profile is `xrechnung`, `xrechnung-extension` or `xrechnung-cvd`, or the buyer's electronic address (BT-49) uses scheme `0204`. Message: "The check digits of the Leitweg-ID ":value" (BT-10) are wrong." |
| `DE-LEITWEG-ID-SUSPECT` | info | Any other buyer in Germany (country `DE` or none) that is not a consumer. Message: "The buyer reference ":value" (BT-10) is shaped like a Leitweg-ID, but its check digits are wrong. It is not treated as a Leitweg-ID, so the invoice is not issued as XRechnung automatically." |

So a mistyped Leitweg-ID does not lead to ZUGFeRD unnoticed. An order number of the same shape (e.g. `4711-01`) gets the same info, which you may ignore. Neither finding changes the format.

`Quittio\Country\DE\Support\LeitwegId`:

| Method | Result |
|---|---|
| `looksLike(?string $value): bool` | the shape: a coarse address of 2 to 12 digits, an optional fine address of up to 30 letters or digits and two check digits, separated by hyphens |
| `isValid(?string $value): bool` | shape and check digits (ISO 7064 Mod 97-10) are correct |
| `checkDigits(string $address): string` | the two check digits for coarse and fine address without hyphens, e.g. `checkDigits('0401100012345')` → `03` |

#### Tax number

`UstgRules::plausibility()` also checks the tax number (Steuernummer, BT-32) of a seller in Germany. The local tax office issues this number; it is not the VAT identifier.

| Rule | Severity | Reported when |
|---|---|---|
| `DE-TAX-NUMBER` | warning (BT-32 `seller.taxNumber`) | The seller's country is `DE`, and the tax number is not a valid 13-digit ELSTER number, a 12-digit federal number or the notation of a federal state, or its check digit is wrong. Also reported when a VAT identifier (`DE…`) was given as tax number. A Bavarian notation without the tax office number (`815/08152`) cannot be checked and is accepted. So is the small business identification number of a small business (suffix `EX`, section 34a UStDV). |

The checks follow the document "Prüfung der Steuer- und Steueridentifikationsnummer" (checking tax numbers and tax identification numbers) of ELSTER, the online service of the German tax authorities, published by the Bavarian State Office for Taxes (Bayerisches Landesamt für Steuern):

- **Format.** The 13-digit ELSTER format is `FFFF0BBBUUUUP` (NRW: `FFFF0BBBBUUUP`), with the 0 at the 5th position.
- **Excluded districts.** 000, 998 and 999; below 100 in the Bavarian group; in NRW 0000, 0998 and 0999, and `UUUP` must be above 0009.
- **Check digit.** Each state has its procedure: the modified 11 procedure (Rhineland-Palatinate), the 2 procedure (Baden-Württemberg, Hesse, Schleswig-Holstein), or the 11 procedure with the factors of the state (all others; Berlin with the variants A and B by tax office and district).
- **Not checked:** whether the tax office exists.
- **Notation without state.** A notation without the state (e.g. `93/815/08150`) is valid when it is valid for any state that uses this notation.

`Quittio\Country\DE\Support\TaxNumber`:

| Method | Result |
|---|---|
| `TaxNumber::parse(string $value, ?string $state = null): ?TaxNumber` | the valid number in any notation (`198/815/08152`, `9198081508152`, `919881508152`, `053 815 08158` …), or null. `$state` (ISO 3166-2:DE, e.g. `BY`) restricts a state notation to that state. |
| `TaxNumber::validate(string $value, ?string $state = null): ?bool` | `true` valid, `false` invalid, `null` plausible but not checkable |
| `TaxNumber::looksLikeVatId(string $value): bool` | a VAT identifier (`DE` + 9 digits) |
| `->elster`, `->state`, `->formatted()` | 13-digit ELSTER format, state, notation on tax notices (e.g. `198/815/08152`, `400/8150/8159`) |

### Mandatory PDF output

After rendering, the mandatory field guard checks that the PDF shows certain values (see [Templates: mandatory field guard](templates.md#mandatory-field-guard)). `CountryDE::mandatoryOutput()` adds these German values to the base fields. Paths without a value are skipped. The legal basis is the value Quittio reports, in German citation form.

| Condition | Access key | Description | Legal basis |
|---|---|---|---|
| not a small amount invoice, and the seller has a VAT identifier or tax number, or a tax representative with VAT identifier | `seller.vatId\|seller.taxNumber\|taxRepresentative.vatId` | tax number or VAT identifier of the seller | § 14 Abs. 4 Nr. 2 UStG |
| not a small amount or small business invoice, with BT-72 or BG-14 | `servicePeriod\|deliveryDate\|delivery.date` | date of the supply or service | § 14 Abs. 4 Nr. 6 UStG |
| the same, without BT-72 and BG-14 | `servicePeriod\|lineGroups[*].lines[*].period\|lines[*].period` | date of the supply or service | § 14 Abs. 4 Nr. 6 UStG |
| self-billed type code | `title`, and the path of the first note containing "Gutschrift" | label and note "Gutschrift" | § 14 Abs. 4 Nr. 10 UStG |
| flag `de.property_related_service` | path of the retention note (its text or a note containing "14b") | retention notice for property related services | § 14 Abs. 4 Nr. 9 UStG |
| each VAT row of category `AE` or `K` | `vatRows[i].label` or the legal note with its BT-120 text | reverse charge or exemption note | § 14a UStG |
| category `AE` or `K` used | `buyer.vatId` | VAT identifier of the buyer | § 14a Abs. 1 und 3 UStG |
| category `K` used, or `AE` with a buyer country different from the seller's | `seller.vatId\|taxRepresentative.vatId` | VAT identifier of the seller | § 14a Abs. 1 und 3 UStG |

The default templates print all of them.

### Legal texts

`Quittio\Country\DE\Texts\LegalTexts::catalog()` (also `CountryDE::legalTexts()`) holds the German notices in German and English. The texts live in the pack's language files `resources/lang/{de,en}/legal.php` (the exemption reasons in `tax.php`) and are read through the translator, so in Laravel `lang/vendor/quittio-de/{locale}/legal.php` overrides single texts. Its fallback locale is `de` (`LegalTexts::FALLBACK_LOCALE`). Builder macros add some of them; texts marked "–" are for your own notes.

| Constant (key) | German | English | Added by |
|---|---|---|---|
| `SELF_BILLING` (`self_billing`) | Gutschrift | Gutschrift (self-billed invoice) | `selfBilled()` |
| `PROPERTY_RETENTION` (`property_retention`) | Sie sind gesetzlich verpflichtet, diese Rechnung, einen Zahlungsbeleg oder eine andere beweiskräftige Unterlage zwei Jahre lang aufzubewahren (§ 14b Abs. 1 Satz 5 UStG). | You are required by law to keep this invoice, a payment receipt or other conclusive document for two years (section 14b (1) sentence 5 UStG). | `propertyRelatedService()` |
| `REVERSE_CHARGE` (`reverse_charge`) | Steuerschuldnerschaft des Leistungsempfängers | Reverse charge, the recipient is liable for VAT | – (text of `VatPreset::ReverseChargeEu`) |
| `INTRA_COMMUNITY_SUPPLY` (`intra_community_supply`) | Steuerfreie innergemeinschaftliche Lieferung (§ 4 Nr. 1b i. V. m. § 6a UStG) | VAT-exempt intra-Community supply (section 4 no. 1b in conjunction with section 6a UStG) | – (text of `VatPreset::IntraCommunitySupply`) |
| `EXPORT` (`export`) | Steuerfreie Ausfuhrlieferung (§ 4 Nr. 1a i. V. m. § 6 UStG) | VAT-exempt export supply (section 4 no. 1a in conjunction with section 6 UStG) | – (text of `VatPreset::Export`) |
| `SMALL_BUSINESS` (`small_business`) | Steuerfreie Leistung nach § 19 Abs. 1 UStG (Steuerbefreiung für Kleinunternehmer). | VAT-exempt supply under section 19 (1) UStG (small business exemption). | – (text of `VatPreset::SmallBusiness`) |
| `SERVICE_DATE_OPEN` (`service_date_open`) | Der Zeitpunkt der Leistung ist noch nicht vereinbart. | The date of the service has not been agreed yet. | `serviceDateOpen()` |
| `PREPAYMENT` (`prepayment`) | Die Umsatzsteuer entsteht mit Vereinnahmung der Anzahlung. Die Leistung wird voraussichtlich am :date erbracht. | VAT becomes due on receipt of the prepayment. The service is expected to be performed on :date. | – |
| `ADVANCE_DEDUCTION` (`advance_deduction`) | Abzüglich Anzahlung gemäß Rechnung :number vom :date: :amounts (§ 14 Abs. 5 Satz 2 UStG). | Less advance payment according to invoice :number of :date: :amounts (section 14 (5) sentence 2 UStG). | `deductAdvance()` |
| `ADVANCE_DEDUCTION_TAXED` (`advance_deduction_taxed`) | Entgelt :net zzgl. :rate Umsatzsteuer :vat | net :net plus :rate VAT :vat | `deductAdvance()`, part of `:amounts` |
| `ADVANCE_DEDUCTION_UNTAXED` (`advance_deduction_untaxed`) | Entgelt :net ohne Umsatzsteuer | net :net without VAT | `deductAdvance()`, part of `:amounts` |
| `CONSUMER_DEFAULT` (`consumer_default`) | Sie kommen spätestens in Verzug, wenn Sie nicht innerhalb von 30 Tagen nach Fälligkeit und Zugang dieser Rechnung zahlen (§ 286 Abs. 3 BGB). | You will be in default at the latest if you do not pay within 30 days of the due date and receipt of this invoice (section 286 (3) BGB). | `consumerDefaultNotice()` |
| `DEFAULT_INTEREST` (`default_interest`) | Verzugszinsen :rate % p. a. (Basiszinssatz :base_rate % + :points Prozentpunkte) vom :from bis :until | Default interest :rate % p.a. (base rate :base_rate % + :points points) from :from to :until | `DefaultInterest` (interest lines of payment reminders; German numbers and dates for every German locale) |
| `DISCOUNT_AGREEMENT` (`discount_agreement`) | Etwaige Minderungen des Entgelts (Boni, Rabatte) ergeben sich aus :agreement. | Any reductions of the consideration (bonuses, rebates) follow from :agreement. | `discountAgreement()` |
| `ZERO_RATED_PV` (`zero_rated_pv`) | Steuersatz 0 % nach § 12 Abs. 3 UStG. | VAT rate 0 % under section 12 (3) UStG. | `VatPreset::ZeroRatedPv` |
| `NEW_VEHICLE` (`new_vehicle`) | Innergemeinschaftliche Lieferung eines neuen Fahrzeugs (§ 14a Abs. 4 UStG); die Fahrzeugmerkmale nach § 1b Abs. 2 und 3 UStG sind bei der Position angegeben. | Intra-Community supply of a new means of transport (section 14a (4) UStG); the vehicle details under section 1b (2) and (3) UStG are stated with the line. | `newVehicle()` |

- `REVERSE_CHARGE`, `INTRA_COMMUNITY_SUPPLY`, `EXPORT` and `SMALL_BUSINESS` are the exemption reasons of the named [tax presets](#tax-presets), in every locale the pack ships (`CountryDE::locales()`). So a notice has one wording in BT-120 and in your notes, including overrides of `tax.php`.
- Validation and rendering add no text. The builder adds the notes of the presets that require one (`StatesInvoiceNote`, see [Tax presets](#tax-presets)).
- `LegalTexts::text($key, $locale, $replace)` returns a text in the language of `$locale` (`en_GB` → English) or in German. `LegalTexts::localeOf($key, $locale)` names the catalog locale it uses.

A text for your own note, here on a prepayment invoice (386), which states that VAT is due on receipt of the payment and names the expected date of the service:

```php
use Quittio\Core\Enums\NoteSubjectCode;
use Quittio\Country\DE\Texts\LegalTexts;

$builder->type('386')->note(
    LegalTexts::catalog()->get(LegalTexts::PREPAYMENT, 'de', ['date' => '01.11.2026']),
    NoteSubjectCode::RegulatoryInformation,
);
```

Prepayment invoices (386) without a service date get a `DE-USTG-03` warning instead of an error; name the expected date as above, or state with `->de()->serviceDateOpen()` that it is not agreed yet. A prepayment invoice for a payment already received (BT-113) states the date of receipt as VAT point date (`->taxPointDate()`, BT-7) if it differs from the issue date (`DE-USTG-17`).

### Meta flags

The pack marks German special cases with meta flags on the invoice (`InvoiceData::$meta->flags`). The builder macros set them, and the rules read them. `Quittio\Country\DE\Support\Flags`:

| Constant | Value | Set by | Effect |
|---|---|---|---|
| `SMALL_AMOUNT` | `de.small_amount` | `smallAmount()` | small amount invoice: relaxed rules, PDF format, `DE-USTG-08`, `DE-USTG-12` |
| `PROPERTY_RELATED_SERVICE` | `de.property_related_service` | `propertyRelatedService()` | `DE-USTG-05`, mandatory output |
| `NEW_VEHICLE` | `de.new_vehicle` | `newVehicle()` | `DE-USTG-07`, `DE-USTG-13`, `DE-USTG-14` (exception) |
| `SERVICE_DATE_OPEN` | `de.service_date_open` | `serviceDateOpen()` | no `DE-USTG-03` warning for a prepayment invoice without service date |
| `SELF_BILLED_ADVANCE` | `de.self_billed_advance` | `deductAdvance()` of a self-billed advance (389) | `DE-USTG-19` |
| `SMALL_BUSINESS` | `small_business` (`Meta::FLAG_SMALL_BUSINESS`) | seller configuration, Managed issuer, `->flag()`, `smallBusinessId()` | small business invoice (section 34a UStDV): `DE-USTG-03`, `DE-USTG-09`, `DE-USTG-11`, `DE-USTG-14`, `DE-TAX-NUMBER`, mandatory output |
| `SMALL_AMOUNT_LIMIT` | `250.00` | – | deprecated: the limit depends on the supply date, see `TaxTables::smallAmountLimitOn()` |

### Retention

`CountryDE::retention()` returns `new RetentionRule(8, fromEndOfCalendarYear: true)`: invoices are kept for 8 years from the end of the calendar year of issue (section 14b UStG, section 147 AO as amended in 2025). An invoice issued on 3 October 2026 is kept until 31 December 2034. The Managed layer stores this date (`retention_until`); `quittio-managed.retention.years` may only lengthen it.

### BMF error classes

In its letter of 15 October 2025, the German Federal Ministry of Finance (BMF) sorts errors in e-invoices into three classes. `Quittio\Country\DE\Rules\BmfErrorClass` maps Quittio's findings to these classes ("Rz." is the marginal number in the letter):

| Case | Value | Findings | `label('de')` | `label('en')` |
|---|---|---|---|---|
| `Format` | `format` | levels L1, L2, L4, L6 | Formatfehler (Rz. 6a) | Format error (marginal no. 6a) |
| `BusinessRule` | `business_rule` | levels L3, L5 | Geschäftsregelfehler (Rz. 6b) | Business rule error (marginal no. 6b) |
| `Content` | `content` | every `DE-USTG-*` finding and level L7 | Inhaltsfehler (Rz. 35a) | Content error (marginal no. 35a) |

- `BmfErrorClass::of(ValidationMessage $message)` classifies one finding.
- `label()` returns German for `de` and English otherwise.
- `voidsEInvoice()` is true for `Format` only: the document is then not an e-invoice but an "other invoice" (sonstige Rechnung).
- `(new CountryDE)->errorClassification($result)` groups the errors and warnings of a `ValidationResult` by class value:

```php
use Quittio\Country\DE\CountryDE;

$classes = (new CountryDE)->errorClassification($result);   // ['format' => [...], 'business_rule' => [...], 'content' => [...]]
```

The validation levels are described in [Validation](validation.md); see also [Validation: Germany: BMF error classes](validation.md#germany-bmf-error-classes).

### Translations

The pack ships German and English catalogs in `resources/lang/{de,en}/`:

- `rules.php`: message and hint of every rule (`BR-DE-*`, `BR-DEX-*`, `DE-XRECHNUNG-EXT-01`, `DE-USTG-*`, `DE-LEITWEG-ID`, `DE-LEITWEG-ID-SUSPECT`, `DE-TAX-NUMBER`);
- `tax.php`: the exemption reason texts of the presets, including `de.exempt` for `VatPreset::exempt()` (placeholder `:paragraph`).

They are registered as the namespace `quittio-de` (e.g. `quittio-de::rules.DE-USTG-03.message`). `CountryDE::registerTranslations()` registers them lazily, so presets work without a `Quittio` instance. Other locales fall back to English. With Laravel, Laravel's translator is used, so the usual override files apply: `lang/vendor/quittio-de/{locale}/rules.php` and `tax.php`, also for additional locales.

`CountryDE::locales()` lists the locales the pack ships (the directories of `resources/lang`). Texts defined in PHP cannot be translated through files:

- the legal texts (German and English, fallback German; the four notices taken from the presets follow `tax.php`);
- the correction policy titles (fallback English);
- the BMF labels and the default interest descriptions (German for `de`, English otherwise).

### The German pack's hooks

For pack authors, this is how `CountryDE` fills the [`CountryPack` contract](#the-countrypack-contract):

| Hook | Germany |
|---|---|
| `defaults()` | currency `EUR`, time zone `Europe/Berlin`, locale `de`, paper `A4`, letter layout `din5008b`, profile `en16931`, payment QR `girocode`, default VAT presets `de.standard` and `de.small_business` |
| `profiles()` | [`XRechnung30`, `XRechnung30Extension`, `XRechnung30Cvd`](#xrechnung-profiles) |
| `taxPresets()` | every case of [`VatPreset`](#tax-presets) |
| `rules()` | the [UStG rules](#ustg-rules) `DE-USTG-01` to `DE-USTG-15`, the [Leitweg-ID checks](#leitweg-id) `DE-LEITWEG-ID` and `DE-LEITWEG-ID-SUSPECT`, and the [tax number check](#tax-number) `DE-TAX-NUMBER` |
| `buyerCountryRules()` | none |
| `formatRules()` | [`GermanFormatRule`](#format-choice) |
| `mandatoryOutput()` | [mandatory PDF output](#mandatory-pdf-output) |
| `legalTexts()` | [`LegalTexts::catalog()`](#legal-texts) |
| `numberingRules()` | pattern `RE-{Y}-{seq:4}`, reset `yearly`, resets `never`, `yearly`, `monthly`, gapless |
| `retention()` | [8 years from the end of the calendar year](#retention) |
| `dunningLaw()` | [section 288 BGB values](#dunning-law) |
| `correctionPolicy()` | [credit note, cancel and reissue, German titles](#cancellations-and-corrections) |
| `letterLayouts()` | [`din5008a`, `din5008b`, `free`](#din-5008-letter-layouts) |
| `paymentQr()`, `addressFormatter()` | defaults: EPC GiroCode, international address format |
| `translationPath()` | `resources/lang` of the package |
| `errorClassification()` | [BMF error classes](#bmf-error-classes) (additional method) |

### Known limitations

- The legal wording and references of presets, rules and texts are proposals that must be confirmed by a tax adviser (see the legal review in the README). The default correction strategy and the cancellation of self-billed invoices are under review as well.
- Final invoices: only the EN 16931 variant (`deductAdvance()`) exists. The EXTENDED variant and the handling of unpaid advance invoices are open.
- The shipped base rate table ends with 1 July 2026. Later rates come from `quittio.countries.de.base_rates` or `php artisan quittio:base-rate:update` (see [Base rate table](#base-rate-table)).
- Default dunning fees and flat rates are under review. The documents layer does not track the section 288 (5) BGB flat rate per claim.
- Distance sales (section 3c UStG) cannot be told from the data; `DE-USTG-12` only warns about possible ones.
- Tax numbers are checked for format and check digit, not against the list of tax offices. Territories without a distinct postcode cannot be detected.
- The pack defines no buyer country rules, exporters (e.g. DATEV) or readers yet.

## How a pack is selected

This and the following sections are for authors of country packs, and for readers who want to know how Quittio picks a pack. A country pack implements `Quittio\Core\Countries\CountryPack`, usually by extending `Quittio\Core\Countries\AbstractCountryPack`, which supplies neutral defaults for every hook (every method of the contract). A pack may only add or tighten rules; it never relaxes EN 16931.

`DefaultCountryResolver` picks the packs for every run: render, check, prepare, documents, cancellations and the validation of incoming files.

1. **Leading pack (seller side).** Quittio takes the first of these values that is set:

   - the country passed to the run (`->country('AT')` on the pending invoice, `RenderOptions::$country`);
   - the seller's country code (BT-40);
   - the configured default country (`quittio.defaults.country`, core config key `country`);
   - otherwise `XX`.

   The registry returns the installed pack for that code, or a generic pack (`CountryGeneric`).

2. **Buyer pack.** If the buyer's country code (BT-55) is set and differs from the leading pack's code, the installed pack of the buyer's country is added. No generic pack is created for the buyer.

```php
use Quittio\Laravel\Facades\Quittio;

Quittio::make($invoice)->country('DE')->render();   // force the leading pack
```

What each side contributes:

| Hook | Leading pack | Buyer pack (cross-border only) |
|---|---|---|
| `rules()` | applied | – |
| `buyerCountryRules()` | – | applied |
| `formatRules()` | domestic invoices | cross-border business invoices (see [automatic format choice](formats.md#automatic-choice)) |
| `mandatoryOutput()` | merged with the base fields of the mandatory field guard | – |
| `defaults()` (locale, paper, letter layout), `letterLayouts()`, `addressFormatter()`, `paymentQr()` | locale fallback and PDF | – |
| `correctionPolicy()` | used for cancellations (`Quittio::cancellation()`) | – |
| `translationPath()` | registered | registered |

"Domestic" means that seller and buyer lie in the same VAT territory (see [`VatTerritory`](#vatterritory)), or that the buyer has no country code. A buyer in a territory excluded from the seller's VAT territory (e.g. Büsingen for Germany) is cross-border. Without a buyer pack of its own, the format rules of the leading pack still apply to it.

Without a pack for the seller's country, `CountryGeneric` applies: EN 16931 rules only, international formatting and neutral defaults. Validation then reports the warning `QUITTIO-GENERIC-COUNTRY` (L3, BT-40): "No country pack is installed for the seller country :value. Only EN 16931 rules are checked; national mandatory information is not validated." `ResolvedCountries::isGeneric()` and `CountryGeneric::isGeneric()` tell this case apart.

With the Managed layer, the issuer's country selects the pack for numbering and retention. The leading pack of an issued invoice is stored with it (`country_pack`) and used again for re-rendering and corrections (see [Managed layer](managed.md)).

## Registering packs

A pack must be registered before Quittio can use it. The German pack registers itself in Laravel.

### Laravel

Laravel finds packs in three places. All of them are instantiated through the container, so constructors may use dependency injection:

1. **Composer metadata.** Every installed package and the root `composer.json` of your application may declare packs. `quittio/country-de` declares itself this way, so `composer require quittio/country-de` (or `quittio/quittio`, which contains it) is all you need. Classes that do not exist are skipped.

   ```json
   "extra": { "quittio": { "country-packs": ["Acme\\CountryAT\\CountryAT"] } }
   ```

2. **Configuration.** `quittio.countries` lists further pack classes; keys are ignored (see [Configuration](configuration.md#country-packs)). Arrays under lower-case codes are the settings of a pack, not classes ([Settings of your pack](#settings-of-your-pack)).

   ```php
   'countries' => [
       'AT' => \Acme\CountryAT\CountryAT::class,
   ],
   ```

3. **At runtime.** Call `Quittio::registerCountry(new CountryAT)` (facade `Quittio\Laravel\Facades\Quittio`), e.g. in a service provider. Packs registered this way win over packs with the same code from the other two sources.

A class from the first two sources that is not a country pack throws a `ConfigurationException`.

Packs are keyed by `code()`. Of two packs with the same code, the one registered last wins. Discovered packs come first, then the configured classes, then packs registered at runtime. So a subclass of `CountryDE` in `quittio.countries` replaces the discovered `CountryDE`.

`php artisan quittio:countries` lists the installed packs with code, class, profiles, number of rules and format rules, and the defaults (currency, locale, profile). Without any pack it prints a warning (see [Artisan commands](commands.md#quittiocountries)).

### Without Laravel

```php
use Quittio\Core\Quittio;
use Quittio\Country\DE\CountryDE;

$quittio = Quittio::create(['country' => 'DE'], [new CountryDE]);
$quittio->registerCountry(new CountryAT);   // further packs at any time
```

Registering a pack does three things:

- it adds the pack to the `CountryRegistry` under its upper-case code;
- it registers the pack's `profiles()` in the profile registry, so that formats, `->profile('xrechnung')` and the reader know them;
- it registers `translationPath()` as the translation namespace `quittio-{code}` (lower case, e.g. `quittio-de`).

`$quittio->countries()` returns the registry: `has($code)`, `get($code)` (installed pack or null), `getOrGeneric($code)`, `all()` (array keyed by code) and `taxPreset($key)` (the preset with this key from any installed pack, or null). Codes are case-insensitive.

## Writing your own country pack

1. Create a Composer package that requires `quittio/core`.
2. Extend `AbstractCountryPack` and override only what differs. `code()` is the one required method.
3. Add tax presets, rules, format rules, mandatory output, layouts and texts as needed, and national output formats with `formats()` ([Formats of your pack](#formats-of-your-pack)).
4. Ship translations in `resources/lang/{locale}/rules.php` and return the directory from `translationPath()`.
5. Declare the pack in `composer.json` (`extra.quittio.country-packs`), so that the Laravel integration finds it.
   If the pack has Laravel settings, keep them under `quittio.countries.{code}` ([Settings of your pack](#settings-of-your-pack)).
6. Test it with example invoices and, where they exist, the official validation artefacts of the country. Have the legal content reviewed by a local tax adviser.

A minimal pack (values for illustration only):

```php
namespace Acme\CountryAT;

use Quittio\Core\Countries\AbstractCountryPack;
use Quittio\Core\Countries\CountryDefaults;
use Quittio\Core\Countries\MandatoryField;
use Quittio\Core\Countries\MandatoryFieldList;
use Quittio\Core\Countries\RetentionRule;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Tax\CustomTaxPreset;
use Quittio\Core\Tax\TaxPresetCollection;
use Quittio\Core\Validation\Rules\CallbackRule;
use Quittio\Core\Validation\Rules\Inspect;
use Quittio\Core\Validation\Rules\RuleReporter;

final class CountryAT extends AbstractCountryPack
{
    public function code(): string
    {
        return 'AT';
    }

    public function defaults(): CountryDefaults
    {
        return new CountryDefaults(currency: 'EUR', timezone: 'Europe/Vienna', locale: 'de');
    }

    public function taxPresets(): TaxPresetCollection
    {
        return new TaxPresetCollection([
            CustomTaxPreset::make('at.standard_20', VatCategory::StandardRate, '20'),
        ]);
    }

    public function rules(): array
    {
        return [
            CallbackRule::l3('ACME-AT-01', 'The seller VAT identifier (BT-31) must be stated.', function (InvoiceData $i, RuleReporter $report): void {
                if (! Inspect::filled($i->seller->vatId)) {
                    $report->error('BT-31', 'seller.vatId');
                }
            }, 'quittio-at'),
        ];
    }

    public function formatRules(): array
    {
        return [new ConsumerPdfRule];
    }

    public function mandatoryOutput(InvoiceData $invoice): MandatoryFieldList
    {
        return new MandatoryFieldList([new MandatoryField('seller.vatId', 'VAT identifier of the seller')]);
    }

    public function retention(): RetentionRule
    {
        return new RetentionRule(7);
    }

    public function translationPath(): string
    {
        return dirname(__DIR__).'/resources/lang';
    }
}
```

The format rule of the example sends invoices to consumers as PDF:

```php
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Formats\FormatChoice;
use Quittio\Core\Formats\FormatRegistry;
use Quittio\Core\Formats\FormatRule;

final class ConsumerPdfRule implements FormatRule
{
    public function resolve(InvoiceData $invoice): ?FormatChoice
    {
        return $invoice->buyer->isConsumer
            ? new FormatChoice(FormatRegistry::PDF, null, 'Invoice to a consumer.')
            : null;   // null: the next rule or the default choice decides
    }
}
```

The German translation of the rule, in `resources/lang/de/rules.php`:

```php
// "The seller's VAT identifier (UID, BT-31) must be stated." / "Set ->vatId() on the seller."
return [
    'ACME-AT-01' => [
        'message' => 'Die UID des Verkäufers (BT-31) muss angegeben sein.',
        'hint' => 'Setzen Sie ->vatId() beim Verkäufer.',
    ],
];
```

### Presets of your pack

A preset implements `Quittio\Core\Tax\TaxPreset`:

| Method | Returns |
|---|---|
| `key(): string` | stable key prefixed with the country code, e.g. `at.standard_20` |
| `category(): VatCategory` | VAT category (BT-151) |
| `rate(): ?BigDecimal` | rate in percent, null for category O |
| `exemptionReasonCode(): VatExemptionReasonCode\|RawCode\|null` | VATEX code (BT-121) |
| `exemptionReason(?string $locale = null): ?string` | exemption reason text (BT-120), shown in XML and PDF |

- `CustomTaxPreset::make(string $key, VatCategory $category, BigDecimal|int|string|null $rate = null, VatExemptionReasonCode|RawCode|string|null $code = null, array|string|null $reason = null)` builds a preset ad hoc. `$reason` is a text or locale => text. A plain string counts as English; without a locale the English text is used.
- For many presets a backed enum is convenient, as `Quittio\Country\DE\Tax\VatPreset` shows.
- `TaxPresetCollection` offers `add()`, `has($key)`, `get($key)` (throws `InvalidArgumentException` for unknown keys), `count()` and iteration.

A preset is applied per line with `->vat($preset)`. The line stores category, rate, reason, code and the preset key (`LineVat::$presetKey`), so that rules can recognise it (see [Building invoices: tax presets](building-invoices.md#tax-presets)).

### Rules of your pack

A rule implements `Quittio\Core\Validation\Rule` (`code()`, `level()`, `check(InvoiceData, ValidationContext): iterable<ValidationMessage>`). `CallbackRule::l3($code, $fallback, $check, $namespace)` (business rules) and `CallbackRule::l7(...)` (plausibility) cover most cases. The closure receives the invoice and a `RuleReporter`:

- `error(?string $bt = null, ?string $path = null, array $replace = [])`, `warning(...)` and `info(...)` add a finding with business term and data path;
- `context()` returns the `ValidationContext`: `profile`, `countryPacks` (leading pack first), `locale`, `option($key)` (e.g. `option('syntax')` with the `Syntax` that will be written), `profileIs(...$keys)`, `leadingCountry()`, `hasCountry($code)`.

Messages:

- They come from the catalog key `{namespace}::rules.{code}` with the entries `message` and `hint`, in the validation locale or English. Without an entry, the English `$fallback` is used.
- `:name` placeholders are replaced from `$replace`.
- Use the namespace `quittio-{code}` that registration gives your `translationPath()`.

The rules of a pack run in every format, also for a plain PDF. Profile specific rules belong to a profile class (see [Formats and profiles](formats.md)). See [Validation](validation.md) for levels and results.

### Format rules of your pack

`Quittio\Core\Formats\FormatRule::resolve(InvoiceData $invoice): ?FormatChoice` returns a `FormatChoice(string $format, ?string $profile = null, ?string $reason = null)` or null.

- Rules run in order; the first non-null choice wins.
- The resolver consults the leading pack for domestic invoices and the buyer's pack for cross-border invoices to businesses.
- An explicit format always wins.
- A choice of `zugferd` or `cii` is upgraded to EXTENDED when EXTENDED features are used.

### Formats of your pack

`AbstractCountryPack` implements `Quittio\Core\Countries\Capabilities\ProvidesFormats` with empty defaults. `Quittio::registerCountry()` registers what a pack returns:

- `formats()`: `list<OutputFormat>`, e.g. a national format with its syntax and allowed profiles; a format with the key of a registered one replaces it.
- `formatAliases()`: further names, e.g. `['xrechnung' => 'xrechnung-cii']`.
- `serializers()`: `list<XmlSerializer>` for syntaxes the core does not write; a serializer replaces the one of its syntax.

Germany brings XRechnung this way (`Quittio\Country\DE\Formats\XRechnungFormats`).

### Capabilities of your profiles

Profiles declare what they can transmit with interfaces in `Quittio\Core\Profiles\Capabilities`, instead of the core checking profile keys:

| Capability | Effect |
|---|---|
| `SupportsSubLines` | sub lines are allowed (no `QUITTIO-PROFILE-SUBLINES`) and the invoice is not upgraded to EXTENDED |
| `SupportsExtensionFields` | `extensions()` names the extensions whose fields the profile carries, e.g. `['xrechnung']` for the fields of `XRechnungExtensionData` |
| `SupportsThirdPartyPayments` | UBL invoices of the profile may carry third party payments (BG-DEX-09) |
| `DescribesEmbeddedXml` | XMP description and version of the embedded XML |
| `ReplacesRules` | `replacedRules(?Syntax $syntax)` names built-in rules the profile replaces with its own; the PHP rules driver does not run them for the profile |
| `RequiresVatBreakdownRate`, `StructuredCashDiscountText`, `ExtendsCodeLists` | rate in every VAT breakdown, structured cash discount texts, additional codes |

### Builder macros of your pack

A pack that offers builder helpers registers one macro named after its code, which returns an object with the helpers: `InvoiceBuilder::macro('at', fn () => new AustrianInvoice($this))`, used as `->at()->…`. Germany uses `->de()` ([`GermanInvoice`](#germany-quittiocountry-de)). Ship an IDE stub for the macro, as `packages/country-de/stubs/InvoiceBuilder.stub.php` does.

### Other hooks of your pack

- `paymentQr()`: a `Quittio\Core\Pdf\Qr\PaymentQrGenerator` (`key()`, `supports(InvoiceData)`, `payload(InvoiceData, array $options = []): ?string`) for the QR mode `auto`.
- `addressFormatter()`: a `Quittio\Core\View\AddressFormatter` (`format(Address $address, array $nameLines = [], ?string $senderCountry = null, ?string $locale = null): list<string>`).
- `profiles()`: national profiles, usually extending `Quittio\Core\Profiles\AbstractProfile`. They are registered with the pack.
- `referenceRates()` (capability `ProvidesReferenceRates`): the reference interest rate of the late payment law, so that `Quittio\Core\Dunning\DefaultInterest::forCountry($pack)` calculates default interest ([DunningLaw](#dunninglaw)).

### Settings of your pack

A pack that needs settings in Laravel (rates, credentials of a national portal, …) keeps them under `quittio.countries.{code}` with its lower-case code, as the German pack does with `quittio.countries.de` ([Configuration](configuration.md#settings-of-a-pack-quittiocountriescode)). Do not add a top-level configuration such as `quittio-at`. In the pack's service provider:

```php
namespace Acme\CountryAT\Laravel;

use Illuminate\Support\ServiceProvider;

final class AustriaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Defaults; the keys of config/quittio/countries/at.php or of 'countries' => ['at' => […]] win.
        $this->mergeConfigFrom(__DIR__.'/../../config/countries/at.php', 'quittio.countries.at');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            // quittio:install publishes every tag quittio-{code}-config.
            $this->publishes([__DIR__.'/../../config/countries/at.php' => $this->app->configPath('quittio/countries/at.php')], 'quittio-at-config');
        }
    }
}
```

- Ship the defaults as `config/countries/{code}.php` and publish them to `config/quittio/countries/{code}.php`; Laravel loads that file as `quittio.countries.{code}`.
- Name the publish tag `quittio-{code}-config`, so that `php artisan quittio:install` publishes it with `config/quittio.php`.
- Read the settings with `config('quittio.countries.at.…')`, and only in Laravel code (the service provider, commands); the pack class itself takes its values as constructor arguments or through `Quittio\Core` contracts, so it works without Laravel.
- Declare the provider in `composer.json` (`extra.laravel.providers`), as `quittio/country-de` does with `GermanyServiceProvider`.

## The `CountryPack` contract

Every method of `Quittio\Core\Countries\CountryPack`, its default in `AbstractCountryPack` and where Quittio uses it:

| Method | Returns | Default in `AbstractCountryPack` | Used by |
|---|---|---|---|
| `code()` | ISO 3166-1 alpha-2 code, e.g. `DE` | – (abstract) | registry key, translation namespace `quittio-{code}` |
| `defaults()` | [`CountryDefaults`](#countrydefaults) | `new CountryDefaults` | locale fallback, paper size, default letter layout |
| `taxPresets()` | `TaxPresetCollection` | empty | lookup of presets by key; presets are never applied automatically |
| `rules()` | `list<Rule>` | `[]` | validation, when the pack leads |
| `buyerCountryRules()` | `list<Rule>` | `[]` | validation, when the pack is the buyer's pack of a cross-border invoice |
| `profiles()` | `list<Profile>` | `[]` | profile registry (national CIUS, i.e. national rule sets on top of EN 16931 such as XRechnung) |
| `formatRules()` | `list<FormatRule>` | `[]` | automatic format choice |
| `mandatoryOutput(InvoiceData $invoice)` | [`MandatoryFieldList`](#mandatory-output) | empty | mandatory field guard (leading pack only) |
| `legalTexts()` | [`LegalTextCatalog`](#legaltextcatalog) | empty | the pack's own helpers and your code; Quittio itself does not read it |
| `numberingRules()` | [`NumberingRules`](#numberingrules) | `new NumberingRules` | Managed numbering |
| `retention()` | [`RetentionRule`](#retentionrule) | `new RetentionRule(10)` | Managed retention |
| `dunningLaw()` | [`DunningLaw`](#dunninglaw) | `new DunningLaw` | `Dunning\DefaultInterest::forCountry()`; reminders take fees and interest explicitly |
| `correctionPolicy()` | [`CorrectionPolicy`](#correctionpolicy) | `new CorrectionPolicy` | `Quittio::cancellation()`, Managed corrections |
| `paymentQr()` | `?PaymentQrGenerator` | `null` | payment QR code of the QR mode `auto`, after a generator registered with `setPaymentQrGenerator()` and unless `CountryDefaults::$paymentQr` is `none`; null falls back to the EPC GiroCode for `girocode` (see [`CountryDefaults`](#countrydefaults)) |
| `addressFormatter()` | `AddressFormatter` | `InternationalAddressFormatter` | address lines of the view model |
| `letterLayouts()` | `array<string, LetterLayout>` | `['free' => LetterLayout::free()]` | position of the address field and marks |
| `exporters()` | `list<Exporter>` | `[]` | declared for accounting exports of the Managed layer (`Exporter::key()`); not read in this release |
| `readers()` | `list<FormatReader>` | `[]` | readers for national formats. `read()`, `readFile()` and `validateFile()` try them after the built-in CII and UBL readers, for every registered pack. |

Optional capabilities (the interface gets new methods only in a major release, new abilities come as capabilities):

| Capability | Methods | Default in `AbstractCountryPack` | Used by |
|---|---|---|---|
| `Capabilities\ProvidesFormats` | `formats()`, `formatAliases()`, `serializers()` | implemented, empty | `Quittio::registerCountry()` ([Formats of your pack](#formats-of-your-pack)) |
| `Capabilities\ProvidesReferenceRates` | `referenceRates(): Dunning\ReferenceRates` | not implemented | `Dunning\DefaultInterest::forCountry()` |
| `translationPath()` | `?string` | `null` | directory with `{locale}/{group}.php` files, registered as namespace `quittio-{code}` |

### CountryDefaults

`new CountryDefaults(currency: 'EUR', timezone: 'UTC', locale: 'en', paper: 'A4', letterLayout: 'free', profile: 'en16931', requiredLocales: [], paymentQr: 'girocode', vatPreset: null, smallBusinessVatPreset: null)`

| Property | Default | Effect |
|---|---|---|
| `locale` | `en` | Last fallback for the render and validation locale: run option, invoice locale (`->locale()`), `quittio.defaults.locale`, then this value. |
| `paper` | `A4` | Paper size unless the presentation or `pdf.paper` sets one. |
| `letterLayout` | `free` | Key of `letterLayouts()` used when the presentation sets none (`pdf.layout`). |
| `currency`, `profile` | `EUR`, `en16931` | Shown by `quittio:countries`; not applied to invoices. |
| `timezone`, `requiredLocales` | `UTC`, `[]` | Informational metadata for applications; not applied by the core. Set the time zone of dates with `->timezone()` on the builder. |
| `paymentQr` | `girocode` | What the QR mode `auto` prints when no generator is registered with `setPaymentQrGenerator()`: `girocode` = the pack's `paymentQr()` generator, otherwise the EPC GiroCode; `none` = no code; another type (e.g. `swiss-qr-bill`) = the pack's generator, otherwise no code. See [Templates: payment QR codes](templates.md#payment-qr-codes). |
| `vatPreset`, `smallBusinessVatPreset` | `null`, `null` | Keys of presets of `taxPresets()` for invoice lines without VAT treatment: the usual rate, and the rate of small business invoices (`Meta::FLAG_SMALL_BUSINESS`). Used by `CountryDefaultVat` for the seller's country ([Default VAT](building-invoices.md#default-vat)). Germany: `de.standard` and `de.small_business`. |

### CorrectionPolicy

The default handling of cancellations and corrections (see [Managed: cancellations and corrections](managed.md#cancellations-and-corrections)).

| Member | Meaning |
|---|---|
| `CANCELLATION_CREDIT_NOTE` = `credit_note` | cancel with type code 381 and positive amounts |
| `CANCELLATION_NEGATIVE_INVOICE` = `negative_invoice` | cancel with type code 380 and negative amounts |
| `CORRECTION_CANCEL_AND_REISSUE` = `cancel_and_reissue` | correct by cancelling and issuing a new invoice |
| `CORRECTION_CORRECTED_INVOICE` = `corrected_invoice` | correct with a corrected invoice (type code 384) |
| `__construct(string $cancellationStrategy = 'credit_note', string $correctionStrategy = 'cancel_and_reissue', array $titles = [])` | `$titles`: document kind => [locale => title] |
| `title(string $kind, string $locale = 'en'): ?string` | title in the locale, else English, else null |
| `cancellationTitle(bool $selfBilled, string $locale = 'en'): ?string` | `cancellation_self_billed` for self-billed originals if defined, otherwise `cancellation` |

The kinds Quittio reads:

- `cancellation` and `cancellation_self_billed`: the title of `Quittio::cancellation()`;
- `correction`: the title of a Managed corrected invoice;
- `self_billed`: the PDF/HTML title and file name of self-billed invoices, after a title set in the presentation.

The sign strategy of a cancellation comes from the call, then `quittio.corrections.cancellation_strategy`, then the pack. The correction strategy comes from `quittio-managed.correction.strategy`, then the pack.

### DunningLaw

Statutory late payment values; amounts and percentage points are decimal strings.

| Property | Default | Meaning |
|---|---|---|
| `b2bInterestPoints` | `8.00` | points above the reference rate for business debtors |
| `b2cInterestPoints` | `0.00` | points above the reference rate for consumers |
| `b2bFlatRate` | `40.00` | flat rate for business debtors (null = none) |
| `dayCount` | `365` | day count basis |
| `consumerDefaultAfterDays` | `null` | days after which a consumer is in default |
| `referenceRateName` | `null` | name of the reference rate |

Quittio's documents do not read these values. `Quittio\Core\Dunning\DefaultInterest` calculates default interest from them and the reference rates of a pack that implements `Countries\Capabilities\ProvidesReferenceRates` (`referenceRates(): Dunning\ReferenceRates` with `rateOn()` and `periods()`): `DefaultInterest::forCountry($pack)->calculate($amount, $from, $until)`. Germany provides the Basiszinssatz this way ([`DefaultInterest`](#default-interest)); a pack of another country implements `ReferenceRates`, e.g. for the ECB main refinancing rate, on top of `Quittio\Core\Support\DatedValues`.

### NumberingRules

Used by Managed numbering (see [Managed: numbering](managed.md#numbering)).

| Property | Default | Meaning |
|---|---|---|
| `defaultPattern` | `INV-{Y}-{seq:4}` | pattern when neither the issuer nor the configuration sets one |
| `defaultReset` | `yearly` | reset when none is configured |
| `allowedResets` | `['never', 'yearly', 'monthly']` | other resets are rejected with a `ConfigurationException` |
| `gapless` | `true` | declared; not read in this release (see [gapless numbers](managed.md#gapless-numbers)) |
| `numberConsumedOnRejection` | `true` | declared; not read in this release |

### RetentionRule

`new RetentionRule(int $years, bool $fromEndOfCalendarYear = true)`. `until(Date $issueDate): Date` returns the last day of the retention period: 31 December of the issue year plus `$years`, or, with `fromEndOfCalendarYear: false`, the issue date plus `$years`. The Managed layer stores this date with every issued invoice; `quittio-managed.retention.years` may only lengthen it.

### LegalTextCatalog

`new LegalTextCatalog(array $texts = [], string $fallbackLocale = 'en')` with `$texts` as key => [locale => text].

- `has(string $key): bool`
- `get(string $key, ?string $locale = null, array $replace = []): ?string`: the text in `$locale`, else in the fallback locale, else the first variant; null for unknown keys. `:name` placeholders are replaced from `$replace`.

### Mandatory output

`mandatoryOutput()` names values the rendered PDF must show for one invoice, in addition to the base fields every PDF needs. After rendering, the mandatory field guard checks that the template printed them (see [Templates: mandatory field guard](templates.md#mandatory-field-guard)).

`new MandatoryField(string $accessKey, string $description, ?string $legalBasis = null)`. The access key is a view model path:

- `|` separates alternatives, `&` combines paths that must all be printed;
- `[*]` stands for every index, e.g. `vatRows[*].amount`;
- a path counts only where the view model has a value: null values and empty lists are not required, and a field whose alternatives all have no value is skipped.

`MandatoryFieldList::merge()` combines two lists; for equal access keys the later field wins. `MandatoryFieldGuard::notePath($invoice, $matches)` returns the view model path of the first note that matches a callback (`legalNotes[i]` for regulatory, legal and tax notes, `notes[i].content|notes[i].html` otherwise), so that a pack can require a specific note.

### Letter layouts

`letterLayouts()` returns `Quittio\Core\View\LetterLayout` objects by name. `CountryDefaults::$letterLayout` names the default; the presentation property `letterLayout` (Laravel: `pdf.layout`) selects another one. `free` is always available; an unknown name throws a `ConfigurationException`. Values are millimetres from the top left corner of the first page:

| Property | Default | Meaning |
|---|---|---|
| `name` | – | layout key |
| `addressTop`, `addressLeft` | 45.0, 20.0 | position of the address field |
| `addressWidth`, `addressHeight` | 85.0, 45.0 | size of the address field |
| `senderLineHeight` | 5.0 | height of the sender line above the recipient |
| `addressZoneTop` | 17.7 | distance of the recipient address from the top of the field |
| `foldMarks` | `[]` | distances of the fold marks from the top edge |
| `punchMark` | null | distance of the punch mark |

`letterheadHeight(float $pageMarginTop)` returns the space above the address field: `addressTop` minus the top page margin (15 mm on the first page in the default templates). See [Templates: letter layouts](templates.md#letter-layouts) for how the default templates use them; the German layouts are in [DIN 5008 letter layouts](#din-5008-letter-layouts).

### VatTerritory

`Quittio\Core\Countries\VatTerritory` tells the EU VAT territory (Art. 5 to 7 of Directive 2006/112/EC) from a country code and a postcode. The format resolver, the German rules and the core plausibility checks use it; packs can use it in their own rules.

| Method / constant | Result |
|---|---|
| `EU_MEMBER_STATES` | the 27 member states (Greece is `GR`) |
| `NORTHERN_IRELAND` | `XI` |
| `vatCountry(string $country): string` | the country whose VAT territory a country belongs to: `MC` → `FR`, `IM` → `GB`, `EL` → `GR` |
| `isEuMemberState(string $country): bool` | member state after `vatCountry()` |
| `excludedTerritory(string $country, ?string $postcode): ?string` | name of an excluded territory the address lies in, or null |
| `isEuVatTerritory(string $country, ?string $postcode = null, bool $goods = true): bool` | address inside the EU VAT territory |
| `memberState(string $country, ?string $postcode = null, bool $goods = true): ?string` | member state of the address (`XI` for Northern Ireland and goods), null outside |
| `sameVatTerritory(string $country, ?string $postcode, string $otherCountry, ?string $otherPostcode): bool` | same VAT territory, e.g. `FR` and `MC`, but not `DE` and Büsingen |
| `isNorthernIreland(string $country, ?string $postcode = null): bool` | `XI`, or `GB` with a `BT` postcode |
| `memberStateOfVatId(string $vatId): ?string` | member state of a VAT identifier prefix (`EL` → `GR`, `XI` stays `XI`), null otherwise (also for the prefix `GR`) |

Northern Ireland belongs to the territory for goods only (`$goods = false` excludes it). Territories that are excluded and recognised by postcode:

- Germany: Büsingen am Hochrhein (78266) and Heligoland (27498);
- Spain: the Canary Islands (35xxx, 38xxx), Ceuta (51xxx) and Melilla (52xxx);
- Italy: Livigno (23041) and Campione d'Italia (22061);
- Finland: the Åland Islands (22xxx);
- Greece: Mount Athos (63086);
- France: the overseas territories (97xxx, 981xx to 989xx; Monaco 980xx stays France).

Territories without a distinct postcode (e.g. the part of Cyprus not controlled by the government) cannot be detected.
