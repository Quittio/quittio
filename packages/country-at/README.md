# Quittio country pack Austria

> **Not reviewed by a tax adviser yet.** The legal wording, rules and defaults of this pack have **not been reviewed by an Austrian tax adviser**. Check the mentions and rates of your invoices before you rely on them. Quittio is software, not tax or legal advice: the business that issues an invoice remains responsible for it. The package is provided "as is", without warranty, under the MIT licence ([disclaimer](https://github.com/Quittio/quittio#disclaimer)).

```bash
composer require quittio/country-at
```

The pack is not part of `quittio/quittio`; install it next to it or next to `quittio/core`. Developed in [`packages/country-at`](https://github.com/Quittio/quittio/tree/master/packages/country-at) of the monorepo [Quittio/quittio](https://github.com/Quittio/quittio) and published as the read-only repository [Quittio/country-at](https://github.com/Quittio/country-at). Please open issues and pull requests in the monorepo.

The pack implements the Austrian VAT act (UStG 1994), the national CIUS of EN 16931 and the Austrian law on late payment. Its class is `Quittio\Country\AT\CountryAT` (code `AT`, translation namespace `quittio-at`).

```php
use Quittio\Core\Quittio;
use Quittio\Country\AT\CountryAT;

$quittio = Quittio::create(['country' => 'AT'], [new CountryAT]);
```

## Tax presets

`Quittio\Country\AT\Tax\VatPreset`:

| Case | Key | Category | Rate | Legal basis |
|---|---|---|---|---|
| `Standard` | `at.standard` | `S` | 20 | section 10 (1) UStG |
| `Reduced` | `at.reduced` | `S` | 10 | section 10 (2) UStG |
| `Reduced13` | `at.reduced_13` | `S` | 13 | section 10 (3) UStG |
| `BasicFood` | `at.basic_food` | `S` | 4.9 from 1 July 2026, 10 before (by supply date) | section 10 (1a) UStG (BGBl. I Nr. 37/2026) |
| `SmallBusiness` | `at.small_business` | `E` | 0 | section 6 (1) no. 27 UStG |
| `ReverseChargeDomestic` | `at.reverse_charge_domestic` | `AE` | 0 | section 19 (1a) to (1e) UStG |
| `ReverseChargeEu` | `at.reverse_charge_eu` | `AE` | 0 | section 19 (1) sentence 2 UStG |
| `IntraCommunitySupply` | `at.intra_community_supply` | `K` | 0 | Art. 6 (1) with Art. 7 UStG |
| `TriangularTransaction` | `at.triangular_transaction` | `AE` | 0 | Art. 25 UStG |
| `Export` | `at.export` | `G` | 0 | section 6 (1) no. 1 with section 7 UStG |
| `NotTaxableInAustria` | `at.not_taxable` | `O` | – | place of supply abroad |
| `MarginSecondHand`, `MarginArt`, `MarginAntiques` | `at.margin_…` | `E` | 0 | section 24 UStG |
| `TravelAgency` | `at.travel_agency` | `E` | 0 | section 23 UStG |
| `VatPreset::exempt('§ 6 Abs. 1 Z 19 UStG')` | `at.exempt` | `E` | 0 | any other exemption |

## Rules (section 11 UStG)

| Code | Severity | Check |
|---|---|---|
| `AT-USTG-01` | error | name and address of the seller (lit. a) |
| `AT-USTG-02` | error | name and address of the buyer (lit. b), not on simplified invoices |
| `AT-USTG-03` | error | seller UID (lit. i), unless simplified or only supplies without input tax deduction (categories E and O) |
| `AT-USTG-04` | error (warning for prepayment invoices) | date of the supply (lit. d) |
| `AT-USTG-05` | error | buyer UID on invoices to businesses above 10,000 EUR gross (lit. b) |
| `AT-USTG-06` | error | reverse charge statement (section 11 (1a)) |
| `AT-USTG-07` | error | exemption note as text for categories E, K and G (lit. e) |
| `AT-USTG-08` | error | no VAT on small business invoices (section 11 (12)) |
| `AT-USTG-09` | error (warning in XML) | label "Gutschrift" on self-billed invoices (section 11 (8) no. 3) |
| `AT-USTG-10` | warning | VAT in euro on invoices in another currency (lit. f) |
| `AT-USTG-11` | warning | issued more than six months after the supply (section 11 (1) no. 1) |
| `AT-USTG-12` | warning | EU reverse charge services invoiced after the 15th of the following month (section 11 (1) no. 2) |

Simplified invoices (section 11 (6) UStG): invoices in euro up to 400 EUR gross and invoices of small businesses need neither the buyer's name and address nor the UIDs. Reverse charge and intra-community supplies are never simplified.

## Profiles

| Key | Specification identifier | Rules |
|---|---|---|
| `cius-at-nat` | `urn:cen.eu:en16931:2017#compliant#urn:erechnung.gv.at:CIUS-AT-NAT:1.0.0` | `AT-NAT-01` to `-08`: complete addresses, delivery date or invoicing period, VAT rates of allowances, charges and lines, no external attachments |
| `cius-at-gov` | `…#compliant#urn:erechnung.gv.at:CIUS-AT-GOV:1.1.0` | the rules of `cius-at-nat` and `AT-GOV-01` to `-09` of e-Rechnung.gv.at: buyer reference (order reference), seller contact with email, payment means 30/31/42/49/58/59, limits for amounts, lines and attachments, order line references with a ten digit order number |

Sources: [CIUS-AT-NAT](https://www.erechnung.gv.at/go/cius_at_nat) and [CIUS-AT-GOV](https://www.erechnung.gv.at/go/cius_at_gov) of the BRZ for the BMF.

Format choice: a buyer with an Austrian organisation number as electronic address (scheme `9915`, e.g. `b` for the federal government) receives UBL after `cius-at-gov`. Everything else follows the automatic format choice of the core; Austria has no e-invoicing obligation between businesses.

## Late payment

`Quittio\Country\AT\Dunning\DefaultInterest`: base rate of the OeNB plus 9.2 points between businesses (section 456 UGB), split at the half-years, flat rate of 40 EUR (section 458 UGB), and a fixed 4 % for consumers (section 1000 (1) ABGB). The base rates are published up to the second half of 2026 (`BaseRateTable::PUBLISHED`); add later ones with `withRate()`.

## Other defaults

- Locale `de`, time zone `Europe/Vienna`, A4, the free letter layout, GiroCode (EPC QR code).
- Retention: seven years from the end of the calendar year (section 11 (2) UStG, section 132 BAO). Longer periods for property related supplies (section 18 (10) UStG) are not modelled.
- Numbering: `RE-{Y}-{seq:4}`, a consecutive number that identifies the invoice once (section 11 (1) no. 3 lit. h UStG).
- Titles: "Stornorechnung", "Storno-Gutschrift", "Rechnungsberichtigung", "Gutschrift".

## ebInterface

ebInterface 6.1 is the Austrian XML invoice format of AUSTRIAPRO; e-Rechnung.gv.at accepts it besides UBL.

```php
use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Country\AT\EbInterface\EbInterfaceWriter;

$xml = (new EbInterfaceWriter)->write((new En16931Calculator)->calculate($invoice));
```

- The writer takes a calculated invoice. It follows the official mapping of AUSTRIAPRO between UBL and ebInterface: the purchase order reference (BT-13, else the buyer reference BT-10) is the order reference of the invoice recipient (`OrderID`, the Auftragsreferenz of the federal government) and of every line with its order line reference (BT-132), the first seller identifier (BT-29) is the supplier number (`InvoiceRecipientsBillerID`), a missing VAT identifier is written as `ATU00000000`, credit notes are a `CreditMemo` with positive amounts.
- `EbInterfaceReader` reads ebInterface 6.0 and 6.1; the pack registers it (`readers()`), so `Quittio::read()` and `validateFile()` accept such files. The result has the syntax `ebinterface` and no profile.
- ebInterface is not an output format of `render()` yet: the formats of the core are CII or UBL. Use the writer directly.
- Fields without a place in ebInterface are not written, e.g. the payee (BG-10), the buyer reference next to a purchase order reference, attachments and the VAT in accounting currency (BT-111).
- The tests check the output against the schema of ebInterface 6.1, which `tools/schemas/download.sh` downloads (group `official`).

## Not yet included

- ebInterface as a format of `render()`.
- An Austrian letter layout (ÖNORM A 1080 was withdrawn without a successor) and a Laravel service provider with settings.
