# Troubleshooting

This page lists the errors that people new to Quittio meet most often, with the cause and the fix. Most of them come from validation: Quittio checks every invoice against the rules of the e-invoicing standards before it renders it ([Rules and validation](concepts.md#rules-and-validation)).

## Reading an error message

An invalid invoice stops the rendering with a `Quittio\Core\Validation\ValidationException`. Its message lists every error with the rule code, the field and a hint:

```text
The invoice is not valid (2 errors):
[BR-CO-25] BT-9: In case the amount due for payment (BT-115) is positive, either the payment due date (BT-9) or the payment terms (BT-20) shall be present.
    Add a payment target with ->dueInDays(14) or a due date with ->dueDate().
[DE-USTG-03] BT-72: The date of the supply or service must be stated: delivery date (BT-72), invoicing period (BG-14) or periods on all lines (BG-26) (section 14 (4) no. 6 UStG).
    Set ->deliveryDate(), even if it equals the invoice date, or ->invoicingPeriod().
```

- `[BR-CO-25]` is the rule. The prefix tells where it comes from: `BR-` EN 16931, `BR-DE-` XRechnung, `DE-USTG-` the German VAT Act, `QUITTIO-` Quittio itself ([rule codes](concepts.md#rules-and-validation)).
- `BT-9` is the field ([business terms](concepts.md#en-16931-and-business-terms)); the [Field reference](field-reference.md) shows where each field is in the data model.
- The indented line is the hint: usually the builder method that fixes the error.

Warnings do not stop the rendering; they are in `$result->validation()`. `->check()` instead of `->render()` validates without rendering and returns all findings without throwing, which helps while you build an invoice step by step ([Validation](validation.md)).

The messages are in the language of your Laravel application (`app()->getLocale()`). `quittio.validation.locale` sets another language, `'invoice'` uses the language of the invoice ([Messages and translations](validation.md#messages-and-translations)).

## Errors while building

These errors are thrown by `build()` or `render()` before any validation, as `Quittio\Core\Exceptions\InvalidInvoiceData` or `InvalidArgumentException`.

### "Floats are not accepted"

```text
Floats are not accepted for amounts, prices, rates and quantities (19.99 given) …
```

Amounts, prices, rates and quantities must be strings or integers: `netPrice: '19.99'`, not `netPrice: 19.99`. A float such as 0.1 cannot be stored exactly, and invoices must be exact to the cent. Values from the database are usually strings already; cast decimals with `(string)`.

### "Missing VAT information of line 1 (BG-30)"

The line has no VAT rate. In Laravel every line without `vat:` gets the default VAT (19 % for a German seller, `quittio.defaults.vat`). Without Laravel there is no default: set the VAT per line (`vat: VatPreset::Standard`), for the invoice with `->defaultVat(VatPreset::Standard)`, or once for the application with `InvoiceBuilder::useDefaultVat(…)` ([Default VAT](building-invoices.md#default-vat)).

### "Missing seller postal address (BG-5)" and other "Missing …"

A required part of the invoice is missing entirely; the message names it and its field number. With `Quittio::invoice()` the seller comes from `config/quittio.php`: fill in `seller.address` (street, postcode, city, country) there ([Configuration](configuration.md)). For the buyer, call `->buyer(Party::make('…')->address(…))`.

## Validation errors

### Seller address incomplete (DE-USTG-01)

The address of the seller or buyer lacks street, postcode, city or country; the message names the party and the missing fields. German invoices need the full address of both parties. For the seller, check `seller.address` in `config/quittio.php`.

### Seller identifier missing (BR-CO-26, BR-S-02, DE-USTG-02)

The seller has neither a VAT identification number nor a tax number. Set `seller.vat_id` or `seller.tax_number` in `config/quittio.php` (or `->vatId()` / `->taxNumber()` on the seller). A small business with a tax number only also needs a seller identifier or a trade register number for EN 16931 (`seller.identifiers` or `seller.legal_registration`, rule `BR-CO-26`).

### Date of supply missing (DE-USTG-03)

German invoices must state when the goods were delivered or the service was performed, even if it is the invoice date. Add `->deliveryDate('2026-09-30')`, an invoicing period, or a period on every line (`Line::make(…)->period(…)`).

### Payment due date missing (BR-CO-25)

An invoice with an amount to pay needs a due date or payment terms: `->dueInDays(14)` or `->dueDate('2026-10-17')`. In Laravel `quittio.defaults.due_days` (default 14) adds a due date to every invoice without one, so the error appears without Laravel or when that key is `null`.

### XRechnung needs more fields (QUITTIO-PROFILE-REQUIRED, BR-DE-…)

XRechnung (public buyers in Germany) requires fields that other formats leave optional. A typical first attempt reports several errors at once:

| Field | Set with |
|---|---|
| Buyer reference, the Leitweg-ID of the authority (BT-10) | `->buyerReference('04011000-12345-03')` |
| Seller contact: name, phone, e-mail (BG-6) | `seller.contact` in the configuration, or `->contact(…)` on the seller |
| Electronic address of seller and buyer (BT-34, BT-49) | `seller.electronic_address` in the configuration; `->electronicAddress('ap@kunde.de', 'EM')` on the buyer |
| Payment instructions (BG-16) | `seller.bank_accounts` in the configuration, or `->payment(…)` |

See the [public sector example](examples.md#public-sector) and [German format choice](country-packs.md#format-choice).

### Peppol rejects the electronic address (PEPPOL-EN16931-CL008)

Peppol delivers to the electronic address (BT-34, BT-49), so it must be a Peppol participant identifier. EN 16931 also allows an e-mail address (`EM`), Peppol does not, nor the other letter schemes and a few newer codes of the EAS list. Use the scheme under which the party is registered in Peppol, e.g. `->electronicAddress('DE123456789', '9930')` (German VAT number) or `->electronicAddress('4000001000005', '0088')` (GLN); for the seller set `seller.electronic_address` in the configuration. ZUGFeRD and XRechnung keep accepting `EM`.

### Small business with VAT (DE-USTG-09)

The seller is marked as a small business (`seller.small_business`, German Kleinunternehmer under section 19 UStG), but a line carries VAT. Small businesses charge no VAT: with the small business setting, lines without `vat:` get the small business preset automatically; remove explicit `vat:` values from the lines.

## Rendering and PDF

### "The format … produces e-invoice XML and cannot be rendered with a watermark"

Drafts and previews carry a watermark and must never pass as an e-invoice, so they cannot be rendered in a format with XML. Render drafts with `->render('pdf')` or `'html'` ([Watermark](templates.md#watermark)).

### "The template does not output mandatory information"

An own template (or a changed partial) does not print a value the invoice must show, e.g. the VAT identifier or the date of supply; the message lists the missing values. Print them, or start from a copy of the default template (`php artisan make:quittio-template`). See [Mandatory field guard](templates.md#mandatory-field-guard).

### Logo errors

- "The logo file … does not exist": the path in `pdf.logo` is relative or wrong; use an absolute path such as `storage_path('app/logo.png')`.
- SVG logos in PDF/A documents (ZUGFeRD, XRechnung PDF) need the PHP extension `imagick`; use a PNG otherwise.
- In HTML output a local logo path is not visible in a browser; use a URL or data URI there.

Details: [Logos](templates.md#logos).

### Characters are missing in the PDF

The default font Geist covers Latin and Cyrillic, not Greek. Set `pdf.font = 'inter'` for Greek names or texts, or register a font that has the characters ([Fonts](templates.md#fonts)).

### A long line item starts on the next page

A table row is never split between two pages. When a line with a very long description does not fit below the invoice details, it moves to the next page as a whole. Shorten the description or move details to a note or an attachment.

### "Invalid colour" or "Invalid font name"

Colours in `pdf.colors` must be hex values (`#2741d9`), fonts plain family names that are registered (`geist`, `inter` or an entry of `pdf.fonts`).

## External validators

### KOSIT-UNAVAILABLE, VERAPDF-UNAVAILABLE

The KoSIT validator (official check of XRechnung and EN 16931) or veraPDF (PDF/A check) is enabled in `validation.levels` but could not run, usually because Java, Docker or the validator files are missing. By default (`fail_on_unavailable = true` in `validation.drivers.kosit` and `validation.drivers.verapdf`) this is an error and the rendering stops, so that an invoice is never sent unchecked by mistake. Install the validator as described in [Validation](validation.md#kosit-validator-l5), remove `kosit` or `verapdf` from `validation.levels`, or set `fail_on_unavailable` to `false` to get a warning instead.

## Still stuck?

- Look at the invoice the way Quittio sees it: `Quittio::make($invoice)->prepare()` shows the chosen format, profile and all findings without rendering.
- Render the sample invoices: `php artisan quittio:preview --scenario=simple` shows whether configuration and template work.
- Validate an existing file: `php artisan quittio:validate invoice.xml` ([Artisan commands](commands.md)).
- Compare with a complete example on [Examples](examples.md).
- Questions about sending, the German e-invoicing obligation, Java or temporary files: [FAQ](faq.md).
