# Formats and profiles

This page explains what Quittio produces from an invoice: a PDF with embedded XML, XML only, a plain PDF, HTML or JSON. It shows how Quittio chooses the output by itself, how you request a specific one, and lists every format and profile with its details.

If the words format, profile, CII or UBL are new to you, read [Formats](concepts.md#formats) and [Profiles](concepts.md#profiles) in the E-invoicing basics first. Field numbers such as BT-10 are explained in [EN 16931 and business terms](concepts.md#en-16931-and-business-terms).

Two settings decide the output of a render run:

- The **format** decides what is produced: PDF, XML, HTML or JSON. For XML it also decides the syntax, CII or UBL ([Syntaxes](concepts.md#syntaxes-cii-and-ubl)).
- The **profile** decides which fields and rules the XML follows, for example EN 16931, XRechnung, Peppol or a Factur-X level.

Storage, downloads, queues and events are described in [Laravel integration](laravel.md), all configuration keys in [Configuration](configuration.md).

## You usually do not choose a format

Without `format()`, Quittio chooses format and profile for each invoice. It uses data you provide anyway: the countries of seller and buyer, whether the buyer is a consumer, and the buyer reference. For a German seller:

- A business customer gets ZUGFeRD: a PDF that people can read, with the XML for accounting software embedded (profile EN 16931).
- A German public authority gets XRechnung as CII XML. Quittio recognises it by the Leitweg-ID (the routing ID of a German public buyer) in the buyer reference (BT-10).
- A consumer gets a PDF without XML. So do a customer outside the EU and a small amount invoice up to 250 € that you mark with `->de()->smallAmount()`.

[Which format do I need?](concepts.md#which-format-do-i-need) shows all cases in a table, [Automatic choice](#automatic-choice) the exact rules. The result tells you what Quittio chose:

```php laravel
use Quittio\Laravel\Facades\Quittio;

$result = Quittio::make($invoice)->render();       // no format: automatic choice
$result->format();                                 // e.g. "zugferd"
$result->profile()?->key();                        // e.g. "en16931"

$prepared = Quittio::make($invoice)->prepare();    // the same decision, without rendering
$prepared->format();                               // e.g. "zugferd"
$prepared->profile()?->key();                      // e.g. "en16931"
```

```php plain
$result = $quittio->make($invoice)->render();       // no format: automatic choice
$result->format();                                  // e.g. "zugferd"
$result->profile()?->key();                         // e.g. "en16931"

$prepared = $quittio->make($invoice)->prepare();    // the same decision, without rendering
$prepared->format();                                // e.g. "zugferd"
$prepared->profile()?->key();                       // e.g. "en16931"
```

## Requesting a format

When a customer requires a specific format, name it. Every format has a default profile, so you rarely need `profile()` as well.

```php laravel
use Quittio\Laravel\Facades\Quittio;

// At the end of the builder chain
$result = Quittio::invoice()
    ->number('RE-2026-0043')
    ->deliveryDate('2026-09-30')
    ->buyer($customer)                                     // with an electronic address, required by XRechnung
    ->buyerReference('04011000-12345-03')                  // Leitweg-ID, required by XRechnung
    ->line('Consulting', netPrice: '120.00')
    ->render('xrechnung-cii');

// For invoice data you built before
$result = Quittio::make($invoice)->format('xrechnung-cii')->render();
$result = Quittio::make($invoice)->format('zugferd')->profile('extended')->render();

// Several formats from one calculation; the first one is the main result
$result = Quittio::make($invoice)->formats(['zugferd', 'xrechnung-cii'])->render();
$result->as('xrechnung-cii')->xml();
```

```php plain
// At the end of the builder chain
$result = $quittio->invoice()
    ->number('RE-2026-0043')
    ->deliveryDate('2026-09-30')
    ->buyer($customer)                                     // with an electronic address, required by XRechnung
    ->buyerReference('04011000-12345-03')                  // Leitweg-ID, required by XRechnung
    ->line('Consulting', netPrice: '120.00')
    ->render('xrechnung-cii');

// For invoice data you built before
$result = $quittio->make($invoice)->format('xrechnung-cii')->render();
$result = $quittio->make($invoice)->format('zugferd')->profile('extended')->render();

// Several formats from one calculation; the first one is the main result
$result = $quittio->make($invoice)->formats(['zugferd', 'xrechnung-cii'])->render();
$result->as('xrechnung-cii')->xml();
```

- The format keys are listed in [Output formats](#output-formats).
- Use `profile()` when a recipient asks for a Factur-X level such as `basic`, or for the XRechnung extension. Which profile applies without it is described in [Which profile is used](#which-profile-is-used).
- To use one format for every invoice, set `defaults.format` in `config/quittio.php` (without Laravel: `format`). The default `null` means automatic choice ([Output configuration](#output-configuration)).
- Without Laravel, the same methods exist on `$quittio->invoice()` and `$quittio->make($invoice)` ([Rendering in the core](#rendering-in-the-core)). The plain PHP example uses `$quittio` as created in [Without Laravel](getting-started.md#without-laravel), with your company as `seller`.

## Output formats

These are the keys for `render()`, `format()` and `formats()`:

| Format | Aliases | Syntax | Output | Profiles | Default profile |
|---|---|---|---|---|---|
| `zugferd` | `factur-x`, `facturx` | CII | PDF/A-3b with embedded XML (and the XML) | every CII profile that can be embedded: `minimum`, `basic-wl`, `basic`, `en16931`, `extended`, `xrechnung`, `xrechnung-extension`, `xrechnung-cvd` | `en16931` |
| `xrechnung-cii` | `xrechnung` | CII | XML | `xrechnung`, `xrechnung-extension`, `xrechnung-cvd` | `xrechnung` |
| `xrechnung-ubl` | – | UBL | XML | `xrechnung`, `xrechnung-extension`, `xrechnung-cvd` | `xrechnung` |
| `cii` | – | CII | XML | every CII profile | `en16931` |
| `ubl` | – | UBL | XML | every UBL profile: `en16931`, `peppol`, `xrechnung`, `xrechnung-extension`, `xrechnung-cvd` | `en16931` |
| `peppol` | – | UBL | XML according to Peppol BIS Billing 3.0 | `peppol` | `peppol` |
| `pdf` | – | – | PDF without XML (PDF/A-3b by default; optionally with embedded XML, see [PDF, HTML and JSON](#pdf-html-and-json)) | – | – |
| `html` | – | – | the rendered template | – | – |
| `json` | – | – | the calculated data model | – | – |

PDF/A-3b is the archiving standard for PDF (PDF/A) in version 3, which allows embedded files, at conformance level b.

Format and profile keys are case-insensitive. The profiles `xrechnung`, `xrechnung-extension` and `xrechnung-cvd` and the formats `xrechnung-cii` and `xrechnung-ubl` come with the German country pack (`Quittio\Country\DE\Formats\XRechnungFormats`); without it they are unknown. Other country packs register their own formats the same way ([Writing your own country pack](country-packs.md#writing-your-own-country-pack)). MINIMUM and BASIC WL carry no invoice lines and do not comply with EN 16931. The validation warns about them with `QUITTIO-PROFILE-NOT-EINVOICE`; use them only when a recipient asks for them.

## Automatic choice

Without a format, and without a configured `defaults.format`, the class `Quittio\Core\Formats\DefaultFormatResolver` decides.

It first checks whether the invoice is **domestic**: seller and buyer lie in the same VAT territory, or the buyer has no country code. The VAT territory follows VAT law, not the map (`VatTerritory::sameVatTerritory()`). Monaco counts as France, and excluded territories such as Büsingen or Heligoland do not count as domestic.

Then the first step that applies decides:

1. **Domestic invoice with a country rule.** The format rules of the leading country pack decide only what the country prescribes ([German format choice](country-packs.md#format-choice)). In Germany:
   - A valid Leitweg-ID in the buyer reference (BT-10), or a buyer electronic address with scheme `0204` → `xrechnung-cii` with profile `xrechnung`.
   - A small amount invoice (`smallAmount()`) between parties in Germany's domestic territory under VAT law (the "Inland" of the VAT Act, without Büsingen and Heligoland) → `pdf`.
   - Everything else goes on to the next steps.
2. **Buyer reachable via Peppol.** A buyer that is not a consumer and is reachable via Peppol → `peppol`. This step needs a [Peppol lookup](#peppol-lookup); without one it is skipped.
3. **Consumer** → `pdf`.
4. **Other domestic invoices** → `zugferd` with the configured profile (default `en16931`).
5. **Cross-border invoice.** The format rules of the buyer's country pack decide first. In Germany a Leitweg-ID leads to XRechnung, whatever the seller's country. A buyer in an excluded territory of the seller's country uses the seller's pack. Without a choice, an invoice within the EU VAT territory → `zugferd` with the configured profile.
6. **Seller or buyer outside the EU VAT territory** → `pdf`, e.g. a US seller invoicing a German business.

`prepare()` shows the choice without rendering (see the [example above](#you-usually-do-not-choose-a-format)).

### Upgrade to profile EXTENDED

Some data exists only in the profile EXTENDED: sub lines, `extended()` data and line references. When an invoice uses such data, the profile is upgraded:

- The automatic choice `zugferd` or `cii` with another profile gets the profile `extended`.
- The same applies to a CII format you request without `->profile()`, e.g. `->format('zugferd')`, and to a configured profile. It applies only to formats that allow `extended`.
- A profile you request with `->profile()` is never changed. The validation reports the data it cannot carry.
- With `auto_upgrade_profile = false` (Laravel `format_resolver.auto_upgrade_profile`), such an invoice fails with a `ConfigurationException` instead.

XRechnung has no EXTENDED profile. `->format('xrechnung-cii')` keeps the profile `xrechnung`, and the validation reports the sub lines. To render XRechnung with sub lines, use `->format('xrechnung-ubl')->profile('xrechnung-extension')`.

### Peppol lookup

Peppol is an international network for delivering e-invoices ([Formats](concepts.md#formats)). The default resolver chooses `peppol` only when a lookup says that the buyer is reachable there. The lookup is a closure that receives the `BuyerParty` and returns `true` or `false`. Quittio ships no lookup; pass your own resolver:

```php
use Quittio\Core\Data\Party\BuyerParty;
use Quittio\Core\Formats\DefaultFormatResolver;
use Quittio\Core\Quittio;
use Quittio\Country\DE\CountryDE;

$quittio = Quittio::create($config, [new CountryDE], formatResolver: new DefaultFormatResolver(
    defaultProfile: 'en16931',
    autoUpgradeProfile: true,
    peppolLookup: fn (BuyerParty $buyer): bool => $directory->isRegistered($buyer->electronicAddress),
));
```

In Laravel, name an invokable class in `format_resolver.peppol_lookup`. It is resolved from the container, receives the `BuyerParty` and returns a bool. You can also set a callable at runtime; see [Peppol lookup](laravel.md#peppol-lookup) in the Laravel integration.

Country rules come first, but they decide only what the country prescribes (in Germany: public buyers and small amount invoices). Domestic business invoices and cross-border invoices reach the lookup.

### Own resolver and country rules

To replace the whole choice, write a resolver. It implements `Quittio\Core\Formats\FormatResolver` (`resolve(InvoiceData $invoice, ResolvedCountries $countries): FormatChoice`) and returns a `FormatChoice(string $format, ?string $profile = null, ?string $reason = null)`. A `null` profile uses the default profile of the format. Register it:

- without Laravel with `Quittio::create(formatResolver: …)` or `$quittio->setFormatResolver(…)`,
- in Laravel with `Quittio::extend('format_resolver', 'mine', fn ($app, array $config) => new MyResolver)` and `format_resolver.driver = 'mine'`.

A country pack contributes its domestic and buyer rules through `formatRules()`. This is a list of `Quittio\Core\Formats\FormatRule` (`resolve(InvoiceData $invoice): ?FormatChoice`, `null` if the rule does not apply). The first rule that returns a choice wins ([Country packs](country-packs.md)).

## Profiles

A profile is a set of fields and rules based on EN 16931 ([Profiles](concepts.md#profiles)). Every profile writes its specification identifier (BT-24) into the XML, so that the recipient knows which rules the invoice follows. Some profiles also set a default business process (BT-23), an identifier of the business context in which the invoice is exchanged.

| Key | Name | Level | Syntax | Specification identifier (BT-24) | Business process (BT-23) default | Additionally required |
|---|---|---|---|---|---|---|
| `minimum` | Factur-X/ZUGFeRD MINIMUM | MINIMUM | CII | `urn:factur-x.eu:1p0:minimum` | – | – |
| `basic-wl` | Factur-X/ZUGFeRD BASIC WL | BASIC WL | CII | `urn:factur-x.eu:1p0:basicwl` | – | – |
| `basic` | Factur-X/ZUGFeRD BASIC | BASIC | CII | `urn:cen.eu:en16931:2017#compliant#urn:factur-x.eu:1p0:basic` | – | – |
| `en16931` | EN 16931 | EN 16931 | CII, UBL | `urn:cen.eu:en16931:2017` (`…:2026` with `standard(StandardVersion::En16931_2026)`) | – | – |
| `extended` | Factur-X/ZUGFeRD EXTENDED | EXTENDED | CII | `urn:cen.eu:en16931:2017#conformant#urn:factur-x.eu:1p0:extended` | – | – |
| `peppol` | Peppol BIS Billing 3.0 | EN 16931 | UBL | `urn:cen.eu:en16931:2017#compliant#urn:fdc:peppol.eu:2017:poacc:billing:3.0` | `urn:fdc:peppol.eu:2017:poacc:billing:01:1.0` | Electronic addresses of seller and buyer (BT-34, BT-49); Peppol rules |
| `xrechnung` | XRechnung 3.0 | EN 16931 | CII, UBL | `urn:cen.eu:en16931:2017#compliant#urn:xeinkauf.de:kosit:xrechnung_3.0` | `urn:fdc:peppol.eu:2017:poacc:billing:01:1.0` | Buyer reference (BT-10), electronic addresses of seller and buyer (BT-34, BT-49), seller contact (BG-6), payment instructions (BG-16); BR-DE rules |
| `xrechnung-extension` | XRechnung 3.0 Extension | EN 16931 | CII, UBL | `urn:cen.eu:en16931:2017#compliant#urn:xeinkauf.de:kosit:xrechnung_3.0#conformant#urn:xeinkauf.de:kosit:extension:xrechnung_3.0` | as XRechnung | as XRechnung |
| `xrechnung-cvd` | XRechnung 3.0 CVD | EN 16931 | CII, UBL | `urn:cen.eu:en16931:2017#compliant#urn:xeinkauf.de:kosit:xrechnung_3.0#compliant#urn:xeinkauf.de:kosit:xrechnung:cvd_0.9` | as XRechnung | as XRechnung, plus contract reference (BT-12), tender or lot reference (BT-17) and a vehicle line; BR-DE-CVD rules |

Aliases: `basicwl` (basic-wl), `en-16931` and `comfort` (en16931), `peppol-bis-3` (peppol). The default business process is written only when the invoice sets none (`businessProcess()`). The registry is available as `$quittio->profiles()` (`get()`, `has()`, `all()`, `register()`, `alias()`, `bySpecificationId()`).

### Which profile is used

The profile of a format is the first of these that applies:

1. The profile you request with `profile()`.
2. The configured profile (`profile`, Laravel `defaults.profile`), if it fits the format: the format allows it, it exists in the syntax of the format, and it can be embedded into the PDF where the format embeds XML.
3. The profile of the automatic choice.
4. The default profile of the format.
5. `en16931` (`Quittio::DEFAULT_PROFILE`).

A configured profile therefore applies to `->format('zugferd')` and to formats chosen by a country rule as well. It is skipped where it does not fit, e.g. `basic` for `xrechnung-cii` or `peppol`. Without a configured profile (`null`, the default) the format or the automatic choice decides. The [upgrade to EXTENDED](#upgrade-to-profile-extended) comes on top.

A run fails with a `ConfigurationException` for:

- an unknown format,
- a profile the format does not allow,
- a profile that is not available in the syntax of the format (e.g. `peppol` in `zugferd`),
- a profile that cannot be embedded into a PDF.

An unknown profile throws an `InvalidArgumentException`.

### What each profile carries

The writers leave out every field that the profile does not contain. The validation (level L2, see [Validation](validation.md)) reports such fields as warning `QUITTIO-PROFILE-NOT-ALLOWED`. A missing mandatory field of a profile is an error (`QUITTIO-PROFILE-REQUIRED`), and so is data of profile EXTENDED or of the XRechnung extension in another profile. The "Level" column of the [field reference](field-reference.md) names the lowest level of every field.

| Level | Contains | Drops |
|---|---|---|
| MINIMUM | Number, issue date, type code, currency, buyer reference, purchase order reference, business process, seller name, legal registration, VAT identifier, tax number and country, buyer name and legal registration, total without VAT, VAT total, total with VAT, amount due | lines, addresses, VAT breakdown, payment data and everything else |
| BASIC WL | MINIMUM plus postal addresses, identifiers and electronic addresses of seller and buyer, seller trading name, buyer VAT identifier, tax currency, tax point date, due date, contract, despatch advice and accounting references, payment terms text, notes, preceding invoice, payee, tax representative, delivery, invoicing period, payment means with IBAN and direct debit, document allowances and charges, VAT breakdown, all totals | invoice lines |
| BASIC | BASIC WL plus lines: identifier, note, quantity and unit, prices (net, list price, discount, base quantity), VAT, item name and standard identifier, line period, line allowances and charges, line amount | item description, seller and buyer item numbers, classification, origin country, attributes, line object identifier, order line and accounting references, contacts, seller additional legal information, buyer trading name, supporting documents, project, sales order, receiving advice, tender and invoiced object references, payment means text, card, account name and BIC |
| EN 16931 | everything of EN 16931 | data of profile EXTENDED |
| EXTENDED | EN 16931 plus sub lines (with parent line identifier and line status), `ExtendedData` and `LineExtendedData`, the exchange rate, cash discounts and late fees as structured elements, several cash discount tiers, instalments, further payment instructions, line references, exemption reasons repeated on line and allowance level | – |

The UBL profiles (`en16931`, `peppol`, `xrechnung`, `xrechnung-extension`, `xrechnung-cvd`) are on level EN 16931. UBL ignores `extended()` data and writes sub lines only for the XRechnung extension.

### Capabilities

Profiles can implement marker interfaces in `Quittio\Core\Profiles\Capabilities` that change how the writers work:

| Interface | Effect | Implemented by |
|---|---|---|
| `RequiresVatBreakdownRate` | The VAT breakdown always carries a rate, 0 for category O, outside the scope of VAT (BR-DE-14). | `xrechnung`, `xrechnung-extension`, `xrechnung-cvd` |
| `StructuredCashDiscountText` | Cash discounts are written to the payment terms (BT-20) in the XRechnung notation `#SKONTO#TAGE=…#PROZENT=…#` ([Payment terms](building-invoices.md#payment-terms)). | `xrechnung`, `xrechnung-extension`, `xrechnung-cvd` |
| `DescribesEmbeddedXml` | `xmpDescription()` and `xmpVersion()` replace the XMP metadata of the embedded XML (`Factur-X/ZUGFeRD Invoice`, `fx:Version` `1.0`), e.g. `XRechnung Invoice` and `3.0`. | `xrechnung`, `xrechnung-extension`, `xrechnung-cvd` |
| `ExtendsCodeLists` | `additionalCodes(string $codeList)` adds codes to an official code list for the validation. | `xrechnung-extension` (identifier schemes `XR01` to `XR03` for the code lists ICD and EAS, checked by `BR-DEX-04` to `BR-DEX-08`), `xrechnung-cvd` (item classification scheme `CVD` for UNTDID 7143) |
| `ReplacesRules` | `replacedRules(?Syntax $syntax)` names built-in rules that the PHP rules driver does not run for the profile, because own rules with other codes replace them. | `xrechnung-extension` (`BR-CL-10`, `-11`, `-21`, `-25`, `-26`; in UBL also `BR-CO-16` and `QUITTIO-SUBLINES`, see [XRechnung rules](country-packs.md#xrechnung-rules)) |

### Own profiles and formats

A profile implements `Quittio\Core\Profiles\Profile`. `AbstractProfile` provides the defaults: syntax CII, EN 16931:2017 only, embedded as `factur-x.xml` with AFRelationship `Alternative`, EN 16931 compliant, no additional rules. Register a profile in a country pack (`profiles()`) or with `$quittio->profiles()->register(new MyProfile)`.

Register a format with `$quittio->formats()->register(new OutputFormat(string $key, ?Syntax $syntax = null, bool $pdf = false, bool $embedsXml = false, ?string $defaultProfile = null, array $profiles = [], bool $html = false, bool $json = false))` and an alias with `alias(string $alias, string $key)`. An empty `$profiles` allows every profile of the syntax.

XML is written by one `XmlSerializer` per syntax (`syntax(): Syntax`, `serialize(InvoiceData $invoice, Profile $profile, SerializationOptions $options = new SerializationOptions): string`). `$quittio->addSerializer(…)` replaces the CII or UBL writer, in Laravel `Quittio::extend('serializer', …)`.

## ZUGFeRD and Factur-X

ZUGFeRD (Germany) and Factur-X (France) are the same standard: a PDF for people with the CII XML embedded for software ([Formats](concepts.md#formats)). The format `zugferd` produces a PDF/A-3b with the CII XML as associated file, an embedded file that PDF/A-3 links to the document. mPDF, the PHP library that renders the PDF, writes it as PDF/A-3b (`PDFAversion` 3-B). The embedded XML is the same as `xml()` of the run, byte for byte.

The PDF metadata carry:

- the title "{title} {number}", e.g. "Rechnung RE-2026-0042" ("Rechnung" is the German document title "invoice"),
- the seller as author,
- "Quittio" as creator.

The paper size comes from the presentation, then the option `paper` (Laravel `pdf.paper`), then the country pack (Germany: A4).

### Embedded files

The name of the embedded file and its AFRelationship depend on the profile. The AFRelationship is a PDF/A-3 entry that says how the file relates to the document. The XMP metadata, the metadata block of the PDF, declare conformance level and version, so that software finds the invoice:

| Profile | File name in the PDF | AFRelationship | XMP `fx:ConformanceLevel` | XMP `fx:Version` |
|---|---|---|---|---|
| BASIC, EN 16931, EXTENDED | `factur-x.xml` | `Alternative` | `BASIC`, `EN 16931`, `EXTENDED` | `1.0` |
| MINIMUM, BASIC WL | `factur-x.xml` | `Data` | `MINIMUM`, `BASIC WL` | `1.0` |
| XRechnung, XRechnung extension | `xrechnung.xml` | `Alternative` | `XRECHNUNG` | the XRechnung version, `3.0` |

The embedded file has the MIME type `text/xml` and the description "Factur-X/ZUGFeRD Invoice" or "XRechnung Invoice". The XMP metadata contain the Factur-X extension schema (namespace `urn:factur-x:pdfa:CrossIndustryDocument:invoice:1p0#`, prefix `fx`) and its PDF/A extension schema description. The schema carries:

- `DocumentType` `INVOICE`, also for credit notes,
- `DocumentFileName`, `Version` and `ConformanceLevel`.

Peppol cannot be embedded. XRechnung can: `->format('zugferd')->profile('xrechnung')` gives a PDF with `xrechnung.xml` (ZUGFeRD profile XRECHNUNG).

Supporting documents (`attachment()`, BG-24) are part of the XML, base64 encoded. They are not separate files in the PDF: the PDF carries exactly one associated file.

### Drafts and watermarks

Drafts and previews carry a watermark (`Presentation::$watermark`, or the option `watermark`). They never produce e-invoice XML, so that a draft cannot look like an issued e-invoice. A run with a watermark and any format with XML throws a `ConfigurationException`. This covers ZUGFeRD/Factur-X, XRechnung, CII, UBL and Peppol, also next to `pdf` in `formats([...])`.

Watermarked PDFs are plain PDF without embedded XML. They are not PDF/A, because PDF/A forbids the transparency of the watermark ([Watermark](templates.md#watermark)).

```php laravel
Quittio::make($draft)->format('pdf')->option('watermark', 'ENTWURF')->render();    // "Entwurf" = draft
```

```php plain
$quittio->make($draft)->format('pdf')->option('watermark', 'ENTWURF')->render();    // "Entwurf" = draft
```

### PDF/A and logos

PDF/A is produced for every PDF with embedded XML, and for `pdf` output when `pdf.pdfa` is `true`.

PDF/A forbids the alpha channel (transparency) that mPDF writes. Logos in PDF/A documents are therefore re-encoded as RGB PNG on white. SVG logos are rasterised with the PHP extension `imagick` and must be self-contained. Without `imagick`, rendering an SVG logo fails with a `ConfigurationException` ([Logos](templates.md#logos)).

### PDF renderers

The PDF comes from a `Quittio\Core\Pdf\PdfRenderer` (`render(RenderRequest $request): string`). Quittio ships three:

- `MpdfRenderer`, the default,
- `CallbackRenderer` for another HTML-to-PDF engine (Browsershot, Gotenberg …),
- `PassthroughRenderer` for a finished PDF.

How to configure and register them is described in [PDF renderers](templates.md#pdf-renderers).

For the e-invoice, two fields of the request matter. `RenderRequest::$pdfa` asks for PDF/A-3b. `RenderRequest::$embedded` carries the XML as `EmbeddedXml` (`content`, `filename`, `relationship`, `conformanceLevel`, `description`, `documentType`, `version`).

- A renderer whose `supportsEmbedding()` returns `true` handles both itself.
- For a renderer that returns `false`, the core passes the result to `MpdfPostProcessor` whenever PDF/A or an embedded XML is requested.
- `CallbackRenderer` and `PassthroughRenderer` return `true` and use their own post processor (default `MpdfPostProcessor`). It imports every page into a new PDF/A-3b and adds the output intent, the metadata, the XML as associated file and the Factur-X XMP metadata. So PDFs from other engines become ZUGFeRD.

A result that does not start with `%PDF` throws a `PdfException`.

## XRechnung

XRechnung 3.0 is the German standard for invoices to public authorities. It is a CIUS of EN 16931: a national specialisation with additional rules. The German country pack provides it ([XRechnung profiles](country-packs.md#xrechnung-profiles)) in both syntaxes:

- as plain XML: `xrechnung-cii` (alias `xrechnung`) and `xrechnung-ubl`,
- embedded in ZUGFeRD, as shown in [Embedded files](#embedded-files).

The automatic choice uses `xrechnung-cii` for domestic German invoices whose buyer reference (BT-10) is a Leitweg-ID with valid check digits, or whose buyer electronic address has the scheme `0204`.

XRechnung adds to EN 16931:

- Required fields: the buyer reference (BT-10; for public buyers the Leitweg-ID), the electronic addresses of seller and buyer (BT-34, BT-49), a seller contact with name, phone and email (BG-6), payment instructions (BG-16), and city and postcode of seller and buyer.
- Further XRechnung rules with the codes `BR-DE-…` ([XRechnung rules](country-packs.md#xrechnung-rules)).
- The business process (BT-23) defaults to `urn:fdc:peppol.eu:2017:poacc:billing:01:1.0`.
- Cash discounts in the payment terms (BT-20) use the notation `#SKONTO#TAGE=7#PROZENT=2.00#` (Skonto = cash discount, Tage = days, Prozent = percent; see [Payment terms](building-invoices.md#payment-terms)).
- The VAT breakdown always carries a rate.

The **XRechnung extension** (`->profile('xrechnung-extension')`, specification identifier `…#conformant#urn:xeinkauf.de:kosit:extension:xrechnung_3.0`) adds:

- sub lines, in UBL only (`xrechnung-ubl`; CII fails with BR-DEX-15),
- third party payments (`xrechnungExtension()`, BG-DEX-09), written as `cac:PrepaidPayment` and added to the amount due; only in UBL invoices,
- the identifier schemes `XR01` to `XR03`.

The extension is never chosen automatically; request it explicitly. It has no credit note in UBL (`DE-XRECHNUNG-EXT-01`): cancel such invoices with the strategy `negative_invoice`.

**XRechnung CVD** (`->profile('xrechnung-cvd')`, specification identifier `…#compliant#urn:xeinkauf.de:kosit:xrechnung:cvd_0.9`) is XRechnung 3.0 for vehicles procured under the Clean Vehicles Directive, in CII and UBL. It requires the contract reference (BT-12), the tender or lot reference (BT-17) and at least one line with the vehicle category as item classification of scheme `CVD` (BT-158) and the item attribute `cva` (BT-160, BT-161), see [XRechnung CVD](country-packs.md#xrechnung-cvd). It is never chosen automatically either.

## Peppol BIS Billing 3.0

Peppol BIS Billing 3.0 is the invoice specification of the Peppol network. The format `peppol` writes UBL 2.1 according to it, with the profile `peppol`:

- CustomizationID (the specification identifier, BT-24): `urn:cen.eu:en16931:2017#compliant#urn:fdc:peppol.eu:2017:poacc:billing:3.0`,
- ProfileID (the business process, BT-23): `urn:fdc:peppol.eu:2017:poacc:billing:01:1.0`, unless the invoice sets BT-23.

Seller and buyer need an electronic address (BT-34, BT-49), and the Peppol rules apply ([Validation](validation.md)). The electronic address is the Peppol participant identifier, so its scheme must be one Peppol accepts (`PEPPOL-EN16931-CL008`): e.g. `9930` (German VAT number) or `0088` (GLN), not `EM` (e-mail), which EN 16931 allows. Credit note type codes produce a `CreditNote` document.

Quittio produces the document. Sending it through an access point, the service provider that connects you to the Peppol network, is not part of the core.

## CII and UBL

The formats `cii` and `ubl` write plain XML in any profile of the syntax. Both writers require calculated data; the render run calculates. They write the elements in the order of the XSD, the XML schema of the syntax.

**CII** (UN/CEFACT Cross Industry Invoice D16B, CEN/TS 16931-3-3, root `rsm:CrossIndustryInvoice`) is the syntax of ZUGFeRD/Factur-X and supports all Factur-X levels:

- It carries only one preceding invoice reference (BG-3) on header level, so further references are dropped.
- Party identifiers without scheme are written as `ram:ID`, with scheme as `ram:GlobalID`.
- An account identifier that is no IBAN is written as `ProprietaryID`.
- The tax point date is written with the first VAT breakdown.

**UBL** (OASIS UBL 2.1, CEN/TS 16931-3-2) writes an `Invoice` document. For credit note type codes (`InvoiceTypeCode::isCreditNote()`) it writes a `CreditNote` document, whose element order differs from `Invoice`:

- quantities as `CreditedQuantity`,
- the due date (BT-9) in the first payment means; without payment instructions it is not written,
- the project reference (BT-11) as additional document reference with type code 50, followed by the tender or lot reference (BT-17) as `OriginatorDocumentReference`.

In both UBL documents:

- A note with subject code is written as `#CODE#text`.
- The creditor identifier of a direct debit (BT-90) is written as seller party identifier with scheme `SEPA`.
- A sales order reference without purchase order reference is written with the order ID `NA`.
- The payment card network is written as `NA`.

Rules of both writers:

- Amounts have exactly two decimals, prices and percentages at least two decimals, quantities no trailing zeros. The decimal separator is a dot.
- The VAT accounting currency (BT-6) is written only when it differs from the invoice currency (BT-5).
- Texts generated for the payment terms (BT-20) are in the run locale.

`SerializationOptions(string $locale = 'en', bool $indent = true)` controls locale and indentation when you use a writer (`CiiWriter`, `UblWriter`) directly.

## PDF, HTML and JSON

These formats are not e-invoices. The validation skips the profile field matrix (level L2) for them; the other levels run as usual.

- `pdf` renders the template as PDF without e-invoice XML. It is PDF/A-3b unless `pdf.pdfa` is `false` or the run has a watermark.
- With `pdf.b2c_embed_xml = true` (Laravel `format_resolver.b2c_embed_xml`), `pdf` embeds the CII XML as in ZUGFeRD, also for consumers. The XML uses the profile of the call or the configured profile (default EN 16931). The result then has `xml()` and `profile()`. Watermarked runs never embed XML.
- `html` returns the rendered template as HTML (`html()`), e.g. for previews or mails.
- `json` returns the calculated data model (`json()`). It is the same as `InvoiceData::toJson(JSON_PRETTY_PRINT)` of the calculated data ([Arrays and JSON](building-invoices.md#arrays-and-json)).

## Rendering in the core

This section is the reference of a render call. The examples use the core class `Quittio\Core\Quittio` without Laravel. In Laravel, `Quittio::make()` returns the Laravel variant with the same methods plus `queue()`; its result can also be saved to a disk, downloaded and mailed ([Rendering invoices](laravel.md#rendering-invoices)). With your company as `seller` in the configuration, `$quittio->invoice()` and `$quittio->quote()` … start invoices and documents that render themselves ([Invoices and documents with the configured seller](#invoices-and-documents-with-the-configured-seller)).

```php
use Quittio\Core\Quittio;
use Quittio\Country\DE\CountryDE;

$quittio = Quittio::create([], [new CountryDE]);

$result = $quittio->make($invoice)->render();                                        // automatic choice
$result = $quittio->make($invoice)->format('zugferd')->profile('extended')->render();
$result = $quittio->make($invoice)->formats(['zugferd', 'xrechnung-cii', 'json'])->render();

$result->as('xrechnung-cii')->xml();
```

`make(InvoiceData|ProvidesInvoiceData|InvoiceBuilder $data)` returns a `Quittio\Core\Rendering\PendingInvoice`. A builder is built, and a `ProvidesInvoiceData` model is converted.

| `PendingInvoice` method | Description |
|---|---|
| `format(string $format)` | One output format. Without it: the configured format (`format`, Laravel `defaults.format`), or the [automatic choice](#automatic-choice) if none is configured. |
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
| `check(): ValidationResult` | Validates like a render run, without template and PDF, whatever the validation mode. This includes the XML levels, also for the XML that `pdf` embeds with `pdf.b2c_embed_xml`. Returns the result and does not throw for invalid data. |
| `prepare(): PreparedInvoice` | Calculates and validates like `check()` without rendering. Returns the calculated data, the chosen formats with their profiles (`formats`, `format()`, `profile()`; `null` for formats that write or embed no XML), the leading and buyer country packs, the locale and the validation result. |
| `data(): InvoiceData`, `options(): RenderOptions` | The data and the collected options. |

### Invoices and documents with the configured seller

With your company as `seller` in the configuration, `$quittio->invoice()` starts an invoice like `Quittio::invoice()` in Laravel and renders it at the end of the chain:

```php
use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Builder\Party;
use Quittio\Core\Quittio;
use Quittio\Core\Tax\CountryDefaultVat;
use Quittio\Country\DE\CountryDE;

$quittio = Quittio::create([
    'seller' => Party::make('Muster GmbH')->vatId('DE123456789')->address('Hauptstraße 1', '12345', 'Musterstadt', 'DE'),
    'bank_account' => ['iban' => 'DE02120300000000202051', 'bic' => 'BYLADEM1001', 'name' => 'Muster GmbH'],
    'due_days' => 14,
], [new CountryDE]);
InvoiceBuilder::useDefaultVat(new CountryDefaultVat($quittio->countries()));   // VAT of lines without vat

$invoice = $quittio->invoice()                          // seller and bank account from the configuration
    ->number('RE-2026-0044')
    ->deliveryDate('2026-10-14')
    ->buyer($customer)
    ->line('Consulting', netPrice: '120.00');

$result = $invoice->render();                           // automatic choice
$result = $invoice->render(['zugferd', 'json']);        // several formats; the first is the main result
$report = $invoice->check('zugferd');                   // ValidationResult, nothing rendered

$quote = $quittio->quote()->number('AN-2026-0008')->buyer($customer)->line('Consulting', netPrice: '120.00')->render();
```

| Key of `Quittio::create()` | Value |
|---|---|
| `seller` | Your company: a `PartyBuilder` (`Party::make()`) or a `SellerParty`. It needs a name and a postal address, otherwise `create()` throws a `ConfigurationException`. |
| `bank_account` | `['iban' => …, 'bic' => …, 'name' => …]`, BIC and name optional. Added as SEPA credit transfer (BG-17). |
| `small_business` | `true` flags the invoices as small business invoices (`Meta::FLAG_SMALL_BUSINESS`, [Default VAT](building-invoices.md#default-vat)). Default `false`. |

`bank_account` or `small_business` without `seller` throws a `ConfigurationException`.

- `$quittio->invoice()` returns a `BoundInvoiceBuilder`, an `InvoiceBuilder` that ends with `render()`, `render('xrechnung-cii')`, `render(['zugferd', 'xrechnung-cii'])` (the first format is the main result) or `check()` with the same arguments.
- `$quittio->quote()`, `orderConfirmation()`, `deliveryNote()`, `proforma()`, `paymentReminder(int $level = 0)`, `bonusStatement()` and `accountStatement()` return a `BoundDocumentBuilder` that ends with `render()` ([Documents](documents.md)).
- The defaults behave as in Laravel: `->seller($otherParty)` replaces the seller and removes the bank account and the small business flag; `->seller(fn (PartyBuilder $s) => …)` changes the configured seller and keeps them ([Seller and buyer](building-invoices.md#seller-and-buyer)). `->payment(PaymentMeansCode::SepaCreditTransfer, iban: …)` replaces the account, `->creditTransfer(…)` adds a further one.
- Without `seller`, these methods start empty builders.
- For further options, pass the builder to `$quittio->make($builder)` (invoices) or `$quittio->document($builder)` (documents: locale, template, HTML only). `InvoiceBuilder::make()` and `DocumentBuilder::quote()` … with `$quittio->make()` and `$quittio->document()` remain for special cases, e.g. another seller per invoice.

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
| `save(string $path): string` | Writes the main output to a file, or into a directory (an existing one or a path ending in `/`) with `filename()`; creates missing directories and returns the path. Throws a `RuntimeException` when the file cannot be written. |

### File names

File names follow the pattern `filename` (Laravel `pdf.filename`), default `{title}_{number}.{ext}`:

| Placeholder | Value |
|---|---|
| `{title}` | Document title in the run locale, e.g. "Rechnung" (invoice), "Stornorechnung" (cancellation invoice), "Credit note" (`Presentation::$title` overrides it) |
| `{number}` | Invoice number |
| `{date}` | Issue date, `YYYY-MM-DD` |
| `{format}` | Format key, e.g. `zugferd` |
| `{ext}` | Extension of the output: `pdf`, `xml`, `html` or `json` |

In title and number, characters other than letters, digits, `.`, `_` and `-` become `_`. A run of such characters becomes one `_`, and `_` at the start or end is removed. Example: `Rechnung_RE-2026-0042.pdf`; the XRechnung of the same run is `Rechnung_RE-2026-0042.xml`.

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
