# France (`quittio/country-fr`)

> **Not reviewed by a tax adviser yet.** The legal wording, rules and defaults of this pack follow the law and the official sources as of October 2026, but have not been reviewed by a French tax adviser. Check the mentions and rates of your invoices before you rely on them. Quittio is no tax or legal advice; the business that issues an invoice remains responsible for it.

The French pack implements the mentions obligatoires of French invoices, i.e. the details an invoice must state. They come from the French Tax Code (Code général des impôts, CGI: art. 242 nonies A of annex II), with the new mentions of the e-invoicing reform from 1 September 2026, and from the Commercial Code (Code de commerce) for invoices between businesses. The pack also covers the late payment penalties. Its class is `Quittio\Country\FR\CountryFR` (code `FR`, translation namespace `quittio-fr`). It covers metropolitan France and Monaco. The overseas departments have VAT rates of their own, which are not modelled.

The pack provides:

- [tax presets](#tax-presets) for the French rates, the franchise en base, autoliquidation (reverse charge), intra-community supplies, exports and the margin schemes;
- the [rules](#rules) `FR-CGI-01` to `FR-CGI-09`, `FR-COM-01` and `FR-COM-02`;
- the texts that cite the Code des impositions sur les biens et services (CIBS) instead of the CGI on invoices issued from 1 January 2027 ([From the CGI to the CIBS](#from-the-cgi-to-the-cibs));
- Factur-X for domestic invoices between businesses ([Format choice](#format-choice));
- the late payment mention and the mention of VAT on debits, added while rendering ([Mentions added by the pack](#mentions-added-by-the-pack));
- the ECB rate table and the values for late payment penalties ([Late payment](#late-payment));
- a SIREN and SIRET check ([SIREN and SIRET](#siren-and-siret)), defaults, correction titles ("Avoir") and French and English texts.

This page writes French legal references in their French form ("art. 242 nonies A of annex II to the CGI", "art. L441-10 Code de commerce"). French terms such as avoir or autoliquidation are explained on first use.

## Installation

```bash
composer require quittio/country-fr
```

The pack is not part of `quittio/quittio`. Install it next to `quittio/quittio` or next to `quittio/core`. It is developed in the monorepo [Quittio/quittio](https://github.com/Quittio/quittio/tree/master/packages/country-fr) and published as the read-only repository [Quittio/country-fr](https://github.com/Quittio/country-fr). Please open issues and pull requests in the monorepo.

- **Laravel.** The pack declares itself in its `composer.json` (`extra.quittio.country-packs`), so Laravel finds it after the installation. There is nothing to register. The pack has no service provider and no configuration file of its own; its one setting goes into `config/quittio.php` ([Meta flags and settings](#meta-flags-and-settings)).
- **Without Laravel.** Pass `new CountryFR` to `Quittio::create()`, or register it later with `registerCountry()` ([Registering packs](country-packs.md#registering-packs)):

```php
use Quittio\Core\Quittio;
use Quittio\Country\FR\CountryFR;

$quittio = Quittio::create(['country' => 'FR'], [new CountryFR]);
```

The presets, `Siren` and `LegalTexts` work without a `Quittio` instance: `CountryFR::registerTranslations()` registers the texts when they are first needed.

## When it applies

The pack leads every invoice whose seller is in France (seller country code `FR`, BT-40). It then checks the French rules, chooses the format, completes the invoice and provides the defaults: the French locale (unless the invoice or the configuration sets one), A4 paper and the EPC GiroCode. For an invoice from another country to a buyer in France, only the format rules of the pack apply, and the French format rule has no opinion on cross-border invoices. Details are in [How a pack is selected](country-packs.md#how-a-pack-is-selected).

- **Monaco.** Monaco belongs to the French VAT territory ([`VatTerritory`](country-packs.md#vatterritory)). A buyer in Monaco counts as a buyer in France for the rules and the format choice. The pack also leads the invoices of sellers with the country code `MC` ([How a pack is selected](country-packs.md#how-a-pack-is-selected)). A business in Monaco has no SIREN: `FR-CGI-01` does not ask a seller in Monaco for one, and `FR-CGI-08` does not ask a buyer in Monaco for one.
- **Overseas departments.** Addresses in `FR` with a post code starting with `97` or `98` lie outside the French VAT territory in Quittio. A buyer there is no buyer "in France" for `FR-CGI-08` and the format rule, and the resolver treats the invoice as cross-border.

## Common tasks

| You want to | Read |
|---|---|
| Add the new mentions of the e-invoicing reform | [The four new mentions](#the-four-new-mentions) |
| Know which texts cite the CIBS from 2027 | [From the CGI to the CIBS](#from-the-cgi-to-the-cibs) |
| Set the VAT rate or an exemption on a line | [Tax presets](#tax-presets) |
| Invoice as a small business without VAT (franchise en base) | [Small businesses](#small-businesses-franchise-en-base) |
| Invoice with reverse charge, an intra-community supply or an export | [Reverse charge, intra-community supplies and exports](#reverse-charge-intra-community-supplies-and-exports) |
| Look up a validation code such as `FR-CGI-08` or `FR-COM-02` | [Rules](#rules) |
| Check or extract a SIREN | [SIREN and SIRET](#siren-and-siret) |
| Know which format Quittio picks | [Format choice](#format-choice) |
| Change or switch off the late payment mention | [Mentions added by the pack](#mentions-added-by-the-pack), [Meta flags and settings](#meta-flags-and-settings) |
| Calculate late payment penalties for a reminder | [Late payment](#late-payment) |
| Cancel or correct an invoice (avoir) | [Credit notes, corrections and self-billing](#credit-notes-corrections-and-self-billing) |
| Change a text or add a language | [Translations](#translations) |
| See a complete invoice | [Complete example](#complete-example) |

## E-invoicing reform

All businesses receive e-invoices from 1 September 2026; large and mid-sized businesses issue them from that date, all others from 1 September 2027. They are exchanged through an approved platform (plateforme agréée) in Factur-X, UBL or CII. The pack chooses Factur-X for domestic invoices to businesses ([Format choice](#format-choice)). The connection to the platforms and e-reporting are not part of this pack, nor are the business rules of the French specifications (BR-FR-…); see [Known limitations](#known-limitations).

### The four new mentions

From 1 September 2026 an invoice states four new mentions. The pack checks two of them, adds one and leaves one to you:

| Mention | How to set it | Checked by |
|---|---|---|
| The buyer's SIREN, for a business established in France | `->legalRegistration('901234567', '0002')` on the buyer (BT-47, scheme `0002` = SIREN) | `FR-CGI-08` (warning) |
| The category of the operation: supply of goods, services or both | `->businessProcess('B1')` for goods, `'S1'` for services, `'M1'` for both (operation category, BT-23) | `FR-CGI-09` (warning) |
| The delivery address, if it differs from the buyer's address | `->deliverTo($name, $address)` (deliver-to address, BG-15) | not checked |
| The option to pay VAT on debits (option pour le paiement de la taxe d'après les débits) | `->flag(CountryFR::FLAG_VAT_ON_DEBITS)`: the pack adds "Option pour le paiement de la taxe d'après les débits" | added by `complete()` |

```php
use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Data\Party\Address;
use Quittio\Country\FR\CountryFR;

$builder = InvoiceBuilder::make()
    // number, dates, seller, lines …
    ->businessProcess('M1')                                   // goods and services (BT-23)
    ->buyer(fn ($b) => $b
        ->name('Boutique Exemple SARL')
        ->vatId('FR15901234567')
        ->legalRegistration('901234567', '0002')              // SIREN of the buyer (BT-47)
        ->address('5 avenue de l\'Opéra', '75001', 'Paris', 'FR'))
    ->deliverTo('Boutique Exemple SARL, entrepôt', new Address('FR', '8 rue des Entrepôts', city: 'Saint-Denis', postcode: '93200'))
    ->flag(CountryFR::FLAG_VAT_ON_DEBITS);                    // the seller opted to pay VAT on debits
```

- The rules `FR-CGI-08` and `FR-CGI-09` apply to invoices with an issue date (BT-2) from 1 September 2026 (`FrenchRules::REFORM_DATE`) and not to consumers. They are warnings, so an invoice without these mentions still renders.
- The operation category is written to the XML as business process (BT-23), e.g. `<ram:ID>S1</ram:ID>` in Factur-X.

## From the CGI to the CIBS

On 1 January 2027 the VAT rules of the CGI move into the Code des impositions sur les biens et services (CIBS); ordonnance n° 2026-671 of 27 July 2026 postponed the date from 1 September 2026. References to the CGI, also in the mentions of an invoice, remain accepted until 30 June 2028 (art. 46 II ordonnance n° 2025-1247, BOI-RES-TVA-000253). Art. 242 nonies A of annex II stays in the CGI; its 12° accepts the reference to the CGI, to the VAT Directive or any other mention of the exemption.

On invoices issued from 1 January 2027 (`VatPreset::CIBS_FROM`), the pack replaces the texts of three presets by texts that cite the CIBS:

| Case | Issued until 31 December 2026 | Issued from 1 January 2027 |
|---|---|---|
| `SmallBusiness` | TVA non applicable, art. 293 B du CGI | TVA non applicable, art. L. 233-3 du CIBS |
| `IntraCommunitySupply` | Exonération de TVA, article 262 ter I du CGI | Exonération de TVA, article L. 213-12, 1° du CIBS |
| `Export` | Exonération de TVA, article 262 I du CGI | Exonération de TVA, article L. 213-12, 2° du CIBS |

In English the texts read "VAT not applicable, art. 293 B of the French Tax Code (CGI)", "VAT exempt, article 262 ter I CGI" and "VAT exempt, article 262 I CGI", and from 2027 "VAT not applicable, art. L. 233-3 of the French Code on Taxes on Goods and Services (CIBS)", "VAT exempt, article L. 213-12, 1° CIBS" and "VAT exempt, article L. 213-12, 2° CIBS".

Art. L. 213-12 CIBS states the exemptions of intra-European supplies (1°) and exports (2°); art. L. 213-13 and L. 213-14 set their conditions. Art. L. 233-3 defines the small business under the franchise. A decree or the BOFiP may still prescribe other wording.

How the replacement works:

- **Where.** `CountryFR::complete()` replaces the texts. The render pipeline calls it for the leading pack in `render()`, `check()` and `prepare()`, before calculation and validation ([`CompletesInvoices`](country-packs.md#the-countrypack-contract)). The data of the builder (`$builder->build()`) therefore still has the CGI texts. The rendered data (`$result->data()`, `$prepared->data`), the XML and the PDF have the CIBS texts.
- **What.** The exemption reason of every line (sub lines included), of document level allowances and charges and of the VAT breakdown (BT-120).
- **When.** The issue date (BT-2) decides, not the date of the supply. An invoice of 4 January 2027 for a delivery in December 2026 cites the CIBS.
- **Which texts.** Only texts that equal a CGI text of the three presets word for word, in one of the locales the pack ships (`fr`, `en`), with the translations in use. Texts of your own stay as they are, e.g. `VatPreset::exempt('article 261-4-1° du CGI')` or a changed wording. The comparison is by text, not by preset: a text of your own that equals one of the three texts exactly is replaced as well (`VatPreset::exempt('article 262 I du CGI')` gives the same text as `Export`). The texts of the other presets (Autoliquidation, the margin schemes, not taxable in France) do not change.

`VatPreset::exemptionReasonOn()` returns the text for an issue date, e.g. to store or show it yourself:

```php
use Quittio\Core\Support\Date;
use Quittio\Country\FR\Tax\VatPreset;

VatPreset::Export->exemptionReason();                             // "Exonération de TVA, article 262 I du CGI"
VatPreset::Export->exemptionReasonOn(Date::of(2026, 12, 31));      // "Exonération de TVA, article 262 I du CGI"
VatPreset::Export->exemptionReasonOn(Date::of(2027, 1, 4));        // "Exonération de TVA, article L. 213-12, 2° du CIBS"
VatPreset::Export->exemptionReasonOn(Date::of(2027, 1, 4), 'en');  // "VAT exempt, article L. 213-12, 2° CIBS"
```

For the other presets `exemptionReasonOn()` returns the same text as `exemptionReason()`.

## Tax presets

A tax preset is a ready-made VAT treatment for an invoice line. It sets the VAT category (BT-151) and the rate, and for the other categories also the exemption reason code (BT-121) and text (BT-120). Use a preset instead of a bare category and rate: the line then carries the wording the pack expects, and the rules recognise the case. The categories are the codes of EN 16931 ([Categories](building-invoices.md#categories)).

`Quittio\Country\FR\Tax\VatPreset` is a string-backed enum that implements `TaxPreset`. Apply it per line:

```php
use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Enums\UnitCode;
use Quittio\Country\FR\Tax\VatPreset;

$builder = InvoiceBuilder::make()
    ->locale('fr')
    ->line('Développement web', netPrice: '90.00', quantity: 10, unit: UnitCode::Hour, vat: VatPreset::Standard)
    ->line(fn ($l) => $l->name('Maintenance')->netPrice('120.00')->vat(VatPreset::Intermediate))
    // an exemption without a case of its own: the legal reference as text
    ->line('Soins', netPrice: '60.00', unit: UnitCode::Piece, vat: VatPreset::exempt('article 261-4-1° du CGI'));
```

| Case | Key | Category | Rate | Code (BT-121) | Text (BT-120), French |
|---|---|---|---|---|---|
| `Standard` | `fr.standard` | `S` | 20 | – | – |
| `Intermediate` | `fr.intermediate` | `S` | 10 | – | – |
| `Reduced` | `fr.reduced` | `S` | 5.5 | – | – |
| `Particular` | `fr.particular` | `S` | 2.1 | – | – |
| `SmallBusiness` | `fr.small_business` | `E` | 0 | – | TVA non applicable, art. 293 B du CGI (CIBS from 2027) |
| `ReverseChargeDomestic` | `fr.reverse_charge_domestic` | `AE` | 0 | `VATEX-EU-AE` | Autoliquidation |
| `ReverseChargeEu` | `fr.reverse_charge_eu` | `AE` | 0 | `VATEX-EU-AE` | Autoliquidation |
| `IntraCommunitySupply` | `fr.intra_community_supply` | `K` | 0 | `VATEX-EU-IC` | Exonération de TVA, article 262 ter I du CGI (CIBS from 2027) |
| `Export` | `fr.export` | `G` | 0 | `VATEX-EU-G` | Exonération de TVA, article 262 I du CGI (CIBS from 2027) |
| `NotTaxableInFrance` | `fr.not_taxable` | `O` | none | `VATEX-EU-O` | TVA non applicable en France (lieu de la prestation hors de France) |
| `MarginSecondHand` | `fr.margin_second_hand` | `E` | 0 | `VATEX-EU-F` | Régime particulier – Biens d'occasion |
| `MarginArt` | `fr.margin_art` | `E` | 0 | `VATEX-EU-I` | Régime particulier – Objets d'art |
| `MarginAntiques` | `fr.margin_antiques` | `E` | 0 | `VATEX-EU-J` | Régime particulier – Objets de collection et d'antiquité |
| `TravelAgency` | `fr.travel_agency` | `E` | 0 | `VATEX-EU-D` | Régime particulier – Agences de voyages |
| `VatPreset::exempt(string $paragraph, $code = null)` | `fr.exempt` | `E` | 0 | `$code` (enum, raw code or string) | Exonération de TVA, `$paragraph` |

The English texts are in `resources/lang/en/tax.php`, e.g. "Reverse charge (autoliquidation)", "Not subject to French VAT (place of supply outside France)", "Margin scheme – Second-hand goods" and "VAT exempt, :paragraph". The docblocks of the cases name the provisions behind them: art. 283-2 nonies CGI (domestic reverse charge, e.g. subcontracting in construction), art. 283-2 CGI and art. 196 VAT Directive (services to a business in another member state), art. 297 A CGI (margin schemes) and art. 266-1 e CGI (travel agencies).

How presets behave:

- **Language.** The text is taken in the invoice locale (`->locale()` on the builder) when the line is built. The enum presets and `VatPreset::exempt()` (a `Quittio\Country\FR\Tax\CustomVatPreset`) use French when the invoice has no locale. Other locales without a text of their own fall back to English.
- **Rates.** The rates are fixed values; the pack keeps no table of earlier rates. `Standard`, `Intermediate`, `Reduced` and `Particular` carry no text, because EN 16931 forbids an exemption reason for category `S`.
- **Preset key.** The key stays on the line (`$line->vat->presetKey`). The small business detection of `FR-CGI-02` and `FR-CGI-06` relies on it.
- **VAT breakdown and PDF.** Category, rate, code and text flow into the VAT breakdown (BG-23), as described in [Building invoices: tax presets](building-invoices.md#tax-presets). The PDF lists the texts in its legal notes.
- **Default VAT.** Lines without `vat:` take the [default VAT](building-invoices.md#default-vat). In Laravel that is `VatPreset::Standard` for a French seller (`CountryDefaults::$vatPreset`) unless `quittio.defaults.vat` names another preset, and `VatPreset::SmallBusiness` for a small business. Without Laravel, install `CountryDefaultVat` yourself.
- **Lookup by key**, e.g. from a database column: `VatPreset::from('fr.standard')`, `VatPreset::fromKey($key)` (null for keys of other packs), `(new CountryFR)->taxPresets()->get('fr.standard')` or, across all installed packs, `$quittio->countries()->taxPreset('fr.standard')`. `fr.exempt` is not part of the collection.

### Small businesses (franchise en base)

A small business under the franchise en base (art. 293 B CGI) charges no VAT. Mark the invoice as a small business invoice and use `VatPreset::SmallBusiness` on every line:

- **Flag.** `quittio.seller.small_business = true` in Laravel ([Configuration: small businesses](configuration.md#small-businesses)), or `->flag(Meta::FLAG_SMALL_BUSINESS)` on the builder. With the flag, lines without `vat:` get `VatPreset::SmallBusiness` from the default VAT.
- **Rules.** A small business invoice is one with the flag or with a line that uses `VatPreset::SmallBusiness` (`FrenchRules::isSmallBusinessInvoice()`). It needs no VAT number of the seller (`FR-CGI-02`), and any VAT amount in its breakdown is an error (`FR-CGI-06`).
- **EN 16931.** The rule BR-E-02 of EN 16931 requires the seller's VAT identifier (BT-31), tax registration identifier (BT-32, `->taxNumber()`) or the VAT identifier of a tax representative (BT-63) on every invoice with category `E`. The pack fills in none of them, so a small business invoice without any of them fails with `BR-E-02`, also as plain PDF.
- **From 2027** the text cites art. L. 233-3 CIBS ([From the CGI to the CIBS](#from-the-cgi-to-the-cibs)).

### Reverse charge, intra-community supplies and exports

- **Autoliquidation** (reverse charge, category `AE`): the buyer owes the VAT. Use `ReverseChargeDomestic` or `ReverseChargeEu`. Their texts contain "Autoliquidation" in both languages, which `FR-CGI-04` requires in the VAT breakdown. A category `AE` with a text of your own fails unless the text contains the word.
- **Intra-community supplies** (`IntraCommunitySupply`, category `K`) and **exports** (`Export`, category `G`): `FR-CGI-05` requires the reference to the provision as text; a code alone is not enough. The presets provide it.
- **Other exemptions**: `VatPreset::exempt('article …')` with the reference to the provision, and optionally a VATEX code as second argument.
- **VAT identifiers.** The pack has no rule of its own for the buyer's VAT number. The EN 16931 rules of the categories apply (e.g. BR-AE-02, BR-IC-02).

## Rules

`Quittio\Country\FR\Rules\FrenchRules::all()` (level L3) checks the mentions of a French invoice under art. 242 nonies A of annex II to the CGI and art. L441-9 Code de commerce. The rules apply to every invoice of a French seller, in every format including a plain PDF. Messages and hints are in French and English. Switch a rule off or change its severity with `validation.rules` ([Switching rules off](validation.md#switching-rules-off-and-changing-their-severity)).

| Rule | Severity (BT, path) | Reported when | Legal basis named | Fix |
|---|---|---|---|---|
| `FR-CGI-01` | error (BT-30 `seller.legalRegistration`) | The seller (not in Monaco) has no legal registration identifier, or its value is neither a SIREN nor a SIRET with valid check digits (`Siren::isSiren()`, `Siren::isSiret()`). Spaces are ignored; the scheme is not checked. | art. 242 nonies A annex II CGI, art. R123-237 Code de commerce | `->legalRegistration('812345676', '0002')` on the seller, or a SIRET with scheme `0009`. |
| `FR-CGI-02` | error (BT-31 `seller.vatId`) | Neither the seller nor its tax representative has a VAT number, and the invoice is no [small business invoice](#small-businesses-franchise-en-base). | art. 242 nonies A annex II CGI | `->vatId()` on the seller. |
| `FR-CGI-03` | error per missing field (BT-35, BT-38, BT-37 of the seller; BT-50, BT-53, BT-52 of the buyer) | Street (address line 1), post code or city of the seller or the buyer is empty, also for consumers. | art. 242 nonies A annex II CGI | Complete both addresses. |
| `FR-CGI-04` | error per VAT row (BT-120 `vatBreakdown[i].exemptionReason`) | A VAT row of category `AE` whose exemption reason does not contain "autoliquidation" (case-insensitive). | art. 242 nonies A annex II CGI | `VatPreset::ReverseChargeDomestic` or `ReverseChargeEu`. |
| `FR-CGI-05` | error per VAT row (BT-120 `vatBreakdown[i].exemptionReason`) | A VAT row of category `E`, `K` or `G` without exemption reason text, e.g. with a VATEX code only. | art. 242 nonies A annex II CGI | A preset, or `VatPreset::exempt()` with the reference. |
| `FR-CGI-06` | error per VAT row (BT-117 `vatBreakdown[i].taxAmount`) | A small business invoice has a VAT row whose VAT amount is not zero. | art. 293 B CGI; VAT shown is owed by reason of the invoice (art. 283-3 CGI) | `VatPreset::SmallBusiness` on every line. |
| `FR-CGI-07` | error (BT-22 `notes`); warning when the XML is written (option `syntax`) | A self-billed type code (389, 261, 527), and no note contains "autofacturation" or "self-billing" (case-insensitive). | art. 242 nonies A annex II CGI | Add the note, see [Self-billing](#credit-notes-corrections-and-self-billing). |
| `FR-CGI-08` | warning (BT-47 `buyer.legalRegistration`) | Issue date from 1 September 2026, the buyer is no consumer, its address lies in France (not in Monaco, post codes `97…` and `98…` excluded), and its legal registration identifier yields no SIREN (`Siren::of()`: a SIREN, a SIRET or a French VAT number). The scheme is not checked. | art. 242 nonies A annex II CGI | `->legalRegistration('901234567', '0002')` on the buyer. |
| `FR-CGI-09` | warning (BT-23 `businessProcess`) | Issue date from 1 September 2026, the buyer is no consumer (in France or abroad), and the business process (BT-23) is not one of `FrenchRules::OPERATION_CATEGORIES`. | art. 242 nonies A annex II CGI | `->businessProcess('S1')` for services, `'B1'` for goods, `'M1'` for both. |
| `FR-COM-01` | error (BT-9 `dueDate`) | The buyer is no consumer, the invoice is no credit note, and it has neither a due date (BT-9) nor a payment terms text (BT-20). | art. L441-9 Code de commerce | `->dueDate()` or `->paymentTerms()`. |
| `FR-COM-02` | warning (BT-22 `notes`) | The buyer is no consumer, the invoice is no credit note, and neither a note nor the payment terms text contains "pénalit" or "penalt" (case-insensitive). The rule looks for this word only; it does not check the indemnity of 40 EUR or the discount. | art. L441-9 Code de commerce | Keep the [late payment mention](#mentions-added-by-the-pack) on, or write a note of your own. |

- **Operation categories.** `FrenchRules::OPERATION_CATEGORIES` holds the codes `B1`, `S1`, `M1`, `B2`, `S2`, `M2`, `S3`, `B4`, `S4`, `M4`, `S5`, `S6`, `B7` and `S7` of the French specifications (cadre de facturation). The messages and hints name `B1`, `S1` and `M1`; the pack does not explain the other codes.
- **Self-billing in XML.** `FR-CGI-07` is a warning when the run writes XML (Factur-X, CII, UBL), where the type code marks the invoice as self-billed, and an error for a plain PDF.
- **Credit notes.** `FR-COM-01` and `FR-COM-02` skip credit notes (avoirs).

Helpers used by the rules and available to you:

- `FrenchRules::isSmallBusinessInvoice($invoice)`: the flag `small_business` is set, or a line uses `VatPreset::SmallBusiness`;
- `FrenchRules::inFrance($address)`: the address lies in the French VAT territory (with Monaco, without the overseas departments);
- `FrenchRules::REFORM_DATE` (`2026-09-01`) and `FrenchRules::OPERATION_CATEGORIES`.

## SIREN and SIRET

SIREN (nine digits) and SIRET (fourteen digits, starting with the SIREN) are the numbers of the French business register. Both end with a Luhn check digit. SIRET numbers of La Poste (SIREN 356000000) use a sum of digits instead, which `Siren` knows. Set the seller's number as legal registration identifier (BT-30) with scheme `0002` (SIREN) or `0009` (SIRET), and the buyer's SIREN as BT-47.

`Quittio\Country\FR\Support\Siren`:

| Method | Returns |
|---|---|
| `Siren::isSiren(string $value): bool` | nine digits with a valid check digit; other characters such as spaces are ignored |
| `Siren::isSiret(string $value): bool` | fourteen digits with valid check digits of the SIRET and of its SIREN (La Poste: sum of digits) |
| `Siren::of(?string $value): ?string` | the SIREN of a SIREN, a SIRET or a French VAT number (`FR` + two check digits + SIREN), or null when none with a valid check digit can be read |

```php
use Quittio\Country\FR\Support\Siren;

Siren::isSiren('812 345 676');       // true
Siren::isSiret('81234567600017');    // true
Siren::of('FR19812345676');          // "812345676", from a VAT number
Siren::of('81234567600017');         // "812345676", from a SIRET
Siren::of('812345677');              // null: wrong check digit
```

In a VAT number, `Siren::of()` checks the SIREN only, not the two check digits in front of it. For the VAT number checks of the core see [VAT identifiers and VIES](validation.md#vat-identifiers-and-vies).

## Format choice

Without `->format()`, Quittio chooses the format for each invoice ([Automatic choice](formats.md#automatic-choice)). `Quittio\Country\FR\Formats\FrenchFormatRule` returns a choice for domestic invoices between businesses and null (no opinion) otherwise:

| Condition | Format | Profile |
|---|---|---|
| The buyer is no consumer, and the addresses of seller and buyer lie in France (`FrenchRules::inFrance()`: Monaco included, post codes `97…` and `98…` excluded) | `zugferd` (Factur-X) | `en16931` |
| otherwise | – (null) | – |

Factur-X and ZUGFeRD are the same standard: a PDF/A-3 with the CII XML embedded ([ZUGFeRD and Factur-X](formats.md#zugferd-and-factur-x)).

- **Peppol.** The rule decides before the Peppol lookup of the resolver, so a domestic business invoice is Factur-X even when the buyer is reachable via Peppol.
- **Profile.** The rule asks for EN 16931 whatever profile is configured. When the invoice uses features of EXTENDED (e.g. sub lines), the resolver still upgrades it ([Upgrade to profile EXTENDED](formats.md#upgrade-to-profile-extended)).
- **Everything else** follows the general order of the resolver: consumers get `pdf`; a cross-border invoice within the EU VAT territory gets `zugferd` with the configured profile; an invoice to a buyer outside it (also in the overseas departments) gets `pdf`.
- **Explicit formats.** An explicit `->format()` or a configured `quittio.defaults.format` always wins, e.g. `->format('ubl')` or `->format('cii')` for a platform that expects UBL or CII.
- **Sending.** Quittio produces the file. Sending it through an approved platform is not part of this pack, and the pack does not check whether an invoice must be an e-invoice.

## Mentions added by the pack

`CountryFR` implements `CompletesInvoices`. While rendering, `complete()` adds up to two notes (BT-22) to the invoice, after the notes it already has. Like the [CIBS texts](#from-the-cgi-to-the-cibs), they appear in the rendered data, the XML and the PDF, not in the data of the builder.

| Note | Subject code | Added when |
|---|---|---|
| Late payment mention | `PMT` | the buyer is no consumer, the invoice is no credit note, the setting `payment_mentions` is not `false`, and no note contains "pénalit" or "penalt" (case-insensitive) |
| VAT on debits | `TXD` | the flag `CountryFR::FLAG_VAT_ON_DEBITS` is set, and no note contains "débits" or "debits" |

The late payment mention in French:

> En cas de retard de paiement, des pénalités sont dues au taux de refinancement de la Banque centrale européenne majoré de 10 points, ainsi qu'une indemnité forfaitaire pour frais de recouvrement de 40 € (art. L441-10 du Code de commerce). Pas d'escompte pour paiement anticipé.

And in English:

> In case of late payment, penalties at the refinancing rate of the European Central Bank plus 10 points and a fixed indemnity of EUR 40 for recovery costs are due (art. L441-10 of the French Commercial Code). No discount for early payment.

- **Language.** The language of the invoice locale (`->locale()` on the builder) if the pack ships it (`fr`, `en`), French otherwise and without a locale. A locale passed to the run (`->locale()` on the pending invoice) does not change the notes.
- **Your own wording.** A note of your own that contains "pénalités" (e.g. with an agreed rate or a discount) replaces the mention: the pack adds nothing, and `FR-COM-02` is satisfied. The pack does not look at the payment terms text; penalties stated only there do not stop the mention.
- **Switching it off.** `payment_mentions = false` in `quittio.countries.fr` ([Meta flags and settings](#meta-flags-and-settings)). Then state the penalties yourself, otherwise `FR-COM-02` warns.
- **No duplicates.** Running `complete()` again on completed data adds nothing.

## Late payment

Art. L441-10 Code de commerce: the refinancing rate of the ECB on the first day of the half-year plus 10 points (12.40 % in the second half of 2026) and a fixed indemnity of 40 EUR. `EcbRateTable` holds the ECB rates from 2023; add later half-years with `withRate()`. Consumers owe the legal interest rate (art. 1231-6 Code civil). Its category depends on the creditor (art. L313-2 Code monétaire et financier): a business claims the rate for all other cases, 2.75 % in the second half of 2026 (`b2cFixedRate`; it changes every half-year), not the rate for creditors who are individuals (6.84 %).

Quittio's documents do not apply these values by themselves: calculate the penalties with `DefaultInterest` and pass them to a payment reminder ([Documents: payment reminders](documents.md#payment-reminders)).

`CountryFR::dunningLaw()` (also as `CountryFR::frenchDunningLaw()`) returns these values ([`DunningLaw`](country-packs.md#dunninglaw)):

| Value | France |
|---|---|
| Penalties for business debtors (`b2bInterestPoints`) | ECB rate plus 10.00 points |
| Interest for consumers (`b2cFixedRate`) | 2.75 % fixed, for the whole period |
| Fixed indemnity for business debtors (`b2bFlatRate`) | 40.00 EUR |
| Day count | 365 days |
| Consumer default (`consumerDefaultAfterDays`) | none |
| Reference rate (`referenceRateName`) | "Taux de refinancement de la BCE (art. L441-10 Code de commerce)" |

`CountryFR::referenceRates()` returns a `Quittio\Country\FR\Dunning\EcbRateTable`, so `DefaultInterest::forCountry(new CountryFR)` works without further set-up:

| Method | Result |
|---|---|
| `__construct(array $rates = EcbRateTable::PUBLISHED)` | the rates by the first day of the half-year (ISO date => percent); `PUBLISHED` runs from 1 January 2023 to 1 July 2026 (2.40 %) |
| `withRate(string $from, string $rate): self` | a copy with the rate of one more half-year, e.g. `withRate('2027-01-01', '…')` |
| `rateOn(Date $date): BigDecimal` | the rate of the day |
| `periods(Date $from, Date $until): array` | the periods of equal rate between the two dates (`until` exclusive) |
| `validUntil(): Date` | the first day without a known rate: 1 January 2027 for the shipped table |

Days the table does not cover throw an `InvalidArgumentException` ("No ECB reference rate is known after 2026-12-31. Add it with withRate().").

```php
use Brick\Money\Money;
use Quittio\Core\Documents\DocumentBuilder;
use Quittio\Core\Dunning\DefaultInterest;
use Quittio\Core\Support\Date;
use Quittio\Country\FR\CountryFR;

$interest = DefaultInterest::forCountry(new CountryFR);

// invoice F-2026-00042 of 1,620.00 EUR, due on 4 November 2026:
// penalties from 5 November; 20 December is the first day without
$charges = $interest->calculate(Money::of('1620.00', 'EUR'), Date::parse('2026-11-05'), Date::parse('2026-12-20'));
// one charge of 12.40 % (ECB rate 2.40 % + 10 points) over 45 days: 24.77 EUR

$reminder = DocumentBuilder::paymentReminder(1)
    ->number('R-2026-0007')
    ->seller(fn ($s) => $s->name('Atelier Numérique SAS')->vatId('FR19812345676')->address('12 rue de la République', '69002', 'Lyon', 'FR'))
    ->buyer(fn ($b) => $b->name('Boutique Exemple SARL')->address('5 avenue de l\'Opéra', '75001', 'Paris', 'FR'))
    ->openItem('F-2026-00042', '2026-10-05', '1620.00', dueDate: '2026-11-04')
    ->interestCharge(...$charges)
    ->flatRate('F-2026-00042')                                // the fixed indemnity of 40.00 EUR for this invoice
    ->build();
```

For a period after the shipped table, add the rate and pass the table with the law:

```php
use Brick\Money\Money;
use Quittio\Core\Dunning\DefaultInterest;
use Quittio\Core\Support\Date;
use Quittio\Country\FR\CountryFR;
use Quittio\Country\FR\Dunning\EcbRateTable;

$ecbRate = '2.40';   // placeholder: the rate the ECB applies on 1 January 2027
$interest = new DefaultInterest((new EcbRateTable)->withRate('2027-01-01', $ecbRate), CountryFR::frenchDunningLaw());
$charges = $interest->calculate(Money::of('1620.00', 'EUR'), Date::parse('2026-12-05'), Date::parse('2027-02-01'));
```

- **Descriptions.** `DefaultInterest::forCountry()` creates the charges without a description; the reminder then prints its default label ("Default interest … p.a. …"). Pass `description:` to `interest()` for a wording of your own.
- **Day count.** The pack counts on 365 days (`InterestCharge::$dayCount`); `interestCharge()` takes it over.
- **Consumers.** `calculate(…, consumer: true)` charges the fixed 2.75 % for the whole period, also for days in another half-year, and needs no ECB rate. For another half-year, pass a `DunningLaw` with the rate of that half-year as `b2cFixedRate`.
- **Fixed indemnity.** `$interest->flatRate()` returns 40.00 EUR; `DocumentBuilder::flatRate($claim)` adds it once per claim and refuses it for consumers ([Documents: payment reminders](documents.md#payment-reminders)).

## Meta flags and settings

Meta flags mark special cases on the invoice (`InvoiceData::$meta->flags`, set with `->flag()`):

| Constant | Value | Effect |
|---|---|---|
| `CountryFR::FLAG_VAT_ON_DEBITS` | `fr.vat_on_debits` | `complete()` adds "Option pour le paiement de la taxe d'après les débits" ([Mentions added by the pack](#mentions-added-by-the-pack)) |
| `Meta::FLAG_SMALL_BUSINESS` | `small_business` | small business invoice: no VAT number required (`FR-CGI-02`), no VAT allowed (`FR-CGI-06`), default VAT `VatPreset::SmallBusiness` |

Settings of the pack live under `quittio.countries.fr` ([Settings of a pack](configuration.md#settings-of-a-pack-quittiocountriescode)):

| Key | Type | Default | Effect |
|---|---|---|---|
| `countries.fr.payment_mentions` | bool | `true` | `false` = no late payment mention on invoices between businesses |

The pack ships no configuration file, so `php artisan quittio:install` publishes none. Set the key in `config/quittio.php`:

```php
'countries' => [
    'fr' => ['payment_mentions' => false],
],
```

Without Laravel, pass the same array to `Quittio::create()`:

```php
use Quittio\Core\Quittio;
use Quittio\Country\FR\CountryFR;

$quittio = Quittio::create(['country' => 'FR', 'countries' => ['fr' => ['payment_mentions' => false]]], [new CountryFR]);
```

`complete()` receives these settings from the render pipeline. When you call `(new CountryFR)->complete($invoice, $settings)` yourself, pass them as second argument.

## Defaults

How `CountryFR` fills the [`CountryPack` contract](country-packs.md#the-countrypack-contract):

| Hook | France |
|---|---|
| `defaults()` | currency `EUR`, time zone `Europe/Paris`, locale `fr`, paper `A4`, letter layout `free`, profile `en16931`, payment QR `girocode`, default VAT presets `fr.standard` and `fr.small_business` ([`CountryDefaults`](country-packs.md#countrydefaults)) |
| `taxPresets()` | every case of [`VatPreset`](#tax-presets) |
| `rules()` | the [rules](#rules) `FR-CGI-01` to `FR-CGI-09`, `FR-COM-01`, `FR-COM-02` |
| `buyerCountryRules()`, `profiles()`, `legalTexts()`, `letterLayouts()`, `exporters()`, `readers()` | none (defaults of `AbstractCountryPack`) |
| `formatRules()` | [`FrenchFormatRule`](#format-choice) |
| `mandatoryOutput()` | [mandatory PDF output](#mandatory-pdf-output) |
| `complete()` | [CIBS texts](#from-the-cgi-to-the-cibs) and [mentions](#mentions-added-by-the-pack) |
| `numberingRules()` | pattern `F-{Y}-{seq:5}`, reset `yearly`, resets `never` and `yearly`, gapless |
| `retention()` | ten years from the end of the calendar year |
| `dunningLaw()`, `referenceRates()` | [late payment values and ECB rates](#late-payment) |
| `correctionPolicy()` | [credit note, cancel and reissue, French titles](#credit-notes-corrections-and-self-billing) |
| `paymentQr()`, `addressFormatter()` | defaults: EPC GiroCode ([Payment QR codes](templates.md#payment-qr-codes)), international address format |
| `translationPath()` | `resources/lang` of the package |

- **Numbering.** A unique number of a continuous chronological series (art. 242 nonies A annex II CGI). Unless the issuer or the configuration sets a pattern, the Managed layer numbers French invoices `F-2026-00001`, `F-2026-00002` … and resets the sequence every year; a monthly reset is rejected ([Managed: numbering](managed.md#numbering), [`NumberingRules`](country-packs.md#numberingrules)).
- **Retention.** Ten years (art. L123-22 Code de commerce). `retention()` returns `new RetentionRule(10, fromEndOfCalendarYear: true)`: an invoice issued on 5 October 2026 is kept until 31 December 2036. The Managed layer stores this date ([Managed: retention and deletion](managed.md#retention-and-deletion)).
- **Locale.** French is the last fallback of the render and validation locale, after the run option, the invoice locale and `quittio.defaults.locale` ([Translations and locales](templates.md#translations-and-locales)). The core labels exist in French.

### Credit notes, corrections and self-billing

`CountryFR::correctionPolicy()` sets how invoices of a French seller are cancelled and corrected ([Building invoices: cancellations and corrections](building-invoices.md#cancellations-and-corrections)):

- **Cancellation strategy `credit_note`:** a cancellation is a credit note (avoir, type code 381) with positive amounts.
- **Correction strategy `cancel_and_reissue`:** the Managed layer corrects an invoice by cancelling it and issuing a new one.

Change them with `quittio.corrections.cancellation_strategy` and `quittio-managed.correction.strategy`. The policy sets these document titles:

| Kind | French | English |
|---|---|---|
| `cancellation` | Avoir | Credit note |
| `correction` | Facture rectificative | Corrected invoice |
| `self_billed` | Autofacturation | Self-billed invoice |

Other locales use the English titles. A cancellation of a self-billed invoice is titled "Avoir" as well; the pack defines no title for `price_reduction`.

A self-billed invoice (autofacturation: the buyer issues the invoice in the seller's name) needs the mention "Autofacturation" in a note (`FR-CGI-07`). The title alone does not satisfy the rule. Add the note with the text of the pack:

```php
use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Enums\InvoiceTypeCode;
use Quittio\Country\FR\Texts\LegalTexts;

$builder = InvoiceBuilder::make()
    ->type(InvoiceTypeCode::SelfBilledInvoice)
    ->note(LegalTexts::text(LegalTexts::SELF_BILLING, 'fr'));   // "Autofacturation"
```

## Mandatory PDF output

After rendering, the mandatory field guard checks that the PDF shows certain values ([Templates: mandatory field guard](templates.md#mandatory-field-guard)). `CountryFR::mandatoryOutput()` adds these French values to the base fields ([Mandatory output](country-packs.md#mandatory-output)):

| Condition | Access key | Description | Legal basis |
|---|---|---|---|
| the seller has a legal registration identifier | `seller.legalRegistration\|issuer.legalRegistration` | SIREN of the seller | art. R123-237 Code de commerce |
| the seller has a VAT number | `seller.vatId\|taxRepresentative.vatId` | VAT number of the seller | art. 242 nonies A annexe II CGI |
| the buyer is no consumer and the invoice has a due date | `dueDate\|paymentTerms.lines[*]` | due date | art. L441-9 Code de commerce |
| each VAT row of category `AE`, `K`, `G` or `E` | `vatRows[i].label` or the legal note with its BT-120 text | reverse charge or exemption mention | art. 242 nonies A annexe II CGI |

## Translations

The pack ships French and English texts in `resources/lang/{fr,en}/`:

- `rules.php`: message and hint of every rule (`FR-CGI-01` to `FR-CGI-09`, `FR-COM-01`, `FR-COM-02`);
- `tax.php`: the exemption reason texts of the presets, `fr.exempt` for `VatPreset::exempt()` (placeholder `:paragraph`) and the CIBS texts (`fr.small_business_cibs`, `fr.intra_community_supply_cibs`, `fr.export_cibs`);
- `legal.php`: the late payment mention (`late_payment`), the mention of VAT on debits (`vat_on_debits`) and "Autofacturation" (`self_billing`), read through `Quittio\Country\FR\Texts\LegalTexts::text($key, $locale)`.

They are registered as the namespace `quittio-fr` (e.g. `quittio-fr::rules.FR-CGI-08.message`). `CountryFR::registerTranslations()` registers them lazily, so presets work without a `Quittio` instance. `CountryFR::locales()` lists the locales the pack ships (`en`, `fr`). Fallbacks:

- rule messages and preset texts: English for other locales;
- legal texts (`LegalTexts::text()`): French for other languages;
- correction titles (defined in PHP): English for other locales.

To change a text, override the key in your own file:

- **Laravel:** `lang/vendor/quittio-fr/{locale}/rules.php`, `tax.php` or `legal.php`, also for additional locales.
- **Without Laravel:** register a directory with `{locale}/{group}.php` files after the pack's own. Keys you leave out keep the texts of the pack.

```php
use Quittio\Core\I18n\Translations;
use Quittio\Country\FR\CountryFR;

CountryFR::registerTranslations();
Translations::translator()->addPath(__DIR__.'/lang', CountryFR::TRANSLATION_NAMESPACE);   // e.g. lang/fr/legal.php
```

The CIBS replacement compares with the preset texts of the translations in use, so a changed CGI text is still replaced by its CIBS text from 2027. Rule messages follow the validation locale ([Messages and translations](validation.md#messages-and-translations)).

## Known limitations

- The legal wording, rules and defaults follow the law and the official sources of October 2026 and have not been reviewed by a French tax adviser. Later changes of the law need a new release.
- Connection to approved platforms, lifecycle statuses and e-reporting (planned as quittio/fr-platforms).
- The French business rules BR-FR-… (norm AFNOR XP Z12-012, external specifications of the DGFiP) and the FEC export (art. A47 A-1 LPF).
- Overseas rates: 8.5 %, 2.1 %, 1.75 % and 1.05 % in Guadeloupe, Martinique and La Réunion (art. 296 and 296 bis CGI); no VAT in Guyane and Mayotte (art. 294 CGI).
- ECB rates from 2027: `EcbRateTable` ends with the second half of 2026; add later half-years with `withRate()`. The consumer rate of 2.75 % is the one of the second half of 2026 and is not dated.
- The pack does not check whether an invoice must be an e-invoice, the delivery address of the reform, or the content of the late payment mention beyond the word "pénalités" (`FR-COM-02`).
- `FR-CGI-01` and `FR-CGI-08` check the number, not its scheme (`0002`, `0009`), and do not look the number up in the business register.
- The pack defines no buyer country rules, profiles, legal text catalog, exporters or readers.

## Complete example

A web agency in Lyon invoices a shop in Paris for services in October 2026. Quittio chooses Factur-X (EN 16931), adds the late payment mention and validates the invoice with the French rules:

```php
use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Enums\PaymentMeansCode;
use Quittio\Core\Enums\UnitCode;
use Quittio\Core\Quittio;
use Quittio\Country\FR\CountryFR;
use Quittio\Country\FR\Tax\VatPreset;

$quittio = Quittio::create(['country' => 'FR'], [new CountryFR]);

$invoice = InvoiceBuilder::make()
    ->number('F-2026-00042')
    ->issueDate('2026-10-05')
    ->deliveryDate('2026-09-30')
    ->currency('EUR')
    ->locale('fr')
    ->businessProcess('S1')                                   // services (BT-23)
    ->seller(fn ($s) => $s
        ->name('Atelier Numérique SAS')
        ->vatId('FR19812345676')
        ->legalRegistration('812345676', '0002')              // SIREN of the seller (BT-30)
        ->address('12 rue de la République', '69002', 'Lyon', 'FR')
        ->contact('Claire Martin', '+33 4 72 00 00 00', 'factures@atelier-numerique.example'))
    ->buyer(fn ($b) => $b
        ->name('Boutique Exemple SARL')
        ->vatId('FR15901234567')
        ->legalRegistration('901234567', '0002')              // SIREN of the buyer (BT-47)
        ->address('5 avenue de l\'Opéra', '75001', 'Paris', 'FR'))
    ->paymentTerms(fn ($t) => $t->dueInDays(30))
    ->payment(PaymentMeansCode::SepaCreditTransfer, iban: 'FR1420041010050500013M02606')
    ->line('Développement web', netPrice: '90.00', quantity: 10, unit: UnitCode::Hour, vat: VatPreset::Standard)
    ->line('Formation des utilisateurs', netPrice: '450.00', unit: UnitCode::Day, vat: VatPreset::Standard)
    ->build();

$result = $quittio->make($invoice)->render();

$result->format();                  // "zugferd": Factur-X with the profile EN 16931
$result->validation()->errors();    // []
$result->data()->notes;             // the late payment mention, added by the pack
$result->save('F-2026-00042.pdf');  // net 1,350.00 EUR, VAT 270.00 EUR, total 1,620.00 EUR
```

`$quittio->make($invoice)->check()` returns the same validation result without rendering the PDF ([Checking without rendering](validation.md#checking-without-rendering)).
