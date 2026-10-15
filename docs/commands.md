# Artisan commands

This page lists every Artisan command of Quittio and every publish tag for `vendor:publish`. You need it when you install Quittio, check the invoice design, validate e-invoice files (locally or in CI) and schedule recurring tasks. The commands are grouped by purpose: [Setup](#setup), [Preview and testing](#preview-and-testing), [Maintenance](#maintenance) and [Package development](#package-development). Terms such as XRechnung, Peppol, profile or KoSIT are explained in [E-invoicing basics](concepts.md).

Three packages register commands:

- `quittio/laravel` registers the general commands (console only).
- `quittio/managed` adds the commands for stored invoices and extends `quittio:install` and `quittio:render`.
- `quittio/country-de` adds `quittio:base-rate:update`.

Every command returns exit code `0` on success and `1` on failure. The failure cases are listed per command, so CI jobs and the scheduler can react to them.

## Overview

| Group | Command | Package | Purpose |
|---|---|---|---|
| Setup | [`quittio:install`](#quittioinstall) | laravel | Publish the configuration, optionally templates and fonts; `--managed` installs the Managed layer |
| Setup | [`quittio:countries`](#quittiocountries) | laravel | Show the installed country packs |
| Setup | [`make:quittio-template`](#makequittio-template) | laravel | Create a template from the default template |
| Setup | [`quittio:schemas:download`](#quittioschemasdownload) | laravel | Download the KoSIT validator and the XRechnung configuration, compile the Peppol rules |
| Preview and testing | [`quittio:preview`](#quittiopreview) | laravel | Render a sample invoice with the configured design |
| Preview and testing | [`quittio:render`](#quittiorender) | laravel, managed | Render an invoice from a JSON file, or render a stored invoice again |
| Preview and testing | [`quittio:validate`](#quittiovalidate) | laravel | Validate an XML or PDF e-invoice and print a report |
| Preview and testing | [`quittio:read`](#quittioread) | laravel | Read an e-invoice and show its content |
| Preview and testing | [`quittio:benchmark`](#quittiobenchmark) | laravel | Measure rendering performance |
| Maintenance | [`quittio:verify`](#quittioverify) | managed | Check the hashes of the archived documents |
| Maintenance | [`quittio:drafts:prune`](#quittiodraftsprune) | managed | Delete left-behind cancellation and correction drafts |
| Maintenance | [`quittio:retention:check`](#quittioretentioncheck) | managed | List (and delete) invoices past their retention period |
| Maintenance | [`quittio:sequence:show`](#quittiosequenceshow) | managed | Show the number sequences |
| Maintenance | [`quittio:sequence:set`](#quittiosequenceset) | managed | Increase a number sequence |
| Maintenance | [`quittio:base-rate:update`](#quittiobase-rateupdate) | country-de | Fetch the German base rate (Basiszinssatz, section 247 of the German Civil Code BGB) from the Deutsche Bundesbank |
| Package development | [`quittio:codelists:update`](#quittiocodelistsupdate) | laravel | Regenerate the code lists |

`php artisan about` shows a section "Quittio" with the active country packs and profiles.

Commands that ask for confirmation (`quittio:sequence:set`, `quittio:retention:check --delete`, `quittio:drafts:prune`) treat a run without interaction as "no" and exit with `1`. Runs without interaction are `--no-interaction`, the scheduler and CI; pass `--force` there.

## Setup

Run these commands when you install Quittio or change its setup.

### quittio:install

```bash
php artisan quittio:install {--views} {--fonts} {--managed} {--force}
```

| Option | Effect |
|---|---|
| `--views` | Also publish the Blade templates (`quittio-views`) |
| `--fonts` | Also publish the fonts (`quittio-fonts`, to `resources/fonts/quittio/geist` and `resources/fonts/quittio/inter`). The renderer searches `resources/fonts/quittio` and its subdirectories automatically. |
| `--managed` | Also install the Managed layer (needs `quittio/managed`) |
| `--force` | Overwrite existing files |

The command publishes `config/quittio.php` (tag `quittio-config`) and the selected tags. Then it prints the next steps: set the seller, install a country pack, run the preview. It exits with `1` when `--managed` is given without `quittio/managed`.

With `--managed`, the Managed installer then:

1. publishes `config/quittio-managed.php` (`quittio-managed-config`; overwritten with `--force`),
2. publishes the migrations (`quittio-managed-migrations`) unless they were published before. It detects them by file name, because `vendor:publish` gives migrations new timestamps.
3. asks "Run the migrations now?" (default yes) and runs `migrate --force`. When you decline, it prints the next steps and ends with exit code `0`.
4. asks for the first issuer profile, if none exists: key (`default`), company name (required), street and number, postcode, city, country (`DE`), VAT identifier, tax number (only without VAT identifier), e-mail address for invoices (contact and electronic address), IBAN, BIC (only with IBAN) and time zone. The time zone defaults to `app.timezone`, or to `Europe/Berlin` for Germany when that is UTC. The profile becomes the default issuer.

Without interaction (`-n`), it runs the migrations without asking and skips the issuer profile. The immutability triggers are not published; publish them separately (see [Managed layer](managed.md#immutability)). It exits with `1` when the migrations or the database fail.

### quittio:countries

```bash
php artisan quittio:countries
```

Lists the active country packs: code, class, profiles, number of rules and format rules, and the defaults (currency, locale, profile). Without a country pack, it warns that only EN 16931 rules are checked (exit code `0`). See [Country packs](country-packs.md).

### make:quittio-template

```bash
php artisan make:quittio-template {name} {--force}
```

| Argument / option | Effect |
|---|---|
| `name` | Template name: lower-case letters, digits, `-` and `_`, starting with a letter or digit |
| `--force` | Overwrite an existing template |

Copies the default template (`layout.blade.php` and `partials/`) to `resources/views/invoices/{name}` and points the layout to its own partials. Use the new template per call with `->template('invoices.{name}.layout')` (allowed by the default `pdf.templates`), or for every invoice with `pdf.template`. An invalid name, or an existing template without `--force`, exits with `1`. See [Templates and design](templates.md).

### quittio:schemas:download

```bash
php artisan quittio:schemas:download {--path=} {--force} {--without-peppol} {--java=java}
```

Installs the official validators for local checks and CI: the KoSIT validator (the validator for XRechnung and EN 16931, published by KoSIT, the agency behind XRechnung) and the rules of Peppol BIS Billing. Quittio uses them for the validation level L5 ([KoSIT validator](validation.md#kosit-validator-l5)).

| Option | Default | Effect |
|---|---|---|
| `--path` | `storage/quittio/validators` | Target directory |
| `--force` | – | Download and compile again, also when the files exist |
| `--without-peppol` | – | Skip compiling the Peppol BIS Billing 3.0 rules |
| `--java` | `java` | Java binary for compiling the Peppol rules |

It downloads into the target directory:

| File | Content |
|---|---|
| `validator.jar` | KoSIT validator 1.6.3 (standalone) |
| `xrechnung/` | XRechnung validator configuration (release `v2026-08-31`, XRechnung 3.0.2), with `scenarios.xml` |
| `peppol/PEPPOL-EN16931-UBL.xsl` | Peppol BIS Billing 3.0 rules (Schematron, a rule language for XML, `v3.0.20`), compiled with SchXslt 1.10.1 and the Saxon of the validator JAR |

Existing files are kept unless `--force` is given. The command needs the PHP `zip` extension. Compiling the Peppol rules and running the validator need Java 11 or newer.

Afterwards it prints:

- the configuration for `validation.drivers.kosit` in mode `cli` (`jar`, `scenarios`, `repository` and, with Peppol, `configurations`),
- a reminder to add `kosit` to `validation.levels`,
- the command that starts the validator as a daemon for the default mode `daemon`, on the port of `validation.drivers.kosit.url`. The default URL is `http://127.0.0.1:8081`, so the command contains `-P 8081`; the validator's own default port is 8080.

```bash
java -jar '…/validators/validator.jar' -s '…/validators/xrechnung/scenarios.xml' -r '…/validators/xrechnung' \
    -s '…/peppol-scenarios.xml' -r '…/validators' -D -P 8081
```

Download and compile errors exit with `1`. See [Validation](validation.md#kosit-validator-l5) and [Official validators in CI](testing.md#official-validators-in-ci).

## Preview and testing

Use these commands to check the design, to render invoices from files and to check e-invoice files, e.g. in CI or when a customer sends you an invoice.

### quittio:preview

```bash
php artisan quittio:preview {--scenario=simple} {--template=} {--format=pdf} {--locale=} {--output=}
```

Renders a sample invoice with your design (logo, colours, texts, template) into a file. The sample invoices use sample data, not your seller.

| Option | Default | Effect |
|---|---|---|
| `--scenario` | `simple` | Sample scenario (see [Scenarios](testing.md#scenarios)) |
| `--template` | configured | A view that rendering accepts: `default`, the configured `pdf.template`, a view under `quittio::templates.` or one of `pdf.templates`, e.g. `invoices.acme.layout` from `make:quittio-template`. No partials; the view must exist. |
| `--format` | `pdf` | `pdf` or `html` |
| `--locale` | scenario locale (`de`) | Locale of the labels |
| `--output` | `storage/app/quittio/preview/{scenario}.{format}` | Target file; missing directories are created |

The command renders the scenario with the configured design, validation mode `warn` and the watermark "PREVIEW" ("VORSCHAU" in German), like the [preview route](laravel.md#preview-route). Unknown scenarios, formats, templates or invalid locales exit with `1`.

### quittio:render

```bash
php artisan quittio:render {invoice?} {--json=} {--format=} {--profile=} {--output=}
```

| Argument / option | Effect |
|---|---|
| `invoice` | Number, UUID or id of a stored invoice of the Managed layer (see below). Takes precedence over `--json`. |
| `--json` | JSON file with invoice data in the format of `InvoiceData::toJson()` (see [Getting started](getting-started.md#json)) |
| `--format` | Output format; default: `defaults.format`, else the automatic choice |
| `--profile` | Profile |
| `--output` | Existing directory (the file gets `filename()`) or target file; default: the current directory |

**From a JSON file.** With `--json`, the command renders like `Quittio::make($data)->format(…)->profile(…)->render()`, with the configured validation mode. It writes the main output (`contents()`): the PDF of `zugferd` and `pdf`, the XML of the XML formats. It prints the number, format, profile and path, and the findings when there are warnings. Validation errors are printed as a table and exit with `1`. A missing `--json`, an unreadable file and any other error exit with `1` as well.

**A stored invoice (Managed layer).** With `invoice` (and `quittio/managed` installed), the command renders an issued invoice whose documents are missing or failed:

- It looks up a UUID first, then the number (a number used by several issuers must be given as UUID), then the numeric id.
- When the current document version is complete, it reports that and exits with `0`.
- Missing files are rendered as a new document version; existing files are never overwritten. The command lists the missing files and the new version.
- Damaged files (hash mismatch) dispatch `ArchiveIntegrityViolation` and exit with `1`. Drafts and unknown invoices exit with `1` as well.

Without `quittio/managed`, `invoice` exits with `1`. See [Rendering and archive](managed.md#rendering-and-archive).

### quittio:validate

```bash
php artisan quittio:validate {file} {--profile=} {--kosit} {--verapdf} {--json}
```

Checks an e-invoice file against the rules, e.g. one you received or one your application produced. See [Validation](validation.md) for the levels L1 to L7.

| Argument / option | Effect |
|---|---|
| `file` | XML or PDF file |
| `--profile` | Expected profile (aliases allowed); a different profile fails |
| `--kosit` | Also run the KoSIT validator (L5) with all settings of `quittio.validation.drivers.kosit` (`mode`, `url`, `jar`, `scenarios`, `repository`, `configurations`, `java`, `timeout`, `fail_on_unavailable`) |
| `--verapdf` | Also run veraPDF, the PDF/A checker (L6), with all settings of `quittio.validation.drivers.verapdf` (`mode`, `binary`, `image`, `docker`, `timeout`, `fail_on_unavailable`) |
| `--json` | Print the result as JSON instead of the report |

The command reads the file and validates it with the configured levels plus `kosit` and `verapdf` (`Quittio::coreWithLevels()`):

- The drivers are built exactly as in a render run.
- A level already in `validation.levels` is not added twice.
- The cached core of the application is not changed.
- With `fail_on_unavailable` set to `false`, a validator that cannot be reached or started is reported as a warning (`KOSIT-UNAVAILABLE`, `VERAPDF-UNAVAILABLE`) and does not fail the command.

The report shows invoice number, syntax, profile, the checked levels and a table of findings (severity, level, code, business term, message and hint). `--json` prints `{"valid": …, "levels": […], "messages": [{severity, code, message, level, bt, path, hint, xpath}], "profile": …}`.

Exit code `0` when the file has no errors and, with `--profile`, has the expected profile; warnings do not fail. A missing or unreadable file, a file that cannot be read as e-invoice, errors and a profile mismatch exit with `1`.

### quittio:read

```bash
php artisan quittio:read {file} {--json}
```

| Argument / option | Effect |
|---|---|
| `file` | XML or PDF file |
| `--json` | Print the invoice data as JSON (`InvoiceData::toJson()`) |

Shows the content of an e-invoice file:

- number, issue date, type code, syntax and profile,
- seller and buyer (with country),
- the embedded file name of a PDF,
- a table of the lines (position, item, quantity and unit, net amount, VAT category and rate),
- net total, VAT and amount due (the totals declared in the file),
- the findings of the reader.

A missing or unreadable file exits with `1`. See [Reading incoming invoices](reading.md).

### quittio:benchmark

```bash
php artisan quittio:benchmark {--count=20} {--scenario=simple} {--format=*}
```

| Option | Default | Effect |
|---|---|---|
| `--count` | `20` | Measured runs per format (at least 1) |
| `--scenario` | `simple` | Sample scenario |
| `--format` | `cii`, `zugferd` | Formats; repeat the option for several (`--format=cii --format=zugferd`) |

Renders the scenario once per format to warm up fonts and caches. Then it renders `--count` times with validation mode `warn` and prints average, minimum, 95th percentile and maximum in milliseconds, plus the peak memory. An unknown scenario exits with `1`.

## Maintenance

Recurring tasks. Most of them belong to the Managed layer, which stores invoices in the database ([Managed layer](managed.md)). Schedule them as shown in [Scheduling](#scheduling).

### quittio:verify

Managed layer.

```bash
php artisan quittio:verify {--from=} {--to=} {--issuer=}
```

| Option | Effect |
|---|---|
| `--from` | Issue date from (`Y-m-d`, inclusive) |
| `--to` | Issue date to (`Y-m-d`, inclusive) |
| `--issuer` | Only invoices of this issuer key |

Checks every issued invoice of all issuers; the tenancy scope does not apply. The archived XML and PDF of the current document version must exist and match their SHA-256 hashes. Issued invoices whose documents were never archived (render status `pending`, `rendering` or `failed`) count as problems too.

Every problem dispatches `ArchiveIntegrityViolation` (invoice, path, problem). The run writes the audit entry `verified` with the number of checked invoices and violations. Problems are printed as a table and exit with `1`. See [Rendering and archive](managed.md#rendering-and-archive).

### quittio:drafts:prune

Managed layer.

```bash
php artisan quittio:drafts:prune {--older-than=60} {--force}
```

| Option | Default | Effect |
|---|---|---|
| `--older-than` | `60` | Minimum age of cancellation drafts in minutes |
| `--force` | – | Do not ask for confirmation |

Deletes drafts that can never be issued:

- cancellation drafts older than `--older-than` minutes. They were left behind (e.g. by a crash) while `cancel()` or `cancelPartially()` issued them.
- correction drafts whose original is no longer in status `issued` or no longer exists.

The command lists them (draft, kind, original, created), asks, and deletes each through the model (audit entry `deleted_draft`). A draft issued meanwhile is not touched. A non-numeric `--older-than` or a declined confirmation exits with `1`. See [Cancellations and corrections](managed.md#cancellations-and-corrections).

### quittio:retention:check

Managed layer.

```bash
php artisan quittio:retention:check {--delete} {--force}
```

| Option | Effect |
|---|---|
| `--delete` | Delete the expired invoices and their archived documents (asks for confirmation) |
| `--force` | Do not ask for confirmation |

Invoices must be kept for a legal retention period ([Retention and deletion](managed.md#retention-and-deletion)). The command lists the issued invoices past their retention period that are not on legal hold. An invoice counts as expired two days after `retention_until`. It dispatches `RetentionExpired` for each of them. It also reports archived files of deleted invoices that could not be deleted yet. Without `--delete` nothing changes.

With `--delete`, it first retries the leftover files. Then it deletes by group:

- A group is an invoice together with all invoices connected to it through cancellations, partial cancellations, corrections and replacements.
- A group is deleted only when every member is expired and none is on legal hold. Otherwise the command names the invoice that keeps the group.
- The rows of a group (relations, deliveries, referring drafts, invoices) are deleted in one transaction with audit entries, then all archived files of all document versions. Files that cannot be deleted are tried again by the next run.

Failures of single groups are reported and the run continues (exit code `0`). A declined confirmation exits with `1`. See [Rendering and archive](managed.md#rendering-and-archive).

### quittio:sequence:show

Managed layer.

```bash
php artisan quittio:sequence:show {--issuer=}
```

| Option | Effect |
|---|---|
| `--issuer` | Only the sequences of this issuer key |

Lists every number sequence with its next value and pattern, ordered by key. The key has the form `{issuer}:{sequence}:{period}`, e.g. `default:invoice:2026`. Sequences are created when the first invoice of a period is issued. See [Numbering](managed.md#numbering).

### quittio:sequence:set

Managed layer.

```bash
php artisan quittio:sequence:set {key} {value} {--force}
```

| Argument / option | Effect |
|---|---|
| `key` | Sequence key `{issuer}:{sequence}:{period}`, e.g. `default:invoice:2026` |
| `value` | New next value, a positive integer greater than the current one |
| `--force` | Do not ask for confirmation |

Raises the next value of a sequence, e.g. when you migrate from another system. Lowering it is refused.

A sequence that does not exist yet is created ahead of time, but only when the numbering would use it:

- the issuer must exist,
- the sequence must be `invoice`, or a kind with its own sequence for that issuer (`cancellation`, `correction`),
- the period must match the reset mode (`2026` yearly, `2026-10` monthly, `all` never).

The pattern comes from the configuration. The change runs in a transaction with a row lock and is written to the audit log. Invalid values or keys, a lower value and a declined confirmation exit with `1`. See [Numbering](managed.md#numbering).

### quittio:base-rate:update

German country pack.

```bash
php artisan quittio:base-rate:update [--dry-run]
```

| Option | Effect |
|---|---|
| `--dry-run` | Show the rates, write nothing |

German default interest on late payments is the base rate (Basiszinssatz) under section 247 of the German Civil Code (BGB) plus a surcharge. The base rate changes on 1 January and 1 July. Quittio ships the rates up to its release; this command fetches the later ones, so that default interest (`DefaultInterest`) covers periods after the shipped table without a new release.

The command fetches the base rate from the statistics API of the Deutsche Bundesbank (series `BBIN1.M.DE.BBK.BBKBAS2.EUR.ME`). It stores the rates that the shipped table does not contain, and values that differ from it, in `quittio-de.base_rate_file` (default `storage/app/quittio/base-rates.json`). The German country pack registers it (`GermanyServiceProvider`).

It prints the latest rate and the rates after the shipped table. It warns when a value differs from the shipped table; the Bundesbank value is then used. It writes the file only when something changed.

Exit code `1` when:

- the API cannot be reached or returns no rates,
- `quittio-de.base_rate_file` is empty (not with `--dry-run`),
- the directory of the file cannot be created.

See [Country packs: base rate table](country-packs.md#base-rate-table) and [Country pack Germany](configuration.md#country-pack-germany-configquittio-dephp).

## Package development

### quittio:codelists:update

```bash
php artisan quittio:codelists:update {xsl?} {--target=}
```

| Argument / option | Default | Effect |
|---|---|---|
| `xsl` | `storage/quittio/validators/xrechnung/resources/cii/16b/xsl/EN16931-CII-validation.xsl` | EN 16931 CII Schematron XSL of the validator configuration |
| `--target` | the code lists of `quittio/core` (`resources/codelists`) | Output directory |

For the development of Quittio itself: it regenerates the JSON code lists (units, currencies, countries, VAT categories, VATEX, EAS and ICD schemes, payment means, …) from the official rules and prints the number of codes per list. In an application, pass `--target`; the default writes into the installed package. A missing XSL exits with `1` (run `quittio:schemas:download` first).

## Scheduling

Schedule the maintenance commands in `routes/console.php`:

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('quittio:verify')->weekly()->onOneServer();                  // exit code 1 on problems
Schedule::command('quittio:drafts:prune --force')->daily()->onOneServer();
Schedule::command('quittio:retention:check')->monthly()->onOneServer();        // report and RetentionExpired events
Schedule::command('quittio:base-rate:update')->weekly()->onOneServer();        // new base rate on 1 January and 1 July
```

| Command | Recommendation |
|---|---|
| `quittio:verify` | Daily or weekly. React to failures with `->onFailure(…)` or `->emailOutputOnFailure(…)` and listen for `ArchiveIntegrityViolation`. On large archives limit the range, e.g. `['--from' => now()->subDays(8)->toDateString()]`, and run a full check less often. |
| `quittio:drafts:prune` | Daily with `--force` |
| `quittio:retention:check` | Monthly without `--delete` for a report. Add `--delete --force` only when expired invoices should be deleted without review. |
| `quittio:schemas:download` | Not scheduled: run it in deployment or CI when you use the KoSIT validator |
| `quittio:base-rate:update` | Weekly (or daily in January and July): the new rate is stored as soon as the Bundesbank has published it |

## Publish tags

Publish tags copy files of the packages into your application, so that you can change them:

```bash
php artisan vendor:publish --tag=quittio-config
```

| Tag | Package | Publishes to |
|---|---|---|
| `quittio-config` | laravel | `config/quittio.php` ([Configuration](configuration.md)) |
| `quittio-views` | laravel | `resources/views/vendor/quittio/templates` (default template and partials; they override the package views) |
| `quittio-lang` | laravel | `lang/vendor/quittio` (labels, rule messages, codes, terms) |
| `quittio-fonts` | laravel | `resources/fonts/quittio/geist` (Geist) and `resources/fonts/quittio/inter` (Inter). `resources/fonts/quittio` and its subdirectories are added to the font directories automatically, so further font files placed there only need an entry in `pdf.fonts`. |
| `quittio-managed-config` | managed | `config/quittio-managed.php` |
| `quittio-managed-migrations` | managed | `database/migrations` (tables of the Managed layer, with new timestamps) |
| `quittio-managed-triggers` | managed | `database/migrations` (immutability triggers, recommended for production) |
| `quittio-managed-views` | managed | `resources/views/vendor/quittio-managed` (mail view) |
| `quittio-managed-lang` | managed | `lang/vendor/quittio-managed` (mail and invoice texts) |
| `quittio-de-config` | country-de | `config/quittio-de.php` (base rates) |

Publishing is only registered in the console.
