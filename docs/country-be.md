# Belgium (`quittio/country-be`)

> **Not reviewed by a tax adviser yet.** The legal wording, rules and defaults of this pack follow the law and the official sources as of October 2026, but have not been reviewed by a Belgian tax adviser. Check the mentions and rates of your invoices before you rely on them. Quittio is no tax or legal advice; the business that issues an invoice remains responsible for it. Until the review the pack is not covered by the [compatibility promise](compatibility.md#country-packs-without-tax-review): a minor release may change it.

The Belgian pack implements the invoice mentions of the Belgian VAT Code (Wetboek van de belasting over de toegevoegde waarde / Code de la TVA) and of Royal Decree No. 1 (Koninklijk besluit nr. 1, "KB nr. 1" / Arrêté royal n° 1, "A.R. n° 1"), the e-invoicing obligation of 2026 with Peppol BIS Billing 3.0, the use of languages in the language regions, and the law on late payment of 2 August 2002. Its class is `Quittio\Country\BE\CountryBE` (code `BE`, translation namespace `quittio-be`). Its texts exist in Dutch, French, German and English.

- **Legal references.** This page writes Belgian legal references in English form: "Art. 5 § 1, 10° Royal Decree No. 1" is article 5, paragraph 1, point 10 of the decree. Values that the pack stores or prints keep their Dutch or French form, e.g. "KB nr. 1, art. 20".
- **Business terms.** Codes such as BT-31 are the business terms of EN 16931, the European standard for e-invoices ([E-invoicing basics](concepts.md#en-16931-and-business-terms)).

## Installation

```bash
composer require quittio/country-be
```

The pack is not part of `quittio/quittio`. Install it next to `quittio/quittio` (with Laravel) or next to `quittio/core` (without Laravel). It is developed in [`packages/country-be`](https://github.com/Quittio/quittio/tree/master/packages/country-be) of the monorepo and published as the read-only repository [Quittio/country-be](https://github.com/Quittio/country-be); open issues and pull requests in the monorepo.

- **Laravel.** The pack declares itself in its `composer.json` under `extra.quittio.country-packs`, so Laravel finds it after the installation; there is nothing to register ([Registering packs: Laravel](country-packs.md#laravel)). The pack has no service provider and no configuration file of its own. Its one setting goes into `config/quittio.php` ([Settings](#settings)).
- **Without Laravel.** Pass the pack to `Quittio::create()`:

```php
use Quittio\Core\Quittio;
use Quittio\Country\BE\CountryBE;

$quittio = Quittio::create(['country' => 'BE'], [new CountryBE]);
```

`'country' => 'BE'` makes Belgium the default country for invoices whose seller has no country code. With several packs, pass them all, e.g. `[new CountryBE, new CountryDE]`, or add one later with `$quittio->registerCountry(new CountryBE)` ([Registering packs: Without Laravel](country-packs.md#without-laravel)).

## When it applies

The pack leads every invoice whose seller is in Belgium (seller country code `BE`), or that is rendered with `->country('BE')`. It then checks the Belgian rules, chooses Peppol BIS for domestic business invoices, completes the Peppol endpoints and provides the defaults: the locale `nl_BE` (unless the invoice or the configuration sets one), A4 paper and the payment QR code.

For an invoice from another country to a buyer in Belgium, the pack only registers its translations. It has no rules for the buyer side (`buyerCountryRules()`), and its format rule applies only when the seller is in Belgium as well. How Quittio picks the leading pack and the buyer pack is described in [How a pack is selected](country-packs.md#how-a-pack-is-selected).

## Common tasks

| You want to | Read |
|---|---|
| Set the VAT rate or an exemption on a line | [Tax presets](#tax-presets) |
| Invoice as a small business without VAT | [Small businesses](#small-businesses) |
| Know which format Quittio picks for a Belgian customer | [Format choice](#format-choice) |
| Send an invoice through Peppol | [Format choice](#format-choice), [Peppol endpoints](#peppol-endpoints) |
| Choose the language of the invoice | [Languages and language regions](#languages-and-language-regions) |
| Look up a validation code such as `BE-VAT-04` or `BE-LANG-01` | [Rules](#rules) |
| Print a QR code for the bank transfer | [Payment QR code](#payment-qr-code) |
| Send a reminder with default interest | [Late payment](#late-payment) |
| Cancel or correct an invoice | [Cancellations and corrections](#cancellations-and-corrections) |
| Change the wording of a mention or rule message | [Translations](#translations) |
| Copy a complete invoice | [Complete example](#complete-example) |

## Tax presets

A tax preset is a ready-made VAT treatment for an invoice line. It sets the VAT category (BT-151) and the rate, and for exempt supplies also the exemption reason code (BT-121) and text (BT-120). Use a preset instead of a bare category and rate: the line then carries the wording of the mention, and the Belgian rules recognise the case. How presets work in general is described in [Building invoices: Tax presets](building-invoices.md#tax-presets).

The categories are the codes of EN 16931: `S` standard rate, `Z` zero rated, `E` exempt, `AE` reverse charge, `K` intra-community supply, `G` export outside the EU, `O` not subject to VAT ([Categories](building-invoices.md#categories)).

`Quittio\Country\BE\Tax\VatPreset` is a string-backed enum that implements `TaxPreset`. Apply it per line:

```php
use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Enums\UnitCode;
use Quittio\Country\BE\Tax\VatPreset;

$builder = InvoiceBuilder::make()
    ->deliveryDate('2026-03-02')
    // ... number, seller, buyer, payment
    ->line('Consultancy', netPrice: '120.00', quantity: 8, unit: UnitCode::Hour, vat: VatPreset::Standard)    // 21 %
    ->line('Hotel night', netPrice: '110.00', quantity: 2, unit: UnitCode::Day, vat: VatPreset::Accommodation)  // 12 %: supplied on 2 March 2026
    ->line('Renovation works', netPrice: '5000.00', vat: VatPreset::ReverseChargeDomestic)                    // "Verlegging van heffing – KB nr. 1, art. 20"
    // an exemption without its own case: the legal reference as text
    ->line('Training', netPrice: '300.00', vat: VatPreset::exempt('artikel 44, § 2, 4°, Wetboek btw'));
```

| Case | Key | Category | Rate | Code (BT-121) | Use |
|---|---|---|---|---|---|
| `Standard` | `be.standard` | `S` | 21 | – | Standard rate |
| `Intermediate` | `be.intermediate` | `S` | 12 | – | Intermediate rate |
| `Reduced` | `be.reduced` | `S` | 6 | – | Reduced rate |
| `Accommodation` | `be.accommodation` | `S` | 12 from 1 March 2026, 6 before (by supply date) | – | Accommodation in hotels and campsites |
| `ZeroRated` | `be.zero_rated` | `Z` | 0 | – | Zero rate, e.g. for daily newspapers |
| `SmallBusiness` | `be.small_business` | `E` | 0 | – | Small business exemption scheme (Art. 56bis VAT Code); see [Small businesses](#small-businesses) |
| `ReverseChargeDomestic` | `be.reverse_charge_domestic` | `AE` | 0 | `VATEX-EU-AE` | Domestic reverse charge, e.g. works on immovable property (Art. 20 Royal Decree No. 1) |
| `ReverseChargeEu` | `be.reverse_charge_eu` | `AE` | 0 | `VATEX-EU-AE` | Service to a business in another member state, which owes the VAT (Art. 196 of the VAT Directive 2006/112/EC) |
| `IntraCommunitySupply` | `be.intra_community_supply` | `K` | 0 | `VATEX-EU-IC` | Exempt intra-community supply (Art. 39bis VAT Code) |
| `Export` | `be.export` | `G` | 0 | `VATEX-EU-G` | Exempt export (Art. 39 VAT Code) |
| `NotTaxableInBelgium` | `be.not_taxable` | `O` | none | `VATEX-EU-O` | Supply not taxable in Belgium (place of supply abroad) |
| `MarginSecondHand` | `be.margin_second_hand` | `E` | 0 | `VATEX-EU-F` | Margin scheme, second-hand goods (Art. 58 § 4 VAT Code) |
| `MarginArt` | `be.margin_art` | `E` | 0 | `VATEX-EU-I` | Margin scheme, works of art (Art. 58 § 4 VAT Code) |
| `MarginAntiques` | `be.margin_antiques` | `E` | 0 | `VATEX-EU-J` | Margin scheme, collectors' items and antiques (Art. 58 § 4 VAT Code) |
| `TravelAgency` | `be.travel_agency` | `E` | 0 | `VATEX-EU-D` | Margin scheme of travel agencies (Art. 58 § 3 VAT Code) |
| `VatPreset::exempt(string $paragraph, $code = null)` | `be.exempt` | `E` | 0 | `$code` (enum, raw code or string) | Any other exemption, e.g. `artikel 44, § 2, 1° Wetboek btw` |

The codes in column BT-121 are VATEX codes, the standard codes for the reason of an exemption. The rates are those of Royal Decree No. 20 on the VAT rates; `Quittio\Country\BE\Tax\TaxTables` holds them (`STANDARD_RATE`, `INTERMEDIATE_RATE`, `REDUCED_RATE`, `ACCOMMODATION_RATE`).

**Dated rate.** `Accommodation` is the only preset with a dated rate. A line takes the rate that applied on its supply date: 6 % up to 28 February 2026, 12 % from 1 March 2026 (`TaxTables::accommodationRateOn($date)`). The supply date is determined as for the German presets ([Germany: Tax presets](country-packs.md#tax-presets)): the end of the line period, its start, the VAT point date, the delivery date, the end of the invoicing period or the issue date. `VatPreset::Accommodation->rate()` returns the rate of today (time zone Europe/Brussels), `VatPreset::Accommodation->on($date)` the preset with the rate of a date. The other presets have one rate.

**Texts.** `Standard`, `Intermediate`, `Reduced`, `Accommodation` and `ZeroRated` carry no text, because EN 16931 forbids an exemption reason for categories `S` and `Z` (BR-S-10, BR-Z-10). The other presets print an exemption reason (BT-120) from `resources/lang/{nl,fr,de,en}/tax.php`:

| Key | Dutch | French |
|---|---|---|
| `be.small_business` | Bijzondere vrijstellingsregeling kleine ondernemingen | Régime particulier de franchise des petites entreprises |
| `be.reverse_charge_domestic` | Verlegging van heffing – KB nr. 1, art. 20 | Autoliquidation – A.R. n° 1, art. 20 |
| `be.reverse_charge_eu` | Verlegging van heffing – artikel 196 Richtlijn 2006/112/EG | Autoliquidation – article 196 de la directive 2006/112/CE |
| `be.intra_community_supply` | Vrijgestelde intracommunautaire levering – artikel 39bis Wetboek btw | Livraison intracommunautaire exemptée – article 39bis du Code de la TVA |
| `be.export` | Vrijgestelde uitvoer – artikel 39 Wetboek btw | Exportation exemptée – article 39 du Code de la TVA |
| `be.not_taxable` | Niet in België belastbaar (plaats van de handeling in het buitenland) | Non imposable en Belgique (lieu de l'opération à l'étranger) |
| `be.margin_second_hand` | Bijzondere regeling – gebruikte goederen | Régime particulier – biens d'occasion |
| `be.margin_art` | Bijzondere regeling – kunstvoorwerpen | Régime particulier – objets d'art |
| `be.margin_antiques` | Bijzondere regeling – voorwerpen voor verzamelingen of antiquiteiten | Régime particulier – objets de collection et d'antiquité |
| `be.travel_agency` | Bijzondere regeling – reisbureaus | Régime particulier – agences de voyages |
| `be.exempt` | Vrijgesteld van btw – :paragraph | Exempté de la TVA – :paragraph |

The German and English texts are in the same files ([Translations](#translations)).

How presets behave:

- **Language.** The text is taken in the invoice locale (`->locale()` on the builder) when the line is built. The enum presets use Dutch when the invoice has no locale; a regional locale such as `fr_BE` or `nl_BE` takes the text of its language. `VatPreset::exempt()` (a `Quittio\Country\BE\Tax\CustomVatPreset`) behaves the same: Dutch without a locale, the text of the language for a regional locale.
- **Preset key.** The key stays on the line (`$line->vat->presetKey`). The small business detection of `BE-VAT-06` and the [format choice](#format-choice) rely on it.
- **VAT breakdown.** Category, rate, code and text flow into the VAT breakdown (BG-23), and the PDF lists the texts in its legal notes.
- **Lookup by key**, e.g. from a database column: `VatPreset::from('be.standard')`, `VatPreset::fromKey($key)` (null for keys of other packs), `(new CountryBE)->taxPresets()->get('be.standard')` or, across all installed packs, `$quittio->countries()->taxPreset('be.standard')`. `be.exempt` is not part of the collection.
- **Default VAT.** Lines without `vat:` take the [default VAT](building-invoices.md#default-vat). With `CountryDefaultVat` (installed by the Laravel integration) that is `VatPreset::Standard` for a Belgian seller, and `VatPreset::SmallBusiness` for a small business invoice.

### Small businesses

A small business under the exemption scheme (Art. 56bis VAT Code) charges no VAT. Mark its invoices in one of two ways:

- Use `VatPreset::SmallBusiness` on every line. Its text "Bijzondere vrijstellingsregeling kleine ondernemingen" / "Régime particulier de franchise des petites entreprises" appears in the VAT breakdown and the PDF.
- Or flag the invoice with `->flag(Meta::FLAG_SMALL_BUSINESS)` (in Laravel `quittio.seller.small_business = true`, without Laravel `'small_business' => true` in `Quittio::create()`; applied by `Quittio::invoice()` and `$quittio->invoice()`). The flag does not set texts; lines without `vat:` then get `VatPreset::SmallBusiness` through the default VAT.

Either way, `BE-VAT-06` reports every VAT row with a VAT amount as an error. A small business invoice to a Belgian business is still sent through Peppol ([Format choice](#format-choice)).

## Rules

The pack checks the mentions of Art. 5 of Royal Decree No. 1 and Art. 53 of the VAT Code, and the use of languages. These rules apply to every invoice of a Belgian seller, independent of the format. They run at validation level L3 ([Validation levels](validation.md#validation-levels)); the class is `Quittio\Country\BE\Rules\VatCodeRules`.

| Code | Severity | Check |
|---|---|---|
| `BE-VAT-01` | error | The seller's VAT number (BT-31), or that of its tax representative, is stated (Art. 5 § 1, 3° Royal Decree No. 1). |
| `BE-VAT-02` | error | The addresses of seller and buyer are complete: street (BT-35, BT-50), post code (BT-38, BT-53) and city (BT-37, BT-52) (Art. 5 § 1, 5° Royal Decree No. 1). One message per missing field. |
| `BE-VAT-03` | warning | An invoice to a business (not a consumer) with an address in Belgium states the buyer's VAT number (BT-48) (Art. 5 § 1, 4° Royal Decree No. 1). The mention is required if the buyer files VAT returns, which Quittio cannot know, hence a warning. |
| `BE-VAT-04` | error | Every VAT row of category `AE` (reverse charge) has a text (BT-120) that contains a reverse charge statement: "verlegging", "autoliquidation", "Steuerschuld", "reverse charge" or the text of a reverse charge preset in any of the pack's languages (Art. 5 § 1, 10° Royal Decree No. 1). |
| `BE-VAT-05` | error | Every VAT row of category `E`, `K` or `G` has the reference to the provision as text (BT-120); a VATEX code alone is not enough (Art. 5 § 1, 10° Royal Decree No. 1). |
| `BE-VAT-06` | error | A small business invoice (flag `Meta::FLAG_SMALL_BUSINESS` or a line with `VatPreset::SmallBusiness`) has no VAT row with a VAT amount (Art. 56bis VAT Code). |
| `BE-VAT-07` | error, warning in XML | A self-billed invoice (self-billed type code) has a note (BT-22) with "self-billing", "autofacturation", "zelffacturering", "Gutschrift", "factuur uitgereikt door de afnemer" or "facture émise par le preneur" (Art. 5 § 1, 12° Royal Decree No. 1). When the invoice is validated for an XML format, the type code labels it already, so the missing words are a warning; for a document without XML they are an error. |
| `BE-VAT-08` | warning | The invoice is issued by the 15th day of the month following the supply (Art. 53 § 2 VAT Code). The supply date is the latest of the delivery date (BT-72), the end of the invoicing period (BG-14) and the end of each line period; without any of them the rule does not check. Not checked for consumers, credit notes, corrected invoices and prepayment invoices. A late invoice stays valid, hence a warning. |
| `BE-LANG-01` | warning | A business in the Dutch, French or German language region invoices customers in Belgium in the language of its region (language decrees of the Flemish and French Communities; for the German language region Art. 52 of the laws on the use of languages in administrative matters of 18 July 1966). Brussels-Capital is not checked. See [Languages and language regions](#languages-and-language-regions). |

The messages and hints exist in Dutch, French, German and English. To switch a rule off or change its severity, e.g. `BE-LANG-01` for a business that knows its language obligations, use `validation.rules` ([Switching rules off and changing their severity](validation.md#switching-rules-off-and-changing-their-severity)).

For Peppol invoices the Peppol rules apply on top of these ([Peppol rules](validation.md#peppol-rules)). The one you meet most often is `PEPPOL-EN16931-R003`: a Peppol invoice needs a buyer reference (BT-10, `->buyerReference()`) or a purchase order reference (BT-13, `->purchaseOrderReference()`).

### Mandatory PDF output

After rendering, the mandatory field guard checks that the PDF shows certain values ([Templates: mandatory field guard](templates.md#mandatory-field-guard)). `CountryBE::mandatoryOutput()` adds these Belgian values to the base fields:

| Condition | Access key | Description | Legal basis |
|---|---|---|---|
| the seller or its tax representative has a VAT number | `seller.vatId\|taxRepresentative.vatId` | VAT number of the seller | Art. 5 § 1, 3° KB nr. 1 |
| the buyer has a VAT number | `buyer.vatId` | VAT number of the buyer | Art. 5 § 1, 4° KB nr. 1 |
| each VAT row of category `AE`, `K`, `G` or `E` | `vatRows[i].label` or the legal note with its BT-120 text | reverse charge or exemption mention | Art. 5 § 1, 10° KB nr. 1 |

The default templates print all of them.

## Format choice

Since 1 January 2026 invoices between businesses established in Belgium are structured e-invoices, by default Peppol BIS Billing 3.0 (law of 6 February 2024). The pack's format rule, `Quittio\Country\BE\Formats\BelgianFormatRule`, chooses the format `peppol` (profile `peppol`) when:

- the buyer is not a consumer,
- seller and buyer both have an address in Belgium,
- and the invoice does not consist only of exempt lines (category `E`) other than `VatPreset::SmallBusiness`.

The last point leaves out businesses that only perform exempt supplies (Art. 44 VAT Code). The pack decides it per invoice from the lines: an invoice whose lines are all of category `E`, for example with `VatPreset::exempt()` or a margin scheme preset, is not forced to Peppol.

Every other invoice keeps the automatic choice of the core ([Automatic choice](formats.md#automatic-choice)): a consumer gets a PDF, a business in another EU country ZUGFeRD, unless a [Peppol lookup](formats.md#peppol-lookup) finds it in the Peppol network. A format you request yourself (`->format('pdf')`, [Requesting a format](formats.md#requesting-a-format)) always wins over the rule.

The format `peppol` produces UBL XML only ([Peppol BIS Billing 3.0](formats.md#peppol-bis-billing-30)). Render a PDF next to it with `->formats(['peppol', 'pdf'])`; the PDF carries a note that it is a readable copy of the e-invoice and that only the XML is binding. Sending the XML through a Peppol access point is not part of this pack.

### Peppol endpoints

Peppol addresses seller and buyer by their electronic address (BT-34, BT-49). Belgian businesses are identified by their enterprise number (ondernemingsnummer / numéro d'entreprise) with the scheme `0208`. The pack completes the addresses before calculation and validation (`CountryBE::complete()`): a seller or buyer without electronic address and with a Belgian VAT number gets the enterprise number of that VAT number as endpoint. `BE0712345630` becomes `<cbc:EndpointID schemeID="0208">0712345630</cbc:EndpointID>`. An electronic address you set yourself is kept:

```php
->buyer(Party::make('Voorbeeld Handel NV')
    ->vatId('BE0765432146')
    ->address('Korenmarkt 10', '9000', 'Gent', 'BE')
    ->electronicAddress('0765432146', '0208'))         // or another scheme Peppol accepts
```

The setting `peppol_endpoints = false` turns the completion off ([Settings](#settings)). A Peppol invoice without electronic addresses then fails the Peppol rules.

`Quittio\Country\BE\Support\EnterpriseNumber` checks and formats enterprise numbers: ten digits, the first one `0` or `1`, the last two 97 minus the first eight modulo 97.

| Method | Result |
|---|---|
| `EnterpriseNumber::normalize('BE 0712 345 630')` | `'0712345630'`: the ten digits, a leading `0` added to nine digits; null for other lengths |
| `EnterpriseNumber::isValid('0712.345.630')` | `true` when the check digits are right |
| `EnterpriseNumber::fromVatId('BE0712345630')` | `'0712345630'`; null for a VAT number of another country or with wrong check digits |
| `EnterpriseNumber::format('712345630')` | `'0712.345.630'`, the usual notation |
| `EnterpriseNumber::SCHEME` | `'0208'` |

## Languages and language regions

The pack's default locale is `nl_BE`. It applies when neither the render call, the invoice (`->locale()`) nor the configuration (`quittio.defaults.locale`) sets one ([Translations and locales](templates.md#translations-and-locales)). The core has labels in Dutch, French, German and English, so `nl_BE`, `fr_BE` and `de_BE` print in the language with Belgian number and date formats.

Belgian law ties the language of an invoice to the language region of the business's place of operation: the decrees on the use of languages of the Flemish Community (decree of 19 July 1973) and of the French Community (decree of 30 June 1982) require documents that the law prescribes, such as invoices, in the language of the region. `BE-LANG-01` warns when a seller in a language region invoices a customer in Belgium in another language. Set the locale that matches the seller:

| Seller in | Post codes | Locale | `BE-LANG-01` |
|---|---|---|---|
| Dutch language region (Flanders) | 1500–3999, 8000–9999 | `nl_BE` | warns for other languages |
| French language region (Wallonia without the German-speaking municipalities) | 1300–1499, 4000–7999 except 4700–4791 | `fr_BE` | warns for other languages |
| German language region (Eupen, Kelmis, Lontzen, Raeren, Bütgenbach, Büllingen, Amel, St. Vith, Burg-Reuland) | 4700–4791 | `de_BE` | warns for other languages |
| Bilingual region Brussels-Capital | 1000–1299 | `nl_BE` or `fr_BE` | not checked |

- The rule checks only invoices whose seller and buyer both have an address in Belgium, consumers included.
- It compares the language part of the locale (`fr` of `fr_BE`) with the region. The locale is the invoice's, otherwise the locale of the run, so an invoice without locale counts as Dutch, the pack's default. A business in Wallonia should therefore always set `->locale('fr_BE')`.
- `Quittio\Country\BE\Support\LanguageRegion::of($postcode)` returns the region of a post code: `LanguageRegion::DUTCH` (`nl`), `FRENCH` (`fr`), `GERMAN` (`de`), `BILINGUAL` (`bilingual`) or null for a post code that is not Belgian. The pack derives the region from the post code alone.
- The pack does not choose the locale from the seller's region by itself; the warning points out a mismatch instead ([Known limitations](#known-limitations)).

## Payment QR code

The pack sets the payment QR code to `girocode` (`CountryDefaults::$paymentQr`) and ships no generator of its own. With the default QR mode `auto`, the PDF prints the EPC GiroCode, the QR code of the European Payments Council for SEPA credit transfers, when the currency is EUR, the payment means is a credit transfer with a valid IBAN and an amount is due ([Payment QR codes](templates.md#payment-qr-codes)).

The structured communication of Belgian banks (OGM/VCS, "overschrijving met gestructureerde mededeling" / "virement avec communication structurée", in the form `+++090/9337/55493+++`) can be passed as remittance information (BT-83):

```php
->payment(PaymentMeansCode::SepaCreditTransfer, iban: 'BE68539007547034', remittanceInformation: '+++090/9337/55493+++')
```

The pack does not check its check digits (modulo 97), and the GiroCode carries it as unstructured remittance text, not in the field for a structured reference that the Febelfin guidelines for QR codes on invoices ask for ([Known limitations](#known-limitations)).

## Late payment

When a customer pays late, the law on late payment of 2 August 2002 gives the seller interest and, from business debtors, a flat rate of 40 EUR. The pack provides these values and the reference rates. Quittio's documents do not apply them by themselves: calculate the interest with `Quittio\Core\Dunning\DefaultInterest` and pass the results to a payment reminder ([Documents: payment reminders](documents.md#payment-reminders)).

`CountryBE::dunningLaw()` (also `CountryBE::belgianDunningLaw()`) returns:

| Value | Belgium |
|---|---|
| Interest for business debtors | reference rate plus 8 percentage points |
| Interest for consumers | fixed rate of 4.50 % (`b2cFixedRate`), the legal interest rate of 2026 |
| Flat rate for business debtors | 40.00 EUR |
| Day count | 365 days |
| Consumer default | not set (`consumerDefaultAfterDays` null) |
| Reference rate | "Referentie-interestvoet (wet van 2 augustus 2002)" |

The law adds 8 points to the main refinancing rate of the European Central Bank on the first day of the half-year and rounds the sum up to the next half point; the FPS Finance (Federal Public Service Finance) publishes the result. `Quittio\Country\BE\Dunning\ReferenceRateTable` therefore holds the published rates minus 8 points, so that the sum is the published rate: 2.50 for 2026, which gives 10.50 %. The table starts with 1 January 2023 and ends with the second half of 2026.

```php
use Brick\Money\Money;
use Quittio\Core\Builder\Party;
use Quittio\Core\Documents\DocumentBuilder;
use Quittio\Core\Dunning\DefaultInterest;
use Quittio\Core\Quittio;
use Quittio\Core\Support\Date;
use Quittio\Country\BE\CountryBE;

$quittio = Quittio::create(['country' => 'BE'], [new CountryBE]);
$interest = DefaultInterest::forCountry(new CountryBE);   // reference rates and law of the pack

$reminder = DocumentBuilder::paymentReminder(1)           // first reminder, "1. aanmaning"
    ->number('H-2026-0007')
    ->issueDate('2026-10-20')
    ->locale('nl_BE')
    ->seller(Party::make('Voorbeeld Software BV')->vatId('BE0712345630')->address('Markt 1', '8000', 'Brugge', 'BE'))
    ->buyer(Party::make('Voorbeeld Handel NV')->vatId('BE0765432146')->address('Korenmarkt 10', '9000', 'Gent', 'BE'))
    ->creditTransfer('BE68539007547034')
    ->openItem('F-2026-0042', '2026-08-03', '1210.00', dueDate: '2026-09-02')
    // first day with interest: the day after the due date; 2026-10-20 is the first day without interest
    ->interestCharge(...$interest->calculate(Money::of('1210.00', 'EUR'), Date::parse('2026-09-03'), Date::parse('2026-10-20')))
    ->flatRate('F-2026-0042')                              // 40.00 EUR for this claim
    ->deadline('2026-11-03');

$result = $quittio->document($reminder->build())->render();
$result->save(__DIR__.'/'.$result->filename());           // 1._aanmaning_H-2026-0007.pdf
```

- **Interest.** From 3 September to 19 October 2026 the rate is 10.50 %; on 1,210.00 EUR this gives 16.36 EUR for 47 days. A period that spans a change of the reference rate is split into one charge per rate. Pass `consumer: true` for a consumer: one charge at 4.50 %, whatever the dates.
- **Flat rate.** `flatRate($claim)` adds the 40.00 EUR once per claim; the builder rejects it for a consumer and twice for the same claim in one reminder ([Payment reminders](documents.md#payment-reminders)). `$interest->flatRate()` returns the amount.
- **Later rates.** For a day after 31 December 2026, `calculate()` throws an `InvalidArgumentException` ("No Belgian reference rate is known …"). Add the rate of a new half-year, the published rate minus 8 points, with `withRate()`:

```php
use Quittio\Core\Dunning\DefaultInterest;
use Quittio\Country\BE\CountryBE;
use Quittio\Country\BE\Dunning\ReferenceRateTable;

$rates = (new ReferenceRateTable)->withRate('2027-01-01', '2.50');   // example value: the published rate minus 8 points
$interest = new DefaultInterest($rates, CountryBE::belgianDunningLaw());
```

- **Consumers.** The legal interest rate changes every year, but the pack has one fixed rate of 4.50 %, which it applies to any period. For another year, pass your own `DunningLaw` with the rate of that year as `b2cFixedRate` to `new DefaultInterest(...)`. The rules of Book XIX of the Code of Economic Law on consumer debts are not modelled ([Known limitations](#known-limitations)).
- **Descriptions.** `DefaultInterest::forCountry()` returns charges without a description; the reminder prints the default label of the core, e.g. "Nalatigheidsinterest 10,5 % per jaar op 1.210,00 € van 03/09/2026 tot 19/10/2026".

## Settings

The pack has one setting, under `quittio.countries.be` ([Settings of a pack](configuration.md#settings-of-a-pack-quittiocountriescode)):

| Key | Type | Default | Effect |
|---|---|---|---|
| `countries.be.peppol_endpoints` | bool | `true` | Seller and buyer without electronic address get the enterprise number of their Belgian VAT number as Peppol endpoint (scheme `0208`, [Peppol endpoints](#peppol-endpoints)). `false` = set the electronic addresses yourself. |

The pack has no configuration file to publish. In Laravel, set the key in `config/quittio.php`; without Laravel, pass the same array to `Quittio::create()`:

```php laravel
'countries' => [
    'be' => ['peppol_endpoints' => false],
],
```

```php plain
use Quittio\Core\Quittio;
use Quittio\Country\BE\CountryBE;

$quittio = Quittio::create(['country' => 'BE', 'countries' => ['be' => ['peppol_endpoints' => false]]], [new CountryBE]);
```

The pack defines no meta flags of its own. It reads the core flag `Meta::FLAG_SMALL_BUSINESS` ([Small businesses](#small-businesses)).

## Defaults

`CountryBE::defaults()`:

| Value | Belgium | Effect |
|---|---|---|
| `locale` | `nl_BE` | last fallback for the render and validation locale ([Languages and language regions](#languages-and-language-regions)) |
| `currency` | `EUR` | shown by `quittio:countries`; not applied to invoices |
| `timezone` | `Europe/Brussels` | informational; also the time zone of `VatPreset::Accommodation->rate()` |
| `paper` | `A4` | [Paper size](templates.md#paper-size) |
| `letterLayout` | `free` | the pack has no letter layouts of its own ([Letter layouts](templates.md#letter-layouts)) |
| `profile` | `peppol` | shown by `quittio:countries`; the format rule chooses Peppol where it applies ([Format choice](#format-choice)) |
| `paymentQr` | `girocode` | [Payment QR code](#payment-qr-code) |
| `vatPreset`, `smallBusinessVatPreset` | `be.standard`, `be.small_business` | [Default VAT](building-invoices.md#default-vat) |

### Numbering

`CountryBE::numberingRules()` is used by Managed numbering ([Managed: numbering](managed.md#numbering)). An invoice needs a sequential number in one or more series (Art. 5 § 1, 2° Royal Decree No. 1).

| Property | Belgium |
|---|---|
| `defaultPattern` | `F-{Y}-{seq:4}`, e.g. `F-2026-0042` |
| `defaultReset` | `yearly` |
| `allowedResets` | `never`, `yearly` (no monthly reset) |
| `gapless`, `numberConsumedOnRejection` | `true`, `true` (declared, see [NumberingRules](country-packs.md#numberingrules)) |

### Retention

`CountryBE::retention()` returns `new RetentionRule(7, fromEndOfCalendarYear: true)`: invoices are kept for seven years from the end of the calendar year of issue (Art. 60 § 3 VAT Code as amended by the law of 18 December 2025, for VAT due from 2023). An invoice issued on 5 October 2026 is kept until 31 December 2033. The Managed layer stores this date ([Managed: retention and deletion](managed.md#retention-and-deletion)).

### Cancellations and corrections

`CountryBE::correctionPolicy()` cancels with a credit note (type code 381, positive amounts) and corrects by cancelling and issuing a new invoice ([CorrectionPolicy](country-packs.md#correctionpolicy), [Building invoices: cancellations and corrections](building-invoices.md#cancellations-and-corrections)). The titles:

| Kind | Dutch | French | German | English |
|---|---|---|---|---|
| `cancellation` | Creditnota | Note de crédit | Gutschrift | Credit note |
| `correction` | Verbeterde factuur | Facture rectificative | Berichtigte Rechnung | Corrected invoice |
| `self_billed` | Zelffacturering | Autofacturation | Gutschrift (Selbstfakturierung) | Self-billed invoice |

A self-billed invoice still needs the words in a note for `BE-VAT-07`, e.g. `->note('Zelffacturering')`.

## Translations

The pack ships catalogs in Dutch, French, German and English in `resources/lang/{nl,fr,de,en}/`:

- `rules.php`: message and hint of every rule (`BE-VAT-01` to `BE-VAT-08`, `BE-LANG-01`);
- `tax.php`: the exemption reason texts of the presets, including `be.exempt` for `VatPreset::exempt()` (placeholder `:paragraph`).

They are registered as the namespace `quittio-be`, e.g. `quittio-be::rules.BE-VAT-08.message`. `CountryBE::registerTranslations()` registers them lazily, so presets work without a `Quittio` instance. A regional locale such as `fr_BE` takes the texts of its language; other locales fall back to English. `CountryBE::locales()` lists the shipped locales (`de`, `en`, `fr`, `nl`). The correction policy titles are defined in PHP and cannot be changed through files.

With Laravel, Laravel's translator is used, so the usual override files apply: `lang/vendor/quittio-be/{locale}/rules.php` and `tax.php`, also for additional locales. A file needs only the keys it changes.

Without Laravel, add a directory of your own after the pack has registered its texts; a path added later overrides earlier ones:

```php
use Quittio\Core\I18n\Translations;
use Quittio\Core\Quittio;
use Quittio\Country\BE\CountryBE;

$quittio = Quittio::create(['country' => 'BE'], [new CountryBE]);
CountryBE::registerTranslations();
// lang/nl/rules.php: return ['BE-VAT-03' => ['hint' => 'Vraag het btw-nummer van de klant op.']];
Translations::translator()->addPath(__DIR__.'/lang', CountryBE::TRANSLATION_NAMESPACE);
```

Change the wording of a legal mention in `tax.php` only after checking it; the rules `BE-VAT-04` and `BE-VAT-05` read the texts.

## The Belgian pack's hooks

For pack authors, this is how `CountryBE` fills the [`CountryPack` contract](country-packs.md#the-countrypack-contract):

| Hook | Belgium |
|---|---|
| `defaults()` | [Defaults](#defaults) |
| `profiles()` | none (the core profile `peppol` is used) |
| `taxPresets()` | every case of [`VatPreset`](#tax-presets) |
| `rules()` | [`BE-VAT-01` to `BE-VAT-08` and `BE-LANG-01`](#rules) |
| `buyerCountryRules()` | none |
| `formatRules()` | [`BelgianFormatRule`](#format-choice) |
| `mandatoryOutput()` | [mandatory PDF output](#mandatory-pdf-output) |
| `legalTexts()` | none |
| `numberingRules()` | [pattern `F-{Y}-{seq:4}`, resets `never` and `yearly`](#numbering) |
| `retention()` | [7 years from the end of the calendar year](#retention) |
| `dunningLaw()` | [law of 2 August 2002](#late-payment) |
| `correctionPolicy()` | [credit note, cancel and reissue](#cancellations-and-corrections) |
| `letterLayouts()`, `paymentQr()`, `addressFormatter()` | defaults: `free`, EPC GiroCode, international address format |
| `translationPath()` | `resources/lang` of the package |
| `complete()` (`CompletesInvoices`) | [Peppol endpoints](#peppol-endpoints) |
| `referenceRates()` (`ProvidesReferenceRates`) | [`ReferenceRateTable`](#late-payment) |

## Known limitations

- The legal wording, rules and defaults have not been reviewed by a Belgian tax adviser (see the notice at the top).
- The locale is not chosen from the seller's language region; `BE-LANG-01` points out a mismatch instead. The language rules beyond the main language are not modelled either: Flanders allows a second binding version only for customers in another EU or EEA state, the French language region an additional language of the parties' choice, Brussels Dutch or French.
- Structured communication (OGM/VCS, `+++…+++`, check digits modulo 97) is not checked, and the GiroCode carries it as unstructured text instead of in the reference field (EPC069-12 version 002) as the Febelfin guidelines for QR codes on invoices ask.
- There is no Laravel service provider with a configuration file; set the one setting in `config/quittio.php` ([Settings](#settings)).
- Consumer debts (Book XIX of the Code of Economic Law): the free first reminder, the waiting period of 14 calendar days, interest of at most the reference rate plus 8 points and the agreed flat rates (20 EUR, 30 EUR plus 10 %, 65 EUR plus 5 %, at most 2,000 EUR) are not modelled. The consumer rate is one fixed rate of 4.50 %.
- Reference rates from 2027: `ReferenceRateTable` ends with the second half of 2026; add later half-years with `withRate()`.
- Sending through a Peppol access point is not part of the pack.
- The pack has no rules for foreign sellers invoicing Belgian buyers, and no exporters or readers.

## Complete example

A software company in Bruges invoices a trading company in Ghent for ten hours of web development and a handbook. Both are businesses in Belgium, so the pack chooses Peppol BIS, adds the enterprise numbers as Peppol endpoints and checks the Belgian mentions. The seller is in the Dutch language region, so the invoice is in Dutch.

```php
<?php
require __DIR__.'/vendor/autoload.php';

use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Builder\Party;
use Quittio\Core\Enums\PaymentMeansCode;
use Quittio\Core\Enums\UnitCode;
use Quittio\Core\Quittio;
use Quittio\Core\Tax\CountryDefaultVat;
use Quittio\Country\BE\CountryBE;
use Quittio\Country\BE\Tax\VatPreset;

$quittio = Quittio::create(['country' => 'BE'], [new CountryBE]);
InvoiceBuilder::useDefaultVat(new CountryDefaultVat($quittio->countries()));   // lines without vat: 21 %

$invoice = InvoiceBuilder::make()
    ->number('F-2026-0042')
    ->issueDate('2026-10-05')
    ->deliveryDate('2026-09-30')                         // invoice by 15 October (BE-VAT-08)
    ->locale('nl_BE')                                    // Bruges: Dutch language region (BE-LANG-01)
    ->buyerReference('PO-4711')                          // Peppol needs BT-10 or BT-13
    ->seller(Party::make('Voorbeeld Software BV')
        ->vatId('BE0712345630')                          // enterprise number 0712.345.630
        ->address('Markt 1', '8000', 'Brugge', 'BE')
        ->contact('An Peeters', '+32 50 12 34 56', 'facturen@voorbeeld-software.example'))
    ->buyer(Party::make('Voorbeeld Handel NV')
        ->vatId('BE0765432146')
        ->address('Korenmarkt 10', '9000', 'Gent', 'BE'))
    ->line('Webontwikkeling', netPrice: '95.00', quantity: 10, unit: UnitCode::Hour)   // 21 %
    ->line('Handboek', netPrice: '40.00', vat: VatPreset::Reduced)                     // 6 %
    ->payment(PaymentMeansCode::SepaCreditTransfer, iban: 'BE68539007547034', remittanceInformation: '+++090/9337/55493+++')
    ->dueInDays(30)
    ->build();

// Peppol BIS (the binding e-invoice) and a readable PDF from one calculation
$result = $quittio->make($invoice)->formats(['peppol', 'pdf'])->render();

$result->format();                                       // "peppol"
$result->save(__DIR__.'/'.$result->filename());          // Factuur_F-2026-0042.xml
$result->as('pdf')->save(__DIR__.'/'.$result->as('pdf')->filename());   // Factuur_F-2026-0042.pdf

$result->validation()->isValid();                        // true, no errors or warnings
$result->data()->seller->electronicAddress?->value;     // "0712345630", scheme 0208
echo $result->data()->totals->amountDue;                 // EUR 1191.90
```

- `render()` calculates, validates and renders. An error, e.g. a missing address (`BE-VAT-02`) or a missing buyer reference (`PEPPOL-EN16931-R003`), throws a `ValidationException`; warnings such as `BE-VAT-03` or `BE-LANG-01` are in `$result->validation()->warnings()`. To check without rendering, call `$quittio->make($invoice)->check()` ([Validation](validation.md)).
- The XML contains `<cbc:EndpointID schemeID="0208">0712345630</cbc:EndpointID>` for the seller and `0765432146` for the buyer ([Peppol endpoints](#peppol-endpoints)). Hand it to your Peppol access point.
- The PDF is in Dutch ("Factuur", "Totaal incl. btw"), with Belgian date formats and a GiroCode for the transfer.
- With Laravel, the seller and its bank account come from `config/quittio.php`, and `Quittio::invoice()` starts the invoice ([Laravel integration](laravel.md#starting-invoices)). Set `defaults.country` to `BE` for invoices whose seller has no country code.
