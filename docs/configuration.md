# Configuration

This page describes `config/quittio.php`, the configuration file of the Laravel integration. When you install Quittio, read [The keys you set first](#the-keys-you-set-first): your company, your bank account and the defaults most applications change. The rest of the page is the reference of every key, in the order of the file.

The terms used here (format, profile, BT numbers, rule codes) are explained in [E-invoicing basics](concepts.md).

## The keys you set first

Publish the file once:

```bash
php artisan quittio:install                              # config/quittio.php, with hints for the next steps
php artisan vendor:publish --tag=quittio-config           # the same file, without the hints
```

The options of the installer are described in [Artisan commands](commands.md#quittioinstall).

### Your company

Every invoice names the company that issues it: the **seller**. `Quittio::invoice()` takes the seller from the `seller` section, so you set it once:

```php
// config/quittio.php
'seller' => [
    'name' => env('QUITTIO_SELLER_NAME'),                  // legal name of the company
    'vat_id' => env('QUITTIO_SELLER_VAT_ID'),              // VAT identification number, e.g. DE123456789
    'tax_number' => env('QUITTIO_SELLER_TAX_NUMBER'),      // tax number from the tax office, e.g. 21/815/08150
    'legal_registration' => 'HRB 12345',                   // commercial register number
    'address' => [
        'line1' => 'Hauptstraße 1',                        // street and number
        'line2' => null,
        'postcode' => '12345',
        'city' => 'Musterstadt',
        'country' => 'DE',
    ],
    'contact' => ['name' => 'Max Muster', 'phone' => '+49 30 1234567', 'email' => 'billing@muster.de'],
    'electronic_address' => ['value' => 'billing@muster.de', 'scheme' => 'EM'],
    'bank_accounts' => [
        ['iban' => 'DE89370400440532013000', 'bic' => 'COBADEFFXXX', 'name' => 'Muster GmbH'],
    ],
    'small_business' => (bool) env('QUITTIO_SELLER_SMALL_BUSINESS', false),
    // identifiers and additional_legal_info: see the reference below
],
```

Name, VAT identification number, tax number and the small business setting can also come from the environment:

```bash
# .env
QUITTIO_SELLER_NAME="Muster GmbH"
QUITTIO_SELLER_VAT_ID=DE123456789
QUITTIO_SELLER_TAX_NUMBER=21/815/08150
QUITTIO_SELLER_SMALL_BUSINESS=false
```

The rules of the formats require some of these values:

- Every invoice needs the name. German invoices also need street, postcode and city.
- German invoices need the VAT identification number or the tax number.
- Without a VAT identification number, EN 16931 (the European standard for e-invoices) needs another identifier of the seller: `identifiers` (BT-29) or `legal_registration` (BT-30). Otherwise rendering fails with the rule BR-CO-26. This concerns, for example, a small business with a tax number only.
- XRechnung (the format for German public authorities) also needs the contact with name, phone and e-mail, and the electronic address. Peppol (an international network for delivering e-invoices) needs the electronic address as well.

BT numbers are explained in [EN 16931 and business terms](concepts.md#en-16931-and-business-terms), rule codes such as BR-CO-26 in [Rules and validation](concepts.md#rules-and-validation). Every key of the section is listed in [Seller](#seller).

### Your bank account

`Quittio::invoice()` puts the first entry of `seller.bank_accounts` that has an IBAN on every invoice, as payment by SEPA credit transfer. The PDF shows the IBAN and a payment QR code that banking apps can read (GiroCode in Germany). `bic` and `name` are optional. Further entries are ignored.

### Small businesses

Set `seller.small_business` to `true` if you are a small business that charges no VAT (Kleinunternehmer, section 19 of the German VAT Act UStG). Invoices then carry no VAT. Lines without `->vat()` get the small business exemption with its legal note, and VAT on a line is rejected. See [Small businesses](country-packs.md#small-businesses).

### Defaults you may change

The `defaults` section sets what an invoice uses when it does not set a value itself. These are the ones applications change most often:

| Key | Default | Change it when |
|---|---|---|
| `defaults.due_days` | `14` | Your invoices are due after another number of days. `null` = no due date unless the invoice sets one. |
| `defaults.vat` | `null` (19 % for a German seller) | Most of your lines have another VAT rate, e.g. `'de.reduced_7'` (7 %) for a bookshop |
| `defaults.format` | `null` (automatic choice) | All your invoices need the same format, e.g. `'xrechnung-cii'` |
| `defaults.locale` | `null` (German for a German seller) | Your invoices should be in another language, e.g. `'en'` |

All keys of the section are described in [Defaults](#defaults).

### Design

The `pdf` section sets the look of the PDF: logo, colours, font and texts. For example `'logo' => resource_path('images/logo.png')` and `'colors' => ['primary' => '#0f766e', …]`. See [PDF and design](#pdf-and-design) and [Templates and design](templates.md).

Check the design with the sample invoices. They use sample data, not your seller:

```bash
php artisan quittio:preview --scenario=simple
```

Then render a first invoice with your own data ([Getting started](getting-started.md#building-an-invoice)).

## How the configuration is read

Without a published file, the package defaults apply. Laravel merges the package file with yours by top-level key. A section you leave out (for example `preview`) keeps the package defaults.

Inside a section you keep, a missing key gets the value Quittio uses when nothing is set. For most keys that is the default listed on this page. These keys differ:

| Key left out | Value |
|---|---|
| `defaults.country` | none: the seller's country decides; without it the only installed country pack, else the generic EN 16931 rules |
| `defaults.due_days` | none: no due date unless the invoice sets one |
| `pdf.cache_path` | `sys_get_temp_dir().'/quittio-mpdf'` |
| `storage.disk` | the default disk of `config/filesystems.php` |
| `queue.queue` | the default queue of the connection |

The manager (a singleton) builds the core from the configuration when it is used for the first time and keeps it as long as the `quittio` configuration stays the same. A value changed at runtime (in tests, or per tenant) makes the next call build the core again, without further steps:

```php
config()->set('quittio.pdf.colors.primary', '#0f766e');
Quittio::make($invoice)->render(); // uses the new colour
```

Under Octane the manager reads the configuration of the current request, not that of the worker's first request. `Quittio::flush()` still forgets the built core, e.g. after a bound class changed.

Some values are process-wide rather than per application: the translator bridge, the default VAT for lines without VAT, the snapshot upcasters and the registered base rates of the German pack. `Quittio\Core\Quittio::resetState()` forgets them; the service providers set them again when the application boots. The test trait `InteractsWithQuittio` calls it after each test ([Testing](testing.md#assertions)).

Some keys are read at other times:

- `seller`, `defaults.vat`, `storage.disk` and `queue` are read on every call.
- `preview` and `snapshots.upcasters` are read when the application boots.

A value of the wrong type fails with a `ConfigurationException` that names the key, e.g. `quittio.pdf.pdfa must be a boolean.`

The Managed layer has its own file, `config/quittio-managed.php`, with the database, issuers, archive, queue, numbering, corrections, delivery, audit log, retention and policies. Its keys are described in the [Managed layer](managed.md). Issuer profiles of the Managed layer replace the `seller` section below.

## Environment variables

| Variable | Key | Default |
|---|---|---|
| `QUITTIO_DEFAULT_COUNTRY` | `defaults.country` | – (the only installed country pack) |
| `QUITTIO_SELLER_NAME` | `seller.name` | – |
| `QUITTIO_SELLER_VAT_ID` | `seller.vat_id` | – |
| `QUITTIO_SELLER_TAX_NUMBER` | `seller.tax_number` | – |
| `QUITTIO_SELLER_SMALL_BUSINESS` | `seller.small_business` | `false` |
| `QUITTIO_DEFAULT_VAT` | `defaults.vat` | – (standard rate of the seller's country) |
| `QUITTIO_VALIDATION_MODE` | `validation.mode` | `error` |
| `QUITTIO_VALIDATION_LOCALE` | `validation.locale` | – (the language of the application) |
| `QUITTIO_MANDATORY_FIELD_GUARD` | `validation.mandatory_field_guard` | – (`exception`, in production `log`) |
| `QUITTIO_KOSIT_URL` | `validation.drivers.kosit.url` | `http://127.0.0.1:8081` (start the validator with `-P 8081`; its own default port is 8080) |
| `QUITTIO_CANCELLATION_STRATEGY` | `corrections.cancellation_strategy` | – (country pack) |
| `QUITTIO_STORAGE_DISK` | `storage.disk` | `local` |
| `QUITTIO_PREVIEW_ENABLED` | `preview.enabled` | – (local environment only) |

`QUITTIO_PREVIEW_ENABLED` must be `true` or `false`. Other values count as not set.

Quittio 0.1.0 used shorter names for some variables: `QUITTIO_COUNTRY`, `QUITTIO_VALIDATION`, `QUITTIO_GUARD`, `QUITTIO_DISK` and `QUITTIO_PREVIEW`. They are still read when the new name is not set, until 1.0.

## Defaults

Values used when an invoice or a render call does not set them itself.

| Key | Type | Default | Effect |
|---|---|---|---|
| `defaults.country` | string\|null | `env('QUITTIO_DEFAULT_COUNTRY')` | Country pack used when the seller has no country code (BT-40), e.g. `'DE'`. Normally the seller's country decides; `->country()` on a render call wins as well. `null` = the only installed country pack if there is exactly one (`quittio/quittio` installs Germany). Without an installed pack for the code, only the generic EN 16931 rules apply. |
| `defaults.profile` | string\|null | `null` | Profile of the e-invoice XML, i.e. how much of EN 16931 it contains ([Profiles](concepts.md#profiles)), e.g. `'en16931'`, `'extended'`, `'xrechnung'`. It applies to every format that allows it, also to `->format('zugferd')` and to formats chosen by a country rule. Formats that do not allow it ignore it (e.g. `basic` for XRechnung or Peppol). `null` = the profile of the automatic choice or the format's default (`zugferd`, `cii`, `ubl`: `en16931`; `xrechnung-cii`, `xrechnung-ubl`: `xrechnung`; `peppol`: `peppol`). `->profile()` on a render call always wins. See [Formats and profiles](formats.md). |
| `defaults.format` | string\|null | `null` | Format of a render call without `->format()`. `null` or `'auto'` = automatic choice by the format resolver: XRechnung for public buyers with a Leitweg-ID (the routing ID of a German public buyer, see [German terms](concepts.md#german-terms)), PDF for consumers, ZUGFeRD for businesses ([Automatic choice](formats.md#automatic-choice)). Keys: `zugferd` (PDF with embedded XML; aliases `factur-x`, `facturx`), `xrechnung-cii` (alias `xrechnung`), `xrechnung-ubl`, `cii`, `ubl`, `peppol`, `pdf`, `html`, `json`. |
| `defaults.locale` | string\|null | `null` | Language of the invoice (labels, generated texts, number and date formats), e.g. `'de'` or `'en'`, when neither `->locale()` on the render call nor the invoice (`InvoiceBuilder::locale()`) sets one. `null` = the default of the seller's country pack (Germany: `de`). |
| `defaults.due_days` | int\|numeric string\|null | `14` | Due date (BT-9) = issue date + this number of days. It applies to invoices that set neither a due date nor payment terms (`->dueInDays()`), never to credit notes. `null` or `''` switches it off. Other values fail with a `ConfigurationException`. |
| `defaults.vat` | string\|null | `env('QUITTIO_DEFAULT_VAT')` | Tax preset of invoice lines without `->vat()`, by key, e.g. `'de.reduced_7'` for a bookshop. `null` = the standard rate of the seller's country pack (Germany: `de.standard_19`). Invoices of small businesses (`seller.small_business`) use the small business preset of the pack (`de.small_business`). An unknown key fails with a `ConfigurationException` when a line needs the default. See [Default VAT](building-invoices.md#default-vat). |

## Country packs

A country pack brings the tax presets, legal rules and texts of one country ([How Quittio works](concepts.md#how-quittio-works)). `quittio/quittio` includes the pack for Germany.

```php
'countries' => [
    // 'AT' => \App\Quittio\CountryAT::class,
],
```

Country packs are found in three ways, and all of them are combined:

1. **Package discovery**: a Composer package (or your root `composer.json`) lists its packs under `extra.quittio.country-packs`. `quittio/country-de` does this, so `composer require quittio/country-de` is enough.

   ```json
   "extra": {
       "quittio": {
           "country-packs": ["App\\Quittio\\CountryAT"]
       }
   }
   ```

   The result is kept in `bootstrap/cache/quittio-countries.php` until Composer rewrites `vendor/composer/installed.json` or your `composer.json` changes, so requests do not read the package list. After editing `extra.quittio.country-packs` of the root `composer.json` by hand, the next request picks it up as well.

2. **Configuration**: `countries` lists further pack classes. The array keys are ignored; the pack's `code()` counts. Classes are resolved through the container.
3. **At runtime**: `Quittio::registerCountry(new CountryAT)`, e.g. in a service provider. It wins over the other two for the same country code and survives `Quittio::flush()`.

Every pack must implement `Quittio\Core\Countries\CountryPack`, otherwise building the core fails with a `ConfigurationException`.

For the same country code the last one wins, in this order: discovered packs, then `countries`, then `Quittio::registerCountry()`. So a subclass of `CountryDE` listed in `countries` replaces the discovered `CountryDE`.

`php artisan quittio:countries` and `php artisan about` list the active packs. To write your own pack, see [Country packs](country-packs.md).

### Country pack Germany (`config/quittio-de.php`)

The German pack has a small configuration of its own. Publish it with `php artisan vendor:publish --tag=quittio-de-config`.

It holds the base rate (Basiszinssatz) under section 247 of the German Civil Code (BGB). `DefaultInterest` needs it to calculate the statutory interest on late payments, e.g. for payment reminders ([German default interest](documents.md#german-default-interest)).

| Key | Type | Default | Effect |
|---|---|---|---|
| `base_rates` | array | `[]` | Base rates in addition to the table shipped with Quittio: ISO date (1 January or 1 July) => percent, e.g. `['2027-01-01' => '1.77']`. They win over the file below. |
| `base_rate_file` | string\|null | `storage_path('app/quittio/base-rates.json')` | JSON file written by `php artisan quittio:base-rate:update` with the rates of the Deutsche Bundesbank. `null` = not used. |

See [Country packs: base rate table](country-packs.md#base-rate-table) and [quittio:base-rate:update](commands.md#quittiobase-rateupdate).

## Seller

Your company as it appears on every invoice. The section is used by `Quittio::invoice()`, the document methods (`Quittio::quote()` …), `Seller::fromConfig()` and `Seller::apply()` (see [Laravel integration](laravel.md#seller-from-the-configuration)). With `quittio/managed`, issuer profiles replace it.

| Key | Type | Default | Effect |
|---|---|---|---|
| `seller.name` | string | `env('QUITTIO_SELLER_NAME')` | Legal name of the company (BT-27). Required. |
| `seller.vat_id` | string\|null | `env('QUITTIO_SELLER_VAT_ID')` | VAT identification number with country prefix, e.g. `'DE123456789'` (BT-31) |
| `seller.tax_number` | string\|null | `env('QUITTIO_SELLER_TAX_NUMBER')` | Tax number from the tax office, e.g. `'21/815/08150'` (BT-32). German invoices need the VAT identification number or the tax number. |
| `seller.legal_registration` | string\|null | `null` | Commercial register number, e.g. `'HRB 12345'` (legal registration identifier, BT-30) |
| `seller.identifiers` | list | `[]` | Further identifiers of the company (BT-29): `['value' => '…', 'scheme' => null]` (the scheme is optional) or plain strings, e.g. `[['value' => '4000001000005', 'scheme' => '0088']]` for a GLN (Global Location Number; `0088` is its code in the ISO 6523 list). Empty values are skipped. Without a VAT identification number, EN 16931 needs an identifier here or `legal_registration`, otherwise rendering fails with BR-CO-26. |
| `seller.additional_legal_info` | string\|null | `null` | Further legal information printed on the invoice (BT-33), e.g. the court of registration and the managing directors |
| `seller.address.line1` | string\|null | `null` | Street and number (BT-35). Required on German invoices. |
| `seller.address.line2` | string\|null | `null` | Additional address line (BT-36) |
| `seller.address.postcode` | string\|null | `null` | Postcode (BT-38). Required on German invoices. |
| `seller.address.city` | string\|null | `null` | City (BT-37). Required on German invoices. |
| `seller.address.country` | string\|null | `null` | Country code, ISO 3166-1 alpha-2 (BT-40), e.g. `'DE'`. It also selects the leading country pack. `null` = `defaults.country`, without it the code of the only installed country pack. |
| `seller.contact.name` / `.phone` / `.email` | string\|null | `null` | Contact person (BG-6), added only when at least one value is set. XRechnung requires name, phone and e-mail. |
| `seller.electronic_address.value` | string\|null | `null` | Electronic address of the seller (BT-34), added only when set. Required by XRechnung and Peppol. |
| `seller.electronic_address.scheme` | string | `'EM'` | Scheme of the electronic address, a code of the EAS list (electronic address schemes): `EM` = e-mail address, `9930` = German VAT number (as Peppol participant ID) |
| `seller.bank_accounts` | list | `[['iban' => null, 'bic' => null, 'name' => null]]` | Bank accounts. `Quittio::invoice()` (`Seller::apply()`) adds a SEPA credit transfer with the first entry whose `iban` is not empty: the PDF shows the IBAN and a payment QR code. `bic` and `name` are optional. The other entries are ignored. |
| `seller.small_business` | bool | `(bool) env('QUITTIO_SELLER_SMALL_BUSINESS', false)` | `Quittio::invoice()` (`Seller::apply()`) marks the invoice as an invoice of a small business (Germany: section 19 UStG, `Meta::FLAG_SMALL_BUSINESS`). Lines without `->vat()` get the small business preset of the country pack (Germany: `VatPreset::SmallBusiness`) with its legal note. Country packs reject VAT on the invoice; Germany also rejects the intra-community supply exemption, except for new vehicles. |

## PDF and design

The look of the PDF and HTML output. The design of a single invoice and `->presentation()` on a render call override these values property by property.

| Key | Type | Default | Effect |
|---|---|---|---|
| `pdf.renderer` | string | `'mpdf'` | PDF engine: `mpdf` (bundled) or a name registered with `Quittio::extend('renderer', …)`. An unknown name fails with a `ConfigurationException`. |
| `pdf.template` | string | `'quittio::templates.default.layout'` | Blade view of the invoice layout. It is used for the template name `default`, i.e. for every render without `->template()` or `Presentation::$template`. Create your own copy of the default template with `php artisan make:quittio-template`, or name your own view. |
| `pdf.templates` | list | `['invoices.*']` | Further views that `->template()`, `Presentation::$template` or issuer branding may select, besides `pdf.template` and the shipped `quittio::templates.*` views. `prefix*` allows every view below a prefix (`invoices.*`, `mytheme::*`). Partials (`.partials.`) are never selectable. Views not listed are refused. A missing key means `['invoices.*']`, `[]` allows no further views. See [Blade templates](laravel.md#blade-templates). |
| `pdf.layout` | string\|null | `null` | Letter layout, i.e. the position of the address field: `free` (no norm), with the German pack also `din5008a` and `din5008b` (DIN 5008, the German standard for business letters). `null` = the default of the country pack (Germany: `din5008b`). An unknown layout fails with a `ConfigurationException`. See [Letter layouts](templates.md#letter-layouts). |
| `pdf.paper` | string\|null | `null` | Paper size, e.g. `A4`, `Letter`. `null` = the default of the country pack (Germany: A4). A size set here wins over the country default, also `A4`. The invoice presentation and `->presentation()` win over it. |
| `pdf.pdfa` | bool | `true` | Create plain PDFs (format `pdf`, quotes, reminders and other documents) as PDF/A-3b, the PDF standard for archiving. PDFs with embedded XML (ZUGFeRD/Factur-X) are always PDF/A-3b. PDFs with a watermark (drafts, previews) never are. |
| `pdf.font` | string | `'geist'` | Default font family (plain family name). Geist (Latin, Cyrillic) and Inter (also Greek) are bundled, see [Fonts](templates.md#fonts). |
| `pdf.fonts` | array | `[]` | Additional TrueType fonts for mPDF: lower-case family => `['R' => 'Regular.ttf', 'B' => 'Bold.ttf', 'I' => 'Italic.ttf', 'BI' => 'BoldItalic.ttf']`, files relative to `pdf.font_directories` |
| `pdf.font_directories` | list | `[]` | Directories mPDF searches for the font files of `pdf.fonts`. `resources/fonts/quittio` and its subdirectories are added after the configured directories when they exist, so fonts placed there need no entry here. (`--tag=quittio-fonts` publishes Geist and Inter there, to `resources/fonts/quittio/geist` and `…/inter`.) mPDF takes a file from the first directory that has it: these directories come before the bundled fonts and those of mPDF. |
| `pdf.logo` | string\|null | `null` | Logo: absolute path, `http(s)` URL or data URI of a PNG, JPEG or SVG. At most 10 MB and 25 megapixels; URLs only from public hosts. For PDF/A, logos are flattened to an RGB PNG; SVG logos then need the PHP extension `imagick`. Details in [Templates and design](templates.md#logos). |
| `pdf.colors.primary` | string | `'#2741d9'` | Primary colour (hex): issuer name, title, gross total or amount due |
| `pdf.colors.text` | string | `'#15171c'` | Text colour (hex) |
| `pdf.colors.muted` | string | `'#5f6470'` | Colour of secondary text (hex): labels, descriptions, sender line, footer |
| `pdf.colors.secondary` | string\|null | `null` | Accent colour (hex), e.g. section titles and the mark of highlighted lines (`design.secondaryColor` in templates). `null` = the primary colour. |
| `pdf.sender_line` | string\|null | `null` | Small sender line above the address field. `null` = generated from the seller. |
| `pdf.footer_columns` | list | `[]` | Footer columns, each a list of lines: `[['Muster GmbH', 'Hauptstraße 1'], ['IBAN DE02 …']]`. `[]` = footer generated from the seller data. |
| `pdf.texts.salutation` | string\|null | `null` | Salutation above the intro text. `null` = none. |
| `pdf.texts.intro` | string\|null | `null` | Text above the lines (Markdown, PDF only). `null` = none. |
| `pdf.texts.closing` | string\|null | `null` | Text below the totals (Markdown, PDF only). `null` = none. |
| `pdf.show_item_numbers` | bool | `false` | Print the seller's item number (BT-155) next to the item name |
| `pdf.markdown` | bool | `false` | Render line descriptions and notes as Markdown instead of plain text. It is off by default: the same texts are in the e-invoice XML, where formatting characters such as `*` or `_` stay visible and can change the displayed text. |
| `pdf.fold_marks` | bool | `true` | Print fold and punch marks for window envelopes, at the left edge of the first page |
| `pdf.payment_qr.type` | string | `'auto'` | Payment QR code in the PDF. `auto`: the generator below, otherwise the code of the country pack (Germany: GiroCode, the EPC QR code for banking apps). `girocode`: the EPC QR code. `swiss-qr-bill`: needs `pdf.payment_qr.generator`; Quittio ships no Swiss QR bill generator, so without one rendering fails with a `ConfigurationException`. `none`: no code. Unknown values fail with a `ConfigurationException`. Refunds and self-billed invoices never get a code. |
| `pdf.payment_qr.generator` | class-string\|null | `null` | Class implementing `Quittio\Core\Pdf\Qr\PaymentQrGenerator`, resolved from the container. It is used for `auto` before the country pack's generator and required for `swiss-qr-bill`. Another class fails with a `ConfigurationException`. |
| `pdf.payment_qr.girocode_amount` | string | `'due'` | Amount in the GiroCode: `due` (amount due) or `discounted` (amount after the first cash discount; the PDF then notes until when it is valid) |
| `pdf.filename` | string | `'{title}_{number}.{ext}'` | File name pattern of the results. Placeholders: `{title}` (document title in the language of the invoice, e.g. "Rechnung", German for invoice), `{number}`, `{date}` (issue date, `Y-m-d`), `{format}` (format key; the document type for quotes and other documents), `{ext}` (`pdf`, `xml`, `html`, `json`). Title and number are reduced to letters, digits, `.`, `_` and `-`. |
| `pdf.cache_path` | string\|null | `storage_path('framework/cache/quittio')` | Temporary directory of mPDF. Prepared logos are cached in its `logos` subdirectory. `null` = `sys_get_temp_dir().'/quittio-mpdf'`. |
| `pdf.renderers.mpdf.simple_tables` | bool | `false` | mPDF `simpleTables`: drops the borders of table cells and is not faster. Keep it off. |
| `pdf.renderers.mpdf.pdfa_auto` | bool | `false` | mPDF `PDFAauto`: let mPDF correct PDF/A errors itself instead of failing |
| `pdf.renderers.{name}` | array | – | Options passed to the factory of a renderer registered with `Quittio::extend('renderer', '{name}', …)` |

The design keys (`logo`, `colors`, `font`, `sender_line`, `layout`, `texts`, `footer_columns`, `show_item_numbers`, `payment_qr.type`, `paper`) are design level 1 of [Templates and design](templates.md). The invoice presentation and `->presentation()` override them property by property.

## Validation

Every invoice is checked before it is created: against EN 16931, the rules of the format (XRechnung, Peppol) and those of the country (Germany: UStG). See [Rules and validation](concepts.md#rules-and-validation) for the basics and [Validation](validation.md) for the details.

| Key | Type | Default | Effect |
|---|---|---|---|
| `validation.mode` | string | `env('QUITTIO_VALIDATION_MODE', 'error')` | What happens with errors. `error`: throw a `ValidationException`. `warn`: create the invoice anyway and attach the result (`$result->validation()`). `off`: no validation while rendering. Per call: `->validate()`, `->validate(false)`, `->withoutValidation()`. |
| `validation.levels` | list | `['php', 'xsd']` | Checks that run while rendering and in `validateFile()`: `php` (rules of EN 16931, format and country; levels L1–L3 and L7), `xsd` (XML schema, L4), `kosit` (the official KoSIT validator for XRechnung and Peppol, needs Java; L5), `verapdf` (PDF/A check, needs veraPDF; L6), or the name of a driver registered with `Quittio::extend('validator', …)`. Without `php`, the PHP rules do not run. |
| `validation.block_on_warnings` | bool | `false` | In mode `error`, warnings block like errors |
| `validation.locale` | string\|null | `env('QUITTIO_VALIDATION_LOCALE')` | Language of the validation messages and hints. `null` = the language of the application (`app()->getLocale()`), `'invoice'` = the language of the invoice, or a locale such as `'de'`. The invoice itself keeps its language. |
| `validation.mandatory_field_guard` | string\|null | `env('QUITTIO_MANDATORY_FIELD_GUARD')` | Check that the PDF shows every legally required value (the template could leave one out): `exception`, `log` or `off`. `null` = `exception` outside production, `log` in production. See [Mandatory field guard](validation.md#mandatory-field-guard). |
| `validation.drivers.kosit.mode` | string | `'daemon'` | `daemon` (a running validator via HTTP, fast) or `cli` (one Java call per file) |
| `validation.drivers.kosit.url` | string | `env('QUITTIO_KOSIT_URL', 'http://127.0.0.1:8081')` | Address of the validator daemon. The validator listens on port 8080 unless started with `-P 8081` (or mapped, `docker run -p 8081:8080`). `quittio:schemas:download` prints a start command for the port of this URL. |
| `validation.drivers.kosit.jar` | string\|null | `null` | Validator JAR (mode `cli`) |
| `validation.drivers.kosit.scenarios` | string\|null | `null` | Scenario file of the main configuration (mode `cli`, e.g. XRechnung) |
| `validation.drivers.kosit.repository` | string\|null | `null` | Repository directory of the main configuration (mode `cli`) |
| `validation.drivers.kosit.configurations` | list | `[]` | Further scenario sets `[['scenarios' => …, 'repository' => …]]` (mode `cli`), e.g. Peppol BIS. `quittio:schemas:download` prints the values. |
| `validation.drivers.kosit.java` | string | `'java'` | Java binary (mode `cli`) |
| `validation.drivers.kosit.timeout` | int | `30` | Timeout in seconds |
| `validation.drivers.kosit.fail_on_unavailable` | bool | `true` | `true` = an unreachable validator is an error (`KOSIT-UNAVAILABLE`), `false` = a warning |
| `validation.drivers.verapdf.mode` | string | `'cli'` | `cli` (local `verapdf` binary) or `docker` (Docker image; the PDF is passed via stdin) |
| `validation.drivers.verapdf.binary` | string | `'verapdf'` | veraPDF binary (mode `cli`) |
| `validation.drivers.verapdf.image` | string | `'verapdf/cli'` | Docker image (mode `docker`) |
| `validation.drivers.verapdf.docker` | string | `'docker'` | Docker binary (mode `docker`) |
| `validation.drivers.verapdf.timeout` | int | `120` | Timeout in seconds |
| `validation.drivers.verapdf.fail_on_unavailable` | bool | `true` | `true` = an unavailable veraPDF is an error, `false` = a warning |
| `validation.drivers.vies.enabled` | bool | `false` | Check VAT identification numbers online with VIES, the VAT number service of the EU (`QUITTIO-VIES`, `QUITTIO-VIES-UNAVAILABLE`). A `Quittio\Core\Validation\Plausibility\VatIdVerifier` bound in the container replaces the VIES client (e.g. in tests). |
| `validation.drivers.vies.cache_ttl` | int | `86400` | Seconds a VALID or INVALID answer is cached (in memory, per process) |
| `validation.drivers.vies.timeout` | int | `5` | Timeout in seconds |
| `validation.drivers.{name}` | array | – | Options passed to the factory of a validator registered with `Quittio::extend('validator', '{name}', …)` |

The KoSIT and veraPDF drivers run only when `kosit` and `verapdf` are listed in `validation.levels`. `quittio:validate --kosit --verapdf` adds them for one file, with the same settings. Levels, messages and the official validators: see [Validation](validation.md).

## Corrections

| Key | Type | Default | Effect |
|---|---|---|---|
| `corrections.cancellation_strategy` | string\|null | `env('QUITTIO_CANCELLATION_STRATEGY')` | How cancellations (`$result->cancel()`, `Quittio::cancellation()` and Managed cancellations) are written. `credit_note`: type code 381 with positive amounts. `negative_invoice`: type code 380 with negative amounts. `null` = the default of the country pack (Germany: `credit_note`; both are permitted). The `strategy:` argument wins. An unknown value fails with a `ConfigurationException`. See [Cancellations and corrections](building-invoices.md#cancellations-and-corrections). |

## Snapshots

A snapshot is an invoice stored as JSON together with the version of Quittio's data model. Queued render jobs and the Managed layer store invoices this way.

| Key | Type | Default | Effect |
|---|---|---|---|
| `snapshots.upcasters` | list | `[]` | Classes (resolved through the container) or instances implementing `Quittio\Core\Serialization\Snapshots\SnapshotUpcaster`. They bring stored snapshots of older data model versions up to date when they are read: `InvoiceData::fromSnapshot()`, queued `RenderInvoice` jobs and the invoices of the Managed layer. Only needed after an update that changes the data model. Other values fail with a `ConfigurationException`. |

An upcaster reads one version and returns the next one:

```php
use Quittio\Core\Serialization\Snapshots\SnapshotUpcaster;

final class RenameReferenceField implements SnapshotUpcaster
{
    public function from(): int
    {
        return 1;                                  // produces version 2
    }

    public function upcast(array $snapshot): array  // without the "_version" key
    {
        $snapshot['buyerReference'] ??= $snapshot['reference'] ?? null;
        unset($snapshot['reference']);

        return $snapshot;
    }
}
```

## Reader

Limits for reading incoming e-invoices, as protection against oversized files. They apply to `Quittio::read()`, `readFile()`, `validateFile()`, `validatePath()`, `quittio:read` and `quittio:validate`. See [Reading incoming invoices](reading.md#limits-and-security).

| Key | Type | Default | Effect |
|---|---|---|---|
| `reader.max_xml_bytes` | int | `10 * 1024 * 1024` | Largest XML file (bytes) |
| `reader.max_pdf_bytes` | int | `25 * 1024 * 1024` | Largest PDF file (bytes) |
| `reader.max_decompressed_bytes` | int | `50 * 1024 * 1024` | Largest decompressed content of the PDF streams (bytes) |

## Format resolver

The format resolver makes the automatic choice of the format when a render call names none ([Automatic choice](formats.md#automatic-choice)).

| Key | Type | Default | Effect |
|---|---|---|---|
| `format_resolver.driver` | string | `'default'` | `default` (the built-in choice) or the name of a resolver registered with `Quittio::extend('format_resolver', …)`. The factory receives the whole `format_resolver` array. An unknown name fails with a `ConfigurationException`. |
| `format_resolver.peppol_lookup` | class-string\|null | `null` | Peppol lookup of the `default` resolver: an invokable class, resolved from the container, that receives the `Quittio\Core\Data\Party\BuyerParty` and returns `true` when the buyer receives invoices via Peppol (an international delivery network for e-invoices). The automatic choice then picks `peppol` for business buyers that no domestic country rule decided. See [Peppol lookup](laravel.md#peppol-lookup). At runtime a callable works as well. Anything else fails with a `ConfigurationException`. `null` = no lookup, the resolver never picks `peppol`. |
| `format_resolver.b2c_embed_xml` | bool | `false` | `true` = embed the e-invoice XML in CII syntax (`factur-x.xml`, profile from `defaults.profile`) also into plain PDFs (format `pdf`, e.g. the automatic choice for consumers). These PDFs are then PDF/A-3b. |
| `format_resolver.auto_upgrade_profile` | bool | `true` | When an invoice uses EXTENDED features (e.g. sub lines) and no profile was requested, CII formats switch to the profile EXTENDED, which has more fields than EN 16931. `false` = fail with a `ConfigurationException` instead. |

Published files of earlier versions may contain a `networks` section (mail, Peppol, French platform). Nothing reads it, so remove it. The Peppol lookup above replaces `networks.peppol`.

## Storage, queue and preview

| Key | Type | Default | Effect |
|---|---|---|---|
| `storage.disk` | string\|null | `env('QUITTIO_STORAGE_DISK', 'local')` | Filesystem disk (`config/filesystems.php`, e.g. `local` or `s3`) of `->save()` on rendered results and of queued renders without a disk. `null` or `''` = the default disk of `config/filesystems.php`. The archive of the Managed layer uses `quittio-managed.disk`. |
| `queue.connection` | string\|null | `null` | Queue connection of `->queue()`. `null` = the default connection. |
| `queue.queue` | string\|null | `'quittio'` | Queue name of `->queue()`. Workers must listen on it: `php artisan queue:work --queue=quittio,default`. |
| `queue.after_commit` | bool | `true` | Inside a database transaction the job is dispatched after the commit, and not at all after a rollback. `false` = at once. |
| `preview.enabled` | bool\|null | `env('QUITTIO_PREVIEW_ENABLED')` | `true` = register the browser preview of the sample invoices, `false` = never. `null` = only in the `local` environment. Never registered while routes are cached (`route:cache`). |
| `preview.path` | string | `'_quittio/preview'` | URL prefix of the preview |
| `preview.middleware` | list | `['web']` | Middleware of the preview routes, e.g. `['web', 'auth']` when you enable it outside `local`. Outside `local` the gate `viewQuittioPreview` must also allow the request ([Preview route](laravel.md#preview-route)). |
| `preview.throttle` | string\|null | `'30,1'` | Rate limit of the preview as `attempts,minutes` (Laravel's `throttle` middleware); `null` = none |

See [Saving, downloading and mailing](laravel.md#saving-downloading-and-mailing), [Queueing](laravel.md#queueing) and [Preview route](laravel.md#preview-route).

## Serializer extensions

`serializers.{name}` (not in the published file) holds the options passed to the factory of a serializer registered with `Quittio::extend('serializer', '{name}', …)`. Registered serializers are always used. They replace the built-in XML writer of their syntax (CII or UBL, see [Syntaxes](concepts.md#syntaxes-cii-and-ubl)).

## Without Laravel

The core takes the same settings as a `Quittio\Core\Config` object or as an array for `Quittio::create()` (`Config::fromArray()`). Some keys are shaped differently there, e.g. `validation.levels` as `L1` … `L7`, `pdf.b2c_embed_xml`, and `format` instead of `defaults.format`. See [Getting started](getting-started.md#without-laravel), which maps every core key to its Laravel key.
