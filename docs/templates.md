# Templates and design

| Level | What you do |
|---|---|
| 1 – Configuration | Logo, colours, font, texts, footer, sender line, letter layout, paper and QR code in `config/quittio.php` (`pdf.*`), per invoice or per call with a [`Presentation`](#presentation) |
| 2 – Partials | `php artisan vendor:publish --tag=quittio-views` and change single partials |
| 3 – Own template | `php artisan make:quittio-template my-design`, then `->template('invoices.my-design.layout')` or `pdf.template` |
| 4 – Own renderer | `Quittio::extend('renderer', …)` ([PDF renderers](#pdf-renderers)); PDF/A and the embedded XML stay with the package |

Templates only display data. Totals, VAT, texts of payment terms, QR codes and labels are prepared in the [view model](#view-model); the same data also produces the XML, and the [mandatory field guard](#mandatory-field-guard) checks that a template prints every mandatory value.

## Default templates

| Engine | Template | Used by |
|---|---|---|
| Blade | `quittio::templates.default.layout` with partials in `quittio::templates.default.partials` | Laravel (`pdf.template`) |
| Plain PHP | `packages/core/resources/templates/default.php` | `Quittio::create()` (template `default`); Laravel falls back to it for names that are no Blade view |

Both print the same document. Partials of the Blade template:

| Partial | Content |
|---|---|
| `styles` | CSS: page margins, font, colours, address field position, watermark for browsers |
| `footer` | `<htmlpagefooter>` with the footer columns and "Page {PAGENO} of {nbpg}" |
| `marks` | Fold and punch marks (option `fold_marks`) |
| `sender-line` | Sender line above the address |
| `recipient` | Address lines of the recipient |
| `letterhead` | Logo, name of the issuer, the issuer's address when the footer does not start with it |
| `meta` | Number, date, valid until, reference document, delivery date, service period, customer number, references, VAT identifier and tax number of the seller, tax representative, VAT identifier of the buyer, deliver-to address |
| `title` | Title (`<h1>`) |
| `references` | Preceding invoices ("Reference: invoice … of …") |
| `intro` | Salutation and intro text |
| `lines` | Line table: groups with subtotals, optional and alternative markers, item numbers, descriptions, line periods, attributes, line allowances and charges, line notes, serial numbers, sub lines, price base quantity, gross unit price |
| `allowances` | Sum of lines, document allowances and charges (inside `totals`) |
| `totals` | Net total, VAT rows, gross total, paid amount, rounding, third party payments, amount due, VAT in accounting currency |
| `documents` | Packages, open items, fees, interest, totals and balance of [documents](documents.md) |
| `tax-notes` | Exemption reasons and regulatory notes (`legalNotes`) |
| `payment` | Payment terms, bank details or payout account, direct debit notice, payment QR code |
| `notes` | Other notes |
| `attachments` | Attached documents (identifier and description) |
| `closing` | Closing text, acceptance field of quotes |

`php artisan vendor:publish --tag=quittio-views` copies the views to `resources/views/vendor/quittio/templates`; Laravel then uses your copy of a partial instead of the shipped one. The layout takes the partial prefix from `$partials` (default `quittio::templates.default.partials`).

## Selecting a template

The template of a run is, in this order: `->template('…')` of the call, `Presentation::$template` of the invoice's own presentation, the `template` of the presentation passed to `->presentation()`, the `template` of the configured presentation, the configured template. In Laravel the configured name is `default`, which the Blade engine maps to `pdf.template` (default `quittio::templates.default.layout`); without Laravel it is the `template` key of `Quittio::create()` (default `default`). The same order applies to documents (quotes, reminders …).

Template names may come from invoice data (`Presentation::$template`, issuer branding of the [Managed layer](managed.md)), so they are restricted:

- The Blade engine renders `default`, the configured `pdf.template`, the shipped views under `quittio::templates.` (not their partials) and the views listed in `pdf.templates` (default `['invoices.*']`; an entry ending in `*` allows every view below that prefix, e.g. `mytheme::*`). Another existing view fails with an `InvalidArgumentException`. Names that are no Blade view are passed to the plain PHP engine.
- The plain PHP engine accepts names only (`[A-Za-z0-9_-]` segments separated by `/`, no `..`, no absolute paths or stream wrappers) and resolves `{name}.php` inside its template directories (`template_paths` of `Quittio::create()`, then the shipped templates). An unknown or invalid name fails with an `InvalidArgumentException`.

```bash
php artisan make:quittio-template my-design          # resources/views/invoices/my-design (--force overwrites)
```

The command copies the default layout and partials and points the layout at `invoices.my-design.partials`. Use it per call with `->template('invoices.my-design.layout')` (allowed by `invoices.*`) or for every invoice with `pdf.template`.

A plain PHP template receives `$invoice` and `$e` (escape function returning HTML-safe text). Blade templates receive `$invoice`. Print values with `{{ }}`; only the prepared HTML values `lines[].description.html`, `notes[].html`, `texts.intro`, `texts.closing` and `design.watermarkCss` may be printed unescaped.

Other engines (Twig …) implement `Quittio\Core\Templates\TemplateEngine::render(string $template, InvoiceViewModel $invoice, array $data = []): string` and are passed to `Quittio::create(templates: …)` or `$quittio->withTemplateEngine(…)`.

## View model

Templates receive `$invoice` (`Quittio\Core\View\InvoiceViewModel`) and nothing else. Nodes are read-only `ViewNode` objects: use object syntax (`$invoice->seller->name`) or array syntax, loop over lists, `count()`, `isEmpty()` and `toArray()` (plain values, formatted). Writing throws a `LogicException`; a missing key reads as `null`.

Values that need formatting are `FormattedValue` objects: `{{ $invoice->totals->amountDue }}` prints the formatted text (`->formatted`), `->raw` holds the original value (`Brick\Money\Money` for amounts, `Quittio\Core\Support\Date` for dates, `Period` for periods, `Brick\Math\BigDecimal` for quantities, prices and rates). Templates contain no calculations.

`$invoice->label($key, $replace)` returns a label with `:placeholders` replaced (unknown keys return the key), `$invoice->title()` the title.

### Top level

| Key | Content |
|---|---|
| `number` | Invoice number (BT-1) |
| `numberLabel`, `dateLabel` | Labels for number and date ("Invoice number", "Invoice date"; documents: "Number", "Date") |
| `title` | Document title: `Presentation::$title`; for self-billed type codes the title `self_billed` of the country pack's correction policy (Germany: "Gutschrift", in English "Self-billed invoice (Gutschrift)"), like the titles of cancellations and corrections; otherwise the label of the type code (`title_380` "Invoice", `title_381` "Credit note", `title_384`, `title_389` "Self-billed invoice", `title_325`, `title_326`, `title_386`, `title_875`, `title_876`, `title_877`; other self-billed codes use `title_389`, other credit notes `title_381`, everything else `title_default`) |
| `issueDate`, `dueDate` | Dates (BT-2, BT-9) |
| `servicePeriod` | Invoicing period (BG-14), otherwise the delivery date (BT-72), otherwise the period from the earliest start to the latest end of the line periods when every line has one; `null` otherwise |
| `servicePeriodLabel` | "Delivery date" for a single date or a one-day period, otherwise "Service period" (German "Leistungsdatum" / "Leistungszeitraum") |
| `deliveryDate` | BT-72 when an invoicing period is also set (otherwise `servicePeriod` shows it) |
| `currency` | Invoice currency (BT-5) |
| `document` | [document](#document) |
| `documentInfo` | `null` for invoices, [documentInfo](#documentinfo) for documents |
| `seller`, `buyer` | [Parties](#parties) |
| `issuer`, `recipient` | The party in letterhead, sender line and footer, and the party in the address field: seller and buyer, on a self-billed invoice buyer and seller |
| `payee` | Payee (BG-10): `name`, `identifier`, `legalRegistration`; `null` without |
| `taxRepresentative` | Seller tax representative (BG-11): `name`, `vatId`, `address`; `null` without |
| `delivery` | `recipientName`, `date` (BT-72), `address` (with the recipient name as first line); `null` without delivery data |
| `references` | List of `key`, `label`, `value` for the filled references: `buyer_reference`, `purchase_order`, `sales_order`, `contract`, `project` (identifier and name), `despatch_advice`, `tender`, `invoiced_object`, `accounting_reference` |
| `precedingInvoices` | List of `number`, `issueDate` (BG-3) |
| `lineGroups` | [Line groups](#lines) |
| `lines` | All top-level [lines](#lines) |
| `allowances`, `charges` | [Document allowances and charges](#allowances-and-charges) |
| `vatRows` | [VAT breakdown](#vat-rows) |
| `totals` | [Totals](#totals); `null` when not calculated |
| `payment` | [Payment](#payment); `null` without payment instructions |
| `paymentTerms` | [Payment terms](#payment-terms) |
| `legalNotes` | List of texts: exemption reasons (BT-120, except category S) and notes with subject `REG`, `ABL` or `TXD` (BT-22), without duplicates |
| `notes` | Other notes: `subject` (BT-21), `content`, `html` |
| `attachments` | List of `id`, `description`, `filename`, `uri` (BG-24) |
| `texts` | `salutation` (plain text), `intro`, `closing` (HTML from Markdown) |
| `labels` | All labels of the locale, with the overrides of `Presentation::$labels` |
| `design` | [Design values](#design) |
| `meta` | `locale`, `custom` (values of `->custom()`), `source` |

### document

| Key | Content |
|---|---|
| `typeCode` | BT-3 as string, e.g. `380`; `null` for documents |
| `type` | Name of the type code in the locale; for documents the name of the document type, e.g. "Quote" |
| `isCreditNote` | Credit note type code |
| `isSelfBilled` | Self-billed invoice ("Gutschrift") |
| `profile`, `profileKey` | Name and key of the profile of the run, e.g. `EN 16931`, `en16931` |
| `kind` | `invoice`, or the document type (`quote`, `order_confirmation`, `delivery_note`, `proforma`, `payment_reminder`, `account_statement`) |
| `isInvoice` | `true` for invoices, `false` for documents |
| `showPrices` | `false` for delivery notes |
| `showTotals` | `false` for delivery notes, reminders and statements |


### Parties

`seller` and `buyer`:

| Key | Seller | Buyer |
|---|---|---|
| `name` | BT-27 | BT-44 |
| `tradingName` | BT-28 | BT-45 |
| `displayName` | trading name, otherwise name | trading name, otherwise name |
| `address` | see below | see below |
| `vatId` | BT-31 | BT-48 |
| `taxNumber` | BT-32 | – |
| `legalRegistration` | BT-30 value | BT-47 value |
| `additionalLegalInfo` | BT-33 | – |
| `customerNumber` | – | buyer identifier (BT-46) |
| `contact` | `name`, `phone`, `email` or `null` | `name`, `phone`, `email` or `null` |
| `electronicAddress` | BT-34 value | – |
| `identifiers` | list of BT-29 values | – |
| `isConsumer` | – | consumer flag |

`issuer` and `recipient` are the same nodes. Every `address` has `lines` (formatted by the country pack's address formatter, starting with the name; the country is added in upper case when it differs from the issuer's country), `street`, `additional`, `line3`, `postcode`, `city`, `subdivision`, `countryCode` and `country` (name in the locale). The fallback formatter writes "postcode city", for GB, IE, US, CA, AU, LV and UA "city subdivision postcode".

### Lines

`lines` and `lineGroups[].lines` contain:

| Key | Content |
|---|---|
| `position`, `id` | Line identifier (BT-126) |
| `name` | Item name (BT-153) |
| `description` | `text` (BT-154) and `html` (escaped plain text with line breaks, or Markdown with the option `markdown`) |
| `note` | Line note (BT-127) |
| `sellerId`, `buyerId` | Item identifiers (BT-155, BT-156) |
| `quantity` | Quantity (BT-129), formatted without trailing zeros |
| `unit` | `code` (BT-130) and `label` in the locale |
| `unitPrice` | Net price (BT-146), at least two decimals |
| `grossUnitPrice` | Price including VAT (gross price mode) |
| `listPrice` | Gross price before the price discount (BT-148) |
| `baseQuantity` | Price base quantity (BT-149) |
| `unitPriceBase` | Base quantity with unit, e.g. "100 pcs", for printing with the unit price; `null` for 1 |
| `showGrossPrice` | Print `grossUnitPrice`: the line's `showGrossPrice` (`LineBuilder::showGrossPrice()`), otherwise the presentation's `showGrossPrices`, and only if a price including VAT exists |
| `total` | Line net amount (BT-131) |
| `vatRate` | Rate (BT-152); `null` without rate and for margin schemes and travel agents |
| `vatCategory` | Category code (BT-151) |
| `period` | Line period (BG-26) |
| `attributes` | List of `name`, `value` (BG-32) |
| `allowances`, `charges` | Line allowances and charges: `reason`, `amount`, `percent`, `label` ("Allowance reason 10 %") |
| `subLines` | Sub lines with the same keys |
| `highlight` | `LineBuilder::highlight()`; the default templates shade the line and mark it in the secondary colour |
| `groupId` | Line group |
| `optional`, `alternativeTo`, `serialNumbers` | Document line options (quotes, delivery notes) |

`lineGroups` lists the groups defined with `InvoiceBuilder::group()` in their order, then one group with `id` and `title` `null` for the lines without (or with an unknown) group; empty groups are left out. Each group has `id`, `title`, `lines` and `subtotal` (sum of the line net amounts when `showSubtotal` is set, otherwise `null`). Print either `lineGroups` or `lines`, not both.

### Allowances and charges

`allowances` and `charges` (BG-20, BG-21) contain `reason` (text or code), `amount`, `percent`, `base`, `vatRate`, `vatCategory` and `label` ("Allowance reason 3 %"; with several VAT groups followed by the VAT rate or category, e.g. "(19 % VAT)").

### VAT rows

| Key | Content |
|---|---|
| `label` | Ready text: "19 % VAT on 1.000,00 €", "VAT exempt (…) on …", "No VAT (reverse charge) on …", "0% VAT (zero rate) on …", "Not subject to VAT: …", "Special scheme, no separate VAT statement: …" |
| `category`, `categoryLabel` | Category code and name |
| `rate` | Rate (BT-119) |
| `base` | Taxable amount (BT-116) |
| `amount` | VAT amount (BT-117); `null` for margin schemes and travel agents (`VATEX-EU-D`, `-F`, `-I`, `-J`) |
| `isSpecialScheme` | Margin scheme or travel agents |
| `exemptionReason`, `exemptionReasonCode` | BT-120, BT-121 |

### Totals

`lineNetSum`, `allowanceSum`, `chargeSum`, `netTotal`, `vatTotal`, `vatTotalInTaxCurrency` (BT-111), `taxCurrencyNote` ("VAT in USD: … (rate … of …)" when an exchange rate is set), `grossTotal`, `prepaid`, `rounding`, `thirdPartyPayments` (list of `description`, `amount`, XRechnung extension), `amountDue` and `amountDueLabel`.

`amountDueLabel` is "Amount due", "Amount to be refunded" on credit notes and negative invoices, "Amount paid out" on self-billed invoices. On a negative invoice (e.g. a cancellation with the strategy `negative_invoice`) `amountDue` is printed as a positive refund amount; `amountDue->raw` and the XML keep the negative BT-115.

### Payment

| Key | Content |
|---|---|
| `meansCode`, `means`, `meansText` | Payment means code (BT-81), its name, BT-82 |
| `remittance` | Remittance information (BT-83), otherwise the invoice number |
| `accounts` | List of `iban` (grouped in blocks of four), `bic`, `name` |
| `card` | `number` ("•••• 1234"), `holder`; or `null` |
| `directDebit` | `mandate`, `creditorId`, `account`; or `null` |
| `qrCode` | [Payment QR code](#payment-qr-codes) or `null` |
| `isRefund` | Credit note or negative amount due: show no bank details for a transfer by the buyer and no direct debit notice |
| `isPayout` | Self-billed invoice: the accounts receive the payout |

### Payment terms

`null` without payment terms (BT-20) and due date, otherwise `lines` (ready texts: the terms text, "Please pay the amount by …" or on self-billed invoices "We will transfer the amount to your account by …", one text per cash discount, late fee terms), `discounts` (list of `days`, `percent`, `until`, `amount`, `text`) and `dueDate`. On credit notes, offers and invoices without a positive amount due, discounts are shown as agreement ("For payment within 7 days …") without date or amount, and no due date text is added.

### Design

| Key | Content |
|---|---|
| `primaryColor`, `secondaryColor`, `textColor`, `mutedColor` | Hex colours (`secondaryColor` falls back to the primary colour). The default templates print company name, title and the header of the lines in the primary colour, section titles, the line above the amount due and the mark of highlighted lines in the secondary colour |
| `font` | Font family |
| `logo` | Prepared logo ([Logos](#logos)) or `null` |
| `letterLayout` | Name of the letter layout |
| `addressTop`, `addressLeft`, `addressWidth`, `addressHeight` | Address field in mm from the top left page corner |
| `pageMarginTop` | Top page margin of the default templates (20 mm) |
| `letterheadHeight` | Space above the address field: `addressTop` − `pageMarginTop` |
| `addressZoneTop` | Start of the recipient within the address field (DIN 5008 "Anschriftzone", 17.7 mm) |
| `senderLineHeight` | Height of the sender line (5 mm) |
| `noticeZoneHeight` | `addressZoneTop` − `senderLineHeight`: "Zusatz- und Vermerkzone" above the sender line |
| `foldMarks`, `punchMark` | Mark positions in mm from the top; empty unless the option `fold_marks` is set |
| `paper` | Paper of the presentation |
| `watermark`, `watermarkCss` | Watermark text, and the same as escaped CSS string for the HTML overlay |
| `senderLine` | Configured sender line, otherwise "Name · Street · Postcode City" of the issuer |
| `footerColumns` | Configured columns, otherwise generated: the issuer's address lines; phone, e-mail, VAT identifier, tax number; account holder, IBAN, BIC of the first credit transfer (not on refunds and self-billed invoices); legal registration and additional legal information |
| `footerHasAddress` | The generated footer starts with the issuer's address |
| `showItemNumbers`, `showGrossPrices` | Presentation switches |

### documentInfo

Only for [documents](documents.md):

| Key | Content |
|---|---|
| `type` | Document type value |
| `validUntil` | Quote validity |
| `reference`, `referenceDate` | Preceding document (`reference()`) |
| `deliveryDate` | Delivery date |
| `packages` | List of `id`, `type`, `weight` ("2.5 kg"), `dimensions`, `trackingNumber` |
| `lines` | Line options keyed by line identifier: `optional`, `alternativeTo`, `serialNumbers` |
| `dunning` | `level`, `deadline`, `deadlineText` ("Please pay the total amount by …"), `openItems`, `fees` (`description`, `amount`), `interest` (`description`, `days`, `amount`), `openTotal`, `feeTotal`, `interestTotal`, `total`; `null` for other types |
| `statement` | `period`, `items`, `openingBalance`, `balance`; `null` for other types |
| `showAcceptance` | Print the acceptance field (quotes) |

Open items (`dunning.openItems[]`, `statement.items[]`) have `invoiceNumber`, `invoiceDate`, `dueDate`, `description`, `amount`, `paid` and `open`.

## Presentation

`Quittio\Core\Data\Presentation\Presentation` holds everything that only affects the PDF and HTML; it is never written to the XML.

| Property | Default | Effect | Laravel configuration |
|---|---|---|---|
| `template` | `null` | Template of this invoice ([Selecting a template](#selecting-a-template)) | `pdf.template` |
| `logo` | `null` | Logo ([Logos](#logos)) | `pdf.logo` |
| `colors` | `null` | `Colors(primary: '#1e40af', text: '#111827', muted: '#6b7280', secondary: null)` | `pdf.colors.primary`, `text`, `muted`, `secondary` |
| `font` | `null` (`inter`) | Font family ([Fonts](#fonts)) | `pdf.font` |
| `senderLine` | `null` (generated) | Sender line above the address | `pdf.sender_line` |
| `letterLayout` | `null` (country default) | [Letter layout](#letter-layouts) | `pdf.layout` |
| `salutation` | `null` | Plain text before the intro | `pdf.texts.salutation` |
| `introText`, `closingText` | `null` | Markdown texts before the lines and at the end | `pdf.texts.intro`, `pdf.texts.closing` |
| `footerColumns` | `[]` (generated) | `list<list<string>>`, one list of lines per column | `pdf.footer_columns` |
| `lineGroups` | `[]` | `list<LineGroup>` (`id`, `title`, `showSubtotal = true`) | – (`InvoiceBuilder::group()`) |
| `showGrossPrices` | `false` | Print the price including VAT below the net unit price | – |
| `showItemNumbers` | `false` | Print the seller's item number (BT-155) after the item name | `pdf.show_item_numbers` |
| `paymentQr` | `auto` | [Payment QR code](#payment-qr-codes): `auto`, `girocode`, `swiss-qr-bill`, `none` | `pdf.payment_qr.type` |
| `watermark` | `null` | [Watermark](#watermark) text | – (option `watermark`) |
| `paper` | `A4` | [Paper size](#paper-size) | `pdf.paper` |
| `labels` | `[]` | Label overrides, e.g. `['amount_due' => 'Zu zahlen']` | – |
| `title` | `null` | Title instead of the type code label; also used in the file name and the PDF title | – |
| `explicit` | `[]` | Properties whose value wins even when it equals the default | – |

Line-level hints are `LinePresentation` (`groupId`, `highlight`, `showGrossPrice`); the builder sets them with `LineBuilder::group($groupId)`, `highlight()` and `showGrossPrice(?bool $show = true)` (prints the price including VAT of this line, or with `false` not, whatever `showGrossPrices` says; `null` follows the presentation).

```php
use Quittio\Core\Data\Presentation\Colors;
use Quittio\Core\Data\Presentation\Presentation;

$invoice = InvoiceBuilder::make()
    // ...
    ->presentation(fn (Presentation $p) => $p->with(['logo' => storage_path('app/logo.png'), 'introText' => 'Thank you for your order.']))
    ->group('hardware', 'Hardware')                                     // line group with subtotal
    ->line(Line::make('Router')->quantity(2, 'C62')->netPrice('89.00')->vat(VatPreset::Standard19)->group('hardware'))
    ->build();

Quittio::make($invoice)
    ->presentation(new Presentation(colors: new Colors(primary: '#0f766e'), labels: ['amount_due' => 'Total to pay']))
    ->render();
```

The design of a run is the invoice presentation over the presentation of the call (`->presentation(...)`) over the configured design, property by property: a value that equals the default of an empty `Presentation` (`false`, `[]`, `'auto'`, `'A4'`, `null`) counts as not set and keeps the value below; objects such as `colors` replace the value below as a whole; labels are merged key by key. To reset a configured value for one invoice, name the property in `explicit`: `new Presentation(showGrossPrices: false, paper: 'A4', footerColumns: [], explicit: ['showGrossPrices', 'paper', 'footerColumns'])` switches gross prices off, prints on A4 although `pdf.paper` is Letter and prints no footer columns.

Values are checked when rendering, otherwise a `ConfigurationException` is thrown: colours must be hex values (`#1e40af` or `#fff`), fonts plain family names (letters, digits, space, `_` and `-`, at most 64 characters), letter layouts and payment QR code types must be known. An unknown `pdf.payment_qr.type` already fails when the Laravel configuration is mapped (first use of the facade).

Render options that affect the design (per call with `->option()`, without Laravel also in `options`): `fold_marks` (bool), `markdown` (bool), `paper` (string), `watermark` (string), `girocode_amount` (`due`, `discounted`) and `logo_cache` (directory).

## Letter layouts

A letter layout (`Quittio\Core\View\LetterLayout`) fixes the address field on the first page: `name`, `addressTop` (45), `addressLeft` (20), `addressWidth` (85), `addressHeight` (45), `senderLineHeight` (5), `foldMarks` (`[]`), `punchMark` (`null`) and `addressZoneTop` (17.7), all in mm. `LetterLayout::free()` is the layout without norm.

The layout is `Presentation::$letterLayout` (`pdf.layout`), otherwise the country pack default, otherwise `free`. Country packs provide layouts with `letterLayouts()`; an unknown name fails with a `ConfigurationException` listing the known layouts.

The German pack provides `din5008a` (address field 27 mm from the top, fold marks at 87 and 192 mm), `din5008b` (45 mm, fold marks at 105 and 210 mm, default) and `free`; the punch mark is at 148.5 mm. The sender line closes the "Zusatz- und Vermerkzone", the recipient starts in the "Anschriftzone" 17.7 mm below the top of the field. Fold and punch marks are printed with `pdf.fold_marks = true` (option `fold_marks`). See [Country packs](country-packs.md).

## Logos

- Logos are local files, data URIs or `http`/`https` URLs of PNG, JPEG or SVG images, at most 10 MB and 25 megapixels. Other stream wrappers (`ftp://`, `php://`, `phar://` …), a leading `//`, backslashes and surrounding whitespace fail with a `ConfigurationException`, as does a local file that does not exist.
- A data URI is decoded strictly, checked and re-encoded, so only validated bytes reach the renderer.
- Remote logos are downloaded by Quittio on every render (no cache): 10 s timeout, at most 3 redirects, each checked again, only from hosts whose every IPv4 and IPv6 address is public (no loopback, private, link-local, shared, reserved, documentation or multicast range, also not inside NAT64 addresses); the connection is pinned to the checked address and TLS is verified for the host name. A refused address fails with a `ConfigurationException`; an unreachable logo is left out. The downloaded copy is embedded as data URI (also in HTML output).
- Raster logos larger than 600 × 200 px are scaled down with GD (transparency is kept); scaled copies of local files are cached in the logo cache (`pdf.cache_path`/`logos`, without Laravel `temp_dir`/`logos`, default `sys_get_temp_dir()/quittio-mpdf/logos`).
- For PDF/A (ZUGFeRD/Factur-X, XRechnung in PDF, or `pdf.pdfa = true`), every logo is re-encoded as RGB PNG on white, because PDF/A forbids the alpha channel mPDF writes; SVG is rasterised with the PHP extension `imagick` (SVG coder only, 300 dpi but at most twice the logo size). Without `imagick` an SVG logo fails with a `ConfigurationException` in PDF/A documents.
- SVG logos must be self-contained: no `href` or `url()` to files, URLs or nested SVG (embedded PNG, JPEG, GIF or WebP data URIs are allowed) and no entity declarations.
- mPDF never fetches anything itself ([PDF renderers](#pdf-renderers)).
- In HTML output a local logo is referenced by its file path, which browsers cannot load; use a URL or data URI for logos in HTML that is shown in a browser.
- Local logo paths and URLs are trusted configuration: do not take `Presentation::$logo` or issuer branding from end users unless your application validates them (e.g. an uploaded file stored by the application).

## Fonts

Inter (Regular, Bold, Italic, Bold Italic) is bundled and the default (`pdf.font = 'inter'`). The font name is used as CSS `font-family` in the templates and as default font of mPDF. Register more fonts in Laravel:

```php
'font' => 'corporate',
'fonts' => ['corporate' => ['R' => 'Corporate-Regular.ttf', 'B' => 'Corporate-Bold.ttf', 'I' => 'Corporate-Italic.ttf', 'BI' => 'Corporate-BoldItalic.ttf']],
'font_directories' => [resource_path('fonts')],
```

Use lower case family names, as mPDF looks fonts up in lower case. Fonts must be embeddable TrueType fonts (PDF/A embeds all fonts). `php artisan vendor:publish --tag=quittio-fonts` copies Inter to `resources/fonts/quittio/inter`. The Laravel renderer searches `pdf.font_directories`, then `resources/fonts/quittio` and its subdirectories automatically, so fonts placed there only need an entry in `pdf.fonts`. Font files are taken from the first directory that has them: the configured directories come before the bundled Inter and the fonts of mPDF, so a published and edited Inter replaces the bundled one. Without Laravel pass the fonts to the renderer: `new MpdfRenderer(tempDir: …, fonts: [...], fontDirectories: [...], defaultFont: 'corporate')`.

## Paper size

The paper is the first of: the invoice presentation's `paper`, the presentation of the call and the configured presentation (each only when it is not `A4` or names `paper` in `explicit`), the option `paper` (`pdf.paper`), the country pack default (Germany: A4). `pdf.paper = null` uses the country default; a configured size wins, also `A4`. The value is a page format of mPDF such as `A4`, `A5` or `Letter`.

## Watermark

A watermark marks drafts and previews: `->option('watermark', 'DRAFT')` or `Presentation::$watermark`. Watermarked PDFs are rendered without PDF/A (PDF/A does not allow the transparency of watermarks) and never contain e-invoice XML: a watermark together with any format that produces XML (also next to `pdf` in `formats([...])`) throws a `ConfigurationException`, so a draft can never pass as an e-invoice. The default templates print it with mPDF's `<watermarktext>` (alpha 0.08) and, for browsers, as rotated CSS overlay (`design.watermarkCss`). The labels `draft` and `preview` ("DRAFT", "PREVIEW"; German "ENTWURF", "VORSCHAU") can be used as text.

## Page numbers, headers and footers

The default templates put the footer columns and "Page {PAGENO} of {nbpg}" into an `<htmlpagefooter>` that every page uses (`@page { footer: html_quittioFooter; }`). `{PAGENO}` and `{nbpg}` are replaced by mPDF; headers work the same way with `<htmlpageheader>`.

## Payment QR codes

| `paymentQr` | Generator |
|---|---|
| `auto` (default) | A registered generator (`withPaymentQrGenerator()`, Laravel `pdf.payment_qr.generator`), otherwise what the country pack names in `CountryDefaults::$paymentQr`: `none` prints no code; otherwise the country pack's generator (`paymentQr()`), otherwise the EPC GiroCode for `girocode` (the default, also without country pack) and no code for other types |
| `girocode` | EPC GiroCode |
| `swiss-qr-bill` | The generator registered with `withPaymentQrGenerator()` (Laravel: `pdf.payment_qr.generator`). Quittio ships no Swiss QR bill generator; without one the render run fails with a `ConfigurationException` |
| `none` | No code |

No code is printed on refunds (credit notes, negative invoices) and self-billed invoices. The EPC GiroCode (`EpcGiroCodeGenerator`, EPC069-12 version 002) is created when the currency is EUR, the payment means is a (SEPA) credit transfer (30, 58) with a valid IBAN and the amount due is between 0.01 and 999,999,999.99. The payload contains the BIC (optional), the IBAN, the amount, the beneficiary (payee name, otherwise account name, otherwise seller name, at most 70 characters) and the remittance information (BT-83, otherwise the invoice number, at most 140 characters), at most 331 bytes.

`pdf.payment_qr.girocode_amount` (option `girocode_amount`) is `due` (amount due) or `discounted` (amount after the first cash discount); then `qrCode.note` says until when the code is valid ("QR code with the amount after cash discount (…), valid until …"). Payment reminders get a code over the total of the reminder ([documents](documents.md#payment-reminders)).

`payment.qrCode` holds `type` (e.g. `girocode`), `payload`, `dataUri` (PNG data URI rendered with GD, SVG without GD; PNG because mPDF's SVG output violates PDF/A), `amount` and `note`. `Quittio\Core\Pdf\Qr\QrImage::svg()`, `png()` and `dataUri()` render any payload. Own generators implement `PaymentQrGenerator` (`key()`, `supports(InvoiceData)`, `payload(InvoiceData, array $options)`) and are returned by a country pack's `paymentQr()`.

## Texts and Markdown

Line descriptions and notes are also part of the e-invoice XML and are printed as plain text (`lines[].description.html`, `notes[].html`: escaped, line breaks kept). With `pdf.markdown = true` (option `markdown`) they are rendered as Markdown (paragraphs, lists, bold, italic; HTML is shown as text, links keep only their text). Formatting changes the displayed text compared with the XML, e.g. `20*30*40 cm` is shown as "20*30*40 cm" with "30" in italics and without the asterisks, so keep it off for texts that must match the XML. Intro and closing texts (`texts.intro`, `texts.closing`) are PDF-only and always Markdown; the salutation is plain text.

## Translations and locales

The locale of a run is, in this order: `->locale()` of the call, the invoice's locale (`InvoiceBuilder::locale()`), `defaults.locale` (`locale` in `Quittio::create()`), the country pack default (Germany: `de`). Labels and code names (units, VAT categories, payment means, type codes …) exist in German, English and French; other locales fall back to English. The XML texts generated for the run (e.g. payment terms) use the same locale.

| Source | Where |
|---|---|
| Shipped labels | `packages/core/resources/lang/{de,en,fr}/labels.php` (namespace `quittio`) |
| Code names | `codes.php`, payment terms texts `terms.php` |
| Laravel overrides | `lang/vendor/quittio/{locale}/labels.php` (`php artisan vendor:publish --tag=quittio-lang`) |
| Country pack overrides | `quittio-{code}::labels.{key}` in the pack's `translationPath()` |
| Per invoice or call | `Presentation::$labels` |

Without Laravel the translator is `Quittio\Core\I18n\ArrayTranslator` (files `{path}/{locale}/{group}.php`, later paths override earlier ones, fallback `en`). Add a directory with `Translations::translator()->addPath($path, 'quittio')` or replace the translator with `Translations::use(new MyTranslator)` (`Quittio\Core\I18n\Translator`: `get()`, `has()`, `locale()`, `addPath()`). The Laravel integration bridges to Laravel's translator.

Values are formatted by `IntlFormatter` from their exact decimal value: amounts with two decimals and the currency symbol (`€`, `$`, `£`, `CHF`, otherwise the code; English puts `$` and `£` in front), prices with at least two decimals, quantities and rates without trailing zeros, separators from `ext-intl` (fallback: German and English conventions), dates `dd.MM.yyyy` (de), `dd/MM/yyyy` (fr, it, es, pt, nl, en), `MM/dd/yyyy` (`en_US`) and the medium date format of `ext-intl` for other locales. Country names come from `ext-intl`.

## PDF renderers

| Renderer | Use |
|---|---|
| `MpdfRenderer` (default) | HTML to PDF/A-3b with mPDF in pure PHP; embeds the XML and writes the XMP metadata in one pass |
| `CallbackRenderer(Closure $callback)` | Own rendering (Browsershot, Gotenberg …): `fn (string $html, RenderRequest $request): string` returns the PDF; PDF/A, metadata and the XML are added by `MpdfPostProcessor` |
| `PassthroughRenderer(string $pdf)` | A finished PDF of the application; only PDF/A and the XML are added |

```php
use Quittio\Core\Pdf\Renderers\CallbackRenderer;
use Quittio\Core\Pdf\RenderRequest;
use Spatie\Browsershot\Browsershot;

Quittio::extend('renderer', 'browsershot', fn ($app, array $config) => new CallbackRenderer(
    fn (string $html, RenderRequest $request) => Browsershot::html($html)->format($request->paper)->pdf(),
));
// config/quittio.php: 'pdf' => ['renderer' => 'browsershot', 'renderers' => ['browsershot' => [...]]]
```

The factory receives the container and `pdf.renderers.{name}`. Without Laravel pass the renderer to `Quittio::create(renderer: …)` or `$quittio->withRenderer(…)`. A renderer implements `PdfRenderer` (`render(RenderRequest $request): string`, `supportsEmbedding(): bool`). `supportsEmbedding()` returns `true` when the renderer creates the PDF/A and embeds the XML itself (`MpdfRenderer`, and `CallbackRenderer`/`PassthroughRenderer` through their post processor); for `false` Quittio passes the PDF to `MpdfPostProcessor` whenever the request asks for PDF/A or an embedded XML, so a plain HTML-to-PDF renderer is enough; `RenderRequest` holds `html`, `title`, `author`, `subject`, `paper`, `pdfa`, `embedded` (`EmbeddedXml`: `content`, `filename`, `relationship`, `conformanceLevel`, `description`, `documentType`, `version`) and `font`. The post processor imports the pages with FPDI; the result is PDF/A only if the source PDF allows it (embedded fonts, no encryption, no JavaScript), so check it with [veraPDF](validation.md#verapdf-l6).

A PDF is PDF/A-3b when it embeds XML, otherwise when `pdf.pdfa` is `true` (default) and it has no watermark. Its metadata: title "{title} {number}", author the seller, creator "Quittio".

mPDF settings of `MpdfRenderer`: margins 20 (left), 15 (right), 20 (top), 25 (bottom), header and footer 8 mm (the default templates set their own page margins), `pdf.renderers.mpdf.simple_tables` (default `false`; `true` drops the borders of table cells and is not faster), `pdf.renderers.mpdf.pdfa_auto` (default `false`; lets mPDF fix PDF/A issues itself), `pdf.cache_path` as temporary directory (without Laravel `temp_dir`, default `sys_get_temp_dir()/quittio-mpdf`). mPDF cannot load anything over HTTP (a denying HTTP client is installed), has no remote base path and does not follow redirects; remote resources of a template are rendered as missing.

### mPDF rules for templates

- Layout with tables, not flexbox or grid.
- Inside table cells, mPDF ignores `margin` and `padding` of blocks (`div`, `p`). Create spacing there with the padding of a table cell, e.g. a nested table with one row per block.
- Position elements on the page with `position: absolute` (measured from the page edge); `position: fixed` is measured from the page margins. The address field must be a direct child of `<body>`.
- Repeated table headers via `<thead>`.
- Headers and footers via `<htmlpageheader>` / `<htmlpagefooter>`, page numbers with `{PAGENO}` and `{nbpg}`.
- `page-break-inside: avoid` for the totals block.
- Images as PNG or JPEG; fonts must be embeddable.

## HTML output and preview

`->format('html')` returns the rendered template without PDF (`$result->html()`); documents use `->htmlOnly()`. The HTML is the same markup mPDF receives; the watermark is shown with CSS.

`/_quittio/preview` (local environment, or `quittio.preview.enabled = true`; path `quittio.preview.path`, middleware `quittio.preview.middleware`, not registered while routes are cached) lists the sample scenarios (`simple`, `many-lines`, `long-texts`, `mixed-vat`, `reverse-charge`, `intra-eu`, `export`, `cancellation`, `sub-lines`, `foreign-currency`, `b2c-gross`, `allowances`, `attachments`, `peppol`) and shows them as HTML or, with `?format=pdf`, as PDF with a "PREVIEW" watermark, the configured design and validation in mode `warn`. `?template=` accepts only `default`, the configured `pdf.template` and the views under `quittio::templates.` (not their partials), `?locale=` a locale code; other values return 404. On the command line:

```bash
php artisan quittio:preview --scenario=many-lines --format=pdf --locale=en --output=/tmp/preview.pdf
```

The command writes to `storage/app/quittio/preview/{scenario}.{format}` by default; `--template` accepts the same templates as the route.

## Mandatory field guard

After rendering a PDF or HTML invoice, the guard checks that the template printed every mandatory value:

- number and issue date;
- the full name of seller and buyer (`seller.name` or the address lines, which start with it; the trading name `displayName` alone is not enough) and their address (all address lines, or street, postcode and city);
- name, quantity and unit (`unit.label`, section 14 (4) no. 5 UStG: "Menge und Art") of every line (in `lineGroups` or `lines`);
- label, or base and rate, and the VAT amount of every VAT row;
- the cash discount terms (`paymentTerms.discounts[].text` or `paymentTerms.lines`), the amount due;
- every legal note (exemption reasons and regulatory notes, e.g. "Gutschrift" or the section 14b UStG retention notice), every preceding invoice number;
- the tax representative (name, address, VAT identifier);
- what the country pack requires; Germany: tax number or VAT identifier (also of the tax representative), service date (`servicePeriod`, `deliveryDate` or `delivery.date`, or the period of every line), the title and the note "Gutschrift" on self-billed invoices, the retention notice of property related services, the reverse charge or exemption note per VAT row, the VAT identifiers of buyer and seller for reverse charge and intra-community supplies (see [Country packs](country-packs.md)).

Lists are checked per entry: printing only the first VAT row or note is not enough. Values that are null are not required. A value counts as printed when the template reads it as a leaf, also in a condition such as `@if ($invoice->buyer->vatId)` or while a loop reads the entries of a list, or with `toArray()`; `count()`, `isEmpty()`, `isset()` and reading null values do not count. Values printed through `issuer` and `recipient` count for the seller and the buyer, the generated footer counts as the issuer's address; a custom `senderLine` or custom `footerColumns` do not replace the seller's address.

Mandatory fields are `Quittio\Core\Countries\MandatoryField` objects (`accessKey`, `description`, `legalBasis`): the access key is a view model path where `[*]` stands for every index, `&` joins paths that must all be printed and `|` separates alternatives. Country packs return them from `mandatoryOutput(InvoiceData $invoice)`.

A template that omits one fails with `MandatoryFieldMissing` (`validation.mandatory_field_guard = exception`, default outside production) or logs it (`log`, default in production); `off` disables the check. The event `MandatoryFieldMissing` is dispatched in both modes. Details in [Validation](validation.md#mandatory-field-guard).
