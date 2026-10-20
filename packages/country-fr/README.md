# Quittio country pack France

> **Not reviewed by a tax adviser yet.** The legal wording, rules and defaults of this pack have **not been reviewed by a French tax adviser**. Check the mentions and rates of your invoices before you rely on them. Quittio is software, not tax or legal advice: the business that issues an invoice remains responsible for it. The package is provided "as is", without warranty, under the MIT licence ([disclaimer](https://github.com/Quittio/quittio#disclaimer)).

```bash
composer require quittio/country-fr
```

The pack is not part of `quittio/quittio`; install it next to it or next to `quittio/core`. Developed in [`packages/country-fr`](https://github.com/Quittio/quittio/tree/master/packages/country-fr) of the monorepo [Quittio/quittio](https://github.com/Quittio/quittio) and published as the read-only repository [Quittio/country-fr](https://github.com/Quittio/country-fr). Please open issues and pull requests in the monorepo.

The pack implements the invoice mentions of the French Tax Code (art. 242 nonies A of annex II to the CGI) with the mentions of the e-invoicing reform from 1 September 2026, the mentions of the Code de commerce for invoices between businesses and the late payment penalties. Its class is `Quittio\Country\FR\CountryFR` (code `FR`, translation namespace `quittio-fr`). It covers metropolitan France and Monaco; the overseas departments have VAT rates of their own, which are not modelled.

```php
use Quittio\Core\Quittio;
use Quittio\Country\FR\CountryFR;

$quittio = Quittio::create(['country' => 'FR'], [new CountryFR]);
```

## E-invoicing reform

All businesses receive e-invoices from 1 September 2026; large and mid-sized businesses issue them from that date, all others from 1 September 2027. They are exchanged through an approved platform (plateforme agréée) in Factur-X, UBL or CII. `FrenchFormatRule` chooses Factur-X (format `zugferd`, profile EN 16931) for domestic invoices to businesses. The connection to platforms (quittio/fr-platforms) and e-reporting are not part of this pack, nor are the business rules of the French specifications (BR-FR-…).

The four new mentions from 1 September 2026:

- the buyer's SIREN for a business established in France (`FR-CGI-08`, BT-47 with scheme `0002`: `->legalRegistration('552100554', '0002')` on the buyer);
- the category of the operation (`FR-CGI-09`): goods, services or both, as operation category (BT-23) `B1`, `S1` or `M1` (`->businessProcess('S1')`);
- the delivery address if it differs from the buyer's address (the deliver-to address BG-15; not checked);
- the option to pay VAT on debits: the flag `CountryFR::FLAG_VAT_ON_DEBITS` adds "Option pour le paiement de la taxe d'après les débits".

On 1 January 2027 the VAT rules of the CGI move into the Code des impositions sur les biens et services (CIBS); ordonnance n° 2026-671 of 27 July 2026 postponed the date from 1 September 2026. References to the CGI, also in the mentions of an invoice, remain accepted until 30 June 2028 (art. 46 II ordonnance n° 2025-1247, BOI-RES-TVA-000253). Art. 242 nonies A of annex II stays in the CGI; its 12° accepts the reference to the CGI, to the VAT Directive or any other mention of the exemption.

On invoices issued from 1 January 2027, the pack replaces the texts of three presets by texts that cite the CIBS (`complete()`, `VatPreset::exemptionReasonOn()`); texts of your own, e.g. from `VatPreset::exempt()`, stay as they are:

| Case | Issued until 31 December 2026 | Issued from 1 January 2027 |
|---|---|---|
| `SmallBusiness` | TVA non applicable, art. 293 B du CGI | TVA non applicable, art. L. 233-3 du CIBS |
| `IntraCommunitySupply` | Exonération de TVA, article 262 ter I du CGI | Exonération de TVA, article L. 213-12, 1° du CIBS |
| `Export` | Exonération de TVA, article 262 I du CGI | Exonération de TVA, article L. 213-12, 2° du CIBS |

Art. L. 213-12 CIBS states the exemptions of intra-European supplies (1°) and exports (2°); art. L. 213-13 and L. 213-14 set their conditions. Art. L. 233-3 defines the small business under the franchise. A decree or the BOFiP may still prescribe other wording.

## Tax presets

`Quittio\Country\FR\Tax\VatPreset`:

| Case | Key | Category | Rate | Mention |
|---|---|---|---|---|
| `Standard` | `fr.standard` | `S` | 20 | – |
| `Intermediate` | `fr.intermediate` | `S` | 10 | – |
| `Reduced` | `fr.reduced` | `S` | 5.5 | – |
| `Particular` | `fr.particular` | `S` | 2.1 | – |
| `SmallBusiness` | `fr.small_business` | `E` | 0 | TVA non applicable, art. 293 B du CGI |
| `ReverseChargeDomestic`, `ReverseChargeEu` | `fr.reverse_charge_…` | `AE` | 0 | Autoliquidation |
| `IntraCommunitySupply` | `fr.intra_community_supply` | `K` | 0 | Exonération de TVA, article 262 ter I du CGI |
| `Export` | `fr.export` | `G` | 0 | Exonération de TVA, article 262 I du CGI |
| `NotTaxableInFrance` | `fr.not_taxable` | `O` | – | place of supply outside France |
| `MarginSecondHand`, `MarginArt`, `MarginAntiques`, `TravelAgency` | `fr.margin_…`, `fr.travel_agency` | `E` | 0 | Régime particulier – … |
| `VatPreset::exempt('article 261-4-4° du CGI')` | `fr.exempt` | `E` | 0 | Exonération de TVA, … |

## Rules

| Code | Severity | Check |
|---|---|---|
| `FR-CGI-01` | error | SIREN or SIRET of the seller (BT-30, schemes `0002`, `0009`, with check digits) |
| `FR-CGI-02` | error | VAT number of the seller, except under the franchise |
| `FR-CGI-03` | error | name and address of seller and buyer |
| `FR-CGI-04` | error | "Autoliquidation" on reverse charge |
| `FR-CGI-05` | error | exemption reference as text for categories E, K, G |
| `FR-CGI-06` | error | no VAT under the franchise (art. 293 B CGI) |
| `FR-CGI-07` | error (warning in XML) | "Autofacturation" on self-billed invoices |
| `FR-CGI-08` | warning, from 1 September 2026 | SIREN of a business buyer in France |
| `FR-CGI-09` | warning, from 1 September 2026 | category of the operation (BT-23) |
| `FR-COM-01` | error | due date or payment terms between businesses (art. L441-9 Code de commerce) |
| `FR-COM-02` | warning | late payment penalties, the indemnity of 40 EUR and the discount between businesses |

Invoices between businesses get the mention of `FR-COM-02` from the pack (`complete()`): "En cas de retard de paiement, des pénalités sont dues au taux de refinancement de la Banque centrale européenne majoré de 10 points, ainsi qu'une indemnité forfaitaire pour frais de recouvrement de 40 € (art. L441-10 du Code de commerce). Pas d'escompte pour paiement anticipé." The setting `payment_mentions = false` (`quittio.countries.fr`) turns it off.

## Late payment

Art. L441-10 Code de commerce: the refinancing rate of the ECB on the first day of the half-year plus 10 points (12.40 % in the second half of 2026) and a fixed indemnity of 40 EUR. `EcbRateTable` holds the ECB rates from 2023; add later half-years with `withRate()`. Consumers owe the legal interest rate (art. 1231-6 Code civil). Its category depends on the creditor (art. L313-2 Code monétaire et financier): a business claims the rate for all other cases, 2.75 % in the second half of 2026 (`b2cFixedRate`; it changes every half-year), not the rate for creditors who are individuals (6.84 %).

## Other defaults

- Locale `fr`, A4, GiroCode (EPC QR code); `SIREN`, `Siren::isSiret()` and `Siren::of()` check and extract register numbers.
- Retention: ten years (art. L123-22 Code de commerce).
- Numbering: `F-{Y}-{seq:5}`, a continuous chronological series.
- Titles: "Avoir", "Facture rectificative", "Autofacturation".

## Not yet included

- Connection to approved platforms, lifecycle statuses and e-reporting (planned as quittio/fr-platforms).
- The French business rules BR-FR-… (norm AFNOR XP Z12-012, external specifications of the DGFiP) and the FEC export (art. A47 A-1 LPF).
- Overseas rates: 8.5 %, 2.1 %, 1.75 % and 1.05 % in Guadeloupe, Martinique and La Réunion (art. 296 and 296 bis CGI); no VAT in Guyane and Mayotte (art. 294 CGI).
- ECB rates from 2027: `EcbRateTable` ends with the second half of 2026; add later half-years with `withRate()`.
