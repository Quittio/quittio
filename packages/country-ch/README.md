# Quittio country pack Switzerland

> **Not released.** This pack lives in the Quittio monorepo only. Its legal wording, rules and defaults have **not been reviewed by a Swiss tax adviser** yet; until they are, it is not split into its own repository, not published on Packagist and not part of `quittio/quittio`. Do not rely on it for real invoices.

The pack implements the invoice requirements of the Swiss VAT act (MWSTG), the Swiss QR bill and the Swiss law on late payment. Its class is `Quittio\Country\CH\CountryCH` (code `CH`, translation namespace `quittio-ch`). Switzerland has no e-invoicing obligation; its focus is the payment with the QR bill.

```php
use Quittio\Core\Quittio;
use Quittio\Country\CH\CountryCH;

$quittio = Quittio::create(['country' => 'CH'], [new CountryCH]);
```

## Defaults

CHF, locale `de_CH` (Swiss formats such as 1'590.00, German texts), time zone `Europe/Zurich`, A4, the QR bill as payment QR code (`swiss-qr-bill`). Texts exist in German, French, Italian and English; VAT is labelled "MWST" in German.

## Tax presets

`Quittio\Country\CH\Tax\VatPreset`; the rates follow the supply date of the line:

| Case | Key | Category | Rate | Legal basis |
|---|---|---|---|---|
| `Standard` | `ch.standard` | `S` | 8.1 (2018–2023: 7.7, before: 8.0) | Art. 25 (1) MWSTG |
| `Reduced` | `ch.reduced` | `S` | 2.6 (before 2024: 2.5) | Art. 25 (2) MWSTG |
| `Accommodation` | `ch.accommodation` | `S` | 3.8 (2018–2023: 3.7) | Art. 25 (4) MWSTG |
| `Excluded` | `ch.excluded` | `E` | 0 | Art. 21 MWSTG (excluded, without input tax deduction); `VatPreset::excluded('Art. 21 Abs. 2 Ziff. 3 MWSTG')` names the case |
| `Export` | `ch.export` | `G` | 0 | Art. 23 MWSTG (exempt, with input tax deduction) |
| `NotTaxableInSwitzerland` | `ch.not_taxable` | `O` | – | place of supply abroad (Art. 7, 8 MWSTG) |
| `NotRegistered` | `ch.not_registered` | `O` | – | seller not registered for VAT, e.g. below CHF 100,000 turnover (Art. 10 (2) MWSTG) |

Not included: the increase of the standard rate to 8.5 % and of the accommodation rate to 4.0 % (the reduced rate stays at 2.6 %), on which the vote of 29 November 2026 decides; the Federal Council sets the date, at the earliest 2028. The law limits the accommodation rate to the end of 2027; an extension until 2031 is not yet in force.

## Rules (Art. 26 MWSTG)

| Code | Severity | Check |
|---|---|---|
| `CH-MWSTG-01` | error | name and place of the seller (lit. a) |
| `CH-MWSTG-02` | error | UID number of a seller that charges VAT (lit. a) |
| `CH-MWSTG-03` | warning | UID with the note of the registration, e.g. "CHE-123.456.789 MWST" |
| `CH-MWSTG-04` | error | name and place of the buyer (lit. b), not on till receipts up to CHF 400 (Art. 57 MWSTV) |
| `CH-MWSTG-05` | error | no VAT on invoices of sellers that are not registered (Art. 27 (2) MWSTG) |

## QR bill

`Quittio\Country\CH\QrBill\SwissQrBillGenerator` creates the Swiss QR Code (Swiss Payments Code, version 0200) after the Swiss Implementation Guidelines QR-bill 2.3 of SIX and its payment part with receipt. The default templates print it at the bottom of a page of its own (see [Payment slips](../../docs/templates.md#payment-slips)): code 46 × 46 mm with the Swiss cross, headings in the language of the invoice, Liberation Sans.

- Account: the first credit transfer account with an IBAN of Switzerland or Liechtenstein; currency CHF or EUR.
- Reference: with a QR-IBAN (institution identification 30000 to 31999) the remittance information (BT-83) if it is a QR reference, otherwise the QR reference of the digits of the invoice number (`QrReference`, modulo 10 recursive). With an ordinary IBAN a Creditor Reference (ISO 11649, `CreditorReference`) in BT-83, otherwise no reference. The invoice number or the remittance information is the unstructured message.
- Creditor: account holder or seller with the seller's address; debtor: the buyer, or an empty field when its address is incomplete. Addresses are structured (type "S"); a building number at the end of the street becomes the building number.
- Amount: the amount due; no code for amounts of zero or less.

Billing information (syntax S1 of Swico, maintained by SIX since version 2.4 of the guidelines), alternative procedures and the "do not use for payment" bill are not supported yet.

## Rounding to 5 Rappen

With the flag `CountryCH::FLAG_CASH_ROUNDING` (`->flag('ch.cash_rounding')`) or the setting `cash_rounding = true` (`quittio.countries.ch`), invoices in CHF get a rounding amount (BT-114) that makes the amount due a multiple of 0.05 (`CashRounding`).

## Late payment

Art. 104 OR: 5 % default interest for businesses and consumers, without reference rate and without flat rate (`DefaultInterest::forCountry(new CountryCH)`).

## Other defaults

- Retention: ten years from the end of the calendar year (Art. 70 (2) MWSTG, Art. 958f OR); 20 years for immovable property (Art. 70 (3) MWSTG) are not modelled.
- Numbering: `RE-{Y}-{seq:4}`, not gapless (Swiss law prescribes no numbering).
- Titles: "Gutschrift" / "Note de crédit" for cancellations, "Korrigierte Rechnung" for corrections.

## Not yet included

- Labels and code names in Italian (the core has German, English, French and Dutch; Italian invoices show English labels).
- Swiss letter layouts with the window left or right (SN 10130:2026 "Geschäftsbrief", which replaced SN 010130; Swiss Post specifications for the address window), eBill (through a network partner of SIX), a Laravel service provider with settings.
- Headings in Romansh, which version 2.4 of the guidelines adds (valid from 14 November 2026).
