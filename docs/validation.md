# Validation

Every render run validates the invoice before anything is issued. Validation is split into seven levels; each level is checked by a driver.

| Level | What is checked | Driver | Stage |
|---|---|---|---|
| L1 | Structure and data formats of the data model (calculated, country and currency codes, decimals) | `php` | data |
| L2 | Mandatory and allowed fields of the profile (field matrix) | `php` | data |
| L3 | Business rules: EN 16931 (`BR-…`, `BR-CO-…`, `BR-CL-…`, VAT categories), package rules (`QUITTIO-…`), profile rules (Peppol, XRechnung) and country pack rules (`DE-USTG-…`) | `php` | data |
| L4 | XML against the XSD (CII D16B, UBL 2.1) | `xsd` | xml |
| L5 | XML against the official Schematron (KoSIT validator: EN 16931, XRechnung, Peppol) | `kosit` | xml |
| L6 | PDF/A-3b conformance | `verapdf` | pdf |
| L7 | Plausibility: IBAN, BIC, VAT identifier formats, e-mail addresses, dates, VAT category against the destination, Leitweg-ID, optional VIES check | `php` | data |

L1 to L3 and L7 run in PHP. L4 needs only `ext-dom`. L5 needs Java (or Docker), L6 needs veraPDF (or Docker). No other external validator (for example Mustang) is integrated; add one as [own driver](#own-rules-and-drivers).

## Configuration

In Laravel (keys of `config/quittio.php`, see [Configuration](configuration.md)):

| Key | Values | Default |
|---|---|---|
| `validation.mode` | `error` (throw), `warn` (attach the result only), `off` | `error` (`QUITTIO_VALIDATION`) |
| `validation.levels` | `php` (L1, L2, L3, L7), `xsd` (L4), `kosit` (L5), `verapdf` (L6), or the name of a driver registered with `Quittio::extend('validator', …)` | `['php', 'xsd']` |
| `validation.block_on_warnings` | `true`: warnings block like errors | `false` |
| `validation.locale` | language of the messages: `null` = the language of the application, `'invoice'` = the language of the invoice, or a locale | `null` |
| `validation.mandatory_field_guard` | `exception`, `log`, `off`; `null` = `exception` outside production, `log` in production | `null` (`QUITTIO_GUARD`) |
| `validation.drivers.kosit` | see [KoSIT validator](#kosit-validator-l5) | |
| `validation.drivers.verapdf` | see [veraPDF](#verapdf-l6) | |
| `validation.drivers.vies` | see [VIES](#vat-identifiers-and-vies) | |

Without Laravel, `Quittio::create()` takes the same settings with the level names of the enum and separate switches for the external validators:

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

In `Quittio::create()` the PHP driver runs only the listed levels of L1, L2, L3 and L7 (so L7 can be left out), `L4` adds the XSD driver, and the KoSIT and veraPDF drivers are added only with `enabled => true` (listing `L5` or `L6` alone has no effect). An unknown mode throws a `ConfigurationException`.

### Per call

```php
Quittio::make($invoice)->validate()->render();               // mode error
Quittio::make($invoice)->validate(false)->render();          // mode warn: errors are attached, nothing is thrown
Quittio::make($invoice)->withoutValidation()->render();      // mode off: empty result (check() and prepare() still validate)
Quittio::make($invoice)->option('max_decimals', 6)->render(); // option for the rules
```

Options read by rules (per call with `->option()`, without Laravel also in `options`):

| Option | Used by | Default |
|---|---|---|
| `max_decimals` | `QUITTIO-PRECISION`: more decimals in quantity or net price are a warning | `4` |
| `attachment_max_bytes` | `QUITTIO-ATTACHMENT-SIZE`: limit per attached document | 10 MB |
| `attachments_max_total_bytes` | `QUITTIO-ATTACHMENT-SIZE`: limit of all attachments | 15 MB |
| `syntax` | Syntax of the XML that will be written (`Quittio\Core\Profiles\Syntax`); set by the run for every format with XML, used by `QUITTIO-THIRD-PARTY-PAYMENTS`, `BR-DEX-15` and `DE-XRECHNUNG-EXT-01` | from the format |

## When validation runs

A render run validates in three stages, per requested format:

1. **data** (L1, L2, L3, L7) on the calculated invoice, before any XML is written;
2. **xml** (L4, L5) on the generated XML, for formats with XML (and for `pdf` with embedded XML for consumers, `b2c_embed_xml`);
3. **pdf** (L6) on the rendered PDF.

The validation context holds the profile of the format, the country packs (the seller's pack first, the buyer's pack for cross-border invoices), the locale of the run (the language of the invoice, which rules use for its texts), the language of the messages ([Messages and translations](#messages-and-translations)) and the options. Formats without XML (`pdf`, `html`, `json`) are no e-invoices: L2 (profile field matrix) is skipped for them. With several formats (`->formats([...])`) every format is validated with its own profile and syntax.

In mode `error`, the run stops after the first stage that has errors (or warnings with `block_on_warnings`): data errors throw before XML is written, XML errors before the PDF is rendered. A driver runs only if it supports the subject: XML drivers need XML, veraPDF needs a PDF.

`check()` and `prepare()` validate like a render run without template and PDF:

```php
$result = Quittio::make($invoice)->format('xrechnung-cii')->check();      // ValidationResult
$prepared = Quittio::make($invoice)->formats(['zugferd'])->prepare();     // PreparedInvoice: data, formats, country, buyerCountry, locale, validation
```

They are explicit validation requests: they run the data and xml stages of every requested format with all configured drivers whatever the validation mode (also `off` and `withoutValidation()`; the Managed layer freezes invoices with `prepare()`), and never throw a `ValidationException` (configuration errors such as an unsupported profile or an XML format for watermarked data still throw). They take the same decisions as `render()`: for `pdf` with `b2c_embed_xml` (and no watermark) the embedded CII XML is generated and validated in the same profile, so what is checked is what is rendered. L6 does not run, it needs the rendered PDF.

## Errors while rendering

In mode `error`, a blocking result throws `Quittio\Core\Validation\ValidationException`. Its message lists the first five errors (`[BR-CO-25] BT-9: …`), each followed by its hint on the next line, or the blocking warnings when only warnings block; the full result is in `$e->result`:

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

In mode `warn` the run continues and `$rendered->validation()` holds all findings, errors included. The Laravel integration dispatches `InvoiceValidationFailed($invoice, $result)` before the exception is thrown and `InvoiceValidated($invoice, $result)` after a successful run (also in mode `warn`, with warnings or errors; not in mode `off`, where nothing is validated); see [Laravel integration](laravel.md).

Other exceptions of a render run: `ConfigurationException` (unknown format, profile not supported by the format, watermark with e-invoice XML, invalid design values) and `Quittio\Core\View\MandatoryFieldMissing` from the [mandatory field guard](#mandatory-field-guard). All exceptions of the package implement `Quittio\Core\Exceptions\QuittioException`.

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

Results of several drivers are merged by concatenation: a rule that is checked both in PHP (L3) and by the official Schematron (L5) can appear twice, with the two levels. Within the PHP driver identical findings (same code, path and message) are reported once.

## Messages and translations

Messages and hints are written in the language of the application in Laravel (`app()->getLocale()`), so a developer of an English application reads English messages also for German invoices. `validation.locale` sets `'invoice'` (the language of the invoice, as in the PDF) or a fixed locale. Without Laravel the default is the language of the invoice; `Quittio::create(['validation' => ['locale' => 'en']])` sets a fixed one, `'app'` the locale of the translator. Rules that check texts of the invoice (e.g. the reverse charge statement of `DE-USTG-06`) still use the language of the invoice.

Messages come from the catalog `quittio::rules.{CODE}` with the entries `message` and `hint`, placeholders such as `:value` are replaced. The core ships the catalog in German and English (`packages/core/resources/lang/{de,en}/rules.php`); other locales fall back to English, rules without an entry use their English fallback text. Country packs bring their own namespace (`quittio-de::rules.DE-USTG-01`).

Override messages in Laravel in `lang/vendor/quittio/{locale}/rules.php` (`php artisan vendor:publish --tag=quittio-lang`). Without Laravel, register a directory with `{locale}/rules.php` files; later paths override earlier ones:

```php
use Quittio\Core\I18n\Translations;

Translations::translator()->addPath(__DIR__.'/lang', 'quittio');
```

Findings of the XSD validation, the KoSIT validator, veraPDF and the invoice reader are not translated: they carry the text of the tool or an English text.

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
| profile rules | L3 | Peppol BIS profile: `PEPPOL-EN16931-R001`, `-R002`, `-R003`, `-R005` (warning), `-R010`, `-R020`, `-R040`, `-R041`, `-R042`, `-R061`, `-R120`, `-R121`, `-R130`; XRechnung profiles (country pack DE): `BR-DE-…`, `BR-DEX-15`, `DE-XRECHNUNG-EXT-01` |
| country pack rules | L3, L7 | `rules()` of the seller's pack and `buyerCountryRules()` of the buyer's pack; Germany: `DE-USTG-01` … `DE-USTG-15` (L3), `DE-LEITWEG-ID`, `DE-LEITWEG-ID-SUSPECT`, `DE-TAX-NUMBER` (L7), see [Country packs](country-packs.md) |

All EN 16931, code list, VAT category and Peppol rules report errors unless noted. The PHP rules cover the most frequent Schematron findings with readable messages; they do not replace the official Schematron (L5).

### Package rules (`QUITTIO-…`)

| Code | Severity | Meaning |
|---|---|---|
| `QUITTIO-NEGATIVE-TOTAL` | warning | Commercial invoice (380) with a negative amount due; consider a credit note |
| `QUITTIO-SUBLINES` | error | Net amount of a line differs from the sum of its sub lines |
| `QUITTIO-LINE-ID-DUPLICATE` | warning | A line identifier (BT-126) is used more than once (data not built with `InvoiceBuilder`, which rejects duplicates) |
| `QUITTIO-SUBLINE-VAT` | error | Sub line with another VAT category or rate than its parent line |
| `QUITTIO-PRECEDING-INVOICES-CII` | warning | More than one preceding invoice (BG-3): CII transmits only the first |
| `QUITTIO-EXEMPTION-CONFLICT` | warning | Lines of one VAT group with different exemption reason codes: the code (BT-121) is omitted, the texts are joined |
| `QUITTIO-DECLARED-AMOUNTS` | warning | Declared amounts of an existing document were outside the tolerance and recalculated |
| `QUITTIO-THIRD-PARTY-PAYMENTS` | error | Third party payments (BG-DEX-09) outside an XRechnung UBL invoice of the extension |
| `QUITTIO-VAT-ROUNDING` | warning | VAT amount differs from taxable amount × rate within the tolerance of BR-CO-17 |
| `QUITTIO-CANCELLATION-VAT-ALIGNED` | info | VAT of a partial cancellation aligned with the earlier ones (cumulative rounding) |
| `QUITTIO-LINE-ROUNDING` | warning | Declared line net amount differs from quantity × price ± allowances and charges |
| `QUITTIO-ATTACHMENT-FILENAME` | error | File name of an attached document used twice |
| `QUITTIO-ATTACHMENT-SIZE` | error | Attachments larger than `attachment_max_bytes` or `attachments_max_total_bytes` |
| `QUITTIO-GENERIC-COUNTRY` | warning | No country pack for the seller's country: only EN 16931 is checked |
| `QUITTIO-PROFILE-NOT-EINVOICE` | warning | Profile that is no EN 16931 e-invoice (MINIMUM, BASIC WL) |
| `QUITTIO-STANDARD-VERSION` | error | The profile does not support the EN 16931 version of the invoice |
| `QUITTIO-PROFORMA` | error | Type code 325 in the invoice pipeline; use `Quittio::proforma()` or `DocumentBuilder::proforma()` ([documents](documents.md#proforma-invoices)) |

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

The destination is the deliver-to country (BT-80), otherwise the buyer's country (BT-55); the seller's member state comes from the prefix of BT-31 or BT-63, otherwise from BT-40. Territories outside the EU VAT area (e.g. Heligoland, the Canary Islands) are recognised by postcode.

The helpers are public: `Quittio\Core\Validation\Plausibility\Iban::isValid($iban)`, `Iban::normalize($iban)` and `VatIdFormat::isValid($vatId)` (`null` for an unknown prefix).

### VAT identifiers and VIES

The online check is off by default. In Laravel, switch it on with `validation.drivers.vies.enabled = true` (`timeout` in seconds, default 5; `cache_ttl` in seconds, default 86400). A `Quittio\Core\Validation\Plausibility\VatIdVerifier` bound in the container replaces the VIES client, e.g. in tests. Without Laravel, give the PHP driver a verifier:

```php
use Quittio\Core\Validation\Drivers\PhpRulesDriver;
use Quittio\Core\Validation\Plausibility\ViesVerifier;

$drivers = $quittio->validators();
$quittio->withoutValidators();
foreach ($drivers as $driver) {
    $quittio->withValidator($driver instanceof PhpRulesDriver ? $driver->withVatIdVerifier(new ViesVerifier(timeout: 5, cacheTtl: 86400)) : $driver);
}
```

`ViesVerifier` uses the VIES REST interface and checks only identifiers of EU member states (Greece `EL`) and Northern Ireland (`XI`) whose format is valid; others are reported as not supported and skipped. Only the answers "valid" and "invalid" are results and are cached (per verifier instance); errors and unreachable services give `valid = null`, are not cached and never block (`QUITTIO-VIES-UNAVAILABLE`). `verify($vatId)` returns a `VatIdVerification` with `vatId`, `valid`, `name`, `address`, `error`, `supported` and `toArray()`. Own verifiers implement `VatIdVerifier::verify(string $vatId): VatIdVerification` and must not throw when a service is unavailable.

## XML schema (L4)

`Quittio\Core\Validation\Drivers\XsdValidator` validates against the bundled schemas (`packages/core/resources/xsd`): CII D16B for `CrossIndustryInvoice`, UBL 2.1 for `Invoice` and `CreditNote`. Findings are errors with the code `XSD` and the path `line N`; `XSD-NOT-WELL-FORMED` (unsafe or broken XML, see [reading](reading.md#limits-and-security)) and `XSD-UNKNOWN-ROOT` (no schema for the root element). Use it on its own with `(new XsdValidator)->validateXml($xml)` (returns `list<ValidationMessage>`).

## KoSIT validator (L5)

The official Schematron rules are XSLT 2.0, which PHP cannot execute, so L5 uses the KoSIT validator (Java). It checks EN 16931 and XRechnung (CII and UBL) with the XRechnung validator configuration, and Peppol BIS Billing 3.0 with a second configuration.

### Installation

```bash
php artisan quittio:schemas:download           # into storage/quittio/validators
php artisan quittio:schemas:download --path=/opt/validators --force --java=/usr/bin/java
php artisan quittio:schemas:download --without-peppol
```

The command downloads the validator `1.6.3` (standalone JAR from Maven Central) as `validator.jar` and the XRechnung validator configuration `v2026-08-31` (XRechnung 3.0.2) into `xrechnung/`, then compiles the Peppol BIS Billing 3.0 Schematron (`v3.0.20`, with SchXslt 1.10.1 and the Saxon bundled in the validator) into `peppol/PEPPOL-EN16931-UBL.xsl`. Compiling needs Java; skip it with `--without-peppol`. Existing files are kept unless `--force` is given. The PHP extension `zip` is required. The command prints the configuration to add and the command that starts the daemon on the port of `validation.drivers.kosit.url` (`-P <port>`). Java 11 or newer is required to run the validator.

Without Laravel use `Quittio\Core\Validation\Drivers\KositArtifacts`: `download(string $target, bool $force = false)` returns `['jar' => …, 'scenarios' => …, 'repository' => …]`, `compilePeppol(string $target, string $java = 'java', bool $force = false)` returns `['scenarios' => …, 'repository' => …]` (the scenario file `peppol-scenarios.xml` ships with the core, `KositArtifacts::peppolScenarios()`).

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

The repository of Quittio contains a Docker image with the same setup (`tools/docker/kosit/Dockerfile`), which listens on 8080 inside the container: `docker build -t quittio/kosit -f tools/docker/kosit/Dockerfile .` and `docker run --rm -p 8081:8080 quittio/kosit` maps it to the default port 8081. With another port, set `QUITTIO_KOSIT_URL` (without Laravel `validation.kosit.url`) accordingly. Any endpoint that accepts the POST and returns a KoSIT report works.

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

Add `kosit` to `validation.levels`. Without Laravel the same keys go to `validation.kosit` with `enabled => true`; use `configurations` for all scenario sets there.

### Findings

Every message of the report becomes a finding with the rule code of the report (`BR-DE-15`, `PEPPOL-EN16931-R010` …), the severity of the report (`error`, `warning`, otherwise `info`), the business term found in the text and the XPath location. Findings of the schema step of the validator have the level L4 and the code `KOSIT-XSD`, all others L5. Additional codes:

| Code | Severity | Meaning |
|---|---|---|
| `KOSIT-UNAVAILABLE` | error or warning (`fail_on_unavailable`) | No report: daemon unreachable or HTTP status other than 200, 406 or 422, JAR, configuration or Java missing, timeout |
| `KOSIT-NO-SCENARIO` | warning | The configuration has no scenario for the document (e.g. Factur-X EXTENDED); only the XML schema was checked |
| `KOSIT-REJECT` | error | The validator recommends rejection although no message is an error |
| `KOSIT` | from the report | Message without code |

The raw report of the last call is available from the driver instance with `lastReport()` (e.g. for archiving the test report); `KositReportParser::parse($report)` returns `['accepted' => bool, 'scenario' => ?string, 'messages' => list<ValidationMessage>]`.

## veraPDF (L6)

`Quittio\Core\Validation\Drivers\VeraPdfValidator` checks the rendered PDF against PDF/A-3b (`--flavour 3b`, machine readable report, at most 5 failures per rule). It runs for formats that produce a PDF.

| Mode | How |
|---|---|
| `cli` | local veraPDF installation (Java); the PDF is written to a temporary file and passed to `binary` |
| `docker` | `docker run --rm -i {image}`; the PDF is passed via stdin |

`validation.drivers.verapdf` (all keys are in the published configuration): `mode` (`cli` in the Laravel configuration, `docker` is the default without Laravel), `binary` (`verapdf`), `image` (`verapdf/cli`), `docker` (Docker binary, `docker`), `timeout` (seconds, `120`), `fail_on_unavailable` (`true`). Add `verapdf` to `validation.levels`; without Laravel use `validation.verapdf` with `enabled => true`.

| Code | Meaning |
|---|---|
| `PDFA-{clause}-{test}` | Failed rule, e.g. `PDFA-6.2.2-1`; the hint names specification, clause and description, the path the PDF object |
| `VERAPDF-NOT-COMPLIANT` | Not compliant without a failed rule in the report |
| `VERAPDF-NOT-PARSED` | veraPDF could not validate the file |
| `VERAPDF-UNAVAILABLE` | veraPDF could not be run, e.g. `verapdf` or `docker` not installed (error or warning, `fail_on_unavailable`); the render run continues, also in Laravel |

All findings of the external validators are merged into the result of the run like the PHP findings.

## Incoming files

```php
$report = Quittio::validateFile($content);   // content (bytes) of an XML or PDF e-invoice, never a path
$report = Quittio::validatePath($path);      // the same from a local file
```

Both read the file ([reading](reading.md)) and run every configured driver that supports it, independent of the stage and of `validation.mode`; they never throw a `ValidationException`, but the reader exceptions (`UnsafeXmlException`, `PdfException`, `UnreadableInvoiceException`) are thrown. The result starts with the reader's findings (level L1, `QUITTIO-DECLARED-TOTALS` L3). The rules use the profile detected from BT-24 (otherwise the configured default profile), the country packs of the parties and the syntax of the file; XSD and KoSIT check the XML (for a PDF the embedded XML), veraPDF runs only when the input was a PDF. The read data is checked as declared, without recalculation.

```bash
php artisan quittio:validate invoice.pdf --profile=en16931 --kosit --verapdf --json
```

`--profile` fails the command when the detected profile differs; `--kosit` and `--verapdf` add the drivers for this call with the configured `validation.drivers.*` settings (see [Artisan commands](commands.md)).

## Mandatory field guard

After rendering a PDF or HTML invoice, the guard checks that the template printed every mandatory value (invoice number and date, names and addresses, quantities and units, VAT rows, amount due, legal notes and the values the country pack requires). It does not run for [documents](documents.md).

| Mode | Behaviour |
|---|---|
| `exception` | throws `Quittio\Core\View\MandatoryFieldMissing` (`$e->fields`: list of `MandatoryField` with `accessKey`, `description`, `legalBasis`) |
| `log` | logs a warning (PSR-3 logger) and continues |
| `off` | no check |

Set it with `validation.mandatory_field_guard` (Laravel; default `exception` outside production, `log` in production) or `mandatory_fields` (`Quittio::create()`, default `exception`). In every mode except `off`, a missing value calls the listener of `$quittio->onMandatoryFieldsMissing(fn (InvoiceData $invoice, array $fields) => …)`; Laravel dispatches the event `Quittio\Laravel\Events\MandatoryFieldMissing($invoice, $fields)`. Which values count as printed is described in [Templates](templates.md#mandatory-field-guard).

## Germany: BMF error classes

```php
use Quittio\Country\DE\CountryDE;
use Quittio\Country\DE\Rules\BmfErrorClass;

$classes = (new CountryDE)->errorClassification($result);   // ['format' => [...], 'business_rule' => [...], 'content' => [...]]
BmfErrorClass::of($message)->label('de');                    // "Formatfehler (Rz. 6a)"
```

Errors and warnings (no infos) are grouped by the classes of the BMF letter of 15 October 2025:

| Class | Value | Findings | `voidsEInvoice()` |
|---|---|---|---|
| Format error (marginal no. 6a) | `format` | L1, L2, L4, L6 | `true` |
| Business rule error (marginal no. 6b) | `business_rule` | L3, L5 | `false` |
| Content error (marginal no. 35a) | `content` | every `DE-USTG-…` rule, L7 | `false` |

`label(string $locale = 'de')` returns the German name, any other locale the English name. Classes without findings are not in the array.

## JSON input

Check JSON against the shipped schema (`packages/core/resources/schema/invoice.schema.json`, `JsonSchemaGenerator::defaultPath()`) before hydrating it. This needs the optional package `opis/json-schema`:

```php
use Quittio\Core\Serialization\JsonSchemaValidator;
use Quittio\Core\Data\InvoiceData;

if (JsonSchemaValidator::available()) {
    $errors = (new JsonSchemaValidator)->validate($json);   // list<string> "path: message", at most 20; [] = valid
}
$invoice = InvoiceData::fromJson($json);
```

Without the package `validate()` throws a `RuntimeException`; invalid JSON throws a `JsonException`. The schema checks the structure only; the business rules run when the invoice is rendered or checked.

## Own rules and drivers

A driver implements `Quittio\Core\Validation\ValidationDriver` (`name()`, `levels()`, `supports(ValidationSubject $subject)`, `validate(ValidationSubject $subject): ValidationResult`). Its levels decide the stage: L6 runs on the PDF, L4 or L5 on the XML, everything else on the data. The subject holds `invoice` (calculated `InvoiceData`), `context` (`ValidationContext`: `profile`, `countryPacks`, `locale`, `options`, `option()`, `intOption()`, `leadingCountry()`, `hasCountry()`, `profileIs()`, `translator()`), `xml`, `syntax` and `pdf`.

Rules are easiest written as `CallbackRule` (`CallbackRule::l3()`, `CallbackRule::l7()` or `new CallbackRule($code, $level, $fallback, $check, $namespace)`); the closure receives the invoice and a `RuleReporter` with `error()`, `warning()` and `info()` (`$bt`, `$path`, `$replace`) and `context()`:

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

Register it in Laravel and add its name to the levels (the factory receives the container and `validation.drivers.acme`):

```php
Quittio::extend('validator', 'acme', fn ($app, array $config) => new AcmeRules);
// config/quittio.php: 'levels' => ['php', 'xsd', 'acme']
```

Without Laravel: `$quittio->withValidator(new AcmeRules)`. The message of `ACME-PO` is looked up as `acme::rules.ACME-PO.message` (Laravel: `loadTranslationsFrom(…, 'acme')`; core: `Translations::translator()->addPath($dir, 'acme')`), otherwise the fallback text is used.

Other ways to add rules: implement `Quittio\Core\Validation\Rule` (`code()`, `level()`, `check(InvoiceData, ValidationContext): iterable<ValidationMessage>`, build messages with `Messages::make()`), give them to `new PhpRulesDriver(additionalRules: [...])`, or return them from a country pack (`rules()`, `buyerCountryRules()`) or a profile (`rules()`). Country packs may only add or tighten rules. `Quittio\Core\Validation\InvoiceValidator` runs a list of drivers on a `ValidationSubject` outside a render run.
