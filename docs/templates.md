# Templates and design

This page explains how Quittio prints an invoice or a [document](documents.md) (quote, reminder …) as PDF or HTML, and how you change its look. Read it when you want your own logo, colours, texts or layout. The first sections need no template knowledge; the [view model](#view-model) reference at the end is for writing own templates. The design only affects the PDF and HTML, never the e-invoice XML ([What an e-invoice is](concepts.md#what-an-e-invoice-is)).

## Design levels

You can change the design on four levels. Start with level 1 and go further only when it is not enough.

| Level | Use it when you want to | What you do |
|---|---|---|
| 1 – Configuration | Set logo, colours, font, texts, footer, sender line, letter layout, paper or QR code | Set `pdf.*` in `config/quittio.php`, or pass a [`Presentation`](#presentation) per invoice or per call ([Logo, colours and texts](#logo-colours-and-texts-level-1)) |
| 2 – Partials | Change one part of the default template, e.g. the payment block | `php artisan vendor:publish --tag=quittio-views` and change single partials ([Changing single partials](#changing-single-partials-level-2)) |
| 3 – Own template | Use a layout of your own | `php artisan make:quittio-template my-design`, then `->template('invoices.my-design.layout')` or `pdf.template` ([Own templates](#own-templates-level-3)) |
| 4 – Own renderer | Create the PDF with another engine, e.g. Browsershot or Gotenberg | `Quittio::extend('renderer', …)` ([PDF renderers](#pdf-renderers)); PDF/A and the embedded XML stay with the package |

PDF/A is the PDF variant for long-term archiving. A ZUGFeRD/Factur-X invoice is a PDF/A-3 file with the XML embedded ([Formats](concepts.md#formats)).

Templates only display data. Quittio prepares totals, VAT, the texts of the payment terms, QR codes and labels in the [view model](#view-model) before the template runs. The same data also produces the XML. After rendering, the [mandatory field guard](#mandatory-field-guard) checks that the template printed every value an invoice must show.

## Default templates

Quittio ships one default design in two template engines. Both print the same document.

| Engine | Template | Used by |
|---|---|---|
| Blade | `quittio::templates.default.layout` with partials in `quittio::templates.default.partials` | Laravel (`pdf.template`) |
| Plain PHP | `packages/core/resources/templates/default.php` | `Quittio::create()` without Laravel (template `default`); Laravel falls back to it for names that are no Blade view |

The PDF is created by mPDF, a PHP library that converts the HTML of the template into a PDF ([PDF renderers](#pdf-renderers)). To see the result with your configuration, render the sample invoices with `php artisan quittio:preview` or open the preview route ([HTML output and preview](#html-output-and-preview)).

### Page layout

The default templates print A4 pages ([Paper size](#paper-size)) with a left margin of 20 mm and a right margin of 19 mm, in the font Geist ([Fonts](#fonts)). The issuer is the party that sends the document: the seller, or the buyer on a self-billed invoice (German "Gutschrift", see [German terms](concepts.md#german-terms)).

- **First page:** the logo (at most 14 mm high) or the issuer's name in the primary colour at the top left, 15 mm from the top edge. Below it is the address field at the position of the [letter layout](#letter-layouts): the sender line at its top and the recipient directly below. The invoice details (number, dates, references …) are on the right at the same height. Then follow the title, the line table, the totals with the gross total (or the amount due) in the primary colour, and the payment block.
- **Following pages:** a header with the logo or name on the left, and the title with number, the recipient with customer number and the invoice date on the right. The line table continues below the header with its column heads.
- **Every page:** the footer with up to four columns (address; phone and e-mail; register and legal information; VAT identifier and tax number) and the page number.

Which elements use which colour is listed in [Design](#design).

### Carry-over across pages

When the line table continues on the next page, the default templates print a running total at the break:

- The last row of the page reads "Continued on page 2", "Carried forward" and the sum of the line amounts so far. The German output is "Fortsetzung auf Seite 2" (continued on page 2) and "Übertrag" (carried forward).
- The first row of the next page reads "Brought forward from page 1" (German "Übertrag von Seite 1") with the same amount.

The mPDF renderer does this. It lays the document out once. Only if the line rows land on more than one page, it lays the document out again with space reserved for the carry rows, finds the page breaks, splits the table there and inserts the rows. Single-page documents are rendered once. Documents without prices (delivery notes) get no carry-over.

Own templates can use the same mechanism ([Carry-over in own templates](#carry-over-in-own-templates)).

## Logo, colours and texts (level 1)

Most designs need only configuration. Set the defaults for all invoices in `config/quittio.php` ([Configuration](configuration.md)):

```php
// config/quittio.php
'pdf' => [
    'logo' => storage_path('app/logo.png'),                 // PNG, JPEG or SVG
    'colors' => ['primary' => '#0f766e', 'text' => '#15171c', 'muted' => '#5f6470', 'secondary' => null],
    'font' => 'geist',                                       // or 'inter', or a registered font
    'texts' => ['salutation' => null, 'intro' => 'Thank you for your order.', 'closing' => 'Kind regards'],
    'sender_line' => null,                                   // null: generated from the seller
    'footer_columns' => [],                                  // []: generated from the seller
],
```

To change the design of one invoice, call `->presentation()` on the builder. The design is stored with the invoice data, so the invoice looks the same when it is rendered again. To change it for one render call only, e.g. for a second brand, call `->presentation()` on `Quittio::make()`:

```php
use Quittio\Core\Data\Presentation\Colors;
use Quittio\Core\Data\Presentation\Presentation;
use Quittio\Laravel\Facades\Quittio;

$invoice = Quittio::invoice()
    // ...
    ->presentation(['introText' => 'Thank you for your order.', 'letterLayout' => 'din5008a'])   // this invoice
    ->build();

$result = Quittio::make($invoice)
    ->presentation(new Presentation(logo: storage_path('app/brand-b.png'), colors: new Colors(primary: '#7c3aed')))  // this call
    ->render();
```

The invoice's own presentation wins over the presentation of the call, which wins over the configuration. The rules are in [Presentation](#presentation).

| You want to change | Setting | Details |
|---|---|---|
| Logo | `pdf.logo`, `Presentation::$logo` | [Logos](#logos) |
| Colours | `pdf.colors`, `Presentation::$colors` | [Presentation](#presentation), [Design](#design) |
| Font | `pdf.font`, `pdf.fonts` | [Fonts](#fonts) |
| Salutation, intro and closing text | `pdf.texts.*` | [Texts and Markdown](#texts-and-markdown) |
| Footer columns and sender line | `pdf.footer_columns`, `pdf.sender_line` | [Presentation](#presentation), [Design](#design) |
| Position of the address field, fold marks | `pdf.layout`, `pdf.fold_marks` | [Letter layouts](#letter-layouts) |
| Paper size | `pdf.paper` | [Paper size](#paper-size) |
| Payment QR code | `pdf.payment_qr.*` | [Payment QR codes](#payment-qr-codes) |
| Single printed texts, e.g. "Amount due" | `Presentation::$labels`, language files | [Translations and locales](#translations-and-locales) |
| Title of the document | `Presentation::$title` | [Presentation](#presentation) |
| Draft marker | option `watermark` | [Watermark](#watermark) |

### Presentation

`Quittio\Core\Data\Presentation\Presentation` holds everything that only affects the PDF and HTML. It is never written to the XML.

| Property | Default | Effect | Laravel configuration |
|---|---|---|---|
| `template` | `null` | Template of this invoice ([Selecting a template](#selecting-a-template)) | `pdf.template` |
| `logo` | `null` | Logo ([Logos](#logos)) | `pdf.logo` |
| `colors` | `null` | `Colors(primary: '#2741d9', text: '#15171c', muted: '#5f6470', secondary: null)` | `pdf.colors.primary`, `text`, `muted`, `secondary` |
| `font` | `null` (`geist`) | Font family ([Fonts](#fonts)) | `pdf.font` |
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
| `labels` | `[]` | Label overrides, e.g. `['amount_due' => 'Total to pay']` | – |
| `title` | `null` | Title instead of the type code label; also used in the file name and the PDF title | – |
| `explicit` | `[]` | Properties whose value wins even when it equals the default | – |

BT-155 and the other BT numbers name the fields of the European standard EN 16931 ([Business terms](concepts.md#en-16931-and-business-terms)).

Hints for single lines are `LinePresentation` objects (`groupId`, `highlight`, `showGrossPrice`). The line builder sets them:

- `LineBuilder::group($groupId)` puts the line into a line group.
- `highlight()` shades the line.
- `showGrossPrice(?bool $show = true)` prints the price including VAT of this line, or with `false` not, whatever `showGrossPrices` says. `null` follows the presentation.

```php
use Quittio\Core\Builder\Line;
use Quittio\Core\Data\Presentation\Colors;
use Quittio\Core\Data\Presentation\Presentation;
use Quittio\Laravel\Facades\Quittio;

$invoice = Quittio::invoice()
    // ...
    ->presentation(['logo' => storage_path('app/logo.png'), 'introText' => 'Thank you for your order.'])
    ->group('hardware', 'Hardware')                                     // line group with subtotal
    ->line(Line::make('Router', netPrice: '89.00', quantity: 2)->group('hardware'))   // group(): Line::make()
    ->build();

Quittio::make($invoice)
    ->presentation(new Presentation(colors: new Colors(primary: '#0f766e'), labels: ['amount_due' => 'Total to pay']))
    ->render();
```

**How presentations are merged.** The design of a run is built property by property from three layers: the invoice presentation over the presentation of the call (`->presentation(...)`) over the configured design.

- A value that equals the default of an empty `Presentation` (`false`, `[]`, `'auto'`, `'A4'`, `null`) counts as not set and keeps the value of the layer below.
- Objects such as `colors` replace the value below as a whole.
- Labels are merged key by key.
- To reset a configured value for one invoice, name the property in `explicit`. Example: `new Presentation(showGrossPrices: false, paper: 'A4', footerColumns: [], explicit: ['showGrossPrices', 'paper', 'footerColumns'])` switches gross prices off, prints on A4 although `pdf.paper` is Letter, and prints no footer columns.

**Checks.** Values are checked when rendering; invalid values throw a `ConfigurationException`:

- colours must be hex values (`#2741d9` or `#fff`);
- fonts must be plain family names (letters, digits, space, `_` and `-`, at most 64 characters);
- letter layouts and payment QR code types must be known.

An unknown `pdf.payment_qr.type` already fails when the Laravel configuration is mapped (first use of the facade).

**Render options.** Some design settings are render options: per call with `->option()`, without Laravel also in the `options` key of `Quittio::create()`. They are `fold_marks` (bool, default `true`), `markdown` (bool), `paper` (string), `watermark` (string), `girocode_amount` (`due`, `discounted`) and `logo_cache` (directory).

### Logos

Set the logo with `pdf.logo` or `Presentation::$logo`. The default templates print it at the top left of the first page and in the header of the following pages.

- Logos are local files, data URIs or `http`/`https` URLs of PNG, JPEG or SVG images, at most 10 MB and 25 megapixels. Other stream wrappers (`ftp://`, `php://`, `phar://` …), a leading `//`, backslashes and surrounding whitespace fail with a `ConfigurationException`, as does a local file that does not exist.
- A data URI is decoded strictly, checked and re-encoded, so only validated bytes reach the renderer.
- Remote logos are downloaded by Quittio on every render (no cache), with a 10 s timeout and at most 3 redirects, each checked again. Quittio downloads only from hosts whose every IPv4 and IPv6 address is public: no loopback, private, link-local, shared, reserved, documentation or multicast range, also not inside NAT64 addresses. The connection is pinned to the checked address, and TLS is verified for the host name. A refused address fails with a `ConfigurationException`; an unreachable logo is left out. The downloaded copy is embedded as data URI (also in HTML output).
- Raster logos larger than 600 × 200 px are scaled down with GD (transparency is kept). Scaled copies of local files are cached in the logo cache: `pdf.cache_path`/`logos`, without Laravel `temp_dir`/`logos`, default `sys_get_temp_dir()/quittio-mpdf/logos`.
- For PDF/A (ZUGFeRD/Factur-X, XRechnung in PDF, or `pdf.pdfa = true`), every logo is re-encoded as RGB PNG on white, because PDF/A forbids the alpha channel mPDF writes. SVG is rasterised with the PHP extension `imagick` (SVG coder only, 300 dpi but at most twice the logo size). Without `imagick`, an SVG logo fails with a `ConfigurationException` in PDF/A documents.
- SVG logos must be self-contained: no `href` or `url()` to files, URLs or nested SVG (embedded PNG, JPEG, GIF or WebP data URIs are allowed) and no entity declarations.
- mPDF never fetches anything itself ([PDF renderers](#pdf-renderers)).
- In HTML output, a local logo is referenced by its file path, which browsers cannot load. Use a URL or data URI for logos in HTML that is shown in a browser.
- Local logo paths and URLs are trusted configuration. Do not take `Presentation::$logo` or issuer branding from end users unless your application validates them (e.g. an uploaded file stored by the application).

### Fonts

Two fonts are bundled (SIL Open Font License):

| Family | Files | Script |
|---|---|---|
| `geist` (default, `pdf.font = 'geist'`) | Regular, Bold, Italic, Bold Italic; `geistmedium` with Medium and SemiBold | Latin and Cyrillic |
| `inter` | Regular, Bold, Italic, Bold Italic | Latin, Cyrillic and Greek |

Set `pdf.font = 'inter'` for Greek names or texts: mPDF prints no characters the font does not have. The font name is used as CSS `font-family` in the templates and as default font of mPDF.

Register more fonts in the `pdf` section of `config/quittio.php`:

```php
'font' => 'corporate',
'fonts' => ['corporate' => ['R' => 'Corporate-Regular.ttf', 'B' => 'Corporate-Bold.ttf', 'I' => 'Corporate-Italic.ttf', 'BI' => 'Corporate-BoldItalic.ttf']],
'font_directories' => [resource_path('fonts')],
```

- Use lower case family names, as mPDF looks fonts up in lower case.
- Fonts must be embeddable TrueType fonts (PDF/A embeds all fonts).
- `php artisan vendor:publish --tag=quittio-fonts` copies Geist and Inter to `resources/fonts/quittio/geist` and `resources/fonts/quittio/inter`.
- The Laravel renderer searches `pdf.font_directories`, then `resources/fonts/quittio` and its subdirectories automatically. Fonts placed there only need an entry in `pdf.fonts`.
- Font files are taken from the first directory that has them. The configured directories come before the bundled fonts and those of mPDF, so a published and edited font replaces the bundled one.
- Without Laravel, pass the fonts to the renderer: `new MpdfRenderer(tempDir: …, fonts: [...], fontDirectories: [...], defaultFont: 'corporate')`.

### Letter layouts

A letter layout fixes the address field on the first page, so that the recipient appears in the window of a window envelope. It is a `Quittio\Core\View\LetterLayout` with these values in mm (defaults in parentheses): `name`, `addressTop` (45), `addressLeft` (20), `addressWidth` (85), `addressHeight` (45), `senderLineHeight` (5), `foldMarks` (`[]`), `punchMark` (`null`) and `addressZoneTop` (17.7). `LetterLayout::free()` is the layout without norm.

The layout is `Presentation::$letterLayout` (`pdf.layout`), otherwise the country pack default, otherwise `free`. Country packs provide layouts with `letterLayouts()`; an unknown name fails with a `ConfigurationException` listing the known layouts.

The German pack provides layouts after DIN 5008, the German standard for business letters:

| Layout | Address field from the top | Fold marks | Punch mark |
|---|---|---|---|
| `din5008a` (form A) | 27 mm | 87 and 192 mm | 148.5 mm |
| `din5008b` (form B, default) | 45 mm | 105 and 210 mm | 148.5 mm |
| `free` | 45 mm (layout default) | none | none |

The default templates print the sender line at the top of the address field and the recipient directly below it, both inside the window of a window envelope. DIN 5008 divides the address field into zones; own templates can use them (`design.addressZoneTop`, `design.noticeZoneHeight`, see [Design](#design)). Fold and punch marks are printed at the left edge unless `pdf.fold_marks` (option `fold_marks`) is `false`. See [Country packs](country-packs.md#din-5008-letter-layouts).

### Paper size

The paper is the first of:

1. the invoice presentation's `paper`, the presentation of the call and the configured presentation, each only when it is not `A4` or names `paper` in `explicit`;
2. the option `paper` (`pdf.paper`);
3. the country pack default (Germany: A4).

`pdf.paper = null` uses the country default; a configured size wins, also `A4`. The value is a page format of mPDF such as `A4`, `A5` or `Letter`.

### Watermark

A watermark marks drafts and previews: `->option('watermark', 'DRAFT')` or `Presentation::$watermark`.

- Watermarked PDFs are rendered without PDF/A, because PDF/A does not allow the transparency of watermarks.
- They never contain e-invoice XML. A watermark together with any format that produces XML (also next to `pdf` in `formats([...])`) throws a `ConfigurationException`, so a draft can never pass as an e-invoice.
- The default templates print it with mPDF's `<watermarktext>` (alpha 0.08) and, for browsers, as rotated CSS overlay (`design.watermarkCss`).
- The labels `draft` and `preview` can be used as text: "DRAFT" and "PREVIEW", in German "ENTWURF" (draft) and "VORSCHAU" (preview).

### Payment QR codes

The default templates print a QR code next to the bank details that banking apps can scan to fill in the transfer. The type is set with `Presentation::$paymentQr` (`pdf.payment_qr.type`):

| `paymentQr` | Generator |
|---|---|
| `auto` (default) | A registered generator (`setPaymentQrGenerator()`, Laravel `pdf.payment_qr.generator`). Otherwise what the country pack names in `CountryDefaults::$paymentQr`: `none` prints no code; for other values the country pack's generator (`paymentQr()`), otherwise the EPC GiroCode for `girocode` (the default, also without country pack) and no code for other types |
| `girocode` | EPC GiroCode, the European QR code for SEPA credit transfers |
| `swiss-qr-bill` | The generator registered with `setPaymentQrGenerator()` (Laravel: `pdf.payment_qr.generator`). Quittio ships no generator for the Swiss QR bill; without one, the render run fails with a `ConfigurationException` |
| `none` | No code |

No code is printed on refunds (credit notes, negative invoices) and on self-billed invoices.

The EPC GiroCode (`EpcGiroCodeGenerator`, standard EPC069-12 version 002) is created when:

- the currency is EUR,
- the payment means is a (SEPA) credit transfer (code 30 or 58) with a valid IBAN,
- and the amount due is between 0.01 and 999,999,999.99.

Its payload contains the BIC (optional), the IBAN, the amount, the beneficiary (payee name, otherwise account name, otherwise seller name, at most 70 characters) and the remittance information (BT-83, otherwise the invoice number, at most 140 characters); in total at most 331 bytes.

`pdf.payment_qr.girocode_amount` (option `girocode_amount`) is `due` (the amount due) or `discounted` (the amount after the first cash discount). With `discounted`, `qrCode.note` says until when the code is valid ("QR code with the amount after cash discount (…), valid until …"). Payment reminders get a code over the total of the reminder ([Payment reminders](documents.md#payment-reminders)).

`payment.qrCode` in the view model holds `type` (e.g. `girocode`), `payload`, `dataUri` (PNG data URI rendered with GD, SVG without GD; PNG because mPDF's SVG output violates PDF/A), `amount` and `note`. `Quittio\Core\Pdf\Qr\QrImage::svg()`, `png()` and `dataUri()` render any payload. Own generators implement `PaymentQrGenerator` (`key()`, `supports(InvoiceData)`, `payload(InvoiceData, array $options)`) and are returned by a country pack's `paymentQr()`.

### Texts and Markdown

Line descriptions and notes are also part of the e-invoice XML. The templates therefore print them as plain text (`lines[].description.html`, `notes[].html`: escaped, line breaks kept).

With `pdf.markdown = true` (option `markdown`) they are rendered as Markdown: paragraphs, lists, bold and italic. HTML is shown as text, and links keep only their text. Formatting changes the displayed text compared with the XML. For example, `20*30*40 cm` is shown as "20*30*40 cm" with "30" in italics and without the asterisks. Keep the option off for texts that must match the XML.

Intro and closing texts (`texts.intro`, `texts.closing`) are PDF-only and always Markdown. The salutation is plain text.

### Translations and locales

The locale (language) of a run is the first of:

1. `->locale()` of the call,
2. the invoice's locale (`InvoiceBuilder::locale()`),
3. `defaults.locale` (`locale` in `Quittio::create()`),
4. the country pack default (Germany: `de`).

Labels and code names (units, VAT categories, payment means, type codes …) exist in German, English and French; other locales fall back to English. The XML texts generated for the run (e.g. payment terms) use the same locale.

| Source | Where |
|---|---|
| Shipped labels | `packages/core/resources/lang/{de,en,fr}/labels.php` (namespace `quittio`) |
| Code names | `codes.php`, payment terms texts `terms.php` |
| Laravel overrides | `lang/vendor/quittio/{locale}/labels.php` (`php artisan vendor:publish --tag=quittio-lang`) |
| Country pack overrides | `quittio-{code}::labels.{key}` in the pack's `translationPath()` |
| Per invoice or call | `Presentation::$labels` |

Without Laravel, the translator is `Quittio\Core\I18n\ArrayTranslator` (files `{path}/{locale}/{group}.php`, later paths override earlier ones, fallback `en`). Add a directory with `Translations::translator()->addPath($path, 'quittio')`, or replace the translator with `Translations::use(new MyTranslator)` (`Quittio\Core\I18n\Translator`: `get()`, `has()`, `locale()`, `addPath()`). The Laravel integration bridges to Laravel's translator.

Values are formatted by `IntlFormatter` from their exact decimal value:

- amounts with two decimals and the currency symbol (`€`, `$`, `£`, `CHF`, otherwise the code; English puts `$` and `£` in front);
- prices with at least two decimals;
- quantities and rates without trailing zeros;
- separators from `ext-intl` (fallback: German and English conventions);
- dates as `dd.MM.yyyy` (de), `dd/MM/yyyy` (fr, it, es, pt, nl, en), `MM/dd/yyyy` (`en_US`) and the medium date format of `ext-intl` for other locales.

Country names come from `ext-intl`.

## HTML output and preview

`->format('html')` returns the rendered template without PDF (`$result->html()`); documents use `->htmlOnly()`. The HTML is the same markup mPDF receives; the watermark is shown with CSS.

The preview route `/_quittio/preview` lists the sample scenarios (`simple`, `many-lines`, `long-texts`, `mixed-vat`, `reverse-charge`, `intra-eu`, `export`, `cancellation`, `sub-lines`, `foreign-currency`, `b2c-gross`, `allowances`, `attachments`, `peppol`). It shows them as HTML or, with `?format=pdf`, as PDF, with a "PREVIEW" watermark, the configured design and validation in mode `warn` (errors are reported, not thrown).

- The route exists in the local environment, or with `quittio.preview.enabled = true`. Its path is `quittio.preview.path`, its middleware `quittio.preview.middleware`. It is not registered while routes are cached.
- `?template=` accepts the views that rendering accepts ([Selecting a template](#selecting-a-template)): `default`, the configured `pdf.template`, the views under `quittio::templates.` and the views of `pdf.templates`, never partials; the view must exist.
- `?locale=` accepts a locale code.
- Other values return 404.

More in [Preview route](laravel.md#preview-route). On the command line:

```bash
php artisan quittio:preview --scenario=many-lines --format=pdf --locale=en --output=/tmp/preview.pdf
```

The command writes to `storage/app/quittio/preview/{scenario}.{format}` by default; `--template` accepts the same templates as the route.

## Changing single partials (level 2)

The Blade layout is split into partials, one per part of the page. To change one part, publish the views and edit the copy of that partial:

```bash
php artisan vendor:publish --tag=quittio-views          # copies the views to resources/views/vendor/quittio/templates
```

Laravel then uses your copy of a partial instead of the shipped one. You can delete the copies you did not change; for those, Laravel uses the shipped partials, which follow package updates. The layout takes the partial prefix from the variable `$partials` (default `quittio::templates.default.partials`).

| Partial | Content |
|---|---|
| `styles` | CSS: page margins (first and following pages), font, colours, address field position, watermark for browsers |
| `header` | `<htmlpageheader>` of the following pages: logo or issuer name, title with number, recipient with customer number, invoice date; and an empty header for the first page |
| `footer` | `<htmlpagefooter>` with the footer columns and "Page {PAGENO} of {nbpg}" |
| `marks` | Fold and punch marks at the left edge of the first page (option `fold_marks`, on by default) |
| `sender-line` | Sender line at the top of the address field |
| `recipient` | Address lines of the recipient |
| `letterhead` | Logo or name of the issuer at the top left; the issuer's address when the footer does not start with it |
| `meta` | Number, date, valid until, reference document, delivery date, service period, customer number, references, VAT identifier and tax number of the issuer (only when the footer does not show them), VAT identifier of the recipient ("Your VAT ID"; on a self-billed invoice also the seller's tax number, "Your tax number"), tax representative, deliver-to address |
| `title` | Title (`<h1>`) |
| `references` | Preceding invoices ("Reference: invoice … of …") |
| `intro` | Salutation and intro text |
| `lines` | Line table: groups with subtotals, optional and alternative markers, item numbers, descriptions, line periods, attributes, line allowances and charges, line notes, serial numbers, sub lines, price base quantity, gross unit price; a VAT column only with several VAT rates; the rows for the [carry-over](#carry-over-across-pages) |
| `allowances` | Sum of lines, document allowances and charges (inside `totals`) |
| `totals` | Net total, VAT rows, gross total, paid amount, rounding, third party payments, amount due, VAT in accounting currency. When the amount due is the gross total, it is printed once, as gross total |
| `documents` | Packages, open items, fees, interest, totals and balance of [documents](documents.md) |
| `tax-notes` | Exemption reasons and regulatory notes (`legalNotes`) |
| `payment` | Payment terms and direct debit notice; next to them the bank details (payee, IBAN; BIC and reference; the account holder only when it is not the issuer) or the payout account, and the payment QR code |
| `notes` | Other notes |
| `attachments` | Attached documents (identifier and description) |
| `closing` | Closing text, acceptance field of quotes |

Partials read the [view model](#view-model). Keep printing every value a partial prints today unless you print it elsewhere: the [mandatory field guard](#mandatory-field-guard) reports missing mandatory values.

## Own templates (level 3)

For a layout of your own, create a copy of the default template under your own name:

```bash
php artisan make:quittio-template my-design          # resources/views/invoices/my-design (--force overwrites)
```

The name may contain lower case letters, digits, `-` and `_`. The command copies the default layout and partials and points the layout at `invoices.my-design.partials`. Use the template per call with `->template('invoices.my-design.layout')` (allowed by the default `pdf.templates` entry `invoices.*`) or for every invoice with `pdf.template`.

A template must follow some rules of mPDF ([mPDF rules for templates](#mpdf-rules-for-templates)) and must print every mandatory value ([Mandatory field guard](#mandatory-field-guard)).

### Selecting a template

The template of a run is the first of:

1. `->template('…')` of the call;
2. `Presentation::$template` of the invoice's own presentation;
3. the `template` of the presentation passed to `->presentation()` of the call;
4. the `template` of the configured presentation;
5. the configured template. In Laravel this is the name `default`, which the Blade engine maps to `pdf.template` (default `quittio::templates.default.layout`). Without Laravel it is the `template` key of `Quittio::create()` (default `default`).

The same order applies to documents (quotes, reminders …).

Template names may come from invoice data (`Presentation::$template`, issuer branding of the [Managed layer](managed.md)), so they are restricted:

- **Blade engine:** it renders `default`, the configured `pdf.template`, the shipped views under `quittio::templates.` (not their partials) and the views listed in `pdf.templates` (default `['invoices.*']`; an entry ending in `*` allows every view below that prefix, e.g. `mytheme::*`). A view whose name contains `.partials.` is never rendered as a template. Another existing view fails with an `InvalidArgumentException`. Names that are no Blade view are passed to the plain PHP engine.
- **Plain PHP engine:** it accepts names only, no paths: segments of letters, digits, `_` and `-` separated by `/` (segments after the first may also contain `.`), no `..`, no absolute paths or stream wrappers. It resolves `{name}.php` inside its template directories (`template_paths` of `Quittio::create()`, then the shipped templates). An unknown or invalid name fails with an `InvalidArgumentException`.

### What a template receives

A Blade template receives `$invoice`, the [view model](#view-model). A plain PHP template receives `$invoice` and `$e`, an escape function that returns HTML-safe text.

Print values escaped: with `{{ }}` in Blade, with `$e(…)` in plain PHP. Only these prepared HTML values may be printed unescaped (`{!! !!}`): `lines[].description.html`, `notes[].html`, `texts.intro`, `texts.closing` and `design.watermarkCss`.

### Carry-over in own templates

Own templates get the [carry-over](#carry-over-across-pages) with these markers (other renderers and browsers ignore them):

```html
<table class="lines">
    <thead>…</thead>
    <tbody>
    <!--quittio:rows-->
    <tr data-carry-over="{{ $line->carryOver }}">…</tr>   {{-- every line row; sub lines and group rows without --}}
    <!--/quittio:rows-->
    </tbody>
</table>
<!--quittio:carry-out <tr class="carry-out"><td>Continued on page {page}</td><td>{amount}</td></tr> -->
<!--quittio:carry-in <tr class="carry-in"><td>Brought forward from page {page}</td><td>{amount}</td></tr> -->
```

- `<!--quittio:rows-->` and `<!--/quittio:rows-->` enclose the rows of the line table.
- Every line row carries its running total in `data-carry-over` (`lines[].carryOver` of `lineGroups`).
- The `carry-out` comment holds the row printed at the end of a page, the `carry-in` comment the row at the start of the next page.
- `{page}` is the next page in the carry-out row and the previous page in the carry-in row. `{amount}` is the `carryOver` of the last line row before the break.
- The table needs a `<thead>` and fixed column widths, because each page gets a table of its own.

### Page numbers, headers and footers

The default templates put the footer columns and "Page {PAGENO} of {nbpg}" into an `<htmlpagefooter>` that every page uses (`@page { footer: html_quittioFooter; }`). mPDF replaces `{PAGENO}` and `{nbpg}` with the page number and the number of pages. Headers work the same way with `<htmlpageheader>`: the first page uses an empty header (`@page :first { header: html_quittioFirstHeader; }`), the following pages the header with logo, title and recipient.

Up to four footer columns get a fixed width (43.8 mm each, the last 39.6 mm); more columns share the width. The page margins come from the view model: `design.pageMarginTop` for the first page and `design.continuationMarginTop` for the following pages ([Design](#design)).

### mPDF rules for templates

mPDF supports only part of CSS. Follow these rules:

- Layout with tables, not flexbox or grid.
- Inside table cells, mPDF ignores `margin` and `padding` of blocks (`div`, `p`). Create spacing there with the padding of a table cell, e.g. a nested table with one row per block.
- Position elements on the page with `position: absolute` (measured from the page edge); `position: fixed` is measured from the page margins. The address field must be a direct child of `<body>`.
- Repeat table headers on every page via `<thead>`.
- Headers and footers via `<htmlpageheader>` / `<htmlpagefooter>`, page numbers with `{PAGENO}` and `{nbpg}`.
- Use `page-break-inside: avoid` for the totals block.
- Images as PNG or JPEG; fonts must be embeddable.

### Other template engines

Other engines (Twig …) implement `Quittio\Core\Templates\TemplateEngine::render(string $template, InvoiceViewModel $invoice, array $data = []): string`. Pass them to `Quittio::create(templates: …)` or `$quittio->setTemplateEngine(…)`.

## PDF renderers

A renderer turns the HTML of the template into the PDF (design level 4).

| Renderer | Use |
|---|---|
| `MpdfRenderer` (default) | HTML to PDF/A-3b with mPDF in pure PHP; embeds the XML and writes the XMP metadata (the document description PDF/A requires) in one pass |
| `CallbackRenderer(Closure $callback)` | Own rendering (Browsershot, Gotenberg …): `fn (string $html, RenderRequest $request): string` returns the PDF; PDF/A, metadata and the XML are added by `MpdfPostProcessor` |
| `PassthroughRenderer(string $pdf)` | A finished PDF of the application; only PDF/A and the XML are added |

```php
use Quittio\Core\Pdf\Renderers\CallbackRenderer;
use Quittio\Core\Pdf\RenderRequest;
use Quittio\Laravel\Facades\Quittio;
use Spatie\Browsershot\Browsershot;

Quittio::extend('renderer', 'browsershot', fn ($app, array $config) => new CallbackRenderer(
    fn (string $html, RenderRequest $request) => Browsershot::html($html)->format($request->paper)->pdf(),
));
// config/quittio.php: 'pdf' => ['renderer' => 'browsershot', 'renderers' => ['browsershot' => [...]]]
```

The factory receives the container and `pdf.renderers.{name}`. Without Laravel, pass the renderer to `Quittio::create(renderer: …)` or `$quittio->setRenderer(…)`.

A renderer implements `PdfRenderer` with `render(RenderRequest $request): string` and `supportsEmbedding(): bool`:

- `supportsEmbedding()` returns `true` when the renderer creates the PDF/A and embeds the XML itself (`MpdfRenderer`, and `CallbackRenderer`/`PassthroughRenderer` through their post processor).
- For `false`, Quittio passes the PDF to `MpdfPostProcessor` whenever the request asks for PDF/A or an embedded XML, so a plain HTML-to-PDF renderer is enough.
- `RenderRequest` holds `html`, `title`, `author`, `subject`, `paper`, `pdfa`, `embedded` (`EmbeddedXml`: `content`, `filename`, `relationship`, `conformanceLevel`, `description`, `documentType`, `version`) and `font`.
- The post processor imports the pages with FPDI (a PHP library that reads existing PDFs). The result is PDF/A only if the source PDF allows it (embedded fonts, no encryption, no JavaScript), so check it with [veraPDF](validation.md#verapdf-l6), a validator for PDF/A.

A PDF is PDF/A-3b when it embeds XML, otherwise when `pdf.pdfa` is `true` (default) and it has no watermark. Its metadata: title "{title} {number}", author the seller, creator "Quittio".

mPDF settings of `MpdfRenderer`:

- margins 20 (left), 15 (right), 20 (top), 25 (bottom), header and footer 8 mm; the default templates set their own page margins;
- `pdf.renderers.mpdf.simple_tables` (default `false`; `true` drops the borders of table cells and is not faster);
- `pdf.renderers.mpdf.pdfa_auto` (default `false`; lets mPDF fix PDF/A issues itself);
- `pdf.cache_path` as temporary directory (without Laravel `temp_dir`, default `sys_get_temp_dir()/quittio-mpdf`).

mPDF cannot load anything over HTTP (a denying HTTP client is installed), has no remote base path and does not follow redirects. Remote resources of a template are rendered as missing.

## View model

Templates receive `$invoice` (`Quittio\Core\View\InvoiceViewModel`) and nothing else. It holds every value of the invoice, already calculated and formatted for printing.

- Nodes are read-only `ViewNode` objects. Use object syntax (`$invoice->seller->name`) or array syntax, loop over lists, and use `count()`, `isEmpty()` and `toArray()` (plain values, formatted).
- Writing throws a `LogicException`; a missing key reads as `null`.
- Values that need formatting are `FormattedValue` objects. `{{ $invoice->totals->amountDue }}` prints the formatted text (`->formatted`). `->raw` holds the original value: `Brick\Money\Money` for amounts, `Quittio\Core\Support\Date` for dates, `Period` for periods, `Brick\Math\BigDecimal` for quantities, prices and rates.
- Templates contain no calculations.

`$invoice->label($key, $replace)` returns a label with `:placeholders` replaced (unknown keys return the key); `$invoice->title()` returns the title.

The tables name the EN 16931 field of a value in parentheses, e.g. invoice number (BT-1) or seller address (BG-5); see [Business terms](concepts.md#en-16931-and-business-terms).

### Top level

| Key | Content |
|---|---|
| `number` | Invoice number (BT-1) |
| `numberLabel`, `dateLabel` | Labels for number and date ("Invoice number", "Invoice date"; documents: "Number", "Date") |
| `title` | Document title (see below) |
| `issueDate`, `dueDate` | Dates (BT-2, BT-9) |
| `servicePeriod` | Invoicing period (BG-14), otherwise the delivery date (BT-72), otherwise the period from the earliest start to the latest end of the line periods when every line has one; `null` otherwise |
| `servicePeriodLabel` | "Delivery date" for a single date or a one-day period, otherwise "Service period". German output: "Leistungsdatum" (date of supply) and "Leistungszeitraum" (period of supply) |
| `deliveryDate` | BT-72 when an invoicing period is also set (otherwise `servicePeriod` shows it) |
| `currency` | Invoice currency (BT-5) |
| `document` | [document](#document) |
| `documentInfo` | `null` for invoices, [documentInfo](#documentinfo) for documents |
| `seller`, `buyer` | [Parties](#parties) |
| `issuer`, `recipient` | The party in letterhead, sender line and footer, and the party in the address field: seller and buyer; on a self-billed invoice buyer and seller |
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

The `title` is the first of:

1. `Presentation::$title`;
2. for self-billed type codes: the title `self_billed` of the country pack's correction policy, which also holds the titles of cancellations and corrections. Germany: "Gutschrift"; in English "Self-billed invoice (Gutschrift)";
3. the label of the type code (BT-3).

| Label | English text | Used for |
|---|---|---|
| `title_380` | Invoice | Type code 380 |
| `title_381` | Credit note | Type code 381 and other credit note codes |
| `title_384` | Corrected invoice | Type code 384 |
| `title_389` | Self-billed invoice | Type code 389 and other self-billed codes |
| `title_325` | Proforma invoice | Type code 325 |
| `title_326` | Partial invoice | Type code 326 |
| `title_386` | Prepayment invoice | Type code 386 |
| `title_875`, `title_876`, `title_877` | Partial construction invoice, Partial final construction invoice, Final construction invoice | Type codes 875, 876, 877 |
| `title_default` | Invoice | Every other type code |

### document

| Key | Content |
|---|---|
| `typeCode` | Type code (BT-3) as string, e.g. `380`; `null` for documents |
| `type` | Name of the type code in the locale; for documents the name of the document type, e.g. "Quote" |
| `isCreditNote` | Credit note type code |
| `isSelfBilled` | Self-billed invoice ("Gutschrift") |
| `profile`, `profileKey` | Name and key of the [profile](concepts.md#profiles) of the run, e.g. `EN 16931`, `en16931` |
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

`issuer` and `recipient` are the same nodes.

Every `address` has `lines`, `street`, `additional`, `line3`, `postcode`, `city`, `subdivision`, `countryCode` and `country` (name in the locale). `lines` is formatted by the country pack's address formatter and starts with the name. The country is added in upper case when it differs from the issuer's country. The fallback formatter writes "postcode city", for GB, IE, US, CA, AU, LV and UA "city subdivision postcode".

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
| `carryOver` | Sum of the line net amounts up to this line in display order, only in `lineGroups[].lines`: the [carry-over](#carry-over-across-pages) when a page ends after the line |
| `vatRate` | Rate (BT-152); `null` without rate and for margin schemes and travel agents (special VAT schemes without separate VAT) |
| `vatCategory` | Category code (BT-151) |
| `period` | Line period (BG-26) |
| `attributes` | List of `name`, `value` (BG-32) |
| `allowances`, `charges` | Line allowances and charges: `reason`, `amount`, `percent`, `label` ("Allowance reason 10 %") |
| `subLines` | Sub lines with the same keys |
| `highlight` | `LineBuilder::highlight()`; the default templates shade the line and mark it in the secondary colour |
| `groupId` | Line group |
| `optional`, `alternativeTo`, `serialNumbers` | Document line options (quotes, delivery notes) |

`lineGroups` lists the groups defined with `InvoiceBuilder::group()` in their order, then one group with `id` and `title` `null` for the lines without a group (or with an unknown group). Empty groups are left out. Each group has `id`, `title`, `lines` and `subtotal` (sum of the line net amounts when `showSubtotal` is set, otherwise `null`). Print either `lineGroups` or `lines`, not both.

### Allowances and charges

`allowances` and `charges` (BG-20, BG-21) are discounts and surcharges on the whole document. They contain `reason` (text or code), `amount`, `percent`, `base`, `vatRate`, `vatCategory` and `label` ("Allowance reason 3 %"; with several VAT groups followed by the VAT rate or category, e.g. "(19 % VAT)").

### VAT rows

| Key | Content |
|---|---|
| `label` | Ready text: "VAT 19%" when it is the only VAT row (its base is the net total printed above), otherwise "19% VAT on 1,000.00 €"; "VAT exempt (…) on …", "No VAT (reverse charge) on …", "0% VAT (zero rate) on …", "Not subject to VAT: …", "Special scheme, no separate VAT statement: …" |
| `category`, `categoryLabel` | Category code and name |
| `rate` | Rate (BT-119) |
| `base` | Taxable amount (BT-116) |
| `amount` | VAT amount (BT-117); `null` for margin schemes and travel agents (exemption reason codes `VATEX-EU-D`, `-F`, `-I`, `-J`) |
| `isSpecialScheme` | Margin scheme or travel agents |
| `exemptionReason`, `exemptionReasonCode` | BT-120, BT-121 |

Reverse charge means that the buyer owes the VAT ([German terms](concepts.md#german-terms)). Margin schemes and travel agents are special VAT schemes (sections 25 and 25a of the German VAT Act, UStG) that state no separate VAT.

### Totals

| Key | Content |
|---|---|
| `lineNetSum`, `allowanceSum`, `chargeSum`, `netTotal`, `vatTotal`, `grossTotal`, `prepaid`, `rounding` | Document totals |
| `vatTotalInTaxCurrency` | VAT in accounting currency (BT-111) |
| `taxCurrencyNote` | "VAT in USD: … (rate … of …)" when an exchange rate is set |
| `thirdPartyPayments` | List of `description`, `amount` (XRechnung extension) |
| `amountDue` | Amount due |
| `amountDueLabel` | "Amount due"; "Amount to be refunded" on credit notes and negative invoices; "Amount paid out" on self-billed invoices |
| `dueEqualsGross` | The amount due is the gross total: nothing prepaid, rounded or paid by third parties, no refund and no payout |

When `dueEqualsGross` is `true`, the default templates print the amount once, with the label `gross_total` ("Total incl. VAT"; German "Gesamtbetrag", total amount).

On a negative invoice (e.g. a cancellation with the strategy `negative_invoice`), `amountDue` is printed as a positive refund amount; `amountDue->raw` and the XML keep the negative amount due (BT-115).

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

`paymentTerms` is `null` without payment terms (BT-20) and due date. Otherwise it has:

- `lines`: ready texts. These are the terms text; "Please transfer the amount by …" when the buyer pays by credit transfer, otherwise "Please pay the amount by …", on self-billed invoices "We will transfer the amount to your account by …"; one text per cash discount, such as "2% discount until 10/10/2026: 2,692.76 €"; and the late fee terms.
- `discounts`: list of `days`, `percent`, `until`, `amount`, `text`.
- `dueDate`.

On credit notes, quotes, order confirmations and invoices without a positive amount due, discounts are shown as agreement ("For payment within 7 days …") without date or amount, and no due date text is added.

### Design

| Key | Content |
|---|---|
| `primaryColor`, `secondaryColor`, `textColor`, `mutedColor` | Hex colours (`secondaryColor` falls back to the primary colour). The default templates print the issuer's name, the title, the header of the following pages and the gross total or amount due in the primary colour; section titles and the mark of highlighted lines in the secondary colour; the lines below the column heads and above the gross total or amount due in the text colour; secondary texts and labels in the muted colour |
| `font` | Font family |
| `fontMedium` | Family for the medium and semibold weights (item names, column heads, title, headings): `geistmedium` for Geist (normal = medium, bold = semibold); `null` for other fonts, then the templates use bold |
| `logo` | Prepared logo ([Logos](#logos)) or `null` |
| `letterLayout` | Name of the [letter layout](#letter-layouts) |
| `addressTop`, `addressLeft`, `addressWidth`, `addressHeight` | Address field in mm from the top left page corner |
| `pageMarginTop` | Top page margin of the first page in the default templates (15 mm): the letterhead starts here |
| `continuationMarginTop` | Top page margin of the following pages (40 mm), below their header |
| `letterheadHeight` | Space above the address field: `addressTop` − `pageMarginTop` |
| `logoMaxHeight` | Height of the logo: 14 mm, less when the letterhead is lower (the logo ends 2 mm above the address field), at least 6 mm |
| `addressZoneTop` | Start of the recipient zone (DIN 5008 "Anschriftzone") within the address field (17.7 mm), for own templates; the default templates print the recipient directly below the sender line |
| `senderLineHeight` | Height of the sender line (5 mm) |
| `noticeZoneHeight` | `addressZoneTop` − `senderLineHeight`: the DIN 5008 zone for additions and postal notes ("Zusatz- und Vermerkzone") above the sender line, for own templates |
| `foldMarks`, `punchMark` | Mark positions in mm from the top; empty when the option `fold_marks` is `false` (default `true`) |
| `paper` | Paper of the presentation |
| `watermark`, `watermarkCss` | Watermark text, and the same as escaped CSS string for the HTML overlay |
| `senderLine` | Configured sender line, otherwise "Name · Street · Postcode City" of the issuer |
| `footerColumns` | Configured columns, otherwise generated: the issuer's address lines; phone and e-mail; legal registration and the parts of the additional legal information (split at " · " and line breaks); "VAT ID …" and "Tax no. …" of the issuer. The bank details are in the payment block |
| `footerHasAddress` | The generated footer starts with the issuer's address |
| `footerHasTaxIds` | The generated footer shows the issuer's VAT identifier or tax number (they count as printed for the [mandatory field guard](#mandatory-field-guard)); otherwise the default templates print them with the invoice details |
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

## Mandatory field guard

Some values must appear on every invoice by law. After rendering a PDF or HTML invoice, the guard checks that the template printed them all:

- number and issue date;
- the full name of seller and buyer (`seller.name` or the address lines, which start with it; the trading name `displayName` alone is not enough) and their address (all address lines, or street, postcode and city);
- name, quantity and unit (`unit.label`) of every line (in `lineGroups` or `lines`). Section 14 (4) no. 5 UStG requires "Menge und Art" (quantity and kind) of the goods;
- label, or base and rate, and the VAT amount of every VAT row;
- the cash discount terms (`paymentTerms.discounts[].text` or `paymentTerms.lines`) and the amount due;
- every legal note (exemption reasons and regulatory notes, e.g. "Gutschrift" or the retention notice of section 14b UStG) and every preceding invoice number;
- the tax representative (name, address, VAT identifier);
- what the country pack requires. Germany: tax number or VAT identifier (also of the tax representative); the service date (`servicePeriod`, `deliveryDate` or `delivery.date`, or the period of every line); on self-billed invoices the title and the note "Gutschrift"; the retention notice of property related services; the [reverse charge](concepts.md#german-terms) or exemption note per VAT row; the VAT identifiers of buyer and seller for reverse charge and intra-community supplies (tax-exempt supplies of goods to a business in another EU country; see [Country packs](country-packs.md#mandatory-pdf-output)).

How the guard decides whether a value was printed:

- Lists are checked per entry: printing only the first VAT row or note is not enough.
- Values that are null are not required.
- A value counts as printed when the template reads it as a leaf (a single value, not a node), also in a condition such as `@if ($invoice->buyer->vatId)`, while a loop reads the entries of a list, or with `toArray()`.
- `count()`, `isEmpty()`, `isset()` and reading null values do not count.
- Values printed through `issuer` and `recipient` count for the seller and the buyer, and the generated footer counts as the issuer's address. A custom `senderLine` or custom `footerColumns` do not replace the seller's address.

Mandatory fields are `Quittio\Core\Countries\MandatoryField` objects (`accessKey`, `description`, `legalBasis`). The access key is a view model path where `[*]` stands for every index, `&` joins paths that must all be printed and `|` separates alternatives. Country packs return them from `mandatoryOutput(InvoiceData $invoice)`.

A template that omits a mandatory value fails with `MandatoryFieldMissing` (`validation.mandatory_field_guard = exception`, default outside production) or logs it (`log`, default in production); `off` disables the check. The event `MandatoryFieldMissing` is dispatched in both modes. Details in [Validation](validation.md#mandatory-field-guard).
