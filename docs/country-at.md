# Austria (`quittio/country-at`)

> **Not reviewed by a tax adviser yet.** The legal wording, rules and defaults of this pack follow the law and the official sources as of October 2026, but have not been reviewed by an Austrian tax adviser. Check the mentions and rates of your invoices before you rely on them. Quittio is no tax or legal advice; the business that issues an invoice remains responsible for it. Until the review the pack is not covered by the [compatibility promise](compatibility.md#country-packs-without-tax-review): a minor release may change it.

The Austrian pack implements the mandatory invoice information of the Austrian VAT act (UStG 1994, Umsatzsteuergesetz), the Austrian national CIUS of EN 16931 (CIUS-AT-NAT, and CIUS-AT-GOV for the federal government), the Austrian XML invoice format ebInterface and the Austrian law on late payment. Its class is `Quittio\Country\AT\CountryAT` (code `AT`, translation namespace `quittio-at`).

- **Legal references.** This page writes Austrian legal references in English form: "section 11 (1) no. 3 lit. a UStG" stands for "§ 11 Abs. 1 Z 3 lit. a UStG", and "Art. 6 UStG" for an article of the annex to the UStG (the Binnenmarktregelung, the rules for the EU single market). Other laws cited are the Commercial Code (UGB, Unternehmensgesetzbuch), the Civil Code (ABGB, Allgemeines bürgerliches Gesetzbuch) and the Federal Fiscal Code (BAO, Bundesabgabenordnung). Values that the pack stores or prints in German keep their German form.
- **Terms.** The UID (Umsatzsteuer-Identifikationsnummer) is the Austrian VAT identifier, e.g. `ATU12345678`. A Gutschrift is a self-billed invoice, a Kleinunternehmer a small business that charges no VAT. e-Rechnung.gv.at is the portal of the federal government for incoming e-invoices, run by the Bundesrechenzentrum (BRZ) for the Federal Ministry of Finance (BMF).
- **Not part of `quittio/quittio`.** The pack is a package of its own, released with the same version as the rest of Quittio. If EN 16931, profile or CIUS are new to you, read [E-invoicing basics](concepts.md) first.

## Installation

```bash
composer require quittio/country-at
```

The pack needs PHP 8.3 and `quittio/core` of the same version. It is not part of `quittio/quittio`; install it next to `quittio/quittio` (Laravel) or next to `quittio/core` (without Laravel). It is developed in the monorepo [Quittio/quittio](https://github.com/Quittio/quittio/tree/master/packages/country-at) and published as the read-only repository [Quittio/country-at](https://github.com/Quittio/country-at); please open issues and pull requests in the monorepo.

**Laravel.** The pack declares itself in its `composer.json` under `extra.quittio.country-packs` (`Quittio\Country\AT\CountryAT`), so Laravel registers it after `composer require` without further steps ([Registering packs: Laravel](country-packs.md#laravel)). `php artisan quittio:countries` lists it. The pack has no service provider, no configuration file and no settings under `quittio.countries.at`. To invoice as an Austrian company, set your seller in `config/quittio.php` ([Your company](configuration.md#your-company)) with the country `AT`:

```php
// config/quittio.php
'seller' => [
    'name' => 'Donau Werkstatt GmbH',
    'vat_id' => 'ATU12345678',                         // UID
    'address' => [
        'line1' => 'Mariahilfer Straße 1',
        'postcode' => '1060',
        'city' => 'Wien',
        'country' => 'AT',
    ],
    'bank_accounts' => [
        ['iban' => 'AT611904300234573201', 'bic' => 'BKAUATWW', 'name' => 'Donau Werkstatt GmbH'],
    ],
],
```

`Quittio::invoice()` then starts every invoice with this seller, and lines without `vat:` get the standard rate of 20 % ([Default VAT](building-invoices.md#default-vat)).

**Without Laravel.** Pass the pack to `Quittio::create()` ([Registering packs: without Laravel](country-packs.md#without-laravel)):

```php
use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Quittio;
use Quittio\Core\Tax\CountryDefaultVat;
use Quittio\Country\AT\CountryAT;

$quittio = Quittio::create(['country' => 'AT'], [new CountryAT]);
InvoiceBuilder::useDefaultVat(new CountryDefaultVat($quittio->countries()));   // lines without vat(): 20 %
```

Registering the pack adds it to the country registry, registers its profiles `cius-at-nat` and `cius-at-gov`, makes its ebInterface reader available to `read()` and `validateFile()`, and registers `resources/lang` as the translation namespace `quittio-at`. The option `country` is only the default for invoices whose seller has no country code.

## When it applies

The pack leads every invoice whose seller is in Austria (seller country code BT-40 `AT`), or whose run names it (`->country('AT')`). It then checks the [UStG rules](#ustg-rules), chooses the format for Austrian public buyers ([Format choice](#format-choice)) and provides the [defaults](#defaults): German as language (unless the invoice or the configuration sets one), A4 paper, the free letter layout and the EPC GiroCode.

For an invoice from another country to a buyer in Austria, only the format rule of the pack applies: a buyer with an Austrian organisation number receives UBL after CIUS-AT-GOV, whatever the seller's country. The UStG rules are not applied to such invoices. Details are in [How a pack is selected](country-packs.md#how-a-pack-is-selected).

Without the pack, an Austrian seller gets the generic pack: EN 16931 rules only, and the warning `QUITTIO-GENERIC-COUNTRY`.

## Common tasks

| You want to | Read |
|---|---|
| Build, render and validate a typical Austrian invoice | [Complete example](#complete-example) |
| Set the VAT rate or an exemption on a line | [Tax presets](#tax-presets) |
| Invoice as a small business without VAT (Kleinunternehmer) | [Small businesses](#small-businesses) |
| Invoice a business in another EU country | [Reverse charge and intra-community supplies](#reverse-charge-and-intra-community-supplies) |
| Issue an invoice of up to 400 EUR with fewer details | [Simplified invoices](#simplified-invoices) |
| Look up a validation code such as `AT-USTG-05` or `AT-GOV-01` | [UStG rules](#ustg-rules), [Profiles](#profiles) |
| Invoice the federal government (e-Rechnung.gv.at) | [Format choice](#format-choice), [Profiles](#profiles) |
| Write or read ebInterface | [ebInterface](#ebinterface) |
| Issue a self-billed invoice (Gutschrift) | [Self-billing](#self-billing-gutschrift) |
| Cancel or correct an invoice | [Cancellations and corrections](#cancellations-and-corrections) |
| Send a reminder with default interest | [Late payment](#late-payment) |
| Change a text of the pack | [Translations](#translations) |

## Complete example

An invoice of an Austrian company to an Austrian business, without Laravel. It is rendered as ZUGFeRD with the profile EN 16931 (the [automatic format choice](formats.md#automatic-choice) for a domestic business invoice), in German, and validated with the EN 16931 and UStG rules:

```php
use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Builder\Party;
use Quittio\Core\Enums\PaymentMeansCode;
use Quittio\Core\Enums\UnitCode;
use Quittio\Core\Quittio;
use Quittio\Core\Tax\CountryDefaultVat;
use Quittio\Country\AT\CountryAT;
use Quittio\Country\AT\Tax\VatPreset;

$quittio = Quittio::create(['country' => 'AT'], [new CountryAT]);
InvoiceBuilder::useDefaultVat(new CountryDefaultVat($quittio->countries()));   // lines without vat(): 20 %

$invoice = InvoiceBuilder::make()
    ->number('RE-2026-0042')
    ->issueDate('2026-10-05')
    ->deliveryDate('2026-09-30')                                      // date of the supply (AT-USTG-04)
    ->seller(Party::make('Donau Werkstatt GmbH')
        ->vatId('ATU12345678')                                        // UID of the seller (AT-USTG-03)
        ->address('Mariahilfer Straße 1', '1060', 'Wien', 'AT')
        ->contact('Anna Huber', '+43 1 234567', 'rechnung@donau-werkstatt.example'))
    ->buyer(Party::make('Tiroler Handel KG')
        ->vatId('ATU87654321')                                        // required above 10,000 EUR gross (AT-USTG-05)
        ->address('Maria-Theresien-Straße 10', '6020', 'Innsbruck', 'AT'))
    ->line('Softwareentwicklung', netPrice: '100.00', quantity: 12, unit: UnitCode::Hour)   // 20 %
    ->line('Fachbuch', netPrice: '45.00', vat: VatPreset::Reduced)                          // 10 %
    ->paymentTerms(fn ($terms) => $terms->dueInDays(14))
    ->payment(PaymentMeansCode::SepaCreditTransfer, iban: 'AT611904300234573201', bic: 'BKAUATWW')
    ->build();

$result = $quittio->make($invoice)->render();

$result->format();                          // "zugferd"
$result->profile()?->key();                 // "en16931"
$result->validation()->isValid();           // true: no errors (and here no warnings either)
$result->data()->totals?->grossTotal;       // EUR 1489.50
$result->save(__DIR__.'/invoices/');        // invoices/Rechnung_RE-2026-0042.pdf
```

`render()` throws a `ValidationException` when a rule reports an error, e.g. `AT-USTG-04` without a delivery date ([Validation](validation.md)). The PDF carries a GiroCode for the transfer ([Payment QR code](#payment-qr-code)). In Laravel, start with `Quittio::invoice()` instead of `InvoiceBuilder::make()->seller(…)`; the rest of the chain is the same.

## Tax presets

A tax preset is a ready-made VAT treatment for an invoice line. It sets the VAT category (BT-151) and the rate, and for exempt supplies also the exemption reason code (BT-121) and text (BT-120). Use a preset instead of a bare category and rate: the line then carries the wording the UStG requires, and the Austrian rules recognise the case. Lines without `vat:` take the [default VAT](building-invoices.md#default-vat): `VatPreset::Standard` for an Austrian seller, `VatPreset::SmallBusiness` for a small business.

The categories are the codes of EN 16931: `S` standard rate, `E` exempt, `AE` reverse charge, `K` intra-community supply, `G` export outside the EU, `O` not subject to VAT (see [Categories](building-invoices.md#categories)).

`Quittio\Country\AT\Tax\VatPreset` is a string-backed enum that implements `DatedTaxPreset`. Apply it per line:

```php
use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Enums\UnitCode;
use Quittio\Country\AT\Tax\VatPreset;

$builder = InvoiceBuilder::make()
    // ... number, dates, seller and buyer as in the complete example
    ->line('Softwareentwicklung', netPrice: '100.00', quantity: 12, unit: UnitCode::Hour, vat: VatPreset::Standard)   // 20 %
    ->line('Fachbuch', netPrice: '45.00', vat: VatPreset::Reduced)                                                     // 10 %
    ->line('Milch', netPrice: '1.50', quantity: 10, unit: UnitCode::Litre, vat: VatPreset::BasicFood)                 // 4.9 % for a supply from 1 July 2026
    // an exemption without its own case: the legal reference;
    // the invoice prints "Steuerfrei gemäß § 6 Abs. 1 Z 19 UStG"
    ->line('Ärztliche Leistung', netPrice: '300.00', vat: VatPreset::exempt('§ 6 Abs. 1 Z 19 UStG'));
```

| Case | Key | Category | Rate | Code (BT-121) | Use |
|---|---|---|---|---|---|
| `Standard` | `at.standard` | `S` | 20 | – | Standard rate (section 10 (1) UStG) |
| `Reduced` | `at.reduced` | `S` | 10 | – | Reduced rate (section 10 (2) UStG) |
| `Reduced13` | `at.reduced_13` | `S` | 13 | – | Second reduced rate (section 10 (3) UStG) |
| `BasicFood` | `at.basic_food` | `S` | 4.9 from 1 July 2026, 10 before (by supply date) | – | Basic foods (section 10 (1a) UStG, BGBl. I Nr. 37/2026) |
| `SmallBusiness` | `at.small_business` | `E` | 0 | – | Small business exemption (Kleinunternehmer, section 6 (1) no. 27 UStG); see [Small businesses](#small-businesses) |
| `ReverseChargeDomestic` | `at.reverse_charge_domestic` | `AE` | 0 | `VATEX-EU-AE` | Domestic reverse charge (section 19 (1a) to (1e) UStG): the buyer in Austria owes the VAT |
| `ReverseChargeEu` | `at.reverse_charge_eu` | `AE` | 0 | `VATEX-EU-AE` | Service to a business in another member state, which owes the VAT there (section 19 (1) sentence 2 UStG). Issue the invoice by the 15th of the following month (`AT-USTG-12`). |
| `IntraCommunitySupply` | `at.intra_community_supply` | `K` | 0 | `VATEX-EU-IC` | VAT-exempt intra-community supply (Art. 6 (1) with Art. 7 UStG) |
| `TriangularTransaction` | `at.triangular_transaction` | `AE` | 0 | `VATEX-EU-AE` | Intra-community triangular transaction (Art. 25 UStG), in which the last customer owes the VAT |
| `Export` | `at.export` | `G` | 0 | `VATEX-EU-G` | VAT-exempt export supply (section 6 (1) no. 1 with section 7 UStG) |
| `NotTaxableInAustria` | `at.not_taxable` | `O` | none | `VATEX-EU-O` | Supply not taxable in Austria (place of supply abroad) |
| `MarginSecondHand` | `at.margin_second_hand` | `E` | 0 | `VATEX-EU-F` | Margin scheme, second-hand goods (section 24 UStG) |
| `MarginArt` | `at.margin_art` | `E` | 0 | `VATEX-EU-I` | Margin scheme, works of art (section 24 UStG) |
| `MarginAntiques` | `at.margin_antiques` | `E` | 0 | `VATEX-EU-J` | Margin scheme, collectors' items and antiques (section 24 UStG) |
| `TravelAgency` | `at.travel_agency` | `E` | 0 | `VATEX-EU-D` | Margin scheme of travel agencies (section 23 UStG) |
| `VatPreset::exempt(string $paragraph, $code = null)` | `at.exempt` | `E` | 0 | `$code` (enum, raw code or string) | Any other exemption, e.g. `§ 6 Abs. 1 Z 19 UStG` |

The codes in column BT-121 are VATEX codes: the standard codes for the reason of an exemption. `SmallBusiness` has none.

`BasicFood` takes the rate that applied on the supply date of the line (`TaxTables::BASIC_FOOD_RATE`): a delivery on 30 June 2026 gets 10 %, one on 1 July 2026 gets 4.9 %. The supply date is found as for the German presets ([Germany: tax presets](country-packs.md#tax-presets)): the end of the line period, its start, the VAT point date, the delivery date, the end of the invoicing period or the issue date. `VatPreset::BasicFood->rate()` returns the rate of today in Vienna. The other rates are fixed (`TaxTables::STANDARD_RATE`, `REDUCED_RATE`, `REDUCED_RATE_13`).

### Exemption texts

`Standard`, `Reduced`, `Reduced13` and `BasicFood` carry no text, because EN 16931 forbids an exemption reason for taxed supplies (BR-S-10). The other presets print an exemption reason (BT-120) in German or English, from `resources/lang/{de,en}/tax.php`:

| Key | German | English |
|---|---|---|
| `at.small_business` | Umsatzsteuerfrei aufgrund der Kleinunternehmerregelung (§ 6 Abs. 1 Z 27 UStG). | VAT exempt under the small business scheme (section 6 (1) no. 27 UStG). |
| `at.reverse_charge_domestic` | Übergang der Steuerschuld auf den Leistungsempfänger (§ 19 UStG) | Reverse charge: the recipient owes the VAT (section 19 UStG) |
| `at.reverse_charge_eu` | Übergang der Steuerschuld auf den Leistungsempfänger (Reverse Charge) | Reverse charge: the recipient owes the VAT |
| `at.intra_community_supply` | Steuerfreie innergemeinschaftliche Lieferung (Art. 6 Abs. 1 i. V. m. Art. 7 UStG) | VAT exempt intra-community supply (Art. 6 (1) with Art. 7 UStG) |
| `at.triangular_transaction` | Innergemeinschaftliches Dreiecksgeschäft gemäß Art. 25 UStG; Übergang der Steuerschuld auf den letzten Abnehmer | Intra-community triangular transaction under Art. 25 UStG; the VAT is owed by the last customer |
| `at.export` | Steuerfreie Ausfuhrlieferung (§ 6 Abs. 1 Z 1 i. V. m. § 7 UStG) | VAT exempt export supply (section 6 (1) no. 1 with section 7 UStG) |
| `at.not_taxable` | Nicht im Inland steuerbare Leistung (Leistungsort im Ausland) | Not taxable in Austria (place of supply abroad) |
| `at.margin_second_hand` | Differenzbesteuerung – Gebrauchtgegenstände/Sonderregelung | Margin scheme – Second-hand goods |
| `at.margin_art` | Differenzbesteuerung – Kunstgegenstände/Sonderregelung | Margin scheme – Works of art |
| `at.margin_antiques` | Differenzbesteuerung – Sammlungsstücke und Antiquitäten/Sonderregelung | Margin scheme – Collector's items and antiques |
| `at.travel_agency` | Sonderregelung für Reisebüros (§ 23 UStG) | Margin scheme – Travel agents (section 23 UStG) |
| `at.exempt` | Steuerfrei gemäß :paragraph | VAT exempt under :paragraph |

### How presets behave

- **Language.** The text is taken in the invoice locale (`->locale()` on the builder) when the line is built. Without a locale it is German. The enum cases take German for regional locales such as `de_AT` and English for locales the pack does not ship (e.g. `fr`). `VatPreset::exempt()` (a `Quittio\Country\AT\Tax\CustomVatPreset`) behaves the same: German without a locale and for `de_AT`.
- **Preset key.** The key stays on the line (`$line->vat->presetKey`). The small business detection (`AT-USTG-08`) and the deadline of `AT-USTG-12` rely on it.
- **VAT breakdown.** Category, rate, code and text flow into the VAT breakdown (BG-23). The PDF lists the exemption texts in its legal notes.
- **No invoice notes.** Unlike some German presets, the Austrian presets add no invoice note (BT-22); the exemption text is in BT-120 only.
- **Allowances and charges.** Document level allowances and charges take a category and a rate, not a preset: `->charge(VatCategory::StandardRate, '20', amount: '10.00', reason: 'Versand')`.
- **Lookup by key**, e.g. from a database column: `VatPreset::from('at.standard')`, `VatPreset::fromKey($key)` (null for keys of other packs), `(new CountryAT)->taxPresets()->get('at.standard')` or, across all installed packs, `$quittio->countries()->taxPreset('at.standard')`. `at.exempt` is not part of the collection. In Laravel, `quittio.defaults.vat` takes a key, e.g. `'at.reduced'` ([Defaults](configuration.md#defaults)).

## Small businesses

A small business (Kleinunternehmer) applies the exemption of section 6 (1) no. 27 UStG and must not show VAT on its invoices (section 11 (12) UStG). Three steps make an invoice a small business invoice in Quittio:

1. **Mark the seller as a small business.** Set `quittio.seller.small_business = true` in Laravel ([Small businesses](configuration.md#small-businesses)), `'small_business' => true` in `Quittio::create()` without Laravel (applied by `Quittio::invoice()` and `$quittio->invoice()`), or `->flag(Meta::FLAG_SMALL_BUSINESS)` on the builder.
2. **Use `VatPreset::SmallBusiness` on every line.** Lines without `vat:` get it automatically when the invoice has the flag and the default VAT comes from the country pack (`CountryDefaultVat`; in Laravel always). A line with this preset also marks the invoice as small business invoice, without the flag.
3. **Identify the seller without a UID.** EN 16931 requires the seller's VAT identifier or tax number (BT-32) for category `E` (BR-E-02), and a VAT identifier, seller identifier (BT-29) or legal registration (BT-30) so that the buyer can identify the seller (BR-CO-26).

```php
use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Builder\Party;
use Quittio\Core\Data\Meta;
use Quittio\Core\Enums\PaymentMeansCode;
use Quittio\Core\Quittio;
use Quittio\Core\Tax\CountryDefaultVat;
use Quittio\Country\AT\CountryAT;

$quittio = Quittio::create([], [new CountryAT]);
InvoiceBuilder::useDefaultVat(new CountryDefaultVat($quittio->countries()));

$invoice = InvoiceBuilder::make()
    ->number('RE-2026-0007')
    ->issueDate('2026-10-05')
    ->deliveryDate('2026-10-01')
    ->flag(Meta::FLAG_SMALL_BUSINESS)
    ->seller(Party::make('Grafikstudio Maria Berger')
        ->taxNumber('12 345/6789')                  // BT-32 (BR-E-02)
        ->identifier('L-4711')                      // BT-29, e.g. your supplier number at the buyer (BR-CO-26)
        ->address('Hauptplatz 3', '8010', 'Graz', 'AT'))
    ->buyer(Party::make('Steirische Bäckerei OG')->address('Herrengasse 5', '8010', 'Graz', 'AT'))
    ->line('Logo-Entwurf', netPrice: '650.00')       // VatPreset::SmallBusiness through the default VAT
    ->paymentTerms(fn ($terms) => $terms->dueInDays(14))
    ->payment(PaymentMeansCode::SepaCreditTransfer, iban: 'AT611904300234573201')
    ->build();

$result = $quittio->make($invoice)->render();       // "Umsatzsteuerfrei aufgrund der Kleinunternehmerregelung (§ 6 Abs. 1 Z 27 UStG)."
```

A small business invoice is a [simplified invoice](#simplified-invoices): it needs neither the buyer's address nor the UIDs. VAT on it is an error (`AT-USTG-08`).

## Reverse charge and intra-community supplies

- **Services to a business in another member state.** Use `VatPreset::ReverseChargeEu`. The text names the transfer of the tax liability ("Übergang der Steuerschuld auf den Leistungsempfänger"), which `AT-USTG-06` requires. Issue the invoice by the 15th of the month after the service (`AT-USTG-12`).
- **Domestic reverse charge.** Use `VatPreset::ReverseChargeDomestic` (section 19 (1a) to (1e) UStG).
- **Intra-community supply of goods.** Use `VatPreset::IntraCommunitySupply`. EN 16931 also needs the country of the delivery address (BR-IC-12): `->deliverTo('Isar Consulting GmbH', Address::make('Leopoldstraße 20', '80802', 'München', 'DE'))`.
- **VAT identifiers.** Reverse charge and intra-community supplies are never simplified invoices: the UIDs of seller and buyer are checked (`AT-USTG-03`, `AT-USTG-05`), and the [mandatory PDF output](#mandatory-pdf-output) requires the buyer's VAT identifier.

```php
use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Builder\Party;
use Quittio\Core\Enums\UnitCode;
use Quittio\Country\AT\Tax\VatPreset;

$builder = InvoiceBuilder::make()
    // ... number, dates and payment as in the complete example
    ->seller(Party::make('Donau Werkstatt GmbH')->vatId('ATU12345678')->address('Mariahilfer Straße 1', '1060', 'Wien', 'AT'))
    ->buyer(Party::make('Isar Consulting GmbH')->vatId('DE987654321')->address('Leopoldstraße 20', '80802', 'München', 'DE'))
    ->line('Beratung', netPrice: '120.00', quantity: 10, unit: UnitCode::Hour, vat: VatPreset::ReverseChargeEu);
```

Without a preset, `AT-USTG-06` accepts a BT-120 text of category `AE` that contains "Steuerschuld", "reverse charge", "reverse-charge", "autoliquidation", "inversione contabile" or the text of one of the reverse charge presets in any locale (case-insensitive).

## Simplified invoices

`UstgRules::isSimplifiedInvoice($invoice)` decides whether the simplifications of section 11 (6) UStG apply. An invoice is simplified when it

- is in euro and its gross total (BT-112) is at most 400 EUR (150 EUR for invoices issued before 1 March 2014, `TaxTables::SIMPLIFIED_INVOICE_LIMIT`), or
- is a small business invoice ([Small businesses](#small-businesses)).

Invoices with reverse charge (category `AE`) or an intra-community supply (`K`) are never simplified. A simplified invoice needs neither the buyer's name and address (`AT-USTG-02`) nor the UID of the seller (`AT-USTG-03`) or the buyer (`AT-USTG-05`), and `AT-USTG-10` does not apply. There is no builder macro: the rules recognise the case from the amounts and presets.

## UStG rules

`UstgRules::all()` checks the mandatory information of section 11 UStG on every invoice whose leading pack is the Austrian one, in every format. The rules run at validation level L3 ([Validation levels](validation.md#validation-levels)). Switch single rules off or change their severity with `validation.rules` ([Switching rules off](validation.md#switching-rules-off-and-changing-their-severity)).

| Code | Severity | Business term | Check |
|---|---|---|---|
| `AT-USTG-01` | error | BT-35, BT-38, BT-37 | Name and address of the seller: street, post code and city (section 11 (1) no. 3 lit. a UStG). |
| `AT-USTG-02` | error | BT-50, BT-53, BT-52 | Name and address of the buyer: street and city, and the post code for a buyer in Austria (section 11 (1) no. 3 lit. b UStG). Not on [simplified invoices](#simplified-invoices). |
| `AT-USTG-03` | error | BT-31 | UID of the seller, or of its tax representative (section 11 (1) no. 3 lit. i UStG). Not on simplified invoices, and not when all lines are of category `E` or `O` (supplies without input tax deduction). |
| `AT-USTG-04` | error; warning for prepayment invoices (386) and their corrected invoices (384) | BT-72 | Date of the supply: delivery date (BT-72), invoicing period (BG-14) or a period on every line (BG-26) (section 11 (1) no. 3 lit. d UStG). Not for full or partial cancellations of a prepayment invoice that refer to it (flag `prepayment_correction` and BG-3, section 16 (3) no. 2 UStG). |
| `AT-USTG-05` | error | BT-48 | UID of the buyer on an invoice in euro above 10,000 EUR gross (`TaxTables::BUYER_VAT_ID_LIMIT`) from a seller in Austria to a business (section 11 (1) no. 3 lit. b UStG). Not for consumers (`->consumer()`) and not on simplified invoices. |
| `AT-USTG-06` | error | BT-120 | Every VAT row of category `AE` states that the recipient owes the VAT (section 11 (1a) UStG); see [Reverse charge](#reverse-charge-and-intra-community-supplies). |
| `AT-USTG-07` | error | BT-120 | Every VAT row of category `E`, `K` or `G` has an exemption note as text; a code (BT-121) alone is not enough (section 11 (1) no. 3 lit. e UStG). |
| `AT-USTG-08` | error | BT-117 | A small business invoice shows no VAT: every VAT row has a VAT amount of 0, and no row of category `S` a rate other than 0 (section 11 (12) UStG). |
| `AT-USTG-09` | error; warning when the invoice is written as XML | BT-22 | A self-billed invoice (type codes 389, 261 and 527) has a note containing "Gutschrift" or an equivalent ("self-billing", "self-billed invoice", "autofacturation", "autofactura", "autofatturazione", "autofacturação", "zelffacturering", "samofakturowanie") (section 11 (8) no. 3 UStG). In an e-invoice the type code labels it, so there it is a warning. See [Self-billing](#self-billing-gutschrift). |
| `AT-USTG-10` | warning | BT-6 | An invoice in another currency than euro states the VAT in euro: VAT accounting currency (BT-6) `EUR` and the VAT total in it (BT-111), set with `->taxCurrency('EUR', $rate)` (section 11 (1) no. 3 lit. f UStG). Only a warning, because the conversion method may be stated in a note, which the rule cannot read. Not on simplified invoices or without VAT. |
| `AT-USTG-11` | warning | BT-2 | The invoice is issued more than six months after the supply (section 11 (1) no. 1 UStG). Not for consumers, credit notes (381, 261), corrected invoices (384), prepayment invoices (386) and invoices that refer to an earlier invoice without deducting a prepayment. The supply date is the latest of delivery date, end of the invoicing period and end of the line periods. |
| `AT-USTG-12` | warning | BT-2 | An invoice for services in another member state with reverse charge is issued after the 15th of the month following the service (section 11 (1) no. 2 UStG). It applies to lines with `VatPreset::ReverseChargeEu`, and to lines of category `AE` without preset when the buyer is in another member state; the same exceptions as `AT-USTG-11`. |

Late invoices stay valid, so `AT-USTG-11` and `AT-USTG-12` are warnings. Messages and hints exist in German and English ([Translations](#translations)). The rules also offer helpers for your own code: `UstgRules::isSimplifiedInvoice()`, `isSmallBusinessInvoice()`, `needsBuyerVatId()`, `hasServiceDate()` and `supplyDate()`.

## Profiles

The pack registers two profiles of the Austrian CIUS of EN 16931, as published by the BRZ for the BMF ([CIUS-AT-NAT](https://www.erechnung.gv.at/go/cius_at_nat), [CIUS-AT-GOV](https://www.erechnung.gv.at/go/cius_at_gov)). Both have the level EN 16931 and the syntaxes CII and UBL. Embedded in a ZUGFeRD PDF, they have the conformance level "EN 16931" with their own specification identifier ([Profiles](formats.md#profiles)).

| Key | Class | Name | Specification identifier (BT-24) | Rules |
|---|---|---|---|---|
| `cius-at-nat` | `Profiles\CiusAtNat` | CIUS-AT-NAT 1.0.0 | `urn:cen.eu:en16931:2017#compliant#urn:erechnung.gv.at:CIUS-AT-NAT:1.0.0` | `AT-NAT-01` to `AT-NAT-08` |
| `cius-at-gov` | `Profiles\CiusAtGov` | CIUS-AT-GOV 1.1.0 | `urn:cen.eu:en16931:2017#compliant#urn:erechnung.gv.at:CIUS-AT-NAT:1.0.0#compliant#urn:erechnung.gv.at:CIUS-AT-GOV:1.1.0` | the rules of `cius-at-nat` and `AT-GOV-01` to `AT-GOV-09` |

`cius-at-gov` is chosen automatically for public buyers ([Format choice](#format-choice)). `cius-at-nat` is used only when you request it, in any format with CII or UBL:

```php
$result = $quittio->make($invoice)->profile('cius-at-nat')->render();               // ZUGFeRD with CIUS-AT-NAT
$result = $quittio->make($invoice)->format('ubl')->profile('cius-at-nat')->render();
```

The reader recognises both profiles by their specification identifier once the pack is registered ([Reading incoming invoices](reading.md#supported-documents)).

The CIUS documents state no severity; every rule is an error at level L3. Where EN 16931 forbids a value that the CIUS makes mandatory (the VAT rate of category `O`, BR-O-05 to BR-O-07), EN 16931 wins.

CIUS-AT-NAT (`CiusAtRules::national()`):

| Code | Business term | Check |
|---|---|---|
| `AT-NAT-01` | BT-35, BT-50, BT-64, BT-75 | Address line 1 of every postal address: seller, buyer, tax representative and delivery address. |
| `AT-NAT-02` | BT-37, BT-52, BT-66, BT-77 | City of every postal address. |
| `AT-NAT-03` | BT-38, BT-53, BT-67, BT-78 | Post code of every postal address, also of a buyer abroad. |
| `AT-NAT-04` | BT-72 | Delivery date (BT-72) or invoicing period (BG-14) at document level; periods on the lines are not enough. |
| `AT-NAT-05` | BT-96 | VAT rate of every document level allowance, except category `O`. |
| `AT-NAT-06` | BT-103 | VAT rate of every document level charge, except category `O`. |
| `AT-NAT-07` | BT-124 | No external document location; embed the attachment instead. |
| `AT-NAT-08` | BT-152 | VAT rate of every invoice line, except category `O`. |

CIUS-AT-GOV adds the rules of e-Rechnung.gv.at (`CiusAtRules::government()`):

| Code | Business term | Check |
|---|---|---|
| `AT-GOV-01` | BT-10 | Buyer reference: for the federal government the order reference (Auftragsreferenz) of the ordering office (`->buyerReference(…)`). |
| `AT-GOV-02` | BG-6, BT-43 | Seller contact with an email address (`->contact(name, phone, email)` on the seller). |
| `AT-GOV-03` | BT-81 | A payment means code, if given, is one of 30, 31, 42, 49, 58 or 59 (`CiusAtRules::GOV_PAYMENT_MEANS`). |
| `AT-GOV-04` | BT-115 | Amount due at most 999,999,999.99. |
| `AT-GOV-05` | BG-24 | At most 200 attachments of together at most 15 MB. |
| `AT-GOV-06` | BT-125 | Embedded attachments are PDF, PNG, XML or Excel files: mime code and file name extension `.pdf`, `.png`, `.xml`, `.xls` or `.xlsx`. |
| `AT-GOV-07` | BG-25 | At most 999 invoice lines. |
| `AT-GOV-08` | BT-131 | Line net amounts between -999,999,999,999.99 and 999,999,999,999.99. |
| `AT-GOV-09` | BT-132 | With a ten digit order number (BT-13, `->purchaseOrderReference(…)`) every line references its order line (`->orderLineReference(…)` on the line). |

## Format choice

`Formats\AustrianFormatRule` is the format rule of the pack. A buyer whose electronic address (BT-49) has the scheme `9915` (Austrian organisation number, e.g. `b` for the federal government) receives UBL after `cius-at-gov`. This applies to domestic invoices and, as buyer pack, to invoices from other countries.

Everything else follows the [automatic format choice](formats.md#automatic-choice) of the core: a domestic invoice to a business becomes ZUGFeRD with the configured profile (default `en16931`), an invoice to a consumer a PDF. Austria has no e-invoicing obligation between businesses, so the pack prescribes nothing else. `prepare()` shows the choice without rendering, and `->format()` overrides it.

### Invoices to the federal government

```php
use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Builder\Party;
use Quittio\Core\Enums\PaymentMeansCode;
use Quittio\Core\Enums\UnitCode;
use Quittio\Core\Quittio;
use Quittio\Core\Tax\CountryDefaultVat;
use Quittio\Country\AT\CountryAT;

$quittio = Quittio::create([], [new CountryAT]);
InvoiceBuilder::useDefaultVat(new CountryDefaultVat($quittio->countries()));

$invoice = InvoiceBuilder::make()
    ->number('RE-2026-0046')
    ->issueDate('2026-10-05')
    ->deliveryDate('2026-09-30')
    ->buyerReference('4700000001')                 // order reference (Auftragsreferenz) of the ordering office (AT-GOV-01)
    ->seller(Party::make('Donau Werkstatt GmbH')
        ->vatId('ATU12345678')
        ->address('Mariahilfer Straße 1', '1060', 'Wien', 'AT')
        ->contact('Anna Huber', '+43 1 234567', 'rechnung@donau-werkstatt.example'))   // AT-GOV-02
    ->buyer(Party::make('Bundesministerium für Beispiele')
        ->address('Musterplatz 1', '1010', 'Wien', 'AT')
        ->electronicAddress('b', '9915'))          // scheme 9915: UBL after CIUS-AT-GOV
    ->line('Softwareentwicklung', netPrice: '100.00', quantity: 12, unit: UnitCode::Hour)
    ->paymentTerms(fn ($terms) => $terms->dueInDays(30))
    ->payment(PaymentMeansCode::SepaCreditTransfer, iban: 'AT611904300234573201', bic: 'BKAUATWW')   // code 58 (AT-GOV-03)
    ->build();

$result = $quittio->make($invoice)->render();
$result->format();             // "ubl"
$result->profile()?->key();    // "cius-at-gov"
$result->xml();                // the UBL to upload to e-Rechnung.gv.at
```

If you have a ten digit order number, set it as purchase order reference (BT-13) and give every line its order line reference: `->line(fn ($line) => $line->name('…')->…->orderLineReference('10'))` (`AT-GOV-09`).

## ebInterface

ebInterface 6.1 is the Austrian XML invoice format of AUSTRIAPRO; e-Rechnung.gv.at accepts it besides UBL. The pack writes and reads it.

```php
use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Builder\Party;
use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Enums\PaymentMeansCode;
use Quittio\Core\Enums\UnitCode;
use Quittio\Core\Quittio;
use Quittio\Country\AT\CountryAT;
use Quittio\Country\AT\EbInterface\EbInterfaceWriter;
use Quittio\Country\AT\Tax\VatPreset;

$quittio = Quittio::create([], [new CountryAT]);

$invoice = InvoiceBuilder::make()
    ->number('RE-2026-0046')
    ->issueDate('2026-10-05')
    ->deliveryDate('2026-09-30')
    ->purchaseOrderReference('4700000001')        // OrderID of the invoice recipient
    ->seller(Party::make('Donau Werkstatt GmbH')->vatId('ATU12345678')->address('Mariahilfer Straße 1', '1060', 'Wien', 'AT'))
    ->buyer(Party::make('Tiroler Handel KG')->vatId('ATU87654321')->address('Maria-Theresien-Straße 10', '6020', 'Innsbruck', 'AT'))
    ->line(fn ($line) => $line->name('Softwareentwicklung')->quantity(12, UnitCode::Hour)->netPrice('100.00')->vat(VatPreset::Standard)->orderLineReference('10'))
    ->paymentTerms(fn ($terms) => $terms->dueInDays(14))
    ->payment(PaymentMeansCode::SepaCreditTransfer, iban: 'AT611904300234573201', bic: 'BKAUATWW')
    ->build();

$xml = (new EbInterfaceWriter)->write((new En16931Calculator)->calculate($invoice));
file_put_contents('RE-2026-0046.xml', $xml);

$read = $quittio->read($xml);   // the reader of the pack
$read->syntax;                  // "ebinterface"
$read->profile;                 // null
$read->data->number;            // "RE-2026-0046"
```

**Writer** (`EbInterface\EbInterfaceWriter`):

- `write(InvoiceData $invoice): string` takes a calculated invoice (with totals); an invoice without totals throws an `InvalidArgumentException`. Calculate it with `En16931Calculator`, or take `$result->data()` of a render run.
- It follows the official mapping of AUSTRIAPRO between UBL and ebInterface. The purchase order reference (BT-13), else the buyer reference (BT-10), is the order reference of the invoice recipient (`OrderID`, the Auftragsreferenz of the federal government) and of every line with its order line reference (BT-132, `OrderPositionNumber`). The first seller identifier (BT-29) is the supplier number (`InvoiceRecipientsBillerID`).
- A missing VAT identifier of seller or buyer is written as `ATU00000000` (`EbInterfaceWriter::UNDEFINED_VAT_ID`).
- The type code sets the document type (`EbInterfaceWriter::documentType()`): credit notes (381, 261) are a `CreditMemo` with positive amounts, prepayment invoices (386) `InvoiceForAdvancePayment`, partial invoices (326) `InvoiceForPartialDelivery`, self-billed invoices (389) `SelfBilling`, final payment requests (218) `FinalSettlement`, debit notes (383) `SubsequentDebit`, everything else `Invoice`.
- The root element carries `GeneratingSystem="Quittio"`, the currency, the title of the presentation as `DocumentTitle` and the two-letter language of the invoice locale.
- Fields without a place in ebInterface are not written, e.g. the payee (BG-10), the buyer reference next to a purchase order reference, attachments and the VAT in accounting currency (BT-111).

**Reader** (`EbInterface\EbInterfaceReader`):

- It reads ebInterface 6.0 and 6.1 (`EbInterfaceReader::NAMESPACES`). `CountryAT::readers()` registers it, so `read()`, `readFile()`, `validateFile()` and `validatePath()` accept such files once the pack is registered ([Reading incoming invoices](reading.md#reading-a-file)).
- The mapping is that of the writer in reverse. The result has the syntax `ebinterface` and no profile; the rules use the configured default profile.
- The language attribute sets the locale of the data, and the declared amounts are compared with a recalculation as for other formats.
- The XSD level (L4) of the core has schemas for CII and UBL only and skips ebInterface files; the reader checks their structure, the other levels their content ([Incoming files](validation.md#incoming-files)).

ebInterface is not an output format of `render()` yet; the XML formats of the core are CII and UBL ([CII and UBL](formats.md#cii-and-ubl)). Use the writer directly. The pack's tests check its output against the schema of ebInterface 6.1.

## Self-billing (Gutschrift)

A self-billed invoice is issued by the buyer in the name of the seller. Set its type code (`->type(InvoiceTypeCode::SelfBilledInvoice)`, 389; see [Credit notes, corrected invoices and self-billing](building-invoices.md#credit-notes-corrected-invoices-and-self-billing)) and label it "Gutschrift" (section 11 (8) no. 3 UStG). The pack has no builder macro for it; add the note yourself:

```php
use Quittio\Core\Enums\InvoiceTypeCode;
use Quittio\Core\Enums\NoteSubjectCode;
use Quittio\Country\AT\Texts\LegalTexts;

$builder->type(InvoiceTypeCode::SelfBilledInvoice)
    ->note(LegalTexts::text(LegalTexts::SELF_BILLING), NoteSubjectCode::RegulatoryInformation);   // "Gutschrift"
```

Without the note, `AT-USTG-09` is an error for a PDF without XML and a warning for an e-invoice. The PDF and HTML title of a self-billed invoice is "Gutschrift" (English "Self-billed invoice (Gutschrift)") from the [correction policy](#cancellations-and-corrections), and the [mandatory PDF output](#mandatory-pdf-output) requires it to be printed. ebInterface writes such an invoice as `SelfBilling`.

## Cancellations and corrections

`CountryAT::correctionPolicy()` sets how invoices of an Austrian seller are cancelled and corrected (see [Building invoices: cancellations and corrections](building-invoices.md#cancellations-and-corrections) and [`CorrectionPolicy`](country-packs.md#correctionpolicy)):

- **Cancellation strategy `credit_note`:** a cancellation is a credit note (type code 381) with positive amounts.
- **Correction strategy `cancel_and_reissue`:** the Managed layer corrects an invoice by cancelling it and issuing a new one.

Change them with `quittio.corrections.cancellation_strategy` and `quittio-managed.correction.strategy`. The policy sets these titles:

| Kind | German | English |
|---|---|---|
| `cancellation` | Stornorechnung | Cancellation invoice |
| `cancellation_self_billed` | Storno-Gutschrift | Cancellation of the self-billed invoice (Storno-Gutschrift) |
| `correction` | Rechnungsberichtigung | Corrected invoice |
| `self_billed` | Gutschrift | Self-billed invoice (Gutschrift) |

A price reduction (`Quittio::priceReduction()`) has no Austrian title and takes the label of the core. Locales other than German and English take the English title.

```php
$cancellation = $quittio->cancellation($result->data(), 'ST-2026-0001', reason: 'Doppelt verrechnet');
$quittio->make($cancellation)->render()->filename();   // "Stornorechnung_ST-2026-0001.pdf"
```

## Late payment

When a customer pays late, the Austrian Commercial Code (UGB) gives a business creditor default interest from a business debtor and a flat rate for recovery costs; a consumer owes the statutory interest of the Civil Code (ABGB). The pack provides these values and a calculator. Quittio's documents do not apply them by themselves: calculate the interest with [`DefaultInterest`](#default-interest) and pass the results to a payment reminder ([Documents: payment reminders](documents.md#payment-reminders)).

`CountryAT::dunningLaw()` (also `CountryAT::austrianDunningLaw()` without an instance) returns these values ([`DunningLaw`](country-packs.md#dunninglaw)):

| Value | Austria |
|---|---|
| Interest for business debtors | base rate plus 9.2 percentage points (section 456 UGB) |
| Interest for consumers | a fixed 4 % (section 1000 (1) ABGB), not tied to the base rate |
| Flat rate for business debtors | 40.00 EUR (section 458 UGB) |
| Day count | 365 days |
| Consumer default | not modelled (`null`) |
| Reference rate | "Basiszinssatz (OeNB, § 456 UGB)", the base rate of the Oesterreichische Nationalbank |

### Default interest

`Quittio\Country\AT\Dunning\DefaultInterest` calculates the default interest on an open amount:

| Method | Result |
|---|---|
| `__construct(?BaseRateTable $rates = null, ?DunningLaw $law = null)` | Without a table, the published rates (`new BaseRateTable`). Without a law, `CountryAT::austrianDunningLaw()`. |
| `calculate(Money $amount, Date $from, Date $until, bool $consumer = false, string $locale = 'de'): list<InterestCharge>` | From `$from` (first day with interest) to `$until` (exclusive). For businesses one charge per base rate period, rate = base rate + 9.2 points; for consumers (`$consumer`) one charge at 4 % for the whole time. Empty for a non-positive amount or when `$until` is not after `$from`. `InvalidArgumentException` for days the [base rate table](#base-rate-table) does not cover. |
| `flatRate(string $currency = 'EUR'): ?Money` | The flat rate of 40.00 for business debtors (section 458 UGB), once per claim |

```php
use Brick\Money\Money;
use Quittio\Core\Builder\Party;
use Quittio\Core\Documents\DocumentBuilder;
use Quittio\Core\Quittio;
use Quittio\Core\Support\Date;
use Quittio\Country\AT\CountryAT;
use Quittio\Country\AT\Dunning\DefaultInterest;

$quittio = Quittio::create([], [new CountryAT]);
$interest = new DefaultInterest;

$reminder = DocumentBuilder::paymentReminder(1)                     // first reminder ("1. Mahnung")
    ->number('M-2026-0003')
    ->issueDate('2026-10-05')
    ->seller(Party::make('Donau Werkstatt GmbH')->vatId('ATU12345678')->address('Mariahilfer Straße 1', '1060', 'Wien', 'AT'))
    ->buyer(Party::make('Tiroler Handel KG')->vatId('ATU87654321')->address('Maria-Theresien-Straße 10', '6020', 'Innsbruck', 'AT'))
    ->creditTransfer('AT611904300234573201', 'BKAUATWW')
    ->openItem('RE-2026-0042', '2026-06-01', '1440.00', dueDate: '2026-06-15')
    // first day with interest: the day after the due date; 2026-10-05 is the first day without interest
    ->interestCharge(...$interest->calculate(Money::of('1440.00', 'EUR'), Date::parse('2026-06-16'), Date::parse('2026-10-05')))
    ->flatRate('RE-2026-0042')                                         // 40.00 EUR, once per claim, only from businesses
    ->deadline('2026-10-19')
    ->build();

$reminder->dunning->total('EUR');                                      // EUR 1526.99: 1,440.00 + 46.99 interest + 40.00
$quittio->document($reminder)->render()->save(__DIR__.'/reminders/');
```

- **Descriptions.** With a German locale (the default, also `de_AT`) a description reads "Verzugszinsen 10,73 % p. a. (Basiszinssatz 1,53 % + 9,20 Prozentpunkte, § 456 UGB) vom 16.06.2026 bis 04.10.2026", for a consumer "Verzugszinsen 4,00 % p. a. (§ 1000 Abs. 1 ABGB) vom 16.06.2026 bis 04.10.2026". With `locale: 'en'` they are English: "Default interest 10.73 % p. a. (base rate 1.53 % + 9.20 percentage points, section 456 UGB) from 2026-06-16 to 2026-10-04". Other locales take German. The texts are `LegalTexts::DEFAULT_INTEREST` and `DEFAULT_INTEREST_CONSUMER` ([Legal texts](#legal-texts)).
- **Day count.** The charges count 365 days per year (`InterestCharge::$dayCount`); `interestCharge()` takes it over.
- **Periods.** A charge covers a whole period with the same base rate. A table entry that keeps the rate (e.g. 1 July 2026) starts no new period.
- **Flat rate.** `DocumentBuilder::flatRate($claim)` adds 40.00 EUR as fee of kind `DunningFeeKind::FlatRate` for one claim. The builder rejects a second flat rate for the same claim in one reminder and a flat rate for a consumer; a flat rate together with reminder fees gets the notice `QUITTIO-DUNNING-FLAT-RATE-FEES` ([Documents: payment reminders](documents.md#payment-reminders)). Which claims already carried it in earlier reminders is up to your application (the Managed layer does not track it).
- **Core calculator.** `Quittio\Core\Dunning\DefaultInterest::forCountry(new CountryAT)` calculates the same rates from `referenceRates()` and `dunningLaw()`, with the descriptions of the core instead of the Austrian texts.

### Base rate table

`Quittio\Country\AT\Dunning\BaseRateTable` holds the base rate (Basiszinssatz) of the OeNB for contracts concluded from 16 March 2013. The rate on the first day of a half-year applies to the whole half-year. `CountryAT::referenceRates()` returns the table. Quittio ships these rates (`BaseRateTable::PUBLISHED`):

| Valid from | Rate in % |
|---|---|
| 2013-01-01 | 0.38 |
| 2013-07-01 | -0.12 |
| 2016-07-01 | -0.62 |
| 2023-01-01 | 1.88 |
| 2023-07-01 | 3.38 |
| 2024-01-01 | 3.88 |
| 2025-01-01 | 2.53 |
| 2025-07-01 | 1.53 |
| 2026-01-01 | 1.53 |
| 2026-07-01 | 1.53 |

Rates after the last entry are unknown until they are published. Interest for days from 1 January 2027 is therefore rejected instead of silently using the old rate. The pack has no configuration or automatic update for new rates: add a published rate to a table and pass it to `DefaultInterest` (the value below is an example, not the published rate):

```php
use Quittio\Country\AT\Dunning\BaseRateTable;
use Quittio\Country\AT\Dunning\DefaultInterest;

$rates = (new BaseRateTable)->withRate('2027-01-01', '1.78');
$interest = new DefaultInterest($rates);
```

| Method | Result |
|---|---|
| `__construct(array $rates = self::PUBLISHED)` | ISO date => rate in percent, sorted by date |
| `withRate(Date\|string $from, string $rate): self` | new table with an additional or replaced rate |
| `rateOn(Date $date): BigDecimal` | rate on a day; `InvalidArgumentException` before the first entry and from `validUntil()` on |
| `periods(Date $from, Date $until): list<array{from: Date, until: Date, rate: BigDecimal}>` | periods with a constant rate (start inclusive, end exclusive), split where the rate changes; `InvalidArgumentException` if `$until` is after `validUntil()` |
| `lastChange(): Date` | date of the last entry, e.g. to warn when the table is outdated |
| `validUntil(): Date` | first day without a known rate: the next 1 January or 1 July after the last entry (with the published table: 2027-01-01) |

## Payment QR code

The pack's default payment QR code is `girocode`, and it brings no generator of its own. With the default `paymentQr` setting `auto`, the PDF therefore prints the EPC GiroCode of the core next to the bank details: for invoices in euro with a SEPA credit transfer (payment means 30 or 58) and a valid IBAN. Conditions, payload and options are described in [Templates: payment QR codes](templates.md#payment-qr-codes); `none` switches the code off.

## Mandatory PDF output

After rendering, the mandatory field guard checks that the PDF shows certain values ([Templates: mandatory field guard](templates.md#mandatory-field-guard)). `CountryAT::mandatoryOutput()` adds these Austrian values to the base fields. Paths without a value are skipped. The legal basis is the value Quittio reports, in German citation form.

| Condition | Access key | Description | Legal basis |
|---|---|---|---|
| not a simplified invoice, and the seller or its tax representative has a VAT identifier | `seller.vatId\|taxRepresentative.vatId` | VAT identifier (UID) of the seller | § 11 Abs. 1 Z 3 lit. i UStG |
| every invoice with BT-72 or BG-14 | `servicePeriod\|deliveryDate\|delivery.date` | date of the supply or service | § 11 Abs. 1 Z 3 lit. d UStG |
| the same, without BT-72 and BG-14 | `servicePeriod\|lineGroups[*].lines[*].period\|lines[*].period` | date of the supply or service | § 11 Abs. 1 Z 3 lit. d UStG |
| not simplified, the buyer has a VAT identifier and [`AT-USTG-05`](#ustg-rules) requires it | `buyer.vatId` | VAT identifier (UID) of the buyer | § 11 Abs. 1 Z 3 lit. b UStG |
| self-billed type code | `title` | label "Gutschrift" | § 11 Abs. 8 Z 3 UStG |
| each VAT row of category `AE`, `K`, `G` or `E` | `vatRows[i].label` or the legal note with its BT-120 text | reverse charge or exemption note | § 11 Abs. 1 Z 3 lit. e und Abs. 1a UStG |
| category `AE` or `K` used | `buyer.vatId` | VAT identifier (UID) of the buyer | § 11 Abs. 1a UStG, Art. 11 UStG |

The default templates print all of them.

## Legal texts

`Quittio\Country\AT\Texts\LegalTexts::catalog()` (also `CountryAT::legalTexts()`) holds the Austrian notices in German and English. Its fallback locale is German (`LegalTexts::FALLBACK_LOCALE`).

| Constant (key) | German | English | Used by |
|---|---|---|---|
| `SELF_BILLING` (`self_billing`) | Gutschrift | Self-billed invoice (Gutschrift) | – (your note on self-billed invoices) |
| `REVERSE_CHARGE` (`reverse_charge`) | Übergang der Steuerschuld auf den Leistungsempfänger (Reverse Charge) | Reverse charge: the recipient owes the VAT | – (text of `VatPreset::ReverseChargeEu`) |
| `INTRA_COMMUNITY_SUPPLY` (`intra_community_supply`) | Steuerfreie innergemeinschaftliche Lieferung (Art. 6 Abs. 1 i. V. m. Art. 7 UStG) | VAT exempt intra-community supply (Art. 6 (1) with Art. 7 UStG) | – (text of `VatPreset::IntraCommunitySupply`) |
| `EXPORT` (`export`) | Steuerfreie Ausfuhrlieferung (§ 6 Abs. 1 Z 1 i. V. m. § 7 UStG) | VAT exempt export supply (section 6 (1) no. 1 with section 7 UStG) | – (text of `VatPreset::Export`) |
| `SMALL_BUSINESS` (`small_business`) | Umsatzsteuerfrei aufgrund der Kleinunternehmerregelung (§ 6 Abs. 1 Z 27 UStG). | VAT exempt under the small business scheme (section 6 (1) no. 27 UStG). | – (text of `VatPreset::SmallBusiness`) |
| `DEFAULT_INTEREST` (`default_interest`) | Verzugszinsen :rate % p. a. (Basiszinssatz :base_rate % + :points Prozentpunkte, § 456 UGB) vom :from bis :until | Default interest :rate % p. a. (base rate :base_rate % + :points percentage points, section 456 UGB) from :from to :until | `DefaultInterest`, business debtors |
| `DEFAULT_INTEREST_CONSUMER` (`default_interest_consumer`) | Verzugszinsen :rate % p. a. (§ 1000 Abs. 1 ABGB) vom :from bis :until | Default interest :rate % p. a. (section 1000 (1) ABGB) from :from to :until | `DefaultInterest`, consumers |

- `REVERSE_CHARGE`, `INTRA_COMMUNITY_SUPPLY`, `EXPORT` and `SMALL_BUSINESS` are the exemption reasons of the named [tax presets](#tax-presets), in every locale the pack ships, so a notice has one wording in BT-120 and in your notes. The other three come from `resources/lang/{de,en}/legal.php`.
- All texts are read through the translator, so overrides of `tax.php` and `legal.php` change them ([Translations](#translations)).
- `LegalTexts::text($key, $locale, $replace)` returns a text in `$locale`, else in its language (`de_AT` → German, `en_GB` → English), else in German. `LegalTexts::localeOf($key, $locale)` names the catalog locale it uses.
- Validation and rendering add no text.

## Meta flags and settings

The pack defines no meta flags of its own. It reads the small business flag of the core, `Meta::FLAG_SMALL_BUSINESS` (`small_business`), set by `quittio.seller.small_business`, `small_business` of `Quittio::create()`, a Managed issuer or `->flag()` ([Small businesses](#small-businesses)).

The pack has no settings: there is no `quittio.countries.at` section and no configuration file to publish ([Settings of a pack](configuration.md#settings-of-a-pack-quittiocountriescode)). It has no builder macros either.

## Defaults

For pack authors and for reference, this is how `CountryAT` fills the [`CountryPack` contract](country-packs.md#the-countrypack-contract):

| Hook | Austria |
|---|---|
| `defaults()` | currency `EUR`, time zone `Europe/Vienna`, locale `de`, paper `A4`, letter layout `free`, profile `en16931`, payment QR `girocode`, default VAT presets `at.standard` and `at.small_business` |
| `profiles()` | [`CiusAtNat`, `CiusAtGov`](#profiles) |
| `taxPresets()` | every case of [`VatPreset`](#tax-presets) |
| `rules()` | the [UStG rules](#ustg-rules) `AT-USTG-01` to `AT-USTG-12` |
| `buyerCountryRules()` | none |
| `formatRules()` | [`AustrianFormatRule`](#format-choice) |
| `mandatoryOutput()` | [mandatory PDF output](#mandatory-pdf-output) |
| `legalTexts()` | [`LegalTexts::catalog()`](#legal-texts) |
| `numberingRules()` | pattern `RE-{Y}-{seq:4}`, reset `yearly`, resets `never`, `yearly`, `monthly`, gapless, number consumed on rejection |
| `retention()` | 7 years from the end of the calendar year |
| `dunningLaw()`, `referenceRates()` | [UGB and ABGB values, OeNB base rate](#late-payment) |
| `correctionPolicy()` | [credit note, cancel and reissue, Austrian titles](#cancellations-and-corrections) |
| `readers()` | [`EbInterfaceReader`](#ebinterface) |
| `letterLayouts()`, `paymentQr()`, `addressFormatter()` | defaults of the core: `free`, the EPC GiroCode of the core, the international address format |
| `translationPath()` | `resources/lang` of the package |

- **Locale.** German (`de`), unless the run, the invoice or the configuration sets another ([Translations and locales](templates.md#translations-and-locales)). Dates are printed as `dd.MM.yyyy`.
- **Paper and letter layout.** A4 ([Paper size](templates.md#paper-size)) and the free layout without a norm ([Letter layouts](templates.md#letter-layouts)). The pack has no Austrian letter layout.
- **Numbering.** `RE-{Y}-{seq:4}`, e.g. `RE-2026-0042`, reset every year: a consecutive number with one or more series that identifies the invoice once (section 11 (1) no. 3 lit. h UStG). The Managed layer uses it when neither the issuer nor the configuration sets a pattern ([`NumberingRules`](country-packs.md#numberingrules)).
- **Retention.** `new RetentionRule(7, fromEndOfCalendarYear: true)`: invoices are kept for seven years from the end of the calendar year (section 11 (2) UStG, section 132 BAO). An invoice issued on 5 October 2026 is kept until 31 December 2033. Longer periods for property related supplies (section 18 (10) UStG) are not modelled ([`RetentionRule`](country-packs.md#retentionrule)).
- **Correction policy.** See [Cancellations and corrections](#cancellations-and-corrections).

## Translations

The pack ships German and English catalogs in `resources/lang/{de,en}/` (`CountryAT::locales()` returns `['de', 'en']`):

- `rules.php`: message and hint of every rule (`AT-USTG-*`, `AT-NAT-*`, `AT-GOV-*`);
- `tax.php`: the exemption reason texts of the presets, including `at.exempt` for `VatPreset::exempt()` (placeholder `:paragraph`);
- `legal.php`: the label "Gutschrift" and the default interest descriptions.

They are registered as the namespace `quittio-at` (e.g. `quittio-at::rules.AT-USTG-03.message`). `CountryAT::registerTranslations()` registers them lazily, so presets work without a `Quittio` instance. Rule messages and preset texts in other locales fall back to English, legal texts to German. Validation messages are written in the language set by `validation.locale` ([Messages and translations](validation.md#messages-and-translations)).

**Laravel.** Laravel's translator is used, so the usual override files apply: `lang/vendor/quittio-at/{locale}/rules.php`, `tax.php` and `legal.php`, also for additional locales ([Laravel: translations](laravel.md#translations)). A file only needs the keys you change:

```php
// lang/vendor/quittio-at/de/tax.php
return [
    'at.reverse_charge_eu' => 'Reverse Charge – Übergang der Steuerschuld auf den Leistungsempfänger',
];
```

**Without Laravel.** Register a directory with `{locale}/{group}.php` files under the namespace of the pack after creating the `Quittio` instance; later paths override earlier ones:

```php
use Quittio\Core\I18n\Translations;
use Quittio\Core\Quittio;
use Quittio\Country\AT\CountryAT;

$quittio = Quittio::create([], [new CountryAT]);
Translations::translator()->addPath(__DIR__.'/lang', CountryAT::TRANSLATION_NAMESPACE);   // e.g. lang/de/tax.php
```

A changed reverse charge text still satisfies `AT-USTG-06`, because the rule accepts the current texts of the reverse charge presets. Texts defined in PHP cannot be translated through files: the correction policy titles (German and English, fallback English) and the number and date format of the default interest descriptions (German for German locales, otherwise ISO dates).

## Known limitations

- The legal wording, references and defaults follow the law and official sources of October 2026 and have not been reviewed by an Austrian tax adviser. Later changes of the law need a new release.
- ebInterface is not a format of `render()`; use `EbInterfaceWriter`. `validateFile()` does not check ebInterface files against their schema ([ebInterface](#ebinterface)).
- There is no Austrian letter layout (ÖNORM A 1080 was withdrawn without a successor), no Laravel service provider and no settings.
- The shipped base rate table ends with 1 July 2026. Later rates must be added with `withRate()`; there is no configuration key and no automatic update ([Base rate table](#base-rate-table)).
- There are no builder macros for Austrian special cases: the "Gutschrift" note, prepayment invoices and the conversion of foreign currency amounts are set with the general builder methods.
- `AT-USTG-10` cannot read a conversion method stated in a note and only warns.
- Longer retention periods for property related supplies (section 18 (10) UStG) are not modelled.
- The documents layer checks the flat rate of section 458 UGB within one reminder only (once per claim, not for consumers); across reminders the application must track it (the Managed layer does not). When a consumer is in default is not modelled.
- The pack defines no buyer country rules and no exporters.
