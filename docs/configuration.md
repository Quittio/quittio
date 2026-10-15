# Configuration

`quittio/laravel` reads `config/quittio.php`. Publish it with the installer or the publish tag:

```bash
php artisan quittio:install                              # config/quittio.php (see commands.md)
php artisan vendor:publish --tag=quittio-config           # the same, without the hints
```

Without a published file the package defaults apply. Laravel merges the package file with
yours by top-level key: a section you leave out (for example `preview`) keeps the package
defaults, while inside a section you keep, missing keys fall back to the built-in defaults
listed below.

The manager (a singleton) reads the configuration once, when it is used for the first time;
in queue workers and under Octane that is once per worker process. After changing values at
runtime (tests, per-tenant settings) call `Quittio::flush()`, so that the next call builds
the core again:

```php
config()->set('quittio.pdf.colors.primary', '#0f766e');
Quittio::flush();
```

`seller`, `storage.disk` and `queue` are read on every call; `preview` and
`snapshots.upcasters` are read when the application boots.

A value of the wrong type fails with a `ConfigurationException` that names the key, e.g.
`quittio.pdf.pdfa must be a boolean.`

The Managed layer has its own file, `config/quittio-managed.php` (database, issuers,
archive, queue, numbering, corrections, delivery, audit log, retention and policies); its
keys are described in the [Managed layer](managed.md). Issuer profiles of the Managed layer
replace the `seller` section below.

## Environment variables

| Variable | Key | Default |
|---|---|---|
| `QUITTIO_COUNTRY` | `defaults.country` | `DE` |
| `QUITTIO_SELLER_NAME` | `seller.name` | – |
| `QUITTIO_SELLER_VAT_ID` | `seller.vat_id` | – |
| `QUITTIO_SELLER_TAX_NUMBER` | `seller.tax_number` | – |
| `QUITTIO_VALIDATION` | `validation.mode` | `error` |
| `QUITTIO_GUARD` | `validation.mandatory_field_guard` | – (`exception`, in production `log`) |
| `QUITTIO_KOSIT_URL` | `validation.drivers.kosit.url` | `http://127.0.0.1:8081` (start the validator with `-P 8081`; its own default port is 8080) |
| `QUITTIO_CANCELLATION_STRATEGY` | `corrections.cancellation_strategy` | – (country pack) |
| `QUITTIO_DISK` | `storage.disk` | `local` |
| `QUITTIO_PREVIEW` | `preview.enabled` | – (local environment only) |

`QUITTIO_PREVIEW` must be `true` or `false`; other values count as not set.

## Defaults

| Key | Type | Default | Effect |
|---|---|---|---|
| `defaults.country` | string\|null | `env('QUITTIO_COUNTRY', 'DE')` | Country pack used when the seller has no country (BT-40). `->country()` and the seller's country win. Without an installed pack for the code, only the generic EN 16931 rules apply. |
| `defaults.profile` | string\|null | `null` | Default profile for every format that allows it, also for `->format('zugferd')` and formats chosen by a country rule; skipped where the format does not allow it (e.g. `basic` for XRechnung or Peppol). `null` = the profile of the automatic choice or the format's default (`zugferd`, `cii`, `ubl`: `en16931`; `xrechnung-cii`, `xrechnung-ubl`: `xrechnung`; `peppol`: `peppol`). `->profile()` always wins. See [Formats and profiles](formats.md). |
| `defaults.format` | string\|null | `null` | Format of a render call without `->format()`. `null` or `'auto'` = automatic choice by the format resolver (see [Formats and profiles](formats.md)). Keys: `zugferd` (aliases `factur-x`, `facturx`), `xrechnung-cii` (alias `xrechnung`), `xrechnung-ubl`, `cii`, `ubl`, `peppol`, `pdf`, `html`, `json`. |
| `defaults.locale` | string\|null | `null` | Locale of labels and number/date formatting when neither `->locale()` nor the invoice (`InvoiceBuilder::locale()`) sets one. `null` = default of the country pack (Germany: `de`). |
| `defaults.vat` | string\|null | `null` (`QUITTIO_DEFAULT_VAT`) | Tax preset of invoice lines without `->vat()`, by key (e.g. `de.reduced_7`). `null` = the standard rate of the seller's country pack (Germany: `de.standard_19`). Invoices of small businesses (`seller.small_business`) use the small business preset of the pack (`de.small_business`). An unknown key fails with a `ConfigurationException` when a line needs the default. See [Default VAT](building-invoices.md#default-vat). |
| `defaults.due_days` | int\|numeric string\|null | `14` | Due date (BT-9) = issue date + this number of days, for invoices that set neither a due date nor payment terms; never for credit notes. `null` or `''` switches it off; other values fail with a `ConfigurationException`. |

## Country packs

```php
'countries' => [
    // 'DE' => \Quittio\Country\DE\CountryDE::class,
],
```

Country packs are found in three ways; all of them are combined:

1. **Package discovery**: a Composer package (or your root `composer.json`) lists its packs
   under `extra.quittio.country-packs`. `quittio/country-de` does this, so
   `composer require quittio/country-de` is enough.

   ```json
   "extra": {
       "quittio": {
           "country-packs": ["App\\Quittio\\CountryAT"]
       }
   }
   ```

2. **Configuration**: `countries` lists further pack classes (the array keys are ignored,
   the pack's `code()` counts). Classes are resolved through the container.
3. **At runtime**: `Quittio::registerCountry(new CountryAT)`, e.g. in a service provider.
   It wins over the other two for the same country code and survives `Quittio::flush()`.

Every pack must implement `Quittio\Core\Countries\CountryPack`, otherwise building the core
fails with a `ConfigurationException`. For the same country code the last one wins:
discovered packs, then `countries` (so a subclass of `CountryDE` listed here replaces the
discovered `CountryDE`), then `Quittio::registerCountry()`. `php artisan quittio:countries` and `php artisan about` list
the active packs. Writing your own pack: see [Country packs](country-packs.md).

### Country pack Germany (`config/quittio-de.php`)

The German pack has a small configuration of its own (publish it with
`php artisan vendor:publish --tag=quittio-de-config`):

| Key | Type | Default | Effect |
|---|---|---|---|
| `base_rates` | array | `[]` | Base rates (§ 247 BGB) in addition to the table shipped with Quittio, ISO date (1 January or 1 July) => percent, e.g. `['2027-01-01' => '1.77']`; they win over the file |
| `base_rate_file` | string\|null | `storage_path('app/quittio/base-rates.json')` | JSON file written by `php artisan quittio:base-rate:update`; `null` = not used |

See [Country packs: base rate table](country-packs.md#base-rate-table).

## Seller

Used by `Quittio::invoice()`, the document methods (`Quittio::quote()` …), `Seller::fromConfig()` and `Seller::apply()` (see
[Laravel integration](laravel.md#seller-from-the-configuration)). With `quittio/managed`,
issuer profiles replace it.

| Key | Type | Default | Effect |
|---|---|---|---|
| `seller.name` | string | `env('QUITTIO_SELLER_NAME')` | Seller name (BT-27) |
| `seller.vat_id` | string\|null | `env('QUITTIO_SELLER_VAT_ID')` | VAT identifier (BT-31) |
| `seller.tax_number` | string\|null | `env('QUITTIO_SELLER_TAX_NUMBER')` | Tax number (BT-32) |
| `seller.legal_registration` | string\|null | `null` | Legal registration identifier (BT-30), e.g. `HRB 12345` |
| `seller.identifiers` | list | `[]` | Seller identifiers (BT-29): `['value' => '…', 'scheme' => null]` or plain strings; empty values are skipped. Without a VAT identifier (e.g. a small business with a tax number only), EN 16931 needs BT-29 or BT-30, otherwise rendering fails with BR-CO-26. |
| `seller.additional_legal_info` | string\|null | `null` | Additional legal information (BT-33), e.g. court of registration and managing directors |
| `seller.address.line1` | string\|null | `null` | Street and number (BT-35) |
| `seller.address.line2` | string\|null | `null` | Additional address line (BT-36) |
| `seller.address.postcode` | string\|null | `null` | Postcode (BT-38) |
| `seller.address.city` | string\|null | `null` | City (BT-37) |
| `seller.address.country` | string | `'DE'` | Country code (BT-40); also selects the leading country pack |
| `seller.contact.name` / `.phone` / `.email` | string\|null | `null` | Seller contact (BG-6); added only when at least one value is set |
| `seller.electronic_address.value` | string\|null | `null` | Electronic address (BT-34); added only when set |
| `seller.electronic_address.scheme` | string | `'EM'` | Scheme of the electronic address (EAS code, `EM` = e-mail) |
| `seller.bank_accounts` | list | `[['iban' => null, 'bic' => null, 'name' => null]]` | `Quittio::invoice()` (`Seller::apply()`) adds a credit transfer with the first entry whose `iban` is not empty (`bic` and `name` optional); the other entries are ignored. |
| `seller.small_business` | bool | `false` | `Quittio::invoice()` (`Seller::apply()`) marks the invoice as an invoice of a small business (Germany: § 19 UStG, `Meta::FLAG_SMALL_BUSINESS`); country packs then reject VAT on it (Germany also the intra-community supply exemption, except for new vehicles). Use `VatPreset::SmallBusiness` for the lines. |

## PDF and design

| Key | Type | Default | Effect |
|---|---|---|---|
| `pdf.renderer` | string | `'mpdf'` | PDF renderer: `mpdf` or a name registered with `Quittio::extend('renderer', …)`; an unknown name fails with a `ConfigurationException`. |
| `pdf.template` | string | `'quittio::templates.default.layout'` | Blade view used for the template name `default`, i.e. for every render without `->template()` or `Presentation::$template`. |
| `pdf.templates` | list | `['invoices.*']` | Further views that `->template()`, `Presentation::$template` or issuer branding may select, besides `pdf.template` and the shipped `quittio::templates.*` views. `prefix*` allows every view below a prefix (`invoices.*`, `mytheme::*`); partials (`.partials.`) are never selectable. A missing key means `['invoices.*']`, `[]` allows no further views. See [Blade templates](laravel.md#blade-templates). |
| `pdf.layout` | string\|null | `null` | Letter layout; `null` = country pack default (Germany: `din5008b`, also `din5008a`). An unknown layout fails with a `ConfigurationException`. |
| `pdf.paper` | string\|null | `null` | Paper size, e.g. `A4`, `Letter`. `null` = country pack default (Germany: A4); a size set here wins over the country default, also `A4`. The invoice presentation and `->presentation()` win over it. |
| `pdf.pdfa` | bool | `true` | Render plain PDFs (format `pdf`, documents) as PDF/A-3b. PDFs with embedded XML (ZUGFeRD/Factur-X) are always PDF/A-3b; watermarked PDFs (drafts, previews) never. |
| `pdf.font` | string | `'inter'` | Default font family (plain family name). Inter is bundled. |
| `pdf.fonts` | array | `[]` | Additional fonts for mPDF: lower-case family => `['R' => file, 'B' => file, 'I' => file, 'BI' => file]`, files relative to `pdf.font_directories`. |
| `pdf.font_directories` | list | `[]` | Directories mPDF searches for the font files of `pdf.fonts`, after its own and the bundled Inter. `resources/fonts/quittio` and its subdirectories (where `--tag=quittio-fonts` publishes Inter, to `resources/fonts/quittio/inter`) are added after the configured directories when they exist, so fonts placed there need no entry here. mPDF takes a file from the first directory that has it; the bundled Inter comes before all of these. |
| `pdf.logo` | string\|null | `null` | Logo: absolute path, `http(s)` URL or data URI of a PNG, JPEG or SVG; at most 10 MB and 25 megapixels, URLs only from public hosts. For PDF/A logos are flattened to an RGB PNG; SVG needs the `imagick` extension. Details in [Templates and design](templates.md#logos). |
| `pdf.colors.primary` | string | `'#1e40af'` | Primary colour (hex) |
| `pdf.colors.text` | string | `'#111827'` | Text colour (hex) |
| `pdf.colors.muted` | string | `'#6b7280'` | Colour of secondary text (hex) |
| `pdf.colors.secondary` | string\|null | not in the file (`null`) | Secondary colour (`design.secondaryColor`); `null` = the primary colour |
| `pdf.sender_line` | string\|null | `null` | Sender line above the address field; `null` = generated from the seller |
| `pdf.footer_columns` | list | `[]` | Footer columns, each a list of lines: `[['Muster GmbH', 'Hauptstraße 1'], ['IBAN DE02 …']]`. `[]` = footer generated from the seller data. |
| `pdf.texts.salutation` | string\|null | `null` | Salutation above the intro text; `null` = none |
| `pdf.texts.intro` | string\|null | `null` | Text above the lines (Markdown, PDF only) |
| `pdf.texts.closing` | string\|null | `null` | Text below the totals (Markdown, PDF only) |
| `pdf.show_item_numbers` | bool | `false` | Print the seller's item number (BT-155) next to the item name |
| `pdf.markdown` | bool | `false` | Render line descriptions and notes as Markdown instead of plain text. They are also in the e-invoice XML, and formatting can change the displayed text (e.g. `*` for emphasis), so it is off by default. |
| `pdf.fold_marks` | bool | `false` | Print fold and punch marks |
| `pdf.payment_qr.type` | string | `'auto'` | Payment QR code: `auto` (the generator below, otherwise the code of the country pack, Germany: GiroCode), `girocode` (EPC QR code), `swiss-qr-bill` (needs `pdf.payment_qr.generator`; Quittio ships no Swiss QR bill generator, without one rendering fails with a `ConfigurationException`) or `none`. Unknown values fail with a `ConfigurationException`. Refunds and self-billed invoices never get a code. |
| `pdf.payment_qr.generator` | class-string\|null | `null` | Class implementing `Quittio\Core\Pdf\Qr\PaymentQrGenerator`, resolved from the container: used for `auto` before the country pack's generator and required for `swiss-qr-bill`. Another class fails with a `ConfigurationException`. |
| `pdf.payment_qr.girocode_amount` | string | `'due'` | Amount in the GiroCode: `due` (amount due) or `discounted` (amount after the first cash discount; the PDF then notes until when it is valid). |
| `pdf.filename` | string | `'{title}_{number}.{ext}'` | File name pattern of the results. Placeholders: `{title}` (document title, e.g. "Rechnung"), `{number}`, `{date}` (issue date, `Y-m-d`), `{format}` (format key; document type for quotes and other documents), `{ext}` (`pdf`, `xml`, `html`, `json`). Title and number are reduced to letters, digits, `.`, `_` and `-`. |
| `pdf.cache_path` | string\|null | `storage_path('framework/cache/quittio')` | Temporary directory of mPDF; prepared logos are cached in its `logos` subdirectory. `null` = `sys_get_temp_dir().'/quittio-mpdf'`. |
| `pdf.renderers.mpdf.simple_tables` | bool | `false` | mPDF `simpleTables`: drops the borders of table cells and is not faster. Keep it off. |
| `pdf.renderers.mpdf.pdfa_auto` | bool | `false` | mPDF `PDFAauto`: let mPDF correct PDF/A violations itself instead of failing |
| `pdf.renderers.{name}` | array | – | Options passed to the factory of a renderer registered with `Quittio::extend('renderer', '{name}', …)` |

The design keys (`logo`, `colors`, `font`, `sender_line`, `layout`, `texts`,
`footer_columns`, `show_item_numbers`, `payment_qr.type`, `paper`) are the design level 1
of [Templates and design](templates.md); the invoice presentation and `->presentation()`
override them property by property.

## Validation

| Key | Type | Default | Effect |
|---|---|---|---|
| `validation.mode` | string | `env('QUITTIO_VALIDATION', 'error')` | `error`: throw a `ValidationException` on errors; `warn`: attach the result only; `off`: no validation while rendering. Per call: `->validate()`, `->validate(false)`, `->withoutValidation()`. |
| `validation.levels` | list | `['php', 'xsd']` | Validation run while rendering and by `validateFile()`: `php` (L1–L3, L7), `xsd` (L4), `kosit` (L5, KoSIT validator), `verapdf` (L6), or the name of a driver registered with `Quittio::extend('validator', …)`. Without `php`, the PHP rules do not run. |
| `validation.block_on_warnings` | bool | `false` | In mode `error`, warnings block as well |
| `validation.mandatory_field_guard` | string\|null | `env('QUITTIO_GUARD')` | Check that the template printed every mandatory value: `exception`, `log` or `off`. `null` = `exception` outside production, `log` in production. |
| `validation.drivers.kosit.mode` | string | `'daemon'` | `daemon` (HTTP to a running validator) or `cli` (one JVM per file) |
| `validation.drivers.kosit.url` | string | `env('QUITTIO_KOSIT_URL', 'http://127.0.0.1:8081')` | Address of the validator daemon. The validator listens on port 8080 unless started with `-P 8081` (or mapped, `docker run -p 8081:8080`); `quittio:schemas:download` prints a start command for the port of this URL. |
| `validation.drivers.kosit.jar` | string\|null | `null` | Validator JAR (mode `cli`) |
| `validation.drivers.kosit.scenarios` | string\|null | `null` | Scenario file of the main configuration (mode `cli`, e.g. XRechnung) |
| `validation.drivers.kosit.repository` | string\|null | `null` | Repository directory of the main configuration (mode `cli`) |
| `validation.drivers.kosit.configurations` | list | `[]` | Further scenario sets `['scenarios' => …, 'repository' => …]` (mode `cli`), e.g. Peppol BIS; `quittio:schemas:download` prints the values |
| `validation.drivers.kosit.java` | string | `'java'` | Java binary (mode `cli`) |
| `validation.drivers.kosit.timeout` | int | `30` | Timeout in seconds |
| `validation.drivers.kosit.fail_on_unavailable` | bool | `true` | An unreachable validator is an error (`KOSIT-UNAVAILABLE`); `false` = a warning |
| `validation.drivers.verapdf.mode` | string | `'cli'` | `cli` (local `verapdf` binary) or `docker` (image, the PDF is passed via stdin) |
| `validation.drivers.verapdf.binary` | string | `'verapdf'` | veraPDF binary (mode `cli`) |
| `validation.drivers.verapdf.image` | string | `'verapdf/cli'` | Docker image (mode `docker`) |
| `validation.drivers.verapdf.docker` | string | `'docker'` | Docker binary (mode `docker`) |
| `validation.drivers.verapdf.timeout` | int | `120` | Timeout in seconds |
| `validation.drivers.verapdf.fail_on_unavailable` | bool | `true` | An unavailable veraPDF is an error; `false` = a warning |
| `validation.drivers.vies.enabled` | bool | `false` | Check VAT identifiers online with the EU VIES service (`QUITTIO-VIES`, `QUITTIO-VIES-UNAVAILABLE`). A `Quittio\Core\Validation\Plausibility\VatIdVerifier` bound in the container replaces the VIES client (e.g. in tests). |
| `validation.drivers.vies.cache_ttl` | int | `86400` | Seconds a VALID or INVALID answer is cached (in memory, per process) |
| `validation.drivers.vies.timeout` | int | `5` | Timeout in seconds |
| `validation.drivers.{name}` | array | – | Options passed to the factory of a validator registered with `Quittio::extend('validator', '{name}', …)` |

The KoSIT and veraPDF drivers run only when `kosit` and `verapdf` are listed in
`validation.levels`; `quittio:validate --kosit --verapdf` adds them for one file with the same
settings. Levels, messages and the official validators: see [Validation](validation.md).

## Corrections

| Key | Type | Default | Effect |
|---|---|---|---|
| `corrections.cancellation_strategy` | string\|null | `env('QUITTIO_CANCELLATION_STRATEGY')` | Sign strategy of `Quittio::cancellation()` and of Managed cancellations: `credit_note` (type code 381, positive amounts) or `negative_invoice` (type code 380, negative amounts). `null` = default of the country pack (Germany: `credit_note`). The `strategy:` argument wins. An unknown value fails with a `ConfigurationException`. |

## Snapshots

| Key | Type | Default | Effect |
|---|---|---|---|
| `snapshots.upcasters` | list | `[]` | Classes (resolved through the container) or instances implementing `Quittio\Core\Serialization\Snapshots\SnapshotUpcaster`. They bring stored invoice snapshots of older data model versions up to date when they are read: `InvoiceData::fromSnapshot()`, queued `RenderInvoice` jobs and the invoices of the Managed layer. Other values fail with a `ConfigurationException`. |

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

Limits for reading incoming files (`Quittio::read()`, `readFile()`, `validateFile()`,
`validatePath()`, `quittio:read`, `quittio:validate`); see [Reading incoming invoices](reading.md#limits-and-security).

| Key | Type | Default | Effect |
|---|---|---|---|
| `reader.max_xml_bytes` | int | `10 * 1024 * 1024` | Largest XML file (bytes) |
| `reader.max_pdf_bytes` | int | `25 * 1024 * 1024` | Largest PDF file (bytes) |
| `reader.max_decompressed_bytes` | int | `50 * 1024 * 1024` | Largest decompressed content of the PDF streams (bytes) |

## Format resolver

| Key | Type | Default | Effect |
|---|---|---|---|
| `format_resolver.driver` | string | `'default'` | `default` (the built-in choice, see [Formats and profiles](formats.md#automatic-choice)) or the name of a resolver registered with `Quittio::extend('format_resolver', …)`; the factory receives the whole `format_resolver` array. An unknown name fails with a `ConfigurationException`. |
| `format_resolver.peppol_lookup` | class-string\|null | `null` | Peppol lookup of the `default` resolver: an invokable class, resolved from the container, that receives the `Quittio\Core\Data\Party\BuyerParty` and returns `true` when the buyer is reachable via Peppol; the automatic choice then picks `peppol` for business buyers that no domestic country rule decided (see [Peppol lookup](laravel.md#peppol-lookup)). At runtime a callable works as well. Anything else fails with a `ConfigurationException`. `null` = no lookup. |
| `format_resolver.b2c_embed_xml` | bool | `false` | Embed a CII XML (`factur-x.xml`, profile from `defaults.profile`) also into plain PDFs (format `pdf`, e.g. the automatic choice for consumers); these PDFs are then PDF/A-3b. |
| `format_resolver.auto_upgrade_profile` | bool | `true` | When an invoice uses EXTENDED features (e.g. sub lines) and no profile was requested, CII formats switch to the profile EXTENDED; `false` = fail with a `ConfigurationException` instead. |

Published files of earlier versions may contain a `networks` section (mail, Peppol, French
platform); nothing reads it, so remove it. The Peppol lookup above replaces
`networks.peppol`.

## Storage, queue and preview

| Key | Type | Default | Effect |
|---|---|---|---|
| `storage.disk` | string | `env('QUITTIO_DISK', 'local')` | Filesystem disk of `->save()` on rendered results and of queued renders without a disk. Empty = the default disk of `config/filesystems.php`. The archive of the Managed layer uses `quittio-managed.disk`. |
| `queue.connection` | string\|null | `null` | Queue connection of `->queue()`; `null` = the default connection |
| `queue.queue` | string\|null | `'quittio'` | Queue name of `->queue()`. Workers must listen on it: `php artisan queue:work --queue=quittio,default`. |
| `preview.enabled` | bool\|null | `env('QUITTIO_PREVIEW', null)` | Register the browser preview route; `null` = only in the `local` environment. Never registered while routes are cached (`route:cache`). |
| `preview.path` | string | `'_quittio/preview'` | URL prefix of the preview |
| `preview.middleware` | list | `['web']` | Middleware of the preview routes, e.g. `['web', 'auth']` when you enable it outside `local` |

See [Preview route](laravel.md#preview-route).

## Serializer extensions

`serializers.{name}` (not in the published file) holds the options passed to the factory of a
serializer registered with `Quittio::extend('serializer', '{name}', …)`. Registered
serializers are always used and replace the built-in writer of their syntax (CII or UBL).

## Without Laravel

The core takes the same settings as `Quittio\Core\Config` or as an array for
`Quittio::create()` (`Config::fromArray()`); some keys are shaped differently there (e.g.
`validation.levels` as `L1` … `L7`, `pdf.b2c_embed_xml`, `format` instead of
`defaults.format`). See
[Getting started](getting-started.md#without-laravel).
