# Validation

Quittio checks every invoice before it issues it: against the rules of EN 16931, of the profile (for example XRechnung or Peppol, see [Formats](concepts.md#formats)) and of the country (for example the German VAT Act). This page explains what happens when an invoice is invalid, how to read and handle the messages and how to configure the checks. It then describes the validation levels, the installation of the official external validators, all built-in rules and how to add your own.

Rule codes such as `BR-CO-25` are explained in [Rules and validation](concepts.md#rules-and-validation), field numbers such as BT-10 in [EN 16931 and business terms](concepts.md#en-16931-and-business-terms). Common errors and their fixes are listed in [Troubleshooting](troubleshooting.md).

## Errors while rendering

By default (validation mode `error`), an invoice with errors is not rendered: `render()` throws a `Quittio\Core\Validation\ValidationException`. Its message lists the first five errors, each with rule code, field and message, and the hint on the next line. For example, an XRechnung without buyer reference:

```text
The invoice is not valid (2 errors):
[QUITTIO-PROFILE-REQUIRED] BT-10: The field buyerReference (BT-10) is mandatory in profile XRechnung 3.0.
    Provide the field or choose a profile that does not require it.
[BR-DE-15] BT-10: The buyer reference (BT-10) must be provided.
    Set the Leitweg-ID with ->buyerReference('04011000-12345-03').
```

More than five errors end with "… and N more."; the full result is in `$e->result`. When only warnings block (with `block_on_warnings`), the message lists the blocking warnings instead.

### Reading a message

Each finding starts with its rule code in brackets:

- `[BR-DE-15]` is the rule code. Its prefix tells where the rule comes from: `BR-` EN 16931, `BR-DE-` XRechnung, `PEPPOL-` Peppol, `DE-USTG-` the German VAT Act (UStG), `QUITTIO-` Quittio itself. All built-in rules are listed in [Rule sets](#rule-sets).
- `BT-10` is the field, here the buyer reference. The [Field reference](field-reference.md) shows where each field is in the data model.
- The text is the message. The indented line below is the hint, usually the builder method that fixes the error. The hint of `BR-DE-15` sets the Leitweg-ID, the routing ID of a German public buyer ([German terms](concepts.md#german-terms)).

One missing value can be reported by more than one rule. In the example, the field check of the profile (level L2, `QUITTIO-PROFILE-REQUIRED`) and the XRechnung rule `BR-DE-15` (level L3) both report the buyer reference. Setting it fixes both.

### Handling the exception

```php
use Quittio\Core\Validation\ValidationException;
use Quittio\Laravel\Facades\Quittio;

try {
    $rendered = Quittio::make($invoice)->format('xrechnung-cii')->render();
} catch (ValidationException $e) {
    foreach ($e->result->errors() as $message) {
        report($message->code.' '.$message->message);
    }
}
```

`$e->result` is a [`ValidationResult`](#the-result) with all findings.

The Laravel integration dispatches two events ([Events](laravel.md#events)):

- `InvoiceValidationFailed($invoice, $result)` before the exception is thrown,
- `InvoiceValidated($invoice, $result)` after a successful run. This includes mode `warn`, with warnings or errors, but not mode `off`, where nothing is validated.

Other exceptions of a render run:

- `ConfigurationException`: unknown format, profile not supported by the format, watermark with e-invoice XML, invalid design values,
- `Quittio\Core\View\MandatoryFieldMissing` from the [mandatory field guard](#mandatory-field-guard).

All exceptions of the package implement `Quittio\Core\Exceptions\QuittioException`, so `catch (QuittioException $e)` catches every error of Quittio. Invalid arguments and runtime errors without a class of their own are `InvalidArgumentException`, `RuntimeException` or `LogicException` from `Quittio\Core\Exceptions`; they extend the PHP classes of the same name.

### Warnings

Every finding has a severity: error, warning or info. Only errors stop the rendering. Warnings, for example a BIC with an invalid format (`QUITTIO-BIC`), are attached to the result: `$rendered->validation()->warnings()`. With `block_on_warnings` set to `true`, warnings block like errors ([Configuration](#configuration)).

### Checking without rendering

While you build an invoice, `check()` instead of `render()` shows all findings at once. It validates like `render()`, renders no PDF, returns the `ValidationResult` and never throws for invalid data:

```php
use Quittio\Laravel\Facades\Quittio;

$report = Quittio::invoice()->buyer($customer)->line('Consulting', netPrice: '120.00')->check('xrechnung-cii');
$report = Quittio::make($invoice)->format('xrechnung-cii')->check();

$report->isValid();
$report->errors();
```

Details are in [When validation runs](#when-validation-runs).

## Configuration

The validation mode decides what happens with errors:

| Mode | Behaviour |
|---|---|
| `error` (default) | Errors throw a `ValidationException`; nothing is rendered. |
| `warn` | The run continues. `$rendered->validation()` holds all findings, errors included. |
| `off` | Nothing is validated while rendering; the result is empty. `check()` and `prepare()` still validate. |

Set it for the application with `validation.mode` (environment variable `QUITTIO_VALIDATION_MODE`), or for one call.

### Per call

```php
Quittio::make($invoice)->validate()->render();               // mode error
Quittio::make($invoice)->validate(false)->render();          // mode warn: errors are attached, nothing is thrown
Quittio::make($invoice)->withoutValidation()->render();      // mode off: empty result (check() and prepare() still validate)
Quittio::make($invoice)->option('max_decimals', 6)->render(); // option for the rules
```

### Settings

In Laravel (keys of `config/quittio.php`, see [Configuration](configuration.md#validation)). The levels L1 to L7 are described in [Validation levels](#validation-levels) below.

| Key | Values | Default |
|---|---|---|
| `validation.mode` | `error` (throw), `warn` (attach the result only), `off` | `error` (`QUITTIO_VALIDATION_MODE`) |
| `validation.levels` | `php` (L1, L2, L3, L7), `xsd` (L4), `kosit` (L5), `verapdf` (L6), or the name of a driver registered with `Quittio::extend('validator', …)` | `['php', 'xsd']` |
| `validation.block_on_warnings` | `true`: warnings block like errors | `false` |
| `validation.locale` | language of the messages: `null` = the language of the application, `'invoice'` = the language of the invoice, or a locale | `null` (`QUITTIO_VALIDATION_LOCALE`) |
| `validation.mandatory_field_guard` | `exception`, `log`, `off`; `null` = `exception` outside production, `log` in production | `null` (`QUITTIO_MANDATORY_FIELD_GUARD`) |
| `validation.rules` | single PHP rules switched off or re-rated by code, see [Switching rules off](#switching-rules-off-and-changing-their-severity) | nothing changed |
| `validation.drivers.kosit` | see [KoSIT validator](#kosit-validator-l5) | |
| `validation.drivers.verapdf` | see [veraPDF](#verapdf-l6) | |
| `validation.drivers.vies` | see [VIES](#vat-identifiers-and-vies) | |

Without Laravel, `Quittio::create()` takes the same settings. It uses the level names of the enum and separate switches for the external validators:

```php
use Quittio\Core\Quittio;
use Quittio\Country\DE\CountryDE;

$quittio = Quittio::create([
    'validation' => [
        'mode' => 'error',                              // error, warn, off
        'levels' => ['L1', 'L2', 'L3', 'L4', 'L7'],     // default; L5 and L6 are switched on below
        'block_on_warnings' => false,
        'kosit' => ['enabled' => true, 'mode' => 'daemon', 'url' => 'http://127.0.0.1:8081'],
        'verapdf' => ['enabled' => true, 'mode' => 'docker'],
    ],
    'mandatory_fields' => 'exception',                  // exception, log, off
    'options' => ['max_decimals' => 4],                 // options for rules, see below
], [new CountryDE]);
```

In `Quittio::create()`:

- The PHP driver runs only the listed levels of L1, L2, L3 and L7, so L7 can be left out.
- `L4` adds the XSD driver.
- The KoSIT and veraPDF drivers are added only with `enabled => true`. Listing `L5` or `L6` alone has no effect.
- An unknown mode throws a `ConfigurationException`.

### Options for rules

Some rules read options. Set them per call with `->option()`, without Laravel also in `options`:

| Option | Used by | Default |
|---|---|---|
| `max_decimals` | `QUITTIO-PRECISION`: more decimals in quantity or net price are a warning | `4` |
| `attachment_max_bytes` | `QUITTIO-ATTACHMENT-SIZE`: limit per attached document | 10 MB |
| `attachments_max_total_bytes` | `QUITTIO-ATTACHMENT-SIZE`: limit of all attachments | 15 MB |
| `syntax` | Syntax of the XML that will be written (`Quittio\Core\Profiles\Syntax`); set by the run for every format with XML, used by `QUITTIO-THIRD-PARTY-PAYMENTS`, `QUITTIO-CREDIT-NOTE-DUE-DATE`, the `BR-DEX-…` rules, `DE-XRECHNUNG-EXT-01` and the rules a profile replaces (`ReplacesRules`) | from the format |

## Validation levels

The checks are split into seven levels. Each level is checked by a driver. The default configuration runs L1 to L4 and L7 in PHP. L5 and L6 use the official external validators, which you install separately.

| Level | What is checked | Driver | Stage |
|---|---|---|---|
| L1 | Structure and data formats of the data model (calculated, country and currency codes, decimals) | `php` | data |
| L2 | Mandatory and allowed fields of the profile (field matrix) | `php` | data |
| L3 | Business rules: EN 16931 (`BR-…`, `BR-CO-…`, `BR-CL-…`, VAT categories), package rules (`QUITTIO-…`), profile rules (Peppol, XRechnung) and country pack rules (`DE-USTG-…`) | `php` | data |
| L4 | XML against the XSD (CII D16B, UBL 2.1) | `xsd` | xml |
| L5 | XML against the official Schematron (KoSIT validator: EN 16931, XRechnung, Peppol) | `kosit` | xml |
| L6 | PDF/A-3b conformance | `verapdf` | pdf |
| L7 | Plausibility: IBAN, BIC, VAT identifier formats, e-mail addresses, dates, VAT category against the destination, Leitweg-ID, optional VIES check | `php` | data |

Some terms of the table:

- The XSD (XML Schema Definition) describes which elements an XML file of a syntax may contain.
- The Schematron files are the official rules of EN 16931, XRechnung and Peppol, published as executable checks. The [KoSIT validator](#kosit-validator-l5) runs them.
- PDF/A-3b is the archiving variant of PDF that may carry embedded files. [veraPDF](#verapdf-l6) checks it.
- VIES is the EU service that confirms VAT identification numbers online ([VAT identifiers and VIES](#vat-identifiers-and-vies)).

L1 to L3 and L7 run in PHP. L4 needs only `ext-dom`. L5 needs Java (or Docker), L6 needs veraPDF (or Docker). No other external validator (for example Mustang) is integrated; add one as [own driver](#own-rules-and-drivers).

## When validation runs

A render run validates in three stages, per requested format:

1. **data** (L1, L2, L3, L7) on the calculated invoice, before any XML is written;
2. **xml** (L4, L5) on the generated XML, for formats with XML (and for `pdf` with embedded XML for consumers, `b2c_embed_xml`);
3. **pdf** (L6) on the rendered PDF.

In mode `error`, the run stops after the first stage that has errors (or warnings with `block_on_warnings`). Data errors throw before XML is written, XML errors before the PDF is rendered. A driver runs only if it supports the subject: XML drivers need XML, veraPDF needs a PDF.

The validation context holds:

- the profile of the format,
- the country packs: the seller's pack first, the buyer's pack for cross-border invoices,
- the locale of the run: the language of the invoice, which rules use for its texts,
- the language of the messages ([Messages and translations](#messages-and-translations)),
- the options.

Formats without XML (`pdf`, `html`, `json`) are no e-invoices: L2 (profile field matrix) is skipped for them. With several formats (`->formats([...])`), every format is validated with its own profile and syntax.

`check()` and `prepare()` validate like a render run without template and PDF:

```php
$result = Quittio::make($invoice)->format('xrechnung-cii')->check();      // ValidationResult
$prepared = Quittio::make($invoice)->formats(['zugferd'])->prepare();     // PreparedInvoice: data, formats, country, buyerCountry, locale, validation
```

They are explicit validation requests:

- They run the data and xml stages of every requested format with all configured drivers, whatever the validation mode (also `off` and `withoutValidation()`). The [Managed layer](managed.md) freezes invoices with `prepare()`.
- They never throw a `ValidationException`. Configuration errors, such as an unsupported profile or an XML format for watermarked data, still throw.
- They take the same decisions as `render()`. For `pdf` with `b2c_embed_xml` (and no watermark), the embedded CII XML is generated and validated in the same profile, so what is checked is what is rendered.
- L6 does not run, because it needs the rendered PDF.

## The result

`Quittio\Core\Validation\ValidationResult` (immutable, `JsonSerializable`):

| Member | Returns |
|---|---|
| `messages` | `list<ValidationMessage>` – all findings |
| `levels` | `list<ValidationLevel>` – the levels that were checked |
| `isValid()` | `true` if there is no finding with severity `error`; warnings never make a result invalid |
| `hasWarnings()` | whether there are warnings |
| `errors()`, `warnings()`, `infos()` | findings of one severity |
| `byLevel(ValidationLevel $level)` | findings of one level |
| `has(string $code)` | whether a finding with this code exists |
| `merge(ValidationResult $other)` | new result with the messages of both and the union of the levels |
| `toArray()`, `jsonSerialize()` | `['valid' => bool, 'levels' => ['L1', …], 'messages' => [ … ]]` |
| `toHtml()` | self-contained HTML fragment: status ("Valid", "Valid with warnings", "Invalid") and a table with severity, level, code, BT and message (with the hint); CSS classes `quittio-report`, `quittio-report-status`, `quittio-valid`, `quittio-invalid`, `quittio-{severity}` |

`Quittio\Core\Validation\ValidationMessage` (readonly):

| Property | Content |
|---|---|
| `severity` | `Severity::Error`, `Severity::Warning` or `Severity::Info` (`error`, `warning`, `info`) |
| `code` | rule identifier, e.g. `BR-CO-25`, `BR-DE-15`, `DE-USTG-03`, `PEPPOL-EN16931-R010`, `QUITTIO-IBAN`, `XSD`, `PDFA-6.2.2-1` |
| `message` | text in the language of the messages (`validation.locale`, see [Messages and translations](#messages-and-translations)), where a translation exists |
| `level` | `ValidationLevel::L1` … `L7` |
| `bt` | business term or group, e.g. `BT-10`, `BG-23` (nullable) |
| `path` | path in the data model, e.g. `buyer.vatId`, `line 2.price.netPrice`, `vatBreakdown[0].taxAmount`; for XSD `line 12` (nullable) |
| `hint` | solution hint from the message catalog (nullable) |
| `xpath` | location in the XML (KoSIT findings, nullable) |

`isError()` and `toArray()` are available on every message.

Results of several drivers are merged by concatenation. A rule that is checked both in PHP (L3) and by the official Schematron (L5) can therefore appear twice, with the two levels. Within the PHP driver, identical findings (same code, path and message) are reported once.

## Messages and translations

In Laravel, messages and hints are written in the language of the application (`app()->getLocale()`). A developer of an English application therefore reads English messages, also for German invoices. `validation.locale` sets `'invoice'` (the language of the invoice, as in the PDF) or a fixed locale.

Without Laravel, the default is the language of the invoice. `Quittio::create(['validation' => ['locale' => 'en']])` sets a fixed locale, `'app'` the locale of the translator.

Rules that check texts of the invoice, e.g. the reverse charge statement of `DE-USTG-06` (the note that the buyer owes the VAT), still use the language of the invoice.

Messages come from the catalog `quittio::rules.{CODE}` with the entries `message` and `hint`; placeholders such as `:value` are replaced. The core ships the catalog in German and English (`packages/core/resources/lang/{de,en}/rules.php`). Other locales fall back to English, and rules without an entry use their English fallback text. Country packs bring their own namespace (`quittio-de::rules.DE-USTG-01`).

To override messages in Laravel, publish the language files with `php artisan vendor:publish --tag=quittio-lang` and edit `lang/vendor/quittio/{locale}/rules.php`. Without Laravel, register a directory with `{locale}/rules.php` files; later paths override earlier ones:

```php
use Quittio\Core\I18n\Translations;

Translations::translator()->addPath(__DIR__.'/lang', 'quittio');
```

Findings of the XSD validation, the KoSIT validator, veraPDF and the invoice reader are not translated: they carry the text of the tool or an English text.

## XML schema (L4)

`Quittio\Core\Validation\Drivers\XsdValidator` validates against the bundled schemas (`packages/core/resources/xsd`): CII D16B for `CrossIndustryInvoice`, UBL 2.1 for `Invoice` and `CreditNote`. It needs no installation. All its findings are errors:

| Code | Meaning |
|---|---|
| `XSD` | The XML does not match the schema; the path is `line N` |
| `XSD-NOT-WELL-FORMED` | Unsafe or broken XML, see [reading](reading.md#limits-and-security) |
| `XSD-UNKNOWN-ROOT` | No schema for the root element |

Use it on its own with `(new XsdValidator)->validateXml($xml)` (returns `list<ValidationMessage>`).

## KoSIT validator (L5)

KoSIT, the German agency that publishes XRechnung, provides the official validator. The official Schematron rules are XSLT 2.0, which PHP cannot execute, so L5 uses the KoSIT validator (Java). It checks EN 16931 and XRechnung (CII and UBL) with the XRechnung validator configuration, and Peppol BIS Billing 3.0 with a second configuration.

To use it:

1. Download the validator and its configurations ([Installation](#installation)).
2. Start it as a daemon, or let Quittio call it per document ([Modes](#modes)).
3. Add `kosit` to `validation.levels`.

### Installation

```bash
php artisan quittio:schemas:download           # into storage/quittio/validators
php artisan quittio:schemas:download --path=/opt/validators --force --java=/usr/bin/java
php artisan quittio:schemas:download --without-peppol
```

The command downloads into the target directory:

- the validator `1.6.3` (standalone JAR from Maven Central) as `validator.jar`,
- the XRechnung validator configuration `v2026-08-31` (XRechnung 3.0.2) into `xrechnung/`.

It then compiles the Peppol BIS Billing 3.0 Schematron (`v3.0.20`, with SchXslt 1.10.1 and the Saxon bundled in the validator) into `peppol/PEPPOL-EN16931-UBL.xsl`. Compiling needs Java; skip it with `--without-peppol`.

Existing files are kept unless `--force` is given. The PHP extension `zip` is required. Java 11 or newer is required to run the validator. At the end, the command prints the configuration to add and the command that starts the daemon on the port of `validation.drivers.kosit.url` (`-P <port>`).

Without Laravel use `Quittio\Core\Validation\Drivers\KositArtifacts`:

- `download(string $target, bool $force = false)` returns `['jar' => …, 'scenarios' => …, 'repository' => …]`,
- `compilePeppol(string $target, string $java = 'java', bool $force = false)` returns `['scenarios' => …, 'repository' => …]`. The scenario file `peppol-scenarios.xml` ships with the core (`KositArtifacts::peppolScenarios()`).

### Modes

| Mode | How | Configuration |
|---|---|---|
| `daemon` (default, recommended) | HTTP POST of the XML (`Content-Type: application/xml`) to `url`; a running validator answers with its report | `url` |
| `cli` | One JVM per document: `java -jar {jar} -s {scenarios} -r {repository} … -o {tmp} document.xml` | `jar`, `scenarios` and `repository` and/or `configurations`, `java` |

Start a daemon with both configurations (paths as printed by the download command) on the port of the default URL `http://127.0.0.1:8081`. The validator itself listens on port 8080 unless it is started with `-P`:

```bash
cd storage/quittio/validators
java -jar validator.jar \
    -s xrechnung/scenarios.xml -r xrechnung \
    -s ../../../vendor/quittio/core/resources/kosit/peppol-scenarios.xml -r . \
    -D -P 8081
```

The repository of Quittio contains a Docker image with the same setup (`tools/docker/kosit/Dockerfile`). It listens on 8080 inside the container:

```bash
docker build -t quittio/kosit -f tools/docker/kosit/Dockerfile .
docker run --rm -p 8081:8080 quittio/kosit       # maps it to the default port 8081
```

With another port, set `QUITTIO_KOSIT_URL` (without Laravel `validation.kosit.url`) accordingly. Any endpoint that accepts the POST and returns a KoSIT report works.

`validation.drivers.kosit`:

| Key | Meaning | Default |
|---|---|---|
| `mode` | `daemon` or `cli` (other values throw `InvalidArgumentException`) | `daemon` |
| `url` | daemon endpoint | `http://127.0.0.1:8081` (`QUITTIO_KOSIT_URL`) |
| `jar` | path of `validator.jar` (cli) | `null` |
| `scenarios`, `repository` | main configuration (cli) | `null` |
| `configurations` | further configurations (cli), `[['scenarios' => …, 'repository' => …]]`, e.g. Peppol | `[]` |
| `java` | Java binary (cli) | `java` |
| `timeout` | seconds per document (HTTP timeout or process limit) | `30` |
| `fail_on_unavailable` | an unreachable validator is an error (`true`) or a warning (`false`) | `true` |

Add `kosit` to `validation.levels`. Without Laravel, the same keys go to `validation.kosit` with `enabled => true`; use `configurations` for all scenario sets there.

### Findings

Every message of the report becomes a finding with:

- the rule code of the report (`BR-DE-15`, `PEPPOL-EN16931-R010` …),
- the severity of the report (`error`, `warning`, otherwise `info`),
- the business term found in the text,
- the XPath location.

Findings of the schema step of the validator have the level L4, all others L5. A message of the schema step without a code of its own gets the code `KOSIT-XSD`. Additional codes:

| Code | Severity | Meaning |
|---|---|---|
| `KOSIT-UNAVAILABLE` | error or warning (`fail_on_unavailable`) | No report: daemon unreachable or HTTP status other than 200, 406 or 422, JAR, configuration or Java missing, timeout |
| `KOSIT-NO-SCENARIO` | warning | The configuration has no scenario for the document (e.g. Factur-X EXTENDED); only the XML schema was checked |
| `KOSIT-REJECT` | error | The validator recommends rejection although no message is an error |
| `KOSIT` | from the report | Message without code |

The raw report of the last call is available from the driver instance with `lastReport()`, e.g. for archiving the test report. `KositReportParser::parse($report)` returns `['accepted' => bool, 'scenario' => ?string, 'messages' => list<ValidationMessage>]`.

## veraPDF (L6)

veraPDF is an open-source validator for PDF/A. `Quittio\Core\Validation\Drivers\VeraPdfValidator` checks the rendered PDF against PDF/A-3b (`--flavour 3b`, machine readable report, at most 5 failures per rule). It runs for formats that produce a PDF.

### Installing veraPDF

veraPDF runs in one of two modes:

| Mode | How |
|---|---|
| `cli` | local veraPDF installation (Java); the PDF is written to a temporary file and passed to `binary` |
| `docker` | `docker run --rm -i {image}`; the PDF is passed via stdin |

For mode `docker`, Docker is enough; fetch the image once with `docker pull verapdf/cli`. For mode `cli`, install veraPDF locally and make its `verapdf` command available (or set `binary`). Then add `verapdf` to `validation.levels`; without Laravel use `validation.verapdf` with `enabled => true`.

`validation.drivers.verapdf` (all keys are in the published configuration):

| Key | Meaning | Default |
|---|---|---|
| `mode` | `cli` or `docker` | `cli` in the Laravel configuration, `docker` without Laravel |
| `binary` | veraPDF binary (cli) | `verapdf` |
| `image` | Docker image (docker) | `verapdf/cli` |
| `docker` | Docker binary (docker) | `docker` |
| `timeout` | seconds | `120` |
| `fail_on_unavailable` | an unavailable veraPDF is an error (`true`) or a warning (`false`) | `true` |

Findings of veraPDF:

| Code | Meaning |
|---|---|
| `PDFA-{clause}-{test}` | Failed rule, e.g. `PDFA-6.2.2-1`; the hint names specification, clause and description, the path the PDF object |
| `VERAPDF-NOT-COMPLIANT` | Not compliant without a failed rule in the report |
| `VERAPDF-NOT-PARSED` | veraPDF could not validate the file |
| `VERAPDF-UNAVAILABLE` | veraPDF could not be run, e.g. `verapdf` or `docker` not installed. It is reported as a finding, not thrown: an error with `fail_on_unavailable` (in mode `error` the run then stops with a `ValidationException`), otherwise a warning |

All findings of the external validators are merged into the result of the run like the PHP findings.

## Rule sets

The PHP driver (`Quittio\Core\Validation\Drivers\PhpRulesDriver`, name `php`) runs these rule sets, filtered by the configured levels:

| Rule set | Level | Codes |
|---|---|---|
| `StructureRules` | L1 | `QUITTIO-NOT-CALCULATED` (no totals), `QUITTIO-FORMAT-COUNTRY` (BT-40, BT-55 not two upper case letters), `QUITTIO-FORMAT-CURRENCY` (BT-5, BT-6 not three upper case letters), `QUITTIO-PRECISION` (warning, more decimals than `max_decimals`) |
| `FieldMatrixRule` | L2 | `QUITTIO-PROFILE-REQUIRED` (error: mandatory field of the profile missing), `QUITTIO-PROFILE-NOT-ALLOWED` (warning: field is not part of the profile and is not written), `QUITTIO-PROFILE-EXTENDED-ONLY`, `QUITTIO-PROFILE-XRECHNUNG-EXTENSION-ONLY`, `QUITTIO-PROFILE-SUBLINES` (errors). The matrix comes from the mapping attributes of the data model ([field reference](field-reference.md)) plus the business terms the profile requires (XRechnung: BT-10, BT-34, BT-49, BG-6, BG-16); BT-23 is not required when the profile sets a default business process |
| `En16931Rules` | L3 | `BR-02`, `BR-04` to `BR-07`, `BR-09`, `BR-11`, `BR-16` to `BR-18`, `BR-20`, `BR-21`, `BR-25`, `BR-27` to `BR-30`, `BR-33`, `BR-38`, `BR-42`, `BR-44`, `BR-48` to `BR-50`, `BR-52` to `BR-57`, `BR-61`, `BR-CO-03`, `BR-CO-09` to `BR-CO-18`, `BR-CO-25`, `BR-CO-26` |
| `VatCategoryRules` | L3 | per category (`BR-S`, `BR-Z`, `BR-E`, `BR-AE`, `BR-IC`, `BR-G`, `BR-O`): `-01` breakdown present, `-02` party identifiers, `-05` rate, `-08` taxable amount, `-09` tax amount, `-10` exemption reason; plus `BR-IC-11`, `BR-IC-12`, `BR-O-11` |
| `CodeListRules` | L3 | `BR-CL-01`, `-04` to `-08`, `-10`, `-11`, `-13`, `-14`, `-16`, `-19` to `-23`, `-25`, `-26` (official code lists) |
| `QuittioRules` | L3 | package rules, see below |
| `PlausibilityRules` | L7 | see [plausibility](#plausibility-l7) |
| profile rules | L3 | Peppol BIS profile: `PEPPOL-EN16931-R001`, `-R002`, `-R003`, `-R005` (warning), `-R007`, `-R008`, `-R010`, `-R020`, `-R040`, `-R041`, `-R042`, `-R046`, `-R055`, `-R061`, `-R110`, `-R111`, `-R120`, `-R121`, `-R130`, `-CL007`, `-CL008`, `-F001`, `-P0100`, `-P0101`, `-P0104` to `-P0112`, `PEPPOL-COMMON-R040` to `-R047`, `-R049`, `-R050`, `-R052`, `-R053` (`-R044` to `-R047`, `-R052`, `-R053` warnings), see [Peppol rules](#peppol-rules); XRechnung profiles (country pack DE): `BR-DE-…`, `DE-XRECHNUNG-EXT-01`; XRechnung extension: `BR-DEX-02` (warning), `BR-DEX-04` to `-10`, `-12` to `-15` |
| country pack rules | L3, L7 | `rules()` of the seller's pack and `buyerCountryRules()` of the buyer's pack; Germany: `DE-USTG-01` … `DE-USTG-15` (L3), `DE-LEITWEG-ID`, `DE-LEITWEG-ID-SUSPECT`, `DE-TAX-NUMBER` (L7), see [Country packs](country-packs.md) |

All EN 16931, code list, VAT category and Peppol rules report errors unless noted. The PHP rules cover the most frequent Schematron findings with readable messages. They do not replace the official Schematron (L5).

A profile can replace rules with its own (`Profiles\Capabilities\ReplacesRules`); the driver then does not run them for that profile. The XRechnung extension replaces `BR-CL-10`, `-11`, `-21`, `-25`, `-26` with `BR-DEX-04` to `-08` and, in UBL, `BR-CO-16` with `BR-DEX-09` and `QUITTIO-SUBLINES` with `BR-DEX-02`, as its official validator configuration does ([XRechnung rules](country-packs.md#xrechnung-rules)).

### Package rules (`QUITTIO-…`)

| Code | Severity | Meaning |
|---|---|---|
| `QUITTIO-NEGATIVE-TOTAL` | warning | Commercial invoice (380) with a negative amount due; consider a credit note |
| `QUITTIO-SUBLINES` | error | Net amount of a line differs from the sum of its sub lines (XRechnung extension in UBL: `BR-DEX-02`, warning) |
| `QUITTIO-LINE-ID-DUPLICATE` | warning | A line identifier (BT-126) is used more than once (data not built with `InvoiceBuilder`, which rejects duplicates) |
| `QUITTIO-SUBLINE-VAT` | error | Sub line with another VAT category or rate than its parent line |
| `QUITTIO-PRECEDING-INVOICES-CII` | warning | More than one preceding invoice (BG-3): CII transmits only the first |
| `QUITTIO-EXEMPTION-CONFLICT` | warning | Lines of one VAT group with different exemption reason codes: the code (BT-121) is omitted, the texts are joined |
| `QUITTIO-DECLARED-AMOUNTS` | warning | Declared amounts of an existing document were outside the tolerance and recalculated |
| `QUITTIO-THIRD-PARTY-PAYMENTS` | error | Third party payments (BG-DEX-09) outside an XRechnung UBL invoice of the extension |
| `QUITTIO-CREDIT-NOTE-DUE-DATE` | warning | UBL credit note with a due date (BT-9) but without payment instructions (BG-16): the UBL binding has no place for the date there, it is left out |
| `QUITTIO-VAT-ROUNDING` | warning | VAT amount differs from taxable amount × rate within the tolerance of BR-CO-17 |
| `QUITTIO-CANCELLATION-VAT-ALIGNED` | info | VAT of a partial cancellation aligned with the earlier ones (cumulative rounding) |
| `QUITTIO-LINE-ROUNDING` | warning | Declared line net amount differs from quantity × price ± allowances and charges |
| `QUITTIO-ATTACHMENT-FILENAME` | error | File name of an attached document used twice |
| `QUITTIO-ATTACHMENT-SIZE` | error | Attachments larger than `attachment_max_bytes` or `attachments_max_total_bytes` |
| `QUITTIO-INVALID-CHARACTERS` | error | A text contains control characters (e.g. a vertical tab from a spreadsheet cell) or invalid UTF-8, which XML cannot represent; XML writers refuse such data |
| `QUITTIO-GENERIC-COUNTRY` | warning | No country pack for the seller's country: only EN 16931 is checked |
| `QUITTIO-PROFILE-NOT-EINVOICE` | warning | Profile that is no EN 16931 e-invoice (MINIMUM, BASIC WL) |
| `QUITTIO-STANDARD-VERSION` | error | The profile does not support the EN 16931 version of the invoice |
| `QUITTIO-PROFORMA` | error | Type code 325 in the invoice pipeline; use `Quittio::proforma()` or `DocumentBuilder::proforma()` ([documents](documents.md#proforma-invoices)) |

### Peppol rules

The Peppol BIS Billing 3.0 Schematron (3.0.20) has 59 rule ids of its own (`PEPPOL-EN16931-…`, `PEPPOL-COMMON-…`); the national rules it contains (`NO-R-…`, `DK-R-…`, `SE-R-…` …) are not ported. In the Peppol profile the PHP rules check every rule that the data of an invoice can break. The others need no PHP rule:

| Rules | Why there is no PHP rule |
|---|---|
| `PEPPOL-EN16931-R004` | The writer takes the specification identifier (BT-24) from the profile. |
| `PEPPOL-EN16931-R043`, `-R044` | The writer writes the charge indicator itself, `false` for the price discount (BT-147). |
| `PEPPOL-EN16931-R051` | The writer gives every amount the invoice currency (BT-5), BT-111 the VAT accounting currency (BT-6). |
| `PEPPOL-EN16931-R053`, `-R054` | The writer writes one `cac:TaxTotal` with the VAT breakdown and a second one with BT-111 only together with BT-6. A missing breakdown or BT-111 is reported by `BR-CO-18` and `BR-53`. |
| `PEPPOL-EN16931-R080`, `-R100`, `-R101` | The data holds one project reference (BT-11) and one invoiced object per line (BT-128); the writer gives the line reference the type code 130. |
| `PEPPOL-EN16931-CL001` | `MimeType` holds exactly the six MIME codes of the Peppol list (`BR-CL-24`). |
| `PEPPOL-EN16931-CL002`, `-CL003`, `-CL006` | The lists equal those of `BR-CL-19`, `BR-CL-20` and `BR-CL-06`, which report the code once. |
| `PEPPOL-COMMON-R048` | Commented out in the Schematron. |

Where a Peppol rule overlaps an EN 16931 rule, the Peppol code is reported only for what the Peppol rule adds: `-CL007` for currencies of the EN 16931 list that the Peppol copy of ISO 4217 lacks (e.g. `XCG`), `-P0100` and `-P0101` for type codes of the EN 16931 list that business process 01 does not allow, `-R008` for optional values of only whitespace (the writer leaves out empty values; mandatory ones are reported as missing by `BR-02` …), `-R007` for a business process in another format (a missing one is `-R001`). The narrower code lists `CodeList::PEPPOL_CURRENCIES`, `PEPPOL_INVOICE_TYPES` and `PEPPOL_CREDIT_NOTE_TYPES` are generated from the Schematron like `PEPPOL_EAS` (`tools/codelists/update.php`). `PEPPOL-COMMON-R040` to `-R053` check the format and check digits of identifiers with the schemes 0088 (GLN), 0192, 0184, 0208, 0201, 0210, 9907 (electronic address only), 0211, 0007, 0151, 0096 and 0198 in electronic addresses (BT-34, BT-49), party identifiers (BT-29, BT-46, BT-60) and legal registration identifiers (BT-30, BT-47, BT-61).

### Plausibility (L7)

| Code | Severity | Check |
|---|---|---|
| `QUITTIO-IBAN` | error | Check digits (ISO 13616, mod 97) of BT-84 and BT-91, for values that start like an IBAN |
| `QUITTIO-BIC` | warning | Format of BT-86 |
| `QUITTIO-VATID-FORMAT` | warning | Format of BT-31, BT-48 and BT-63 by country prefix (no check digits); unknown prefixes are not judged |
| `QUITTIO-EMAIL` | error / warning | Electronic addresses with scheme `EM` (BT-34, BT-49: error) and contact e-mails (BT-43, BT-58: warning) |
| `QUITTIO-DUE-DATE` | warning | Due date (BT-9) before the issue date |
| `QUITTIO-VAT-K-DOMESTIC` | warning | Category K (intra-community supply) with a destination in the seller's member state |
| `QUITTIO-VAT-K-OUTSIDE-EU` | warning | Category K with a destination outside the EU VAT territory |
| `QUITTIO-VAT-G-INSIDE-EU` | warning | Category G (export) with a destination inside the EU VAT territory |
| `QUITTIO-VIES` | error | VIES reports the VAT identifier as invalid (only with a VAT identifier verifier) |
| `QUITTIO-VIES-UNAVAILABLE` | info | VIES could not answer |

The destination is the deliver-to country (BT-80), otherwise the buyer's country (BT-55). The seller's member state comes from the prefix of BT-31 or BT-63, otherwise from BT-40. Territories outside the EU VAT area (e.g. Heligoland, the Canary Islands) are recognised by postcode.

The helpers are public: `Quittio\Core\Validation\Plausibility\Iban::isValid($iban)`, `Iban::normalize($iban)` and `VatIdFormat::isValid($vatId)` (`null` for an unknown prefix).

### VAT identifiers and VIES

VIES is the EU service that confirms VAT identification numbers online. The online check is off by default.

In Laravel, switch it on with `validation.drivers.vies.enabled = true` (`timeout` in seconds, default 5; `cache_ttl` in seconds, default 86400). A `Quittio\Core\Validation\Plausibility\VatIdVerifier` bound in the container replaces the VIES client, e.g. in tests.

Without Laravel, give the PHP driver a verifier:

```php
use Quittio\Core\Validation\Drivers\PhpRulesDriver;
use Quittio\Core\Validation\Plausibility\ViesVerifier;

$drivers = $quittio->validators();
$quittio->clearValidators();
foreach ($drivers as $driver) {
    $quittio->addValidator($driver instanceof PhpRulesDriver ? $driver->withVatIdVerifier(new ViesVerifier(timeout: 5, cacheTtl: 86400)) : $driver);
}
```

`ViesVerifier` uses the VIES REST interface:

- It checks only identifiers of EU member states (Greece `EL`) and Northern Ireland (`XI`) whose format is valid. Others are reported as not supported and skipped.
- Only the answers "valid" and "invalid" are results. They are cached per verifier instance.
- Errors and unreachable services give `valid = null`, are not cached and never block (`QUITTIO-VIES-UNAVAILABLE`).

`verify($vatId)` returns a `VatIdVerification` with `vatId`, `valid`, `name`, `address`, `error`, `supported` and `toArray()`. Own verifiers implement `VatIdVerifier::verify(string $vatId): VatIdVerification` and must not throw when a service is unavailable.

## Own rules and drivers

A driver implements `Quittio\Core\Validation\ValidationDriver` (`name()`, `levels()`, `supports(ValidationSubject $subject)`, `validate(ValidationSubject $subject): ValidationResult`). Its levels decide the stage: L6 runs on the PDF, L4 or L5 on the XML, everything else on the data.

The subject holds:

- `invoice`: the calculated `InvoiceData`,
- `context`: the `ValidationContext` with `profile`, `countryPacks`, `locale`, `options`, `option()`, `intOption()`, `leadingCountry()`, `hasCountry()`, `profileIs()`, `translator()`,
- `xml`, `syntax` and `pdf`.

Rules are easiest written as `CallbackRule`: `CallbackRule::l3()`, `CallbackRule::l7()` or `new CallbackRule($code, $level, $fallback, $check, $namespace)`. The closure receives the invoice and a `RuleReporter` with `error()`, `warning()` and `info()` (arguments `$bt`, `$path`, `$replace`) and `context()`:

```php
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Validation\Rules\CallbackRule;
use Quittio\Core\Validation\Rules\RuleReporter;
use Quittio\Core\Validation\{ValidationDriver, ValidationLevel, ValidationResult, ValidationSubject};

final class AcmeRules implements ValidationDriver
{
    public function name(): string { return 'acme'; }

    public function levels(): array { return [ValidationLevel::L3]; }

    public function supports(ValidationSubject $subject): bool { return true; }

    public function validate(ValidationSubject $subject): ValidationResult
    {
        $rule = CallbackRule::l3('ACME-PO', 'Invoices to :value need a purchase order number (BT-13).', function (InvoiceData $i, RuleReporter $report): void {
            if ($i->purchaseOrderReference === null) {
                $report->error('BT-13', 'purchaseOrderReference', ['value' => $i->buyer->name]);
            }
        }, namespace: 'acme');

        return new ValidationResult([...$rule->check($subject->invoice, $subject->context)], $this->levels());
    }
}
```

Register it in Laravel and add its name to the levels. The factory receives the container and `validation.drivers.acme`:

```php
Quittio::extend('validator', 'acme', fn ($app, array $config) => new AcmeRules);
// config/quittio.php: 'levels' => ['php', 'xsd', 'acme']
```

Without Laravel: `$quittio->addValidator(new AcmeRules)`.

The message of `ACME-PO` is looked up as `acme::rules.ACME-PO.message`, otherwise the fallback text is used. Register the catalog in Laravel with `loadTranslationsFrom(…, 'acme')`, in the core with `Translations::translator()->addPath($dir, 'acme')`.

Other ways to add rules:

- Implement `Quittio\Core\Validation\Rule` (`code()`, `level()`, `check(InvoiceData, ValidationContext): iterable<ValidationMessage>`) and build the messages with `Messages::make()`.
- Give such rules to `new PhpRulesDriver(additionalRules: [...])`.
- Return them from a country pack (`rules()`, `buyerCountryRules()`) or a profile (`rules()`). Country packs may only add or tighten rules.

`Quittio\Core\Validation\InvoiceValidator` runs a list of drivers on a `ValidationSubject` outside a render run.

## Switching rules off and changing their severity

Every PHP rule is addressed by the code of its messages, e.g. `DE-USTG-15` or `QUITTIO-PRECISION`. `validation.rules` switches rules off, changes the severity of their findings or, with an own implementation, replaces them. A trailing `*` matches by prefix:

```php
// config/quittio.php
'validation' => [
    'rules' => [
        'disabled' => ['QUITTIO-GENERIC-COUNTRY', 'DE-USTG-1*'],
        'severity' => ['DE-USTG-08' => 'warning', 'QUITTIO-*' => 'info'],
        'allow_official' => false,
    ],
],
```

- `disabled` drops the rules and every finding with a matching code. Rules that report several codes are covered too, e.g. `QUITTIO-PROFILE-NOT-ALLOWED` of the field matrix.
- `severity` sets `error`, `warning` or `info`. An exact code wins over a prefix, a longer prefix over a shorter one.
- A code that no rule has throws a `ConfigurationException` when the core is built, so typing errors do not switch nothing off silently. `$quittio->ruleCodes()` lists the known codes.
- The rules of EN 16931 (`BR-…`) and Peppol (`PEPPOL-…`) are official: receivers and the KoSIT validator reject invoices that break them. Changing them needs `'allow_official' => true`.
- The overrides change the PHP rules only (L1, L2, L3, L7). The findings of the XML schema, KoSIT and veraPDF are never changed.

A rule of your own replaces a rule with the same code:

```php
use Quittio\Core\Validation\Rules\CallbackRule;
use Quittio\Core\Validation\Rules\RuleReporter;
use Quittio\Laravel\Facades\Quittio;

Quittio::extend('rule', 'DE-USTG-15', fn ($app) => CallbackRule::l7('DE-USTG-15', 'Own check of the service date.', function ($invoice, RuleReporter $report): void {
    // …
}, namespace: 'quittio-de'));
```

Without Laravel, `Quittio::create()` reads the same keys under `validation.rules`, and `configureRules()` sets them on an instance:

```php
use Quittio\Core\Validation\RuleOverrides;
use Quittio\Core\Validation\Severity;

$quittio->configureRules(new RuleOverrides(
    disabled: ['QUITTIO-GENERIC-COUNTRY'],
    severities: ['DE-USTG-08' => Severity::Warning],
    replacements: ['DE-USTG-15' => $ownRule],
));
```

## Incoming files

To check e-invoices you receive, validate the file itself:

```php
$report = Quittio::validateFile($content);   // content (bytes) of an XML or PDF e-invoice, never a path
$report = Quittio::validatePath($path);      // the same from a local file
```

Both read the file ([Reading incoming invoices](reading.md)) and run every configured driver that supports it, independent of the stage and of `validation.mode`.

- They never throw a `ValidationException`. The reader exceptions (`UnsafeXmlException`, `PdfException`, `UnreadableInvoiceException`) are thrown.
- The result starts with the reader's findings (level L1, `QUITTIO-DECLARED-TOTALS` L3).
- The rules use the profile detected from the specification identifier (BT-24), otherwise the configured default profile. They also use the country packs of the parties and the syntax of the file.
- XSD and KoSIT check the XML; for a PDF, the embedded XML. veraPDF runs only when the input was a PDF.
- The read data is checked as declared, without recalculation.

On the command line:

```bash
php artisan quittio:validate invoice.pdf --profile=en16931 --kosit --verapdf --json
```

`--profile` fails the command when the detected profile differs. `--kosit` and `--verapdf` add the drivers for this call with the configured `validation.drivers.*` settings (see [Artisan commands](commands.md)).

## Mandatory field guard

The template decides what the PDF shows. After rendering a PDF or HTML invoice, the guard checks that the template printed every mandatory value: invoice number and date, names and addresses, quantities and units, VAT rows, amount due, legal notes and the values the country pack requires. It does not run for [documents](documents.md).

| Mode | Behaviour |
|---|---|
| `exception` | throws `Quittio\Core\View\MandatoryFieldMissing` (`$e->fields`: list of `MandatoryField` with `accessKey`, `description`, `legalBasis`) |
| `log` | logs a warning (PSR-3 logger) and continues |
| `off` | no check |

Set the mode with `validation.mandatory_field_guard` (Laravel; default `exception` outside production, `log` in production) or `mandatory_fields` (`Quittio::create()`, default `exception`).

In every mode except `off`, a missing value calls the listener of `$quittio->onMandatoryFieldsMissing(fn (InvoiceData $invoice, array $fields) => …)`. Laravel dispatches the event `Quittio\Laravel\Events\MandatoryFieldMissing($invoice, $fields)`. Which values count as printed is described in [Templates](templates.md#mandatory-field-guard).

## Germany: BMF error classes

The German Federal Ministry of Finance (BMF) groups e-invoice errors into classes in its letter of 15 October 2025. The German country pack sorts findings into these classes:

```php
use Quittio\Country\DE\CountryDE;
use Quittio\Country\DE\Rules\BmfErrorClass;

$classes = (new CountryDE)->errorClassification($result);   // ['format' => [...], 'business_rule' => [...], 'content' => [...]]
BmfErrorClass::of($message)->label('de');                    // "Formatfehler (Rz. 6a)", the German label of "format error (marginal no. 6a)"
```

Errors and warnings (no infos) are grouped by class:

| Class | Value | Findings | `voidsEInvoice()` |
|---|---|---|---|
| Format error (marginal no. 6a) | `format` | L1, L2, L4, L6 | `true` |
| Business rule error (marginal no. 6b) | `business_rule` | L3, L5 | `false` |
| Content error (marginal no. 35a) | `content` | every `DE-USTG-…` rule, L7 | `false` |

`label(string $locale = 'de')` returns the German name, any other locale the English name. Classes without findings are not in the array.

## JSON input

Invoice data can also arrive as JSON, e.g. from an API. Check it against the shipped schema (`packages/core/resources/schema/invoice.schema.json`, `JsonSchemaGenerator::defaultPath()`) before hydrating it. This needs the optional package `opis/json-schema`:

```php
use Quittio\Core\Serialization\JsonSchemaValidator;
use Quittio\Core\Data\InvoiceData;

if (JsonSchemaValidator::available()) {
    $errors = (new JsonSchemaValidator)->validate($json);   // list<string> "path: message", at most 20; [] = valid
}
$invoice = InvoiceData::fromJson($json);
```

Without the package, `validate()` throws a `RuntimeException`; invalid JSON throws a `JsonException`. The schema checks the structure only. The business rules run when the invoice is rendered or checked.
