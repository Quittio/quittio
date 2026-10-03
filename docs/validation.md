# Validation

| Level | Check | Driver |
|---|---|---|
| L1 | Structure and data types | `php` |
| L2 | Mandatory and allowed fields of the profile | `php` |
| L3 | Business rules: EN 16931 (BR-…), country packs (BR-DE-…, DE-USTG-…), Peppol | `php` |
| L4 | XML against XSD (CII D16B, UBL 2.1) | `xsd` |
| L5 | Official Schematron via the KoSIT validator | `kosit` |
| L6 | PDF/A-3 via veraPDF | `verapdf` |
| L7 | Plausibility: IBAN, VAT identifier formats, Leitweg-ID, dates | `php` |

Configure the levels in `quittio.validation.levels` (`php`, `xsd`, `kosit`, `verapdf`, or your own drivers registered with `Quittio::extend('validator', …)`). `validation.mode` is `error` (throw `ValidationException`), `warn` (attach the result) or `off`; `block_on_warnings` also blocks on warnings.

Every message has a code, severity, level, business term, path, text and hint, in German and English:

```php
$result = Quittio::make($invoice)->format('xrechnung-cii')->check();
$result->isValid();
$result->errors();
$result->toArray();
$result->toHtml();
```

## KoSIT validator (L5)

```bash
php artisan quittio:schemas:download         # validator JAR and XRechnung configuration
```

The command also compiles the Peppol BIS Billing 3.0 Schematron with the Saxon of the validator (Java required, skip with `--without-peppol`) and prints the configuration including `configurations` for the Peppol scenarios.

Modes: `daemon` (HTTP, recommended; `java -jar validator.jar -s scenarios.xml -r repository -D` or the Docker image in `tools/docker/kosit`) and `cli` (one JVM per file).

## veraPDF (L6)

`validation.drivers.verapdf.mode` is `cli` (local `verapdf` binary) or `docker` (`verapdf/cli`, the PDF is passed via stdin).

## Incoming files

```php
$report = Quittio::validateFile($pathOrBinary);   // XML or PDF, all configured levels
```

```bash
php artisan quittio:validate invoice.pdf --profile=en16931 --kosit --verapdf
```

## Germany: BMF error classes

`CountryDE::errorClassification($result)` groups findings by the BMF letter of 15 October 2025: format errors (L1, L2, L4, L6), business rule errors (L3, L5) and content errors (DE-USTG rules, L7).
