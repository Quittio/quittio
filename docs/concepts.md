# E-invoicing basics

This page explains the terms you meet in Quittio and in this documentation, in about five minutes. You do not need to know them to render a first invoice ([Getting started](getting-started.md)), but they explain why Quittio asks for certain data and what the formats and error codes mean.

## What an e-invoice is

An e-invoice is an invoice in a structured, machine-readable format: an XML file that accounting software can read without a person typing the values. A PDF that only shows the invoice is not an e-invoice, even when it is sent by e-mail.

Many countries now require e-invoices between businesses. In Germany every domestic business must be able to receive them since 2025; sending them becomes mandatory step by step until 2028, with exceptions such as invoices up to 250 € ([German country pack](country-packs.md#germany-quittiocountry-de)). Invoices to consumers are not affected.

Quittio produces both parts from one set of data: the XML for the software and, where useful, a PDF for people.

## EN 16931 and business terms

**EN 16931** is the European standard that defines what an e-invoice contains: seller, buyer, lines, VAT, totals, payment and so on. Every national format in the EU is based on it.

The standard gives every field a number:

| Abbreviation | Meaning | Example |
|---|---|---|
| BT-*n* | Business term: a single field | BT-1 invoice number, BT-27 seller name, BT-10 buyer reference |
| BG-*n* | Business group: a group of fields | BG-5 seller address, BG-25 invoice line |

This documentation names these numbers in parentheses, e.g. "buyer reference (BT-10)", because validation messages and the formats use them. You never type them in your code: the builder methods have plain names (`buyerReference()`). The [Field reference](field-reference.md) lists every field with its number.

## Syntaxes: CII and UBL

EN 16931 describes the content, not the file. Two XML **syntaxes** write it down:

| Syntax | Full name | Used by |
|---|---|---|
| CII | UN/CEFACT Cross Industry Invoice | ZUGFeRD/Factur-X, XRechnung |
| UBL | OASIS Universal Business Language 2.1 | Peppol, XRechnung |

Both carry the same information. Which one you need depends on the format the recipient expects.

## Formats

A **format** is what Quittio produces. The common ones:

| Format | What it is | Typical recipient |
|---|---|---|
| ZUGFeRD / Factur-X | A PDF (PDF/A-3) with the CII XML embedded: people read the PDF, software reads the XML. ZUGFeRD (Germany) and Factur-X (France) are the same standard. | Businesses |
| XRechnung | The German standard for public authorities: an XML file only, CII or UBL, with additional German rules | Public buyers in Germany |
| Peppol BIS Billing | UBL XML sent over the Peppol network, an international delivery network for e-invoices | Businesses and authorities connected to Peppol |
| CII, UBL | Plain XML in either syntax | Recipients that ask for it |
| PDF | A PDF without XML (PDF/A-3 for archiving) | Consumers, small amount invoices, countries without an obligation |

[Formats and profiles](formats.md) lists every format with its keys (`zugferd`, `xrechnung-cii` …).

## Profiles

A **profile** decides how much of EN 16931 an XML file contains and which extra rules apply. ZUGFeRD/Factur-X has the levels MINIMUM, BASIC WL, BASIC, EN 16931 and EXTENDED; XRechnung and Peppol are profiles with national or network rules on top of EN 16931.

| Profile | Use |
|---|---|
| `en16931` | The standard itself; the default for business invoices |
| `extended` | More fields than EN 16931 (e.g. sub lines); Quittio switches to it automatically when you use them |
| `xrechnung` | XRechnung rules (public buyers in Germany) |
| `peppol` | Peppol BIS Billing 3.0 |
| `minimum`, `basic-wl`, `basic` | Reduced levels without (all) lines; not valid e-invoices under EN 16931, only for recipients that ask for them |

You rarely choose a profile yourself: each format has a default, and the [automatic choice](formats.md#automatic-choice) picks format and profile from the invoice.

## Rules and validation

The standards come with **business rules**: conditions every invoice must meet, such as "the sum of the line amounts equals the net total" or "a seller address is required". They have codes, and Quittio's error messages show them:

| Code | Source |
|---|---|
| `BR-…`, `BR-CO-…`, `BR-CL-…` | EN 16931 (e.g. `BR-CO-26`: the seller needs a VAT identifier or another identifier) |
| `BR-DE-…` | XRechnung (Germany) |
| `PEPPOL-…` | Peppol BIS Billing |
| `DE-USTG-…`, `DE-…` | Quittio's German country pack: mandatory content of German invoices under the VAT Act (UStG) |
| `QUITTIO-…` | Quittio itself |

Quittio checks an invoice before it renders it. An error stops the rendering with a `ValidationException` that names the rule, the field and a hint; warnings are reported but do not stop it. Optional external validators (the official KoSIT validator for XRechnung and EN 16931, veraPDF for PDF/A) check the finished files. See [Validation](validation.md) and, for common errors, [Troubleshooting](troubleshooting.md).

## Which format do I need?

Usually none: without `->format()`, Quittio chooses for each invoice ([Automatic choice](formats.md#automatic-choice)). For a German seller:

| Your customer | Format Quittio chooses | Why |
|---|---|---|
| A business in Germany | ZUGFeRD (profile EN 16931) | E-invoice that is also a readable PDF |
| A public authority in Germany (buyer reference is a Leitweg-ID) | XRechnung (CII) | Required by public buyers |
| A consumer | PDF | No e-invoice obligation |
| Any customer in Germany, invoice up to 250 € marked with `->smallAmount()` | PDF | Small amount invoices are exempt |
| A business in another EU country | ZUGFeRD (profile EN 16931) | Readable PDF with standard XML |
| A business reachable via Peppol (with a Peppol lookup) | Peppol BIS | Delivery over the Peppol network |
| A customer outside the EU | PDF | No EU e-invoice rules |

Ask for a format with `->render('xrechnung-cii')` or `->format(…)` when a customer requires a specific one.

## How Quittio works

```text
config/quittio.php ──► Quittio::invoice() ──► …->line(…)->buyer(…) ──► render()
   (your company)        (invoice builder)       (your invoice data)        │
                                                                             ▼
             calculation → format and profile → validation → XML and PDF → result
                                                                             │
                                                     $result->pdf(), ->xml(), ->save(), ->download()
```

1. **Configuration:** your company (the seller), bank account and defaults are set once in `config/quittio.php` ([Configuration](configuration.md)).
2. **Building:** `Quittio::invoice()` starts an invoice with that seller; you add the number, customer, lines and payment terms ([Building invoices](building-invoices.md)).
3. **Rendering:** `render()` calculates totals and VAT, chooses format and profile, validates, writes the XML and renders the PDF from a template ([Templates and design](templates.md)). You never calculate totals yourself.
4. **Result:** a `RenderedInvoice` with the PDF, the XML, a file name and the validation result, which you save, download or mail ([Laravel integration](laravel.md)).

Everything country-specific, such as German VAT rates, legal texts, XRechnung and the DIN 5008 letter layout, comes from a **country pack** ([Country packs](country-packs.md)); `quittio/quittio` includes the one for Germany. The optional **Managed layer** adds gapless numbering, an archive, cancellations and corrections as stored records ([Managed layer](managed.md)).

## German terms

Quittio prints German invoices with German wording, and some German legal terms have no exact English equivalent. This documentation keeps the German term where it is the text on the invoice or the legal name, with an English explanation:

| German | English | Note |
|---|---|---|
| Rechnung | Invoice | |
| Gutschrift | Self-billed invoice | Issued by the buyer for the seller's supply (type code 389), not a refund |
| Rechnungskorrektur | Invoice correction | Title of credit notes (type code 381) and of corrections issued with the German pack (corrected invoice, type code 384) |
| Korrigierte Rechnung | Corrected invoice | Title of type code 384 outside the German correction policy |
| Stornorechnung | Cancellation invoice | Cancels an issued invoice |
| Anzahlungsrechnung, Abschlagsrechnung, Schlussrechnung | Advance payment invoice, partial invoice, final invoice | |
| Angebot, Auftragsbestätigung, Lieferschein | Quote, order confirmation, delivery note | [Documents](documents.md) |
| Zahlungserinnerung, Mahnung | Payment reminder, reminder | "1. Mahnung" is the first reminder after the payment reminder |
| Leistungsdatum, Leistungszeitraum | Date of supply, period of supply | Mandatory on German invoices |
| Skonto | Cash discount | |
| Übertrag | Carried forward | Running total at a page break |
| USt-IdNr. | VAT identification number | e.g. DE123456789 |
| Steuernummer (St.-Nr.) | Tax number | Issued by the local tax office |
| Kleinunternehmer | Small business | Charges no VAT (section 19 UStG) |
| Steuerschuldnerschaft des Leistungsempfängers | Reverse charge | The buyer owes the VAT (section 13b UStG) |
| Innergemeinschaftliche Lieferung | Intra-community supply | Tax-exempt supply of goods to a business in another EU country |
| Leitweg-ID | Routing ID of a German public buyer | Goes into the buyer reference (BT-10) of an XRechnung |
| UStG | German VAT Act (Umsatzsteuergesetz) | |
| GoBD | German rules for digital bookkeeping and retention | |
| KoSIT | Agency that publishes XRechnung and its validator | |
| DIN 5008 | German standard for business letters | Address field and fold marks of the PDF |
