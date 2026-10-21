# Switzerland (`quittio/country-ch`)

> **Not reviewed by a tax adviser yet.** The legal wording, rules and defaults of this pack follow the law and the official sources as of October 2026, but have not been reviewed by a Swiss tax adviser. Check the mentions and rates of your invoices before you rely on them. Quittio is no tax or legal advice; the business that issues an invoice remains responsible for it.

The Swiss pack implements the invoice requirements of the Swiss VAT act (MWSTG, Mehrwertsteuergesetz) and of its ordinance (MWSTV, Mehrwertsteuerverordnung), the Swiss QR bill and the rule on default interest of the Swiss Code of Obligations (OR, Obligationenrecht). Its class is `Quittio\Country\CH\CountryCH` (code `CH`, translation namespace `quittio-ch`).

Switzerland has no e-invoicing obligation. The focus of the pack is therefore the payment: an invoice of a Swiss seller in CHF or EUR with a Swiss or Liechtenstein bank account gets the QR bill, a payment part with receipt whose code banking apps can scan.

This page writes Swiss legal references in English form: "Art. 26 (2) lit. a MWSTG" stands for "Art. 26 Abs. 2 Bst. a MWSTG". Texts that the pack prints keep their German, French or Italian form; the French and Italian texts name the act LTVA and LIVA.

## Installation

```bash
composer require quittio/country-ch
```

The pack is not part of `quittio/quittio`. Install it next to `quittio/quittio` (Laravel) or next to `quittio/core`. It is developed in [`packages/country-ch`](https://github.com/Quittio/quittio/tree/master/packages/country-ch) of the monorepo and published as the read-only repository [Quittio/country-ch](https://github.com/Quittio/country-ch); please open issues and pull requests in the monorepo.

- **Laravel.** The pack declares itself in its `composer.json` (`extra.quittio.country-packs`), so Laravel finds it after the installation; there is nothing to register ([Registering packs](country-packs.md#registering-packs)). The pack has no service provider and no configuration file of its own. Its one setting goes into `config/quittio.php` ([Meta flags and settings](#meta-flags-and-settings)). `php artisan quittio:countries` lists it ([Artisan commands](commands.md#quittiocountries)).
- **Without Laravel.** Pass the pack to `Quittio::create()`:

```php
use Quittio\Core\Quittio;
use Quittio\Country\CH\CountryCH;

$quittio = Quittio::create(['country' => 'CH'], [new CountryCH]);
$quittio->countries()->get('CH');   // the registered CountryCH
```

`'country' => 'CH'` makes Switzerland the default country for invoices whose seller has no country code; it is optional.

## When it applies

The pack leads every invoice whose seller has the country code `CH` (BT-40). It then checks the [MWSTG rules](#rules), prints the [QR bill](#qr-bill), applies the [rounding to 5 Rappen](#rounding-to-5-rappen) when asked for, and provides its [defaults](#defaults): the locale `de_CH` (unless the invoice or the configuration sets one), A4 paper and the Swiss VAT labels. How Quittio chooses the leading pack is described in [How a pack is selected](country-packs.md#how-a-pack-is-selected).

- **Currencies.** The rules apply to invoices in any currency. The QR bill is printed for invoices in CHF and EUR only; the rounding to 5 Rappen applies to CHF only.
- **Liechtenstein.** Switzerland and Liechtenstein form one area of application of the VAT (treaty of 28 November 1994); Liechtenstein takes over the material provisions of the Swiss VAT law into its own law. The pack therefore also leads the invoices of sellers with the country code `LI` ([`VatTerritory::vatCountry()`](country-packs.md#how-a-pack-is-selected)), and an invoice between Switzerland and Liechtenstein is domestic. The rules cite the Swiss MWSTG. The QR bill accepts bank accounts of Liechtenstein (IBAN `LI…`).
- **Buyers in Switzerland.** The pack defines no rules for buyers, so it adds nothing to an invoice of a foreign seller to a Swiss buyer.
- **Formats.** The pack defines no profiles and no format rules. The automatic choice of Quittio gives an invoice between two Swiss businesses the format `zugferd` (a PDF with embedded EN 16931 XML) and an invoice to a buyer in another country usually a plain `pdf` ([Automatic choice](formats.md#automatic-choice)). Request `->format('pdf')` when your customers want no XML in the PDF.

## Common tasks

| You want to | Read |
|---|---|
| Set the VAT rate or an exemption on a line | [Tax presets](#tax-presets) |
| Invoice without VAT registration | [Sellers not registered for VAT](#sellers-not-registered-for-vat) |
| Look up a validation code such as `CH-MWSTG-02` | [Rules](#rules) |
| Print the QR bill with a QR reference or a Creditor Reference | [QR bill](#qr-bill), [References](#references) |
| Create or check a QR reference or a Creditor Reference | [The classes `QrReference` and `CreditorReference`](#the-classes-qrreference-and-creditorreference) |
| Round the amount due to 5 Rappen | [Rounding to 5 Rappen](#rounding-to-5-rappen) |
| Send a reminder with default interest | [Late payment](#late-payment) |
| Change a label such as "MWST-Nr." | [Languages](#languages) |
| See a complete invoice | [Complete example](#complete-example) |

## Tax presets

A tax preset is a ready-made VAT treatment for an invoice line: it sets the VAT category (BT-151), the rate and, for supplies without VAT, the exemption reason code (BT-121) and text (BT-120). The categories are the codes of EN 16931 (`S` standard rate, `E` exempt, `G` export, `O` not subject to VAT; see [Categories](building-invoices.md#categories)). How presets work in general is described in [Building invoices: Tax presets](building-invoices.md#tax-presets).

`Quittio\Country\CH\Tax\VatPreset` is a string-backed enum. Apply it per line:

```php
use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Enums\UnitCode;
use Quittio\Country\CH\Tax\VatPreset;

$builder = InvoiceBuilder::make()
    // ... seller, buyer, payment
    ->line('Software development', netPrice: '145.00', quantity: 12, unit: UnitCode::Hour, vat: VatPreset::Standard)   // 8.1 %
    ->line('Specialist book', netPrice: '39.00', quantity: 2, unit: UnitCode::Piece, vat: VatPreset::Reduced)           // 2.6 %
    ->line('Hotel room', netPrice: '180.00', quantity: 2, unit: UnitCode::Day, vat: VatPreset::Accommodation)           // 3.8 %
    // an excluded supply with its legal reference;
    // the invoice prints "Von der Steuer ausgenommen nach Art. 21 Abs. 2 Ziff. 11 MWSTG"
    ->line('Course fee', netPrice: '400.00', vat: VatPreset::excluded('Art. 21 Abs. 2 Ziff. 11 MWSTG'));
```

| Case | Key | Category | Rate | Code (BT-121) | Use |
|---|---|---|---|---|---|
| `Standard` | `ch.standard` | `S` | 8.1 (of the supply date) | – | Standard rate (Art. 25 (1) MWSTG) |
| `Reduced` | `ch.reduced` | `S` | 2.6 (of the supply date) | – | Reduced rate (Art. 25 (2) MWSTG), e.g. food, books, medicines |
| `Accommodation` | `ch.accommodation` | `S` | 3.8 (of the supply date) | – | Special rate for accommodation (Art. 25 (4) MWSTG) |
| `Excluded` | `ch.excluded` | `E` | 0 | – | Supply excluded from the tax, without input tax deduction (Art. 21 MWSTG) |
| `VatPreset::excluded(string $paragraph, $code = null)` | `ch.excluded` | `E` | 0 | `$code` (enum, raw code or string) | An excluded supply that names its case, e.g. `Art. 21 Abs. 2 Ziff. 3 MWSTG` |
| `Export` | `ch.export` | `G` | 0 | `VATEX-EU-G` | Supply exempt from the tax with input tax deduction, e.g. an export (Art. 23 MWSTG) |
| `NotTaxableInSwitzerland` | `ch.not_taxable` | `O` | none | `VATEX-EU-O` | Supply whose place is abroad (Art. 7 and 8 MWSTG), not taxable in Switzerland |
| `NotRegistered` | `ch.not_registered` | `O` | none | `VATEX-EU-O` | Seller not registered for VAT, e.g. below a turnover of CHF 100,000 (Art. 10 (2) MWSTG); see [below](#sellers-not-registered-for-vat) |

The codes in column BT-121 are VATEX codes, the standard codes for the reason of an exemption. `VatPreset::excluded()` returns a `Quittio\Country\CH\Tax\CustomVatPreset` with the key `ch.excluded`; it carries a code only when you pass one.

### Rates by supply date

`Standard`, `Reduced` and `Accommodation` take the rate that applied on the supply date of the line (`Quittio\Country\CH\Tax\TaxTables`). The supply date is determined by the core (`Quittio\Core\Tax\SupplyDate`), as described for the [German presets](country-packs.md#tax-presets): the end of the line period, its start, the VAT point date, the delivery date, the end of the invoicing period or the issue date, in this order.

| From | `Standard` | `Reduced` | `Accommodation` |
|---|---|---|---|
| 1 January 2011 | 8.0 | 2.5 | 3.8 |
| 1 January 2018 | 7.7 | 2.5 | 3.7 |
| 1 January 2024 | 8.1 | 2.6 | 3.8 |

The first row also applies to supplies before 2011. A supply of December 2023 invoiced in 2024 therefore gets 7.7 %:

```php
use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Country\CH\Tax\VatPreset;

InvoiceBuilder::make()
    ->issueDate('2024-02-01')
    ->deliveryDate('2023-12-15')
    ->line('Consulting', netPrice: '100.00', vat: VatPreset::Standard);   // 7.7 %
```

`VatPreset::Standard->rate()` returns the rate of today in the time zone `Europe/Zurich`. `TaxTables::standardRateOn(Date $date)`, `reducedRateOn()` and `accommodationRateOn()` return the rate of a date.

Not included: the increase of the standard rate to 8.5 % and of the accommodation rate to 4.0 % (the reduced rate stays at 2.6 %), on which the vote of 29 November 2026 decides; the Federal Council sets the date, at the earliest 2028. The law limits the accommodation rate to the end of 2027; an extension until 2031 is not yet in force. The tables keep the current rates after these dates until a new release changes them.

### Sellers not registered for VAT

A seller that is not registered for VAT uses `VatPreset::NotRegistered` on every line. The invoice then shows no VAT, and the PDF prints the note "Nicht im MWST-Register eingetragen; keine Mehrwertsteuer". The rule [`CH-MWSTG-05`](#rules) reports VAT on an invoice that has such a line.

Such a seller has no VAT identifier. EN 16931 still requires an identifier of the seller in the XML (BR-CO-26: BT-29, BT-30 or BT-31), so give one, e.g. the company's UID number as registration number:

```php
use Quittio\Core\Builder\Party;

$seller = Party::make('Atelier Gerber')
    ->legalRegistration('CHE-109.322.551')   // BT-30
    ->address('Rue du Marché 4', '1204', 'Genève', 'CH');
```

The pack names `ch.not_registered` as the preset for small businesses (`CountryDefaults::$smallBusinessVatPreset`). An invoice with the flag `Meta::FLAG_SMALL_BUSINESS` (in Laravel `quittio.seller.small_business = true`, see [Seller](configuration.md#seller), without Laravel `'small_business' => true` in `Quittio::create()`; applied by `Quittio::invoice()` and `$quittio->invoice()`) therefore gives lines without `vat()` the preset `NotRegistered`. The flag itself triggers no Swiss rule; `CH-MWSTG-05` looks at the presets of the lines.

### Default VAT and lookup

Lines without `vat()` take the [default VAT](building-invoices.md#default-vat). With `CountryDefaultVat` (installed by the Laravel integration, or by `InvoiceBuilder::useDefaultVat(new CountryDefaultVat($quittio->countries()))` without Laravel) that is `VatPreset::Standard` for a Swiss seller and `VatPreset::NotRegistered` for an invoice flagged as small business. In Laravel, `quittio.defaults.vat` can name another key, e.g. `'ch.reduced'`.

A stored key is resolved with `VatPreset::from('ch.standard')`, `VatPreset::fromKey($key)` (null for keys of other packs), `(new CountryCH)->taxPresets()->get('ch.standard')` or, across all installed packs, `$quittio->countries()->taxPreset('ch.standard')`. The key stays on the line (`$line->vat->presetKey`); `CH-MWSTG-05` relies on it.

### Exemption texts

`Standard`, `Reduced` and `Accommodation` carry no text, because EN 16931 allows no exemption reason for category `S`. The other presets print an exemption reason (BT-120) from `resources/lang/{de,fr,it,en}/tax.php`:

| Key | German | French | Italian | English |
|---|---|---|---|---|
| `ch.excluded` | Von der Steuer ausgenommene Leistung (Art. 21 MWSTG) | Prestation exclue du champ de l'impôt (art. 21 LTVA) | Prestazione esclusa dall'imposta (art. 21 LIVA) | Supply excluded from VAT (Art. 21 MWSTG) |
| `ch.excluded_by` (`VatPreset::excluded()`) | Von der Steuer ausgenommen nach :paragraph | Exclue du champ de l'impôt selon :paragraph | Esclusa dall'imposta secondo :paragraph | Excluded from VAT under :paragraph |
| `ch.export` | Von der Steuer befreite Ausfuhr (Art. 23 MWSTG) | Exportation exonérée de l'impôt (art. 23 LTVA) | Esportazione esente dall'imposta (art. 23 LIVA) | Export exempt from VAT (Art. 23 MWSTG) |
| `ch.not_taxable` | Nicht im Inland steuerbare Leistung (Ort der Leistung im Ausland) | Prestation non imposable sur le territoire suisse (lieu de la prestation à l'étranger) | Prestazione non imponibile in territorio svizzero (luogo della prestazione all'estero) | Not taxable in Switzerland (place of supply abroad) |
| `ch.not_registered` | Nicht im MWST-Register eingetragen; keine Mehrwertsteuer | Non inscrit au registre des assujettis à la TVA ; pas de TVA | Non iscritto nel registro dei contribuenti IVA; nessuna IVA | Not registered for Swiss VAT; no VAT charged |

- **Language.** The text is taken in the invoice locale (`->locale()` on the builder) when the invoice is built. Without a locale, the presets use German. The enum presets also find the text of a regional locale such as `fr_CH` (it takes the French text). `VatPreset::excluded()` behaves the same.
- **PDF.** The PDF lists the texts in its legal notes, below the totals.
- **Your own texts.** The texts come from the translation files of the pack and can be overridden like its labels ([Languages](#languages)).

## Rules

The pack checks the elements of an invoice that Art. 26 (2) MWSTG requires, as far as the data shows them. The rules are of level L3 ([Validation levels](validation.md#validation-levels)) and apply independent of the format. An error stops rendering in the default validation mode `error`; a warning does not ([Errors while rendering](validation.md#errors-while-rendering)).

| Code | Severity | Field | Check |
|---|---|---|---|
| `CH-MWSTG-01` | error | BT-37 (`seller.address.city`) | The place of the seller must be given (Art. 26 (2) lit. a MWSTG). The rule checks the city; the name is required by EN 16931 anyway (BR-6). |
| `CH-MWSTG-02` | error | BT-31 (`seller.vatId`) | An invoice that charges VAT (a VAT amount other than zero in the VAT breakdown) must state the UID number under which the seller is registered for VAT (Art. 26 (2) lit. a MWSTG). The VAT identifier of a tax representative (BT-63) is accepted as well. |
| `CH-MWSTG-03` | warning | BT-31 (`seller.vatId`) | A UID number (a VAT identifier that starts with `CHE`) should carry the note of the VAT registration, e.g. `CHE-123.456.789 MWST`. Accepted are the notes `MWST`, `TVA` and `IVA`, in any case. VAT identifiers of other countries are not checked. |
| `CH-MWSTG-04` | error | BT-52 (`buyer.address.city`) | The place of the buyer must be given (Art. 26 (2) lit. b MWSTG). Exception: a till receipt up to CHF 400 (Art. 57 MWSTV), see below. |
| `CH-MWSTG-05` | error | BT-117 (`vatBreakdown[].taxAmount`) | A seller that is not registered for VAT must not charge VAT; VAT shown is owed by reason of the invoice (Art. 27 (2) MWSTG). The rule applies when any line uses `VatPreset::NotRegistered` and reports every row of the VAT breakdown with a VAT amount other than zero. |

- **Till receipts.** `MwstgRules::isTillReceipt(InvoiceData $invoice)` is true for a calculated invoice in CHF whose total including VAT (BT-112) is at most CHF 400 (`TaxTables::TILL_RECEIPT_LIMIT`). Such an invoice may leave out the buyer's place. EN 16931 still requires a buyer name (BR-7), e.g. "Barverkauf" (cash sale).
- **UID numbers.** The builder removes spaces from VAT identifiers, but keeps one before the note of the VAT registration of a UID number (`MWST`, `TVA` or `IVA`): `->vatId('CHE-123.456.789 MWST')` and `->vatId('CHE-123.456.789MWST')` are both stored and printed as "CHE-123.456.789 MWST". `CH-MWSTG-03` accepts both forms.
- **Other elements.** Art. 26 (2) MWSTG also names the date or period of the supply, the kind and extent of the supply, the amount and the rate. Quittio's EN 16931 rules and the [mandatory field guard](templates.md#mandatory-field-guard) cover these values; the pack has no rule of its own for them.
- **Messages.** The messages exist in German, French, Italian and English (`resources/lang/{de,fr,it,en}/rules.php`, keys such as `quittio-ch::rules.CH-MWSTG-02.message`); other locales get English. Most German and English messages have a hint with the builder method that fixes the error.
- **Switching rules off.** Like every rule, a Swiss rule can be switched off or changed in severity with `validation.rules` ([Switching rules off](validation.md#switching-rules-off-and-changing-their-severity)).

### Mandatory PDF output

`CountryCH::mandatoryOutput()` adds two values to the [mandatory field guard](templates.md#mandatory-field-guard), which checks that the template prints them:

| Value | When | Legal basis |
|---|---|---|
| `seller.vatId`: the UID number with the note MWST | the seller has a VAT identifier | Art. 26 (2) lit. a MWSTG |
| `servicePeriod`, `deliveryDate` or `delivery.date`: the date or period of the supply | the invoice has a delivery date or an invoicing period | Art. 26 (2) lit. c MWSTG |

The default templates print both. The guard matters only for your own templates.

## QR bill

`Quittio\Country\CH\QrBill\SwissQrBillGenerator` (key `swiss-qr-bill`) creates the Swiss QR Code (Swiss Payments Code `SPC`, version `0200`) after the Swiss Implementation Guidelines QR-bill 2.3 of SIX, and its payment part with receipt. `CountryCH::paymentQr()` returns it, and the pack's defaults name `swiss-qr-bill` as payment QR code, so the default QR mode `auto` uses it for every invoice the pack leads ([Payment QR codes](templates.md#payment-qr-codes)).

The default templates print it after the [payment slip layout of the core](templates.md#payment-slips): at the bottom of a page of its own, the code at 46 × 46 mm with the Swiss cross in the middle, the headings in the language of the invoice, the texts in [Liberation Sans](templates.md#fonts). The PDF stays PDF/A-3b; the pack's tests check this with veraPDF.

### When the code is printed

The generator creates a code when all of these hold:

- the currency is CHF or EUR;
- the invoice has a credit transfer account (BG-17) with a valid IBAN of Switzerland or Liechtenstein (21 characters, `CH…` or `LI…`); the first such account is used;
- the seller's address has a post code, a city and a two-letter country code;
- the amount due (BT-115) is more than zero and at most 999,999,999.99;
- with a QR-IBAN, a QR reference can be found or built ([References](#references)).

As for every payment QR code, Quittio prints none on credit notes, negative invoices and self-billed invoices. Without a code the invoice is printed as usual, without the extra page.

### References

A QR-IBAN is an IBAN with an institution identification (positions 5 to 9) from 30000 to 31999; `SwissQrBillGenerator::isQrIban(string $iban)` tells it apart. A QR-IBAN needs a QR reference, an ordinary IBAN takes a Creditor Reference or no reference. The generator reads the remittance information (BT-83, `remittanceInformation:` of `->payment()`):

| Account | Remittance information (BT-83) | Reference in the code | Unstructured message |
|---|---|---|---|
| QR-IBAN | a valid QR reference | `QRR` with that reference | the invoice number |
| QR-IBAN | anything else or empty | `QRR` with the QR reference of the digits of the invoice number (`RE-2026-0042` becomes `000000000000000000202600427`) | the remittance information, otherwise the invoice number |
| QR-IBAN | anything else or empty, and the invoice number has no digits or more than 26 | no code | – |
| IBAN | a valid Creditor Reference | `SCOR` with that reference (upper case, without spaces) | the invoice number |
| IBAN | anything else or empty | `NON`, no reference | the remittance information, otherwise the invoice number |

The generator never builds a Creditor Reference on its own. To print one with an ordinary IBAN, pass it as remittance information:

```php
use Quittio\Core\Enums\PaymentMeansCode;
use Quittio\Country\CH\QrBill\CreditorReference;

$builder->payment(
    PaymentMeansCode::SepaCreditTransfer,
    iban: 'CH93 0076 2011 6238 5295 7',
    remittanceInformation: CreditorReference::fromCharactersOf('RE-2026-0043'),   // RF70RE20260043
);
```

### Parties, amount and message

- **Creditor.** The account name of the credit transfer account (`accountName:` of `->payment()`), otherwise the seller's name, with the seller's address.
- **Debtor.** The buyer, if its address has a post code, a city and a two-letter country code. Otherwise the debtor fields stay empty, and the slip shows the field "Zahlbar durch (Name/Adresse)" to fill in by hand.
- **Addresses** are structured (address type `S`): name, street, building number, post code, town and country. A building number at the end of the first address line becomes the building number: "Bahnhofstrasse 12" gives the street "Bahnhofstrasse" and the number "12"; forms such as "12a" or "12-14" are recognised as well.
- **Ultimate creditor.** Always empty; the guidelines reserve it for future use.
- **Amount.** The amount due (BT-115) with two decimals, after prepaid amounts and a [rounding amount](#rounding-to-5-rappen).
- **Text.** Characters outside the character set of the guidelines are removed and line breaks become spaces. Names, streets and messages are cut to the lengths of the guidelines (name and street 70, building number and post code 16, town 35, message 140 characters).

`$generator->payload($invoice)` returns the content of the code for a calculated invoice, or null when no code can be created. The `payment.qrCode` node of the [view model](templates.md#payment) holds it as `payload`, with the type `swiss-qr-bill` and the printed values in `slip`.

### What is printed

| Part | Payment part | Receipt |
|---|---|---|
| Title | "Zahlteil" | "Empfangsschein" |
| "Konto / Zahlbar an" | IBAN in groups of four, creditor name and address | the same |
| "Referenz" | QR reference in blocks (`00 00000 00000 00000 02026 00427`) or Creditor Reference in groups of four; left out without reference | the same |
| "Zusätzliche Informationen" | the unstructured message | – |
| "Zahlbar durch" | debtor name and address, or the empty field "Zahlbar durch (Name/Adresse)" | the same |
| "Währung", "Betrag" | currency and amount with a space as thousands separator (`1 960.95`) | the same |
| "Annahmestelle" | – | the acceptance point |

The note "Vor der Einzahlung abzutrennen" (separate before paying in) is printed at the separation line. Addresses outside Switzerland get the country code before the post code, e.g. "LI-9490 Vaduz". The headings are printed in the language of the invoice locale: German, French, Italian or English ([Languages](#languages)); other languages get German. The Swiss cross is a PNG image and needs the PHP extension GD (`SwissCross::dataUri()` returns null without it).

### The QR mode

The payment QR mode `auto` (the default, `pdf.payment_qr.type` in Laravel, `Presentation::$paymentQr`) and the mode `swiss-qr-bill` both print the QR bill of the pack. A generator registered with `setPaymentQrGenerator()` (Laravel: `pdf.payment_qr.generator`) takes precedence in both modes, for the invoices of every country. The mode `none` prints no code, `girocode` an EPC GiroCode, which exists for EUR only ([Payment QR codes](templates.md#payment-qr-codes)).

### Payment reminders

A [payment reminder](documents.md#payment-reminders) of a Swiss seller gets the Swiss QR Code over the total of the reminder, with the open invoice numbers as unstructured message. With a QR-IBAN its reference is the QR reference of the digits of the reminder number (`M-2026-0003` gives `000000000000000000202600037`). The reminder gets the payment part and receipt like an invoice, with the total of the reminder as amount, on a page of its own at the end.

### The classes `QrReference` and `CreditorReference`

`Quittio\Country\CH\QrBill\QrReference` is the QR reference of the Swiss QR bill: 27 digits, the last a check digit after modulo 10 recursive. It goes with a QR-IBAN only.

| Method | Result |
|---|---|
| `isValid(string $reference): bool` | whether the reference (spaces allowed) has 27 digits and a correct check digit |
| `fromDigitsOf(string $value): ?string` | the QR reference of the digits of `$value`, padded with zeros to 26 digits, plus the check digit; null without digits or with more than 26 |
| `checkDigit(string $digits): string` | the check digit of a string of digits |
| `format(string $reference): string` | the reference in blocks for printing: two digits, then blocks of five |

`Quittio\Country\CH\QrBill\CreditorReference` is the Creditor Reference of ISO 11649: `RF`, two check digits (modulo 97-10) and up to 21 letters and digits. It goes with an ordinary IBAN.

| Method | Result |
|---|---|
| `isValid(string $reference): bool` | whether the reference (spaces allowed, any case) has correct check digits |
| `fromCharactersOf(string $value): ?string` | the Creditor Reference of the letters and digits of `$value`; null without any or with more than 21 |
| `format(string $reference): string` | the reference in groups of four for printing |

```php
use Quittio\Country\CH\QrBill\CreditorReference;
use Quittio\Country\CH\QrBill\QrReference;

QrReference::fromDigitsOf('RE-2026-0042');                  // "000000000000000000202600427"
QrReference::format('000000000000000000202600427');         // "00 00000 00000 00000 02026 00427"
QrReference::isValid('21 00000 00003 13947 14300 09017');   // true

CreditorReference::fromCharactersOf('539007547034');        // "RF18539007547034"
CreditorReference::format('RF18539007547034');              // "RF18 5390 0754 7034"
CreditorReference::isValid('RF18 5390 0754 7034');          // true
```

A QR reference made from the invoice number lets you match an incoming payment to the invoice by its digits. If your bank or your accounting gives you QR references of another structure, pass them as remittance information; the generator uses a valid one as it is.

## Rounding to 5 Rappen

Amounts in CHF are usually rounded to 5 Rappen (0.05 CHF) in Switzerland. The pack can round the amount due to a multiple of 0.05 by adding a rounding amount (BT-114). The VAT and the line amounts stay unchanged, and the XML carries the rounding amount, so the e-invoice stays consistent.

Switch the rounding on per invoice with the flag `CountryCH::FLAG_CASH_ROUNDING` (`ch.cash_rounding`), or for all invoices with the setting `cash_rounding` ([Meta flags and settings](#meta-flags-and-settings)):

```php
use Quittio\Country\CH\CountryCH;

$builder->flag(CountryCH::FLAG_CASH_ROUNDING);
```

- **When.** `CountryCH::complete()` adds the rounding amount when Quittio renders or prepares the invoice (`render()`, `check()`, `prepare()`), not when it is built. `$result->data()` holds the rounded invoice.
- **How.** Half up to 0.05: an amount due of 108.12 becomes 108.10 (rounding amount −0.02), 108.14 becomes 108.15 (+0.01). `Quittio\Country\CH\Rounding\CashRounding::round(BigDecimal $amount)` rounds a single amount, `CashRounding::apply(InvoiceData $invoice)` an invoice.
- **Only CHF.** Invoices in another currency are not rounded.
- **Your own rounding wins.** An invoice with a rounding amount of its own (`->rounding('0.00')`, see [Prepaid amounts and rounding](building-invoices.md#prepaid-amounts-and-rounding)) is not changed. With the setting switched on, `->rounding('0.00')` therefore keeps a single invoice unrounded; removing the flag does not.
- **The QR bill** carries the rounded amount due.

## Late payment

Under Art. 104 OR a debtor in default owes 5 % default interest a year. `CountryCH::dunningLaw()` returns these values ([`DunningLaw`](country-packs.md#dunninglaw)):

| Value | Switzerland |
|---|---|
| Interest for business debtors | 5 % fixed (`b2bFixedRate`) |
| Interest for consumers | 5 % fixed (`b2cFixedRate`) |
| Reference rate | none |
| Flat rate for business debtors | none |
| Day count | 365 days |
| Consumer default after days | not set |

As in other countries, Quittio's documents do not apply these values by themselves. `Quittio\Core\Dunning\DefaultInterest::forCountry(new CountryCH)` calculates the interest; because both rates are fixed (`DunningLaw::hasFixedRates()`), the pack needs no reference rate table, and every period is one charge at 5 %. Pass the charges to a payment reminder ([Payment reminders](documents.md#payment-reminders)):

```php
use Brick\Money\Money;
use Quittio\Core\Builder\Party;
use Quittio\Core\Documents\DocumentBuilder;
use Quittio\Core\Dunning\DefaultInterest;
use Quittio\Core\Quittio;
use Quittio\Core\Support\Date;
use Quittio\Country\CH\CountryCH;

$quittio = Quittio::create([], [new CountryCH]);

$reminder = DocumentBuilder::paymentReminder(1)                      // first reminder ("1. Mahnung")
    ->number('M-2026-0003')
    ->issueDate('2026-12-10')
    ->currency('CHF')
    ->seller(Party::make('Bergsoft AG')->vatId('CHE-123.456.788 MWST')->address('Bahnhofstrasse 12', '8001', 'Zürich', 'CH'))
    ->buyer(Party::make('Muster GmbH')->address('Marktgasse 28', '3011', 'Bern', 'CH'))
    ->creditTransfer('CH93 0076 2011 6238 5295 7')
    ->openItem('RE-2026-0042', '2026-10-05', '1960.95', dueDate: '2026-11-04');

// 5 % for the whole time; the end date is exclusive, so 10 December is the last day with interest
$reminder->interestCharge(...DefaultInterest::forCountry(new CountryCH)->calculate(
    Money::of('1960.95', 'CHF'),
    Date::parse('2026-11-05'),
    Date::parse('2026-12-11'),
));

$result = $quittio->document($reminder->build())->render();   // 1._Mahnung_M-2026-0003.pdf
```

- The charges have no description of their own; the reminder prints the default text, e.g. "Verzugszinsen 5 % p. a. auf 1'960.95 CHF vom 05.11.2026 bis 10.12.2026".
- `calculate()` takes `consumer: true` for consumers; the rate is the same. `flatRate()` of `DefaultInterest` returns null, because the pack's dunning law has no flat rate. `DocumentBuilder::flatRate()` adds the flat rate of the EU late payment directive (40.00 by default); it does not belong on a Swiss reminder.
- Quittio does not check whether and since when the debtor is in default; you pass the first day with interest.

## Meta flags and settings

The pack reads one meta flag and one setting. Meta flags are set on the builder with `->flag()` ([Presentation and metadata](building-invoices.md#presentation-and-metadata)).

| Flag | Value | Effect |
|---|---|---|
| `CountryCH::FLAG_CASH_ROUNDING` | `ch.cash_rounding` | [rounding to 5 Rappen](#rounding-to-5-rappen) of this invoice |
| `Meta::FLAG_SMALL_BUSINESS` | `small_business` | lines without `vat()` get `VatPreset::NotRegistered` ([Sellers not registered for VAT](#sellers-not-registered-for-vat)) |

Settings of the pack live under `quittio.countries.ch` ([Settings of a pack](configuration.md#settings-of-a-pack-quittiocountriescode)):

| Key | Type | Default | Effect |
|---|---|---|---|
| `cash_rounding` | bool | `false` | `true` rounds every invoice in CHF to 5 Rappen, as the flag does for one invoice |

The pack has no configuration file to publish. In Laravel, add the section to `config/quittio.php`; without Laravel, pass it to `Quittio::create()`:

```php laravel
'countries' => [
    'ch' => ['cash_rounding' => true],
],
```

```php plain
use Quittio\Core\Quittio;
use Quittio\Country\CH\CountryCH;

$quittio = Quittio::create(['countries' => ['ch' => ['cash_rounding' => true]]], [new CountryCH]);
```

The value must be the boolean `true`; a string such as `'1'` does not switch the rounding on.

## Defaults

How `CountryCH` fills the [`CountryPack` contract](country-packs.md#the-countrypack-contract):

| Hook | Switzerland |
|---|---|
| `defaults()` | currency `CHF`, time zone `Europe/Zurich`, locale `de_CH` (Swiss formats such as 1'590.00, German texts), paper `A4`, letter layout `free`, profile `en16931`, payment QR `swiss-qr-bill`, default VAT presets `ch.standard` and `ch.not_registered` ([CountryDefaults](country-packs.md#countrydefaults)) |
| `taxPresets()` | every case of [`VatPreset`](#tax-presets) |
| `rules()` | the [MWSTG rules](#rules) `CH-MWSTG-01` to `CH-MWSTG-05` |
| `buyerCountryRules()`, `formatRules()`, `profiles()`, `legalTexts()` | none |
| `mandatoryOutput()` | [UID number and date of the supply](#mandatory-pdf-output) |
| `paymentQr()` | [`SwissQrBillGenerator`](#qr-bill) |
| `complete()` | [rounding to 5 Rappen](#rounding-to-5-rappen) (capability `CompletesInvoices`) |
| `numberingRules()` | pattern `RE-{Y}-{seq:4}`, reset `yearly`, resets `never`, `yearly`, `monthly`, not gapless |
| `retention()` | 10 years from the end of the calendar year |
| `dunningLaw()` | [5 % fixed, no flat rate](#late-payment) |
| `correctionPolicy()` | credit note, cancel and reissue, Swiss titles |
| `letterLayouts()`, `addressFormatter()` | defaults: the layout `free`, international address format |
| `translationPath()` | `resources/lang` of the package |

- **Locale.** `de_CH` is the last fallback of the render locale: the locale of the call, of the invoice and of the configuration come first ([Translations and locales](templates.md#translations-and-locales)). Amounts and dates follow the regional locale with `ext-intl`, e.g. 1'960.95 CHF and 05.10.2026.
- **Currency, time zone, profile.** They are informational and not applied to invoices ([CountryDefaults](country-packs.md#countrydefaults)). The builder starts with EUR, also in Laravel, so set `->currency('CHF')` on every invoice in Swiss francs.
- **Paper and layout.** A4 and the layout `free` (address field 45 mm from the top, no fold marks). Swiss letter layouts are not included ([Known limitations](#known-limitations)).
- **Numbering.** Swiss law prescribes no numbering; unique numbers are good practice. The pattern is the default of [Managed numbering](managed.md#numbering). The pack declares numbers as not gapless.
- **Retention.** Ten years from the end of the calendar year (Art. 70 (2) MWSTG, Art. 958f OR): an invoice issued on 5 October 2026 is kept until 31 December 2036. 20 years for immovable property (Art. 70 (3) MWSTG) are not modelled.

### Cancellations and corrections

An invoice is cancelled by a credit note (type code 381 with positive amounts) and corrected by cancelling it and issuing a new one ([CorrectionPolicy](country-packs.md#correctionpolicy), [Cancellations and corrections](building-invoices.md#cancellations-and-corrections)). The titles:

| Kind | German | French | Italian | English |
|---|---|---|---|---|
| `cancellation` | Gutschrift | Note de crédit | Nota di credito | Credit note |
| `correction` | Korrigierte Rechnung | Facture corrigée | Fattura corretta | Corrected invoice |
| `self_billed` | Gutschrift (Selbstfakturierung) | Autofacturation | Autofatturazione | Self-billed invoice |

A regional locale takes the title of its language (`de_CH` → "Gutschrift", `fr_CH` → "Note de crédit"); other languages get the English title. A cancellation takes the locale of the original, then the configured locale, then the pack default `de_CH`. Pass `title:` to `cancellation()` for another title. The titles are defined in PHP and cannot be changed through translation files.

## Languages

The pack ships texts in German, French, Italian and English (`resources/lang/{de,fr,it,en}/`):

| File | Content | Languages |
|---|---|---|
| `rules.php` | messages and hints of the [rules](#rules) | de, fr, it, en |
| `tax.php` | [exemption texts](#exemption-texts) of the presets | de, fr, it, en |
| `qrbill.php` | headings of payment part and receipt | de, fr, it, en |
| `labels.php` | Swiss words for labels of the core | de, fr |

The German labels replace the core's VAT words with the Swiss ones: "MWST" instead of "USt." (e.g. "MWST 8.1 %", "8.1 % MWST auf 1'740.00 CHF") and "MWST-Nr." for the VAT identifier. The French labels name the VAT identifier "N° TVA (IDE)". All other labels come from the core.

- **Italian.** The core has labels and code names in German, English, French and Dutch, not in Italian. An invoice with an Italian locale (`it` or `it_CH`) therefore has English labels ("Invoice", "VAT"), while the exemption texts, rule messages and QR bill headings are Italian.
- **Romansh.** Headings in Romansh, which version 2.4 of the guidelines adds (valid from 14 November 2026), are not included. A Romansh locale gets German headings on the QR bill and English texts otherwise.
- **QR bill headings.** The guidelines prescribe them; do not change them.

The texts are registered as the namespace `quittio-ch` (e.g. `quittio-ch::labels.vat_id`, `quittio-ch::tax.ch.export`). In Laravel, override them in `lang/vendor/quittio-ch/{locale}/labels.php`, `rules.php` or `tax.php`. A file needs only the keys you change:

```php
// lang/vendor/quittio-ch/de/labels.php: "UID" instead of "MWST-Nr."
return ['vat_id' => 'UID'];
```

Without Laravel, add a directory with the same structure after `Quittio::create()`; later paths win over earlier ones:

```php
use Quittio\Core\I18n\Translations;

Translations::translator()->addPath(__DIR__.'/lang', 'quittio-ch');   // lang/de/labels.php
```

`CountryCH::locales()` lists the languages the pack ships, `CountryCH::registerTranslations()` registers the texts lazily, so the presets work without a `Quittio` instance. Labels of a single invoice can also be changed with `Presentation::$labels` ([Translations and locales](templates.md#translations-and-locales)).

## Known limitations

- The legal wording, rules and defaults have not been reviewed by a Swiss tax adviser (see the note at the top).
- The VAT rates of the vote of 29 November 2026 and a change of the accommodation rate after 2027 are not included ([Rates by supply date](#rates-by-supply-date)).
- QR bill: billing information (syntax S1 of Swico, maintained by SIX since version 2.4 of the guidelines), alternative procedures and the "do not use for payment" bill are not supported yet. Headings in Romansh are missing.
- eBill (through a network partner of SIX) is not supported.
- Swiss letter layouts with the window left or right (SN 10130:2026 "Geschäftsbrief", which replaced SN 010130; Swiss Post specifications for the address window) are not included; the pack uses the layout `free`.
- Labels and code names in Italian are missing in the core; Italian invoices show English labels.
- There is no Laravel service provider with a configuration file of the pack; the setting `cash_rounding` goes into `config/quittio.php`.
- 20 years of retention for immovable property are not modelled.

## Complete example

A Swiss software company invoices a business in Bern: development work at the standard rate and two books at the reduced rate, paid to a QR-IBAN, rounded to 5 Rappen. The script checks the invoice, renders it and saves the PDF with the QR bill on its second page. It runs without Laravel; in Laravel, start with `Quittio::invoice()` instead of `InvoiceBuilder::make()` and leave out `Quittio::create()` and `useDefaultVat()`.

```php
<?php
require __DIR__.'/vendor/autoload.php';

use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Builder\Party;
use Quittio\Core\Enums\PaymentMeansCode;
use Quittio\Core\Enums\UnitCode;
use Quittio\Core\Quittio;
use Quittio\Core\Tax\CountryDefaultVat;
use Quittio\Country\CH\CountryCH;
use Quittio\Country\CH\Tax\VatPreset;

$quittio = Quittio::create([], [new CountryCH]);
InvoiceBuilder::useDefaultVat(new CountryDefaultVat($quittio->countries()));   // lines without vat(): VatPreset::Standard

$invoice = InvoiceBuilder::make()
    ->number('RE-2026-0042')
    ->issueDate('2026-10-05')
    ->deliveryDate('2026-09-30')
    ->currency('CHF')
    ->seller(Party::make('Bergsoft AG')
        ->vatId('CHE-123.456.788 MWST')
        ->address('Bahnhofstrasse 12', '8001', 'Zürich', 'CH')
        ->contact('Lea Meier', '+41 44 123 45 67', 'rechnung@bergsoft.example'))
    ->buyer(Party::make('Muster GmbH')->address('Marktgasse 28', '3011', 'Bern', 'CH'))
    ->line('Softwareentwicklung', netPrice: '145.00', quantity: 12, unit: UnitCode::Hour)                    // 8.1 %
    ->line('Fachbuch', netPrice: '39.00', quantity: 2, unit: UnitCode::Piece, vat: VatPreset::Reduced)       // 2.6 %
    ->payment(PaymentMeansCode::SepaCreditTransfer, iban: 'CH44 3199 9123 0008 8901 2')                      // QR-IBAN
    ->dueInDays(30)
    ->flag(CountryCH::FLAG_CASH_ROUNDING)
    ->build();

// Validate without rendering: EN 16931 and the MWSTG rules
$check = $quittio->make($invoice)->check();
foreach ($check->messages as $message) {
    echo "[{$message->code}] {$message->message}\n";
}

if ($check->isValid()) {
    $result = $quittio->make($invoice)->render();          // format zugferd: PDF with EN 16931 XML
    $path = $result->save(__DIR__.'/invoices/');           // invoices/Rechnung_RE-2026-0042.pdf

    echo $result->data()->totals->roundingAmount, "\n";    // CHF -0.02
    echo $result->data()->totals->amountDue, "\n";         // CHF 1960.95
}
```

What the invoice contains:

- Net amount 1'818.00 CHF, 8.1 % MWST on 1'740.00 CHF (140.94 CHF) and 2.6 % MWST on 78.00 CHF (2.03 CHF), total 1'960.97 CHF, rounding −0.02 CHF, amount due 1'960.95 CHF.
- The date of the supply ("Leistungsdatum 30.09.2026") in the header and the UID number ("MWST-Nr. CHE-123.456.788 MWST") in the footer, as the [mandatory output](#mandatory-pdf-output) requires.
- On the second page the payment part and the receipt: account CH44 3199 9123 0008 8901 2 with Bergsoft AG, the QR reference `00 00000 00000 00000 02026 00427` made from the invoice number, "RE-2026-0042" as additional information, the buyer under "Zahlbar durch" and the amount 1 960.95 CHF.

With an ordinary IBAN instead of the QR-IBAN, the code has no reference (`NON`) unless the remittance information is a Creditor Reference ([References](#references)). With the locale `fr_CH` (`->locale('fr_CH')` before `build()`), labels, exemption texts and the QR bill headings are French ("Section paiement", "Récépissé").
