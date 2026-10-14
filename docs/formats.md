# Formats and profiles

One calculated `InvoiceData` can be written in several outputs. The **format** decides what is produced (PDF, XML, HTML, JSON) and in which XML syntax (CII or UBL); the **profile** decides which rules and fields the XML follows (EN 16931, XRechnung, Peppol, a Factur-X level …). Both are chosen per render run, explicitly or automatically. This page describes the core; the Laravel API (facade, storage, downloads, queue, events) is in [Laravel integration](laravel.md), the configuration keys in [Configuration](configuration.md).

## Output formats

| Format | Aliases | Syntax | Output | Profiles | Default profile |
|---|---|---|---|---|---|
| `zugferd` | `factur-x`, `facturx` | CII | PDF/A-3b with embedded XML (and the XML) | every CII profile that can be embedded: `minimum`, `basic-wl`, `basic`, `en16931`, `extended`, `xrechnung`, `xrechnung-extension` | `en16931` |
| `xrechnung-cii` | `xrechnung` | CII | XML | `xrechnung`, `xrechnung-extension` | `xrechnung` |
| `xrechnung-ubl` | – | UBL | XML | `xrechnung`, `xrechnung-extension` | `xrechnung` |
| `cii` | – | CII | XML | every CII profile | `en16931` |
| `ubl` | – | UBL | XML | every UBL profile: `en16931`, `peppol`, `xrechnung`, `xrechnung-extension` | `en16931` |
| `peppol` | – | UBL | XML according to Peppol BIS Billing 3.0 | `peppol` | `peppol` |
| `pdf` | – | – | PDF without XML (PDF/A-3b by default; optionally with embedded XML, see [PDF, HTML and JSON](#pdf-html-and-json)) | – | – |
| `html` | – | – | the rendered template | – | – |
| `json` | – | – | the calculated data model | – | – |

Format and profile keys are case-insensitive. The profiles `xrechnung` and `xrechnung-extension` come with the German country pack. MINIMUM and BASIC WL carry no invoice lines and are not compliant with EN 16931 (the validation warns with `QUITTIO-PROFILE-NOT-EINVOICE`); use them only when a recipient asks for them.

## Rendering in the core

```php
use Quittio\Core\Quittio;
use Quittio\Country\DE\CountryDE;

$quittio = Quittio::create([], [new CountryDE]);

$result = $quittio->make($invoice)->render();                                        // automatic choice
$result = $quittio->make($invoice)->format('zugferd')->profile('extended')->render();
$result = $quittio->make($invoice)->formats(['zugferd', 'xrechnung-cii', 'json'])->render();

$result->as('xrechnung-cii')->xml();
```

`make(InvoiceData|ProvidesInvoiceData|InvoiceBuilder $data)` returns a `Quittio\Core\Rendering\PendingInvoice` (a builder is built, a `ProvidesInvoiceData` model converted). In Laravel, `Quittio::make()` returns the Laravel variant with the same methods plus `queue()`, and its result can be saved to a disk, downloaded and mailed ([Rendering invoices](laravel.md#rendering-invoices)).

| `PendingInvoice` method | Description |
|---|---|
| `format(string $format)` | One output format; without it the configured format (`format`, Laravel `defaults.format`) or, if none is configured, the [automatic choice](#automatic-choice). |
| `formats(array $formats)` | Several formats from one calculation and validation run, e.g. `['zugferd', 'xrechnung-cii']`; duplicates are removed. The first is the main result. |
| `profile(Profile\|string $profile)` | Profile for all formats of the run (by key, alias or as object). |
| `locale(string $locale)` | Language of PDF, HTML and generated XML texts. |
| `country(string $country)` | Country pack that leads the run, instead of the seller's country (BT-40). |
| `template(string $template)` | Template of PDF and HTML ([Templates and design](templates.md)). |
| `presentation(Presentation $presentation)` | Design defaults for this run; the invoice's own values win. |
| `option(string $key, mixed $value)` | Option for templates and validation rules, e.g. `watermark`, `paper`, `markdown`. |
| `validate(bool $throwOnError = true)` | Validation mode of this run: errors throw, or (`false`) warnings only. |
| `withoutValidation()` | Switches the validation off for this render run (not for `check()` and `prepare()`). |
| `render(): RenderedInvoice` | Calculates, chooses format and profile, validates, writes the XML, renders template and PDF. |
| `check(): ValidationResult` | Validates like a render run (also the XML levels, including the XML that `pdf` embeds with `pdf.b2c_embed_xml`), without template and PDF, whatever the validation mode; returns the result and does not throw for invalid data. |
| `prepare(): PreparedInvoice` | Calculates and validates like `check()` without rendering; returns the calculated data, the chosen formats with their profiles (`formats`, `format()`, `profile()`; `null` for formats that write or embed no XML), the leading and buyer country packs, the locale and the validation result. |
| `data(): InvoiceData`, `options(): RenderOptions` | The data and the collected options. |

The profile of a format is, in this order: `profile()` of the call; the configured profile (`profile`, Laravel `defaults.profile`) if the format allows it (allowed profile, syntax, embeddable into the PDF); the profile of the automatic choice; the default profile of the format; `en16931` (`Quittio::DEFAULT_PROFILE`). A configured profile therefore applies to `->format('zugferd')` and to formats chosen by a country rule as well, and is skipped where it does not fit (e.g. `basic` for `xrechnung-cii` or `peppol`). Without a configured profile (`null`, the default) the format or the automatic choice decides. A run fails with a `ConfigurationException` for an unknown format, a profile the format does not allow, a profile that is not available in the syntax of the format (e.g. `peppol` in `zugferd`) and a profile that cannot be embedded into a PDF; an unknown profile throws an `InvalidArgumentException`.

### Result

`render()` returns a `Quittio\Core\Rendering\RenderedInvoice`:

| Method | Description |
|---|---|
| `data(): InvoiceData` | The calculated data (totals, VAT breakdown, line amounts, due date). |
| `format(): string`, `profile(): ?Profile` | Format key (aliases resolved, e.g. `zugferd` for `factur-x`) and profile; `null` for outputs without XML. |
| `validation(): ValidationResult` | Validation result including warnings ([Validation](validation.md)). |
| `as(string $format): RenderedInvoice` | The result of another format of the same run, by format key (not alias); `LogicException` for a format that was not rendered. |
| `formats(): array` | Keys of all formats of the run. |
| `pdf()`, `xml()`, `html()` | The outputs; `LogicException` if the format produced none. `hasPdf()`, `hasXml()` check first. |
| `json(): string` | The calculated data as pretty-printed JSON (for every format). |
| `contents(): string`, `extension(): string`, `mimeType(): string` | The main output (PDF, otherwise XML, HTML, JSON), its extension and MIME type. |
| `filename(?string $extension = null): string` | File name of the main output, or with another extension. |
| `hashes(): array` | SHA-256 hashes of PDF, XML and HTML (`['pdf' => …, 'xml' => …]`), e.g. for archiving. |
| `save(string $path): string` | Writes the main output to a file, or into a directory with `filename()`; creates missing directories and returns the path. |

### File names

File names follow the pattern `filename` (Laravel `pdf.filename`), default `{title}_{number}.{ext}`:

| Placeholder | Value |
|---|---|
| `{title}` | Document title in the run locale, e.g. "Rechnung", "Stornorechnung", "Credit note" (`Presentation::$title` overrides it) |
| `{number}` | Invoice number |
| `{date}` | Issue date, `YYYY-MM-DD` |
| `{format}` | Format key, e.g. `zugferd` |
| `{ext}` | Extension of the output: `pdf`, `xml`, `html` or `json` |

Characters other than letters, digits, `.`, `_` and `-` in title and number become `_`. Example: `Rechnung_RE-2026-0042.pdf`, for the XRechnung of the same run `Rechnung_RE-2026-0042.xml`.

## Automatic choice

Without a format, `Quittio\Core\Formats\DefaultFormatResolver` decides (specification 9.3). Domestic means that seller and buyer lie in the same VAT territory (`VatTerritory::sameVatTerritory()`: Monaco counts as France, excluded territories such as Büsingen or Heligoland as not domestic) or that the buyer has no country code.

1. Domestic invoices: the format rules of the leading country pack, which decide only what the country prescribes ([German format choice](country-packs.md#format-choice)). Germany: a valid Leitweg-ID in BT-10 or a buyer electronic address with scheme `0204` → `xrechnung-cii` with `xrechnung`; small amount invoices (`smallAmount()`) between parties in the German Inland (without Büsingen and Heligoland) → `pdf`; everything else goes on to the next steps.
2. A buyer that is not a consumer and is reachable via Peppol (only with a lookup, see below) → `peppol`.
3. Consumers → `pdf`.
4. Other domestic invoices → `zugferd` with the configured profile (default `en16931`).
5. Cross-border: the format rules of the buyer's country pack (a buyer in an excluded territory of the seller's country uses the seller's pack; Germany: Leitweg-ID → XRechnung, whatever the seller's country); without a choice, within the EU VAT territory → `zugferd` with the configured profile.
6. Seller or buyer outside the EU VAT territory → `pdf` (e.g. a US seller invoicing a German business).

The chosen format is then upgraded: when the invoice uses features of profile EXTENDED (sub lines, `extended()` data or line references) and the choice is `zugferd` or `cii` with another profile, the profile becomes `extended`. The same upgrade applies to an explicitly requested CII format without `->profile()`, e.g. `->format('zugferd')`, and to a configured profile, but only for formats that allow `extended`. With `auto_upgrade_profile = false` (Laravel `format_resolver.auto_upgrade_profile`) such an invoice fails with a `ConfigurationException` instead. XRechnung has no EXTENDED profile: `->format('xrechnung-cii')` keeps the profile `xrechnung` and the validation reports the sub lines; render XRechnung with sub lines as `->format('xrechnung-ubl')->profile('xrechnung-extension')`.

`prepare()` shows the choice without rendering:

```php
$prepared = $quittio->make($invoice)->prepare();
$prepared->format();              // "xrechnung-cii"
$prepared->profile()?->key();     // "xrechnung"
```

### Peppol lookup

The default resolver chooses `peppol` only with a lookup, a closure that receives the `BuyerParty` and returns whether the buyer is reachable in the Peppol network. Quittio ships no lookup; pass your own resolver:

```php
use Quittio\Core\Data\Party\BuyerParty;
use Quittio\Core\Formats\DefaultFormatResolver;

$quittio = Quittio::create($config, [new CountryDE], formatResolver: new DefaultFormatResolver(
    defaultProfile: 'en16931',
    autoUpgradeProfile: true,
    peppolLookup: fn (BuyerParty $buyer): bool => $directory->isRegistered($buyer->electronicAddress),
));
```

In Laravel name an invokable class in `format_resolver.peppol_lookup` (resolved from the container; it receives the `BuyerParty` and returns a bool), or set a callable at runtime; see [Peppol lookup](laravel.md#peppol-lookup) in the Laravel integration.

Country rules come first, but decide only what the country prescribes (Germany: public buyers and small amount invoices); domestic business invoices and cross-border invoices reach the lookup.

### Own resolver and country rules

A resolver implements `Quittio\Core\Formats\FormatResolver` (`resolve(InvoiceData $invoice, ResolvedCountries $countries): FormatChoice`) and returns a `FormatChoice(string $format, ?string $profile = null, ?string $reason = null)`; a `null` profile uses the default profile of the format. Register it with `Quittio::create(formatResolver: …)` or `$quittio->withFormatResolver(…)`, in Laravel with `Quittio::extend('format_resolver', 'mine', fn ($app, array $config) => new MyResolver)` and `format_resolver.driver = 'mine'`. A country pack contributes its domestic and buyer rules through `formatRules()`, a list of `Quittio\Core\Formats\FormatRule` (`resolve(InvoiceData $invoice): ?FormatChoice`, `null` if the rule does not apply); the first rule that returns a choice wins ([Country packs](country-packs.md)).

## Profiles

| Key | Name | Level | Syntax | Specification identifier (BT-24) | Business process (BT-23) default | Additionally required |
|---|---|---|---|---|---|---|
| `minimum` | Factur-X/ZUGFeRD MINIMUM | MINIMUM | CII | `urn:factur-x.eu:1p0:minimum` | – | – |
| `basic-wl` | Factur-X/ZUGFeRD BASIC WL | BASIC WL | CII | `urn:factur-x.eu:1p0:basicwl` | – | – |
| `basic` | Factur-X/ZUGFeRD BASIC | BASIC | CII | `urn:cen.eu:en16931:2017#compliant#urn:factur-x.eu:1p0:basic` | – | – |
| `en16931` | EN 16931 | EN 16931 | CII, UBL | `urn:cen.eu:en16931:2017` (`…:2026` with `standard(StandardVersion::En16931_2026)`) | – | – |
| `extended` | Factur-X/ZUGFeRD EXTENDED | EXTENDED | CII | `urn:cen.eu:en16931:2017#conformant#urn:factur-x.eu:1p0:extended` | – | – |
| `peppol` | Peppol BIS Billing 3.0 | EN 16931 | UBL | `urn:cen.eu:en16931:2017#compliant#urn:fdc:peppol.eu:2017:poacc:billing:3.0` | `urn:fdc:peppol.eu:2017:poacc:billing:01:1.0` | BT-34, BT-49; Peppol rules |
| `xrechnung` | XRechnung 3.0 | EN 16931 | CII, UBL | `urn:cen.eu:en16931:2017#compliant#urn:xeinkauf.de:kosit:xrechnung_3.0` | `urn:fdc:peppol.eu:2017:poacc:billing:01:1.0` | BT-10, BT-34, BT-49, BG-6, BG-16; BR-DE rules |
| `xrechnung-extension` | XRechnung 3.0 Extension | EN 16931 | CII, UBL | `urn:cen.eu:en16931:2017#compliant#urn:xeinkauf.de:kosit:xrechnung_3.0#conformant#urn:xeinkauf.de:kosit:extension:xrechnung_3.0` | as XRechnung | as XRechnung |

Aliases: `basicwl` (basic-wl), `en-16931` and `comfort` (en16931), `peppol-bis-3` (peppol). The default business process is written only when the invoice sets none (`businessProcess()`). The registry is available as `$quittio->profiles()` (`get()`, `has()`, `all()`, `register()`, `alias()`, `bySpecificationId()`).

### What each profile carries

The writers leave out every field that the profile does not contain; the validation (level L2) reports such fields as warning `QUITTIO-PROFILE-NOT-ALLOWED`, mandatory fields of a profile as error `QUITTIO-PROFILE-REQUIRED`, and data of profile EXTENDED or the XRechnung extension in another profile as error. The "Level" column of the [field reference](field-reference.md) names the lowest level of every field.

| Level | Contains | Drops |
|---|---|---|
| MINIMUM | Number, issue date, type code, currency, buyer reference, purchase order reference, business process, seller name, legal registration, VAT identifier, tax number and country, buyer name and legal registration, total without VAT, VAT total, total with VAT, amount due | lines, addresses, VAT breakdown, payment data and everything else |
| BASIC WL | MINIMUM plus postal addresses, identifiers and electronic addresses of seller and buyer, seller trading name, buyer VAT identifier, tax currency, tax point date, due date, contract, despatch advice and accounting references, payment terms text, notes, preceding invoice, payee, tax representative, delivery, invoicing period, payment means with IBAN and direct debit, document allowances and charges, VAT breakdown, all totals | invoice lines |
| BASIC | BASIC WL plus lines: identifier, note, quantity and unit, prices (net, list price, discount, base quantity), VAT, item name and standard identifier, line period, line allowances and charges, line amount | item description, seller and buyer item numbers, classification, origin country, attributes, line object identifier, order line and accounting references, contacts, seller additional legal information, buyer trading name, supporting documents, project, sales order, receiving advice, tender and invoiced object references, payment means text, card, account name and BIC |
| EN 16931 | everything of EN 16931 | data of profile EXTENDED |
| EXTENDED | EN 16931 plus sub lines (with parent line identifier and line status), `ExtendedData` and `LineExtendedData`, the exchange rate, cash discounts and late fees as structured elements, several cash discount tiers, instalments, further payment instructions, line references, exemption reasons repeated on line and allowance level | – |

The UBL profiles (`en16931`, `peppol`, `xrechnung`, `xrechnung-extension`) are on level EN 16931; UBL ignores `extended()` data and writes sub lines only for the XRechnung extension.

### Capabilities

Profiles can implement marker interfaces in `Quittio\Core\Profiles\Capabilities` that change how the writers work:

| Interface | Effect | Implemented by |
|---|---|---|
| `RequiresVatBreakdownRate` | The VAT breakdown always carries a rate, 0 for category O (BR-DE-14). | `xrechnung`, `xrechnung-extension` |
| `StructuredCashDiscountText` | Cash discounts are written to BT-20 in the notation `#SKONTO#TAGE=…#PROZENT=…#` ([Payment terms](building-invoices.md#payment-terms)). | `xrechnung`, `xrechnung-extension` |
| `ExtendsCodeLists` | `additionalCodes(string $codeList)` adds codes to an official code list for the validation. | `xrechnung-extension` (identifier schemes `XR01` to `XR03` for ICD and EAS) |

### Own profiles and formats

A profile implements `Quittio\Core\Profiles\Profile`; `AbstractProfile` provides the defaults (syntax CII, EN 16931:2017 only, embedded as `factur-x.xml` with AFRelationship `Alternative`, EN 16931 compliant, no additional rules). Register it in a country pack (`profiles()`) or with `$quittio->profiles()->register(new MyProfile)`. Formats are registered with `$quittio->formats()->register(new OutputFormat(string $key, ?Syntax $syntax = null, bool $pdf = false, bool $embedsXml = false, ?string $defaultProfile = null, array $profiles = [], bool $html = false, bool $json = false))` and `alias(string $alias, string $key)`; an empty `$profiles` allows every profile of the syntax. XML is written by one `XmlSerializer` per syntax (`syntax(): Syntax`, `serialize(InvoiceData $invoice, Profile $profile, SerializationOptions $options = new SerializationOptions): string`); `$quittio->withSerializer(…)` replaces the CII or UBL writer, in Laravel `Quittio::extend('serializer', …)`.

## ZUGFeRD and Factur-X

The format `zugferd` produces a PDF/A-3b (written by mPDF, `PDFAversion` 3-B) with the CII XML as associated file. The XML is the same as `xml()` of the run, byte for byte. The PDF metadata carry the title "{title} {number}" (e.g. "Rechnung RE-2026-0042"), the seller as author and "Quittio" as creator; the paper size comes from the presentation, then the option `paper` (Laravel `pdf.paper`), then the country pack (Germany: A4).

### Embedded files

| Profile | File name in the PDF | AFRelationship | XMP `fx:ConformanceLevel` | XMP `fx:Version` |
|---|---|---|---|---|
| BASIC, EN 16931, EXTENDED | `factur-x.xml` | `Alternative` | `BASIC`, `EN 16931`, `EXTENDED` | `1.0` |
| MINIMUM, BASIC WL | `factur-x.xml` | `Data` | `MINIMUM`, `BASIC WL` | `1.0` |
| XRechnung, XRechnung extension | `xrechnung.xml` | `Alternative` | `XRECHNUNG` | the XRechnung version, `3.0` |

The embedded file has the MIME type `text/xml` and the description "Factur-X/ZUGFeRD Invoice" or "XRechnung Invoice". The XMP metadata contain the Factur-X extension schema (namespace `urn:factur-x:pdfa:CrossIndustryDocument:invoice:1p0#`, prefix `fx`) with `DocumentType` `INVOICE` (also for credit notes), `DocumentFileName`, `Version` and `ConformanceLevel`, and its PDF/A extension schema description. Peppol cannot be embedded.

XRechnung can be embedded as well: `->format('zugferd')->profile('xrechnung')` gives a PDF with `xrechnung.xml` (ZUGFeRD profile XRECHNUNG).

Supporting documents (`attachment()`, BG-24) are part of the XML (base64 encoded), not separate files in the PDF; the PDF carries exactly one associated file.

### Drafts and watermarks

Drafts and previews carry a watermark (`Presentation::$watermark`, or the option `watermark`) and never produce e-invoice XML: a run with a watermark and any format with XML (ZUGFeRD/Factur-X, XRechnung, CII, UBL, Peppol, also next to `pdf` in `formats([...])`) throws a `ConfigurationException`. Watermarked PDFs are plain PDF without PDF/A (PDF/A forbids the transparency) and without embedded XML ([Watermark](templates.md#watermark)).

```php
$quittio->make($draft)->format('pdf')->option('watermark', 'ENTWURF')->render();
```

### PDF/A and logos

PDF/A is produced for every PDF with embedded XML, and for `pdf` output when `pdf.pdfa` is `true`. Logos in PDF/A documents are re-encoded as RGB PNG on white, because PDF/A forbids the alpha channel that mPDF writes; SVG logos are rasterised with the PHP extension `imagick` (without it, rendering fails with a `ConfigurationException`) and must be self-contained ([Logos](templates.md#logos)).

### PDF renderers

The PDF comes from a `Quittio\Core\Pdf\PdfRenderer` (`render(RenderRequest $request): string`): `MpdfRenderer` (default), `CallbackRenderer` for another HTML-to-PDF engine (Browsershot, Gotenberg …) or `PassthroughRenderer` for a finished PDF; how to configure and register them is described in [PDF renderers](templates.md#pdf-renderers). For the e-invoice the request is decisive: `RenderRequest::$pdfa` asks for PDF/A-3b and `RenderRequest::$embedded` carries the XML as `EmbeddedXml` (`content`, `filename`, `relationship`, `conformanceLevel`, `description`, `documentType`, `version`). A renderer whose `supportsEmbedding()` returns `true` honours both itself; for one that returns `false`, the core passes the result to `MpdfPostProcessor` whenever PDF/A or an embedded XML is requested. `CallbackRenderer` and `PassthroughRenderer` return `true` and use their own post processor (default `MpdfPostProcessor`): it imports every page into a new PDF/A-3b and adds output intent, metadata, the XML as associated file and the Factur-X XMP metadata, so foreign PDFs become ZUGFeRD. A result that does not start with `%PDF` throws a `PdfException`.

## XRechnung

XRechnung 3.0, the German CIUS of EN 16931, is provided by the German country pack ([XRechnung profiles](country-packs.md#xrechnung-profiles)) in both syntaxes: `xrechnung-cii` (alias `xrechnung`) and `xrechnung-ubl`, as plain XML, or embedded in ZUGFeRD as shown above. The automatic choice uses `xrechnung-cii` for domestic German invoices whose buyer reference (BT-10) is a Leitweg-ID with valid check digits or whose buyer electronic address has the scheme `0204`.

- Required on top of EN 16931: buyer reference BT-10 (for public buyers the Leitweg-ID), electronic addresses of seller and buyer (BT-34, BT-49), seller contact with name, phone and email (BG-6), payment instructions (BG-16), seller and buyer city and postcode; further BR-DE rules ([XRechnung rules](country-packs.md#xrechnung-rules)).
- The business process BT-23 defaults to `urn:fdc:peppol.eu:2017:poacc:billing:01:1.0`.
- Cash discounts in BT-20 use the notation `#SKONTO#TAGE=7#PROZENT=2.00#` ([Payment terms](building-invoices.md#payment-terms)); the VAT breakdown always carries a rate.

The **XRechnung extension** (`->profile('xrechnung-extension')`, specification identifier `…#conformant#urn:xeinkauf.de:kosit:extension:xrechnung_3.0`) adds:

- sub lines, in UBL only (`xrechnung-ubl`; CII fails with BR-DEX-15),
- third party payments (`xrechnungExtension()`, BG-DEX-09), written as `cac:PrepaidPayment` and added to the amount due; only in UBL invoices,
- the identifier schemes `XR01` to `XR03`.

The profile is never chosen automatically; request it explicitly. The extension has no credit note in UBL (`DE-XRECHNUNG-EXT-01`): cancel such invoices with the strategy `negative_invoice`.

## Peppol BIS Billing 3.0

The format `peppol` writes UBL 2.1 according to Peppol BIS Billing 3.0 (profile `peppol`, CustomizationID `urn:cen.eu:en16931:2017#compliant#urn:fdc:peppol.eu:2017:poacc:billing:3.0`, ProfileID `urn:fdc:peppol.eu:2017:poacc:billing:01:1.0` unless the invoice sets BT-23). Seller and buyer need an electronic address (BT-34, BT-49), and the Peppol rules apply ([Validation](validation.md)). Credit note type codes produce a `CreditNote` document. Quittio produces the document; transmission through an access point is not part of the core.

## CII and UBL

The formats `cii` and `ubl` write plain XML in any profile of the syntax. Both writers require calculated data (the render run calculates) and write in the element order of the XSD:

- **CII** (UN/CEFACT Cross Industry Invoice D16B, CEN/TS 16931-3-3, root `rsm:CrossIndustryInvoice`) is the syntax of ZUGFeRD/Factur-X and supports all Factur-X levels. It carries only one preceding invoice reference (BG-3) on header level, so further references are dropped. Party identifiers without scheme are written as `ram:ID`, with scheme as `ram:GlobalID`; an account identifier that is no IBAN as `ProprietaryID`. The tax point date is written with the first VAT breakdown.
- **UBL** (OASIS UBL 2.1, CEN/TS 16931-3-2) writes an `Invoice` document, or a `CreditNote` document for credit note type codes (`InvoiceTypeCode::isCreditNote()`) with `CreditedQuantity`, the due date (BT-9) in the first payment means (without payment instructions it is not written) and the project reference (BT-11) as additional document reference with type code 50, followed by the tender or lot reference (BT-17) as `OriginatorDocumentReference` (the element order of `CreditNote` differs from `Invoice`). A note with subject code is written as `#CODE#text`; the creditor identifier of a direct debit (BT-90) as seller party identifier with scheme `SEPA`; a sales order reference without purchase order reference with the order ID `NA`; the payment card network as `NA`.

Common rules: amounts with exactly two decimals, prices and percentages with at least two decimals, quantities without trailing zeros, dot as decimal separator; BT-6 only when it differs from BT-5; texts generated for BT-20 in the run locale. `SerializationOptions(string $locale = 'en', bool $indent = true)` controls locale and indentation when a writer (`CiiWriter`, `UblWriter`) is used directly.

## PDF, HTML and JSON

- `pdf` renders the template as PDF without e-invoice XML. It is PDF/A-3b unless `pdf.pdfa` is `false` or the run has a watermark. With `pdf.b2c_embed_xml = true` (Laravel `format_resolver.b2c_embed_xml`) the CII XML in the profile of the call or the configured profile (default EN 16931) is embedded as in ZUGFeRD, also for consumers, and the result has `xml()` and `profile()`; watermarked runs never embed XML.
- `html` returns the rendered template as HTML (`html()`), e.g. for previews or mails.
- `json` returns the calculated data model (`json()`, the same as `InvoiceData::toJson(JSON_PRETTY_PRINT)` of the calculated data, see [Building invoices](building-invoices.md#arrays-and-json)).

These formats are not e-invoices: the validation skips the profile field matrix (level L2) for them, the other levels run as usual.

## Output configuration

| `Quittio::create()` key | Laravel key | Default | Effect |
|---|---|---|---|
| `format` | `defaults.format` | `null` | Format of runs without `format()`; `null` = automatic choice (`Quittio::create()` also accepts `auto`). |
| `profile` | `defaults.profile` | `null` | Default profile for every format that allows it; `null` = the profile of the format or of the automatic choice. |
| `auto_upgrade_profile` | `format_resolver.auto_upgrade_profile` | `true` | Upgrade to EXTENDED when EXTENDED features are used. |
| `pdf.b2c_embed_xml` | `format_resolver.b2c_embed_xml` | `false` | Embed the CII XML into `pdf` output. |
| `pdf.pdfa` | `pdf.pdfa` | `true` | `pdf` output as PDF/A-3b (ZUGFeRD is always PDF/A). |
| `filename` | `pdf.filename` | `{title}_{number}.{ext}` | [File names](#file-names). |
| `locale`, `country` | `defaults.locale`, `defaults.country` | `null` | Locale of the run; country pack used when the seller has no country code. |
| `options.paper` | `pdf.paper` | `null` | Paper size that wins over the country pack default. |
| `temp_dir` | `pdf.cache_path` | system temp directory | Temporary files of mPDF and the logo cache. |
| – | `format_resolver.driver` | `default` | Resolver registered with `Quittio::extend('format_resolver', …)`. |
| `peppolLookup` of `DefaultFormatResolver` | `format_resolver.peppol_lookup` | `null` | [Peppol lookup](#peppol-lookup): invokable class (Laravel: resolved from the container) or callable that receives the `BuyerParty` and returns whether the buyer is reachable via Peppol. |
| – | `pdf.renderer` | `mpdf` | Renderer registered with `Quittio::extend('renderer', …)`. |

All keys of `config/quittio.php` are described in [Configuration](configuration.md).
