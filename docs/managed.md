# Managed layer (`quittio/managed`)

The Managed layer stores invoices in the database and takes care of their lifecycle:
issuer profiles, drafts, gapless numbering, issuing, rendering and archiving, cancellations,
partial cancellations, corrections, delivery by mail, the audit log and retention. It uses
only the public API of `quittio/laravel` and `quittio/core`: everything it produces could
also be produced by hand with the core.

`quittio/managed` is commercially licensed and distributed separately from the open source packages.

Not part of version 1.0: recording payments (the payment columns exist, see
[Payment status](#payment-status)), bank reconciliation, dunning runs, subscriptions and
recurring invoices, advance and final invoices, self-billing, inbound invoices, Peppol
transmission and DATEV exports.

## Overview

| Concept | Model | Description |
|---|---|---|
| Issuer profile | `Issuer` | Your company as seller: master data, bank accounts, defaults, texts, branding, numbering |
| Draft | `Invoice` (status `draft`) | Freely changeable, no number, can be deleted |
| Issued invoice | `Invoice` (status `issued` …) | Number, data snapshot, dates and totals frozen; archived as XML and/or PDF |
| Cancellation, correction | `Invoice` (kind `cancellation`, `correction`) | Own invoices with own numbers that refer to the original |
| Relation | `InvoiceRelation` | Link from a cancellation or correction to the original, with the cancelled lines and VAT |
| Sequence | `Sequence` | Counter of a number sequence (`{issuer}:{sequence}:{period}`) |
| Delivery | `Delivery` | One delivery attempt by mail |
| Audit entry | `AuditLog` | Append-only record of every business relevant action |

```
draft ──issue()──▶ issued ──cancelPartially()──▶ partially_cancelled ──▶ … ──▶ cancelled
                     └──────── cancel() or an issued correction ─────────────▶ cancelled
```

A typical flow:

```php
use Quittio\Managed\Models\Invoice;
use Quittio\Managed\Models\Issuer;

$draft = Invoice::draft(Issuer::current()->builder()->buyer(...)->line(...));
$draft->preview();                  // PDF with watermark, no number
$invoice = $draft->issue();         // number, frozen data, rendering queued
$invoice->send();                   // archived documents by mail
$invoice->cancel(reason: 'Doppelt berechnet');
```

## Installation

```bash
composer require quittio/managed
php artisan quittio:install --managed
php artisan vendor:publish --tag=quittio-managed-triggers   # recommended for production
php artisan migrate
```

Requirements: PHP 8.3 or later, Laravel 13, `quittio/core` and `quittio/laravel` in the same version.
Numbering, retention and the correction strategy come from the country pack of the issuer's
country (e.g. `quittio/country-de`, see [Country packs](country-packs.md)); for a country
without a pack the generic rules apply (pattern `INV-{Y}-{seq:4}`, yearly reset, retention
10 years after the end of the year, cancellation by credit note, correction by cancel and
reissue).

`quittio:install --managed` runs these steps (see also [Artisan commands](commands.md)):

1. publishes `config/quittio.php` and `config/quittio-managed.php` (`--force` overwrites
   them),
2. publishes the migrations, unless they were published before (recognised by their file
   name: `vendor:publish` gives migrations a new timestamp each time),
3. asks "Run the migrations now?" and runs `migrate --force`,
4. if no issuer profile exists, asks for the first one: key (default `default`), company
   name (required), street, postcode, city, country (default `DE`), VAT id, tax number (asked
   only without a VAT id), e-mail address for invoices (stored as contact e-mail and as
   electronic address with scheme `EM`), IBAN and BIC, time zone (default `app.timezone`,
   otherwise `Europe/Berlin` for Germany and `UTC` else). The profile is marked as default.

With `--no-interaction` the migrations run without asking and no issuer is created. The
installer does not publish the immutability triggers.

### Databases

Supported: SQLite, MySQL 8 / MariaDB and PostgreSQL (the test matrix uses MySQL 8.4 and
PostgreSQL 16).

| Database | Notes |
|---|---|
| SQLite | Set `'transaction_mode' => 'IMMEDIATE'` and a `busy_timeout` (e.g. `5000`) on the connection in `config/database.php`. Without the immediate transaction mode the numbering logs a warning once per process; concurrent issuing is then retried, but not recommended for production. |
| MySQL, MariaDB | `number`, `number_space` and the archive paths use the collation `utf8mb4_bin` (case sensitive, as on the other databases). Creating the triggers with binary logging needs the `TRIGGER` privilege and `log_bin_trust_function_creators` (or `SUPER`). MySQL fires no triggers on `TRUNCATE` (see [What is not prevented](#what-is-not-prevented)). |
| PostgreSQL | The invoice data is stored as `jsonb`. The triggers also reject `TRUNCATE`. |

### Tables

| Table | Model | Content |
|---|---|---|
| `quittio_issuers` | `Issuer` | Issuer profiles |
| `quittio_sequences` | `Sequence` | Number sequences |
| `quittio_invoices` | `Invoice` | Drafts and issued invoices, cancellations and corrections |
| `quittio_invoice_relations` | `InvoiceRelation` | `cancels`, `partially_cancels`, `corrects`, `replaces` |
| `quittio_deliveries` | `Delivery` | Delivery attempts |
| `quittio_audit_logs` | `AuditLog` | Audit log |
| `quittio_archive_files` | – | Manifest of every archived file (no model) |

The migrations use `quittio-managed.connection` and `quittio-managed.table_prefix`: set both
before migrating.

### Publish tags

`php artisan vendor:publish --tag=…`:

| Tag | Publishes |
|---|---|
| `quittio-managed-config` | `config/quittio-managed.php` |
| `quittio-managed-migrations` | the migrations |
| `quittio-managed-triggers` | the immutability triggers (recommended, see [Database triggers](#database-triggers)) |
| `quittio-managed-views` | the mail view (`resources/views/vendor/quittio-managed`) |
| `quittio-managed-lang` | the translations (`lang/vendor/quittio-managed`) |

### Morph map

Register a morph map for your own models (`Relation::enforceMorphMap()`), because the
invoices, deliveries and the audit log keep references to them (`billable`, `invoiceable`,
`causer`, `tenant`, see [Customers and references](#customers-and-references)) for many
years. The package registers `quittio.issuer`, `quittio.invoice`, `quittio.sequence` and
`quittio.delivery` itself (for the configured model classes); `enforceMorphMap()` merges your
entries with these.

### Queue worker

Issued invoices are rendered by the job `RenderInvoiceDocuments` on the queue `quittio` (see
[Rendering and archive](#rendering-and-archive)). Run a worker that listens to it, e.g.
`php artisan queue:work --queue=quittio,default`, or set `queue.render_sync` to render in the
request.

## Configuration

`config/quittio-managed.php` (publish it with `--tag=quittio-managed-config`). Values are read
when they are used, except the classes of the resolvers and the number generator (resolved
once per process),
the morph map, the policies and the tenancy scope (registered when the application boots).
A value of the wrong type throws a `ConfigurationException` ("… must be a string",
"… must be a boolean", "… must be an integer"); an empty string counts as `null` for string
values.

### Database settings

| Key | Type | Default | Env | Effect |
|---|---|---|---|---|
| `connection` | string\|null | `null` | `QUITTIO_DB_CONNECTION` | Database connection of all Managed tables, models and migrations; `null` = default connection |
| `table_prefix` | string | `'quittio_'` | – | Prefix of every table (`quittio_invoices` …) |
| `models.issuer` | class-string\|null | `null` | – | Own subclass of `Issuer` (see [Own models](#own-models)) |
| `models.invoice` | class-string\|null | `null` | – | Own subclass of `Invoice` |
| `models.sequence` | class-string\|null | `null` | – | Own subclass of `Sequence` |
| `models.invoice_relation` | class-string\|null | `null` | – | Own subclass of `InvoiceRelation` |
| `models.delivery` | class-string\|null | `null` | – | Own subclass of `Delivery` |
| `models.audit_log` | class-string\|null | `null` | – | Own subclass of `AuditLog` |

### Issuer and tenancy settings

| Key | Type | Default | Effect |
|---|---|---|---|
| `issuer_resolver` | class-string\|Closure\|null | `null` | Determines the current issuer: a class implementing `IssuerResolver` or a Closure returning an `Issuer`; `null` = the issuer marked as default (see [Current issuer](#current-issuer)) |
| `tenancy.scope` | bool | `false` | Restricts all invoice queries to the current issuer (see [Tenancy scope](#tenancy-scope)) |

### Archive settings

| Key | Type | Default | Env | Effect |
|---|---|---|---|---|
| `disk` | string | `'local'` | `QUITTIO_DISK` | Filesystem disk of the archive; for production an object store with object lock (WORM) and versioning |
| `path` | string | `'{issuer}/{Y}/{m}/{number}/v{version}'` | – | Directory of a document version; placeholders `{issuer}` `{Y}` `{m}` `{number}` `{uuid}` `{version}` (see [Archive layout](#archive-layout)) |

### Queue settings

| Key | Type | Default | Env | Effect |
|---|---|---|---|---|
| `queue.connection` | string\|null | `null` | `QUITTIO_QUEUE_CONNECTION` | Queue connection of the render job; `null` = default connection |
| `queue.queue` | string\|null | `'quittio'` | `QUITTIO_QUEUE` | Queue name of the render job; empty = default queue |
| `queue.render_sync` | bool | `false` | – | Render in the same request after the commit instead of queueing the job |
| `queue.backoff` | list of int | `[10, 60, 300, 1800]` | – | Seconds before each retry; the job tries `count + 1` times |

### Numbering settings

| Key | Type | Default | Effect |
|---|---|---|---|
| `numbering.pattern` | string\|null | `null` | Number pattern; `null` = country pack (DE: `RE-{Y}-{seq:4}`) |
| `numbering.reset` | string\|null | `null` | `never`, `yearly` or `monthly`; `null` = country pack (DE: `yearly`), limited to the resets the pack allows |
| `numbering.start` | int | `1` | First value of every new sequence, also of every new period after a reset; to continue the numbers of an old system, raise its sequence with `quittio:sequence:set` |
| `numbering.per_kind.cancellation` | string\|null | `null` | Own sequence and pattern for cancellations; `null` = shared invoice sequence |
| `numbering.per_kind.correction` | string\|null | `null` | Own sequence and pattern for corrections; `null` = shared invoice sequence |
| `numbering.generator` | class-string\|null | `null` | Class implementing `NumberGenerator`; `null` = `SequenceNumberGenerator` |

Issuer profiles can override `pattern`, `reset`, `start` and `per_kind` (see
[Numbering](#numbering)).

### Correction settings

| Key | Type | Default | Env | Effect |
|---|---|---|---|---|
| `correction.strategy` | string\|null | `null` | `QUITTIO_CORRECTION_STRATEGY` | `cancel_and_reissue` or `corrected_invoice`; `null` = country pack (DE: `cancel_and_reissue`) |

The sign strategy of cancellations is configured in `config/quittio.php`, see
[Sign strategies and credit notes](#sign-strategies-and-credit-notes).

### Delivery settings

| Key | Type | Default | Env | Effect |
|---|---|---|---|---|
| `delivery.mail.mailable` | class-string\|null | `null` | – | Own Mailable (receives `invoice` and `files` in the constructor); `null` = `InvoiceMail` |
| `delivery.mail.bcc_archive` | string\|null | `null` | `QUITTIO_BCC_ARCHIVE` | Address that receives a blind copy of every invoice mail |
| `delivery.mail.xrechnung_visualization` | bool | `false` | – | Attach a PDF visualisation to mails of XML-only formats |
| `delivery.recipient_resolver` | class-string\|null | `null` | – | Class implementing `RecipientResolver`; `null` = `DefaultRecipientResolver` |

### Audit, retention and policy settings

| Key | Type | Default | Effect |
|---|---|---|---|
| `audit.enabled` | bool | `true` | `false` writes no audit entries at all |
| `retention.years` | int\|null | `null` | Retention in years; `null` = country pack (DE: 8 years after the end of the year of issue); a value can only lengthen it, a shorter one throws a `ConfigurationException` when an invoice is issued |
| `policies.register` | bool | `false` | Register `InvoicePolicy` and `IssuerPolicy` with the gate (see [Authorisation](#authorisation)) |

### Settings of `config/quittio.php` the Managed layer uses

| Key | Effect on the Managed layer |
|---|---|
| `corrections.cancellation_strategy` | Sign strategy of cancellations (`credit_note`, `negative_invoice`) |
| `validation.block_on_warnings` | `issue()` also refuses invoices with warnings |
| `validation.*` | Validation levels applied when issuing and archiving (see [Validation](validation.md)) |
| `pdf.template`, `pdf.templates` | Templates an issuer may choose in `branding.template` |
| `pdf.filename` | File names in the archive (default `{title}_{number}.{ext}`) |
| `countries` | Country packs (numbering, retention, correction policy, titles) |

See [Configuration](configuration.md) for these keys.

### Own models

Replace a model with your own subclass:

```php
// config/quittio-managed.php
'models' => [
    'invoice' => App\Models\Invoice::class,
],
```

```php
namespace App\Models;

use Quittio\Managed\Enums\PaymentStatus;

class Invoice extends \Quittio\Managed\Models\Invoice
{
    public function isPaid(): bool
    {
        return $this->payment_status === PaymentStatus::Paid;
    }
}
```

The class must extend the package model, otherwise `ConfigurationException` ("… must be a
subclass of …"). The package resolves every model through `Managed::issuerModel()`,
`Managed::invoiceModel()`, `Managed::sequenceModel()`, `Managed::relationModel()`,
`Managed::deliveryModel()` and `Managed::auditLogModel()` (class `Quittio\Managed\Managed`);
use them in your code as well. The morph map, the policies and the tenancy scope use the
configured classes. Keep the query builders of the package models (`InvoiceQuery`,
`SequenceQuery`, `AppendOnlyQuery`): the invoice model throws a `LogicException` without its
`InvoiceQuery`. Further helpers: `Managed::table('invoices')` (table name with prefix),
`Managed::connection()` and `Managed::settings()` (typed access to the configuration).

### Own services

The package binds these services as singletons; replace them in a service provider (or,
where a key exists, in the configuration):

| Contract | Default | Configuration key |
|---|---|---|
| `Quittio\Managed\Issuers\IssuerResolver` | `DefaultIssuerResolver` (`CallbackIssuerResolver` for a Closure) | `issuer_resolver` |
| `Quittio\Managed\Numbering\NumberGenerator` | `SequenceNumberGenerator` | `numbering.generator` |
| `Quittio\Managed\Delivery\RecipientResolver` | `DefaultRecipientResolver` | `delivery.recipient_resolver` |
| `Quittio\Managed\Rendering\ArchiveStorage` | `FilesystemArchive` | – |
| `Quittio\Managed\Audit\Auditor` | `DatabaseAuditor` | – |

```php
$this->app->singleton(ArchiveStorage::class, MyWormArchive::class);
```

Classes from the configuration are resolved through the container and must implement the
contract (`ConfigurationException` otherwise); only `issuer_resolver` accepts a Closure.

## Issuer profiles

An issuer profile is the company that issues the invoices: every invoice belongs to exactly
one issuer, and the issuer is always its seller.

```php
use Quittio\Core\Builder\PartyBuilder;
use Quittio\Managed\Models\Issuer;

$issuer = new Issuer;
$issuer->setSellerParty((new PartyBuilder)
    ->name('Muster GmbH')
    ->vatId('DE123456789')
    ->address('Hauptstraße 1', '12345', 'Musterstadt', 'DE')
    ->electronicAddress('rechnung@muster.de', 'EM')
    ->buildSeller());
$issuer->forceFill([
    'key' => 'default',
    'timezone' => 'Europe/Berlin',
    'bank_accounts' => [['iban' => 'DE02120300000000202051', 'bic' => 'BYLADEM1001', 'name' => 'Muster GmbH']],
    'defaults' => ['locale' => 'de', 'due_days' => 14, 'currency' => 'EUR', 'format' => 'zugferd'],
    'tax_settings' => ['small_business' => false],
    'branding' => ['logo' => storage_path('app/logo.png'), 'colors' => ['primary' => '#0f766e']],
    'texts' => ['de' => ['intro' => 'Vielen Dank für Ihren Auftrag.', 'payment_terms' => 'Zahlbar innerhalb von 14 Tagen.']],
    'numbering' => ['pattern' => 'RE-{Y}-{seq:5}'],
    'is_default' => true,
])->save();
```

`setSellerParty()` stores the seller (see [Building invoices](building-invoices.md) for the
`PartyBuilder`) and sets `country` from its address; the country selects the country pack.
The logo must exist when the profile is saved (see [Branding check](#branding-check)).

### Columns

| Column | Type | Meaning |
|---|---|---|
| `key` | string, unique | Short name; part of the sequence keys and the archive paths; cannot change once number sequences use it |
| `seller` | JSON | The seller party (`SellerParty`, normalised); read with `sellerParty()`, write with `setSellerParty()` |
| `country` | char(2) | Country of the seller address; selects the country pack |
| `timezone` | string, default `UTC` | Time zone of issue dates, "today" and the retention period |
| `number_space` | string(128) | Invoice numbers of one company, set on save (see [Number spaces](#number-spaces)) |
| `tenant_type`, `tenant_id` | morph, nullable | Tenant of the issuer (relation `tenant()`) |
| `bank_accounts` | JSON list | `[{iban, bic, name}, …]`; the first account goes into `builder()` |
| `defaults` | JSON | `locale`, `currency`, `due_days`, `format`, `profile` |
| `tax_settings` | JSON | `small_business` |
| `texts` | JSON | Texts per locale: `intro`, `closing`, `payment_terms`, `footer_columns` |
| `branding` | JSON | `template`, `logo`, `colors`, `font`, `sender_line`, `letter_layout`, `payment_qr` |
| `numbering` | JSON | `pattern`, `reset`, `start`, `per_kind` (override of the configuration) |
| `registrations` | JSON | Further VAT registrations and establishments in other countries; stored, not evaluated yet |
| `network_ids` | JSON | Network identifiers (e.g. Peppol); stored, not evaluated yet |
| `is_default` | bool | Chosen by the default issuer resolver |

All columns except `id` are mass assignable (`Issuer::create([...])`, `fill()`); the JSON
columns accept arrays only.

### Defaults, texts and branding

| Setting | Meaning |
|---|---|
| `defaults.locale` | Locale of `builder()`, of the texts, the preview watermark and the mail |
| `defaults.currency` | Currency of `builder()` |
| `defaults.due_days` | Integer: payment terms in days; the due date is the issue date plus these days |
| `defaults.format` | Format of new drafts (`zugferd`, `xrechnung-cii`, `xrechnung-ubl`, `cii`, `ubl`, `peppol`, `pdf`, see [Formats](formats.md)); `null` or `auto` = automatic choice when the invoice is issued |
| `defaults.profile` | Profile of new drafts (e.g. `xrechnung`) |
| `tax_settings.small_business` | Small business (DE: section 19 UStG): new drafts get the flag `Meta::FLAG_SMALL_BUSINESS`, the German pack then rejects charged VAT (DE-USTG-09) and the intra-community supply exemption except for new vehicles (DE-USTG-14) |
| `texts.{locale}.intro`, `closing` | Intro and closing text of the PDF |
| `texts.{locale}.payment_terms` | Text of the payment terms |
| `texts.{locale}.footer_columns` | Footer columns, a list of lists of strings |
| `branding.template` | PDF template (see [Branding check](#branding-check)) |
| `branding.logo` | Logo: local file, data URI or public http(s) URL |
| `branding.colors` | `primary` (default `#2741d9`), `text` (`#15171c`), `muted` (`#5f6470`), `secondary` |
| `branding.font`, `sender_line`, `letter_layout` | Font, sender line above the address, letter layout (e.g. DIN 5008) |
| `branding.payment_qr` | `auto` (default), `girocode`, `swiss-qr-bill` or `none` |

Texts are looked up for the locale, then under the key `default`, then the first entry. See
[Templates](templates.md) for what the presentation settings do in the PDF.

### The issuer builder

`$issuer->builder()` returns an `InvoiceBuilder` with the seller, the time zone, the first bank
account (credit transfer), the payment terms (text and `due_days`), locale, currency, the
presentation (branding and texts) and the small business flag of the issuer. Start drafts
from it:

```php
$draft = Invoice::draft($issuer->builder()
    ->buyer(Party::make('Kunde AG')->address('Bahnhofstraße 5', '54321', 'Beispielstadt', 'DE'))
    ->deliveryDate('2026-09-30')
    ->line('Beratung', netPrice: '120.00', quantity: 8, unit: UnitCode::Hour));
```

`Quittio::invoice()` and the document methods (`Quittio::quote()`, `Quittio::paymentReminder()` …) start with the current issuer in the same way (the Managed layer binds `InvoiceDefaults` to `Issuer::current()->applyTo($builder)`, see [Starting invoices](laravel.md#starting-invoices)), so `Invoice::draft(Quittio::invoice()->buyer($customer)->line(…))` is the same as starting from `Issuer::current()->builder()`. `->seller(...)` with another seller removes the issuer's values again (see [Starting invoices](laravel.md#starting-invoices)); the Managed layer still checks that the seller of a draft is its issuer.

The builder copies these values into the draft. Later changes of the issuer do not change
existing drafts, except the seller, which is taken from the profile again when the draft is
issued. Further methods of `Issuer`:

| Method | Returns |
|---|---|
| `Issuer::current()` | The issuer of the configured resolver |
| `builder()` | `InvoiceBuilder` with the issuer's data (see above) |
| `applyTo(InvoiceBuilder $builder)` | Sets the issuer's data (see above) on an existing builder and returns it; `builder()` is `applyTo(InvoiceBuilder::make())` |
| `sellerParty()` / `setSellerParty(SellerParty $seller)` | The seller; the setter also sets `country` |
| `presentation(?string $locale = null)` | `Presentation` from branding and texts, `null` if the issuer defines none |
| `locale()` | `defaults.locale` or `null` |
| `isSmallBusiness()` | `tax_settings.small_business` |
| `applyTaxSettings(InvoiceData $data)` | The data with the small business flag, if the issuer is one |
| `settings(string $column)` | Typed access to a JSON column (`Settings`) |
| `invoices()` | `HasMany` of the issuer's invoices |
| `tenant()` | `MorphTo` of the tenant |

### Small business

With `tax_settings.small_business`, `builder()` sets the flag `Meta::FLAG_SMALL_BUSINESS`,
and `Invoice::draft()` sets it also on data that was not built with `builder()`. The country
pack decides what follows: the German pack rejects invoices that charge VAT (DE-USTG-09) or
use the intra-community supply exemption except for new vehicles (DE-USTG-14), so such a draft
cannot be issued. Give the lines the preset `VatPreset::SmallBusiness` of the German pack (see
[Country packs](country-packs.md)).

### Branding check

`branding.template` and `branding.logo` are checked when the issuer is saved, with the rules
the rendering applies. The template is checked by the engine of the rendering and the preview
(`BladeTemplateEngine::fromSettings()`): it must be `default`, `quittio.pdf.template`, a view
under `quittio::templates.` (no partials), a view of `quittio.pdf.templates` (`invoices.*`
allows every view below the prefix) or a plain PHP template of the core. The logo must be

- a readable local file of at most 10 MB, given as a path (not as a `file://` URI),
- a data URI (`data:image/…`) that decodes to at most 10 MB (base64 strictly), or
- an http(s) URL of a public host: IP addresses must be public; host names are resolved and
  checked when the logo is downloaded, other spellings of addresses (`2130706433`,
  `localhost.`) are refused,

without surrounding whitespace, backslashes or a leading `//`. An SVG logo must be
self-contained (no external references such as `href="http://…"` or `url(…)`, no entity
declarations), a raster logo at most 25 megapixels. Other values throw `InvalidIssuerProfile`:
the logo becomes part of the invoice data, and an issued invoice whose logo cannot be rendered
can never be archived.

### Seller identity

The seller of an invoice is always its issuer: a draft whose seller has another VAT id or
tax number than the issuer is rejected (`InvalidInvoiceState`), and when a draft is issued,
the seller (name, address, VAT id, tax number, legal information) is taken from the issuer
profile as it is then. Payment instructions and terms stay as the draft sets them. Issued
invoices keep their own snapshot, so later changes of the issuer never affect them.
Identifiers are compared without spaces (also no-break and zero-width spaces), dots, slashes
and dashes, in upper case.

### Current issuer

`Issuer::current()` asks the resolver configured in `quittio-managed.issuer_resolver`. The
default resolver returns the issuer marked as default (`is_default`, the lowest id if
several are), otherwise the only issuer if there is exactly one, otherwise it throws
`IssuerNotFound`. `Invoice::draft()`, `HasInvoices` and the tenancy scope use the current
issuer when none is given.

```php
use Quittio\Managed\Issuers\IssuerResolver;
use Quittio\Managed\Managed;
use Quittio\Managed\Models\Issuer;

final class TeamIssuerResolver implements IssuerResolver
{
    public function resolve(): Issuer
    {
        $team = auth()->user()->currentTeam;

        return Managed::issuerModel()::query()
            ->where('tenant_type', $team->getMorphClass())
            ->where('tenant_id', $team->getKey())
            ->firstOrFail();
    }
}

// config/quittio-managed.php
'issuer_resolver' => App\Billing\TeamIssuerResolver::class,
```

A Closure (`'issuer_resolver' => fn () => …`) works as well; it must return an `Issuer`
(`ConfigurationException` otherwise). Laravel cannot cache a configuration that contains a
Closure (`config:cache`), so prefer a class in production.

### Tenancy scope

With `tenancy.scope`, the invoice model gets the global scope `Invoice::TENANCY_SCOPE`
(`'quittio.issuer'`): every invoice query, including relations such as `$user->invoices()`
and route model binding, is restricted to `issuer_id` of the current issuer, and every such
query calls the resolver. Remove it where needed:

```php
Invoice::query()->withoutGlobalScope(Invoice::TENANCY_SCOPE)->count();
```

The jobs and commands of the package bypass the scope. It applies to invoices only, not to
issuers, sequences, deliveries or audit entries. It also changes the number spaces of issuers
without a tenant (see [Number spaces](#number-spaces)): decide on it before issuing invoices.

### Changing and deleting issuers

- Changes only affect invoices issued afterwards (drafts only through the seller, see above).
- Creating, changing and deleting a profile is written to the audit log with the old and new
  values (`issuer_created`, `issuer_updated`, `issuer_deleted`).
- The `key` cannot change once number sequences use it (`NumberingException`): it is part of
  the sequence keys, and a new key would start the numbering again.
- The number space and the tenant cannot change once the issuer has issued invoices
  (`NumberingException`).
- An issuer with invoices cannot be deleted: the foreign key of `quittio_invoices.issuer_id`
  restricts it (the database throws).
- Saves that change the seller, the key, the number space or the tenant run in a
  transaction under a lock of the tenant (see [Number spaces](#number-spaces)).

## Customers and references

Every invoice can point to two of your own models. Both are optional and polymorphic, so any
Eloquent model works (user, team, company, order, subscription):

| Reference | Meaning | Example |
|---|---|---|
| `billable` | who is invoiced, the customer | `User`, `Team` |
| `invoiceable` | what is invoiced | `Order`, `Subscription` |

Prepare the customer model with the `HasInvoices` trait. `ProvidesParty` supplies the buyer
data, `HasInvoiceEmail` the delivery address (optional, otherwise the `email` attribute is
used):

```php
use Quittio\Core\Builder\PartyBuilder;
use Quittio\Core\Contracts\ProvidesParty;
use Quittio\Managed\Concerns\HasInvoices;
use Quittio\Managed\Delivery\HasInvoiceEmail;

class User extends Authenticatable implements ProvidesParty, HasInvoiceEmail
{
    use HasInvoices;

    public function fillParty(PartyBuilder $party): void
    {
        $party->name($this->company ?? $this->name)
            ->vatId($this->vat_id)
            ->address($this->street, $this->postcode, $this->city, $this->country)
            ->electronicAddress($this->email, 'EM');
    }

    public function invoiceEmail(): ?string
    {
        return $this->billing_email ?? $this->email;
    }
}
```

```php
// AppServiceProvider::boot()
Relation::enforceMorphMap(['user' => User::class, 'order' => Order::class]);
```

The trait adds three methods:

| Method | Returns |
|---|---|
| `invoices()` | `MorphMany` relation of all invoices, cancellations and corrections of the model (drafts included) |
| `invoiceBuilder(?Issuer $issuer = null)` | `$issuer->builder()` (default: the current issuer) with the model as buyer when it implements `ProvidesParty` |
| `draftInvoice($data, ?Issuer $issuer = null, ?Model $invoiceable = null)` | a new draft with the model as billable |

`draftInvoice()` accepts a callable (it receives the builder of `invoiceBuilder()`), a
builder, `InvoiceData` or an application model implementing `ProvidesInvoiceData`. Builders
and data are used as they are, including their buyer; an application model becomes the
invoiceable unless `$invoiceable` is given.

```php
$invoice = $user->draftInvoice(fn (InvoiceBuilder $b) => $b
    ->deliveryDate('2026-10-31')
    ->line('Pro plan October', netPrice: '29.00', unit: UnitCode::Month),
    invoiceable: $subscription,
)->issue();

$invoice->send();                                    // to $user->invoiceEmail()

$user->draftInvoice($order)->issue();                // $order implements ProvidesInvoiceData

$user->invoices()->issued()->latest('issue_date')->get();
$user->invoices()->overdue()->get();
$user->invoices()->ofKind(Kind::Cancellation)->get();
```

Without the trait, pass the references yourself; `forBillable()` queries without a relation
on the model:

```php
$draft = Invoice::draft($builder, $issuer, billable: $user, invoiceable: $order);
$draft = Invoice::draftFor($order, billable: $order->user);   // $order becomes the invoiceable

Invoice::query()->forBillable($user)->open()->get();

$invoice->billable;                                  // the user, or null if it was deleted
$invoice->invoiceable;
```

Laravel Cashier's `Billable` trait also has an `invoices()` method (Stripe invoices) and
calls it internally. Using both, keep Cashier's and rename the relation of Quittio:

```php
use Billable, HasInvoices {
    Billable::invoices insteadof HasInvoices;
    HasInvoices::invoices as quittioInvoices;
}

$user->quittioInvoices()->open()->get();
```

Rules worth knowing:

- The references only link records. What counts legally is the buyer copied into the
  invoice data when the draft was built: when the user changes the address later, issued
  invoices stay as they are (§ 14 UStG, GoBD). A draft keeps its buyer until you set it
  again: `$draft->updateData(fn (InvoiceBuilder $b) => $b->buyer($user))`.
- Cancellations, partial cancellations and corrections take `billable` and `invoiceable`
  over from the original invoice.
- There is no foreign key to your models. Deleting a user leaves the invoices in place
  (they must be retained for eight years, § 14b UStG, § 147 AO); `billable` then returns
  `null`. The buyer data in the archived files is unaffected.
- With `tenancy.scope` the queries above are additionally restricted to the current issuer.

## Drafts

### Creating drafts

```php
use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Managed\Models\Invoice;

$draft = Invoice::draft($builder);                                    // current issuer
$draft = Invoice::draft($builder, $issuer, billable: $user, invoiceable: $order);
$draft = Invoice::draft($invoiceData, $issuer);                       // InvoiceData works too
$draft = Invoice::draftFor($order);                 // ProvidesInvoiceData, $order becomes the invoiceable
$draft = Invoice::draftFor($order, $issuer, billable: $order->user);
$draft = $user->draftInvoice(fn (InvoiceBuilder $b) => $b->line(...));   // HasInvoices
```

| Method | Parameters |
|---|---|
| `Invoice::draft()` | `InvoiceData\|InvoiceBuilder $data, ?Issuer $issuer = null, ?Model $billable = null, ?Model $invoiceable = null` |
| `Invoice::draftFor()` | `ProvidesInvoiceData $source, ?Issuer $issuer = null, ?Model $billable = null` |

Creating a draft

1. takes the given issuer or the current one,
2. builds the data without calculating or validating it (`InvoiceBuilder::buildDraft()`, lines
   are not yet required),
3. rejects a seller with another VAT id or tax number than the issuer
   (`InvalidInvoiceState`),
4. sets the small business flag of the issuer, the format and profile of
   `defaults.format` (`auto` = `null`) and `defaults.profile`, kind `invoice`, status `draft`
   and `created_by` (the authenticated user, if any),
5. stores the data without number and totals, and copies type code, currency, buyer country,
   due date, service date, service period and locale into columns for queries,
6. writes the audit entry `drafted` and dispatches `InvoiceDrafted` once the draft is
   committed (at once outside a transaction).

`$invoice->createdBy` and `$invoice->issuedBy` return the users who created the draft and
issued the invoice (`MorphTo`, `null` without an authenticated user, e.g. in queued jobs).

A draft holds no number, number space, sequence key or sequence value: `issue()` sets them.

### Changing drafts

```php
$draft->updateData(fn (InvoiceBuilder $b) => $b->line(...)->buyerReference('PO-4711'));

$draft->forceFill(['format' => 'xrechnung-cii', 'profile' => 'xrechnung'])->save();
$draft->billable()->associate($user)->save();
```

`updateData()` starts an `InvoiceBuilder` from the current data (`InvoiceBuilder::from()`),
passes it to the callable and stores the result; it throws `InvoiceNotDraft` for issued
invoices. The other columns of a draft can be changed through the model (they are guarded:
use `forceFill()`), except `kind` and `preceding_invoice_id`, which are fixed when the draft
is created, and the number columns. Unsaved changes of the model are saved together with
`issue()`. Draft changes are not written to the audit log.

A draft keeps no totals: the invoice is calculated again when it is issued, also a draft
started with `InvoiceBuilder::from()` on data another system rounded. Only the VAT breakdown
of an aligned partial cancellation is kept (see
[VAT of partial cancellations](#vat-of-partial-cancellations)).

### Preview and validation

```php
return $draft->preview();                 // Responsable: PDF in the browser
$draft->preview()->download('entwurf.pdf');
$result = $draft->validate();             // Quittio\Core\Validation\ValidationResult
$result->isValid();
```

`preview()` renders a PDF (always format `pdf`) with the watermark of the translation
`quittio-managed::invoice.draft_watermark` ("ENTWURF", "DRAFT") in the locale of the draft or
of the issuer, without a number, dated today in the issuer's time zone and without
validation. It returns a `RenderedInvoice` of `quittio/laravel` (`pdf()`, `download()`,
usable as response or mail attachment) and throws `InvoiceNotDraft` for issued invoices.

`validate()` runs the full validation as if the draft were issued today with the next number
(the number generator's preview, nothing is consumed), with the format, profile and locale
of the draft. On an issued invoice it validates the frozen data with the format, profile,
locale and country pack it was issued with. See
[Validation](validation.md) for the result.

### Deleting drafts

```php
$draft->delete();                                         // audit entry deleted_draft
Invoice::query()->drafts()->where('created_at', '<', now()->subYear())->delete();
```

Deleting a draft leaves no gap in the numbers, since drafts hold none. A bulk delete only
removes drafts, throws `InvoiceImmutable` if the query would reach an issued invoice and
writes no audit entries (it runs without model events). Drafts of cancellations and
corrections that can no longer be issued are removed by `quittio:drafts:prune` (see
[Left-behind drafts](#left-behind-drafts)); ordinary drafts are never deleted automatically.

## Issuing

```php
$invoice = $draft->issue();                 // today in the issuer's time zone
$invoice = $draft->issue('2026-10-05');     // string, DateTimeInterface or Quittio\Core\Support\Date
```

`issue()` returns the same model, now issued. The steps:

1. The invoice must be a draft (`InvoiceNotDraft`). Drafts of cancellations are issued by
   `cancel()` and `cancelPartially()` only (`InvalidInvoiceState`), drafts of corrections
   follow the correction strategy (see [Corrections](#corrections)).
2. The issue date is the given date or today in the issuer's time zone. Cancellations and
   corrections cannot be dated before the invoice they refer to, and the issuer must still be
   the company that issued the original (same VAT id or tax number); otherwise
   `InvalidInvoiceState`.
3. The seller is taken from the issuer profile as it is now, the issue date is set, the
   number generator prepares the sequence (outside the transaction), and the data is
   calculated and fully validated with the probable number, with the format, profile and
   locale of the draft. A format that produces neither a PDF nor an XML invoice (`html`,
   `json`) is refused with `InvalidInvoiceState`, because the archive must hold the invoice
   itself. Validation errors throw `Quittio\Core\Validation\ValidationException`
   (`$e->result` holds the messages); with `quittio.validation.block_on_warnings` warnings
   do as well. No number is used up.
4. `InvoiceIssuing` is dispatched. A listener that throws stops the issue before a number is
   assigned.
5. A short transaction locks the draft (`SELECT … FOR UPDATE`) and checks again that it is a
   draft (another process may have issued or deleted it), takes the next number, reads the
   issuer with a shared lock, checks that no other issuer of the number space used the number
   and freezes the invoice: number, number space, sequence key and value, status `issued`,
   `issued_at`, issue date, totals, the calculated data snapshot, standard version, the
   format and profile actually used, country pack, locale, render status `pending`,
   validation status, retention period and `issued_by` (the authenticated user, if any).
   Cancellation and archive columns are reset. The audit entry `issued` is written.
   Deadlocks, serialisation failures and concurrent sequence changes are retried (three
   attempts).
6. After the commit, the documents are rendered by the job `RenderInvoiceDocuments` (or at
   once with `queue.render_sync`), then `InvoiceIssued` is dispatched. If a listener throws,
   the exception reaches the caller, but the invoice is issued: the model shows the committed
   state and the job is queued.

The retention period (`retention_until`) follows the rule of the country pack (DE: 8 years
after the end of the year), counted from the year of issue, but never from a year before
the invoice is actually issued: a backdated invoice keeps the full period.

| Exception | When |
|---|---|
| `ValidationException` (core) | The calculated invoice is invalid (or has warnings with `block_on_warnings`) |
| `InvoiceNotDraft` | Not a draft, or issued or deleted by another process meanwhile |
| `InvalidInvoiceState` | Cancellation draft, date before the original, another company, issuer deleted, format without PDF or XML (`html`, `json`) |
| `NumberingException` | Invalid pattern, number already taken (also by another issuer of the number space) |
| `ConfigurationException` (core) | Reset not allowed by the country pack, `retention.years` too short, invalid generator |
| `InvoiceImmutable` | The draft row was changed by another process since it was loaded |

### Issuing inside your own transaction

`issue()` (and `cancel()`, `cancelPartially()`, issuing a correction) can run inside a
database transaction of your application on the Managed connection. Then

- there are no retries: your transaction decides,
- the render job and the events `InvoiceIssued`, `InvoiceCancelled` … run only when your
  transaction commits, and an exception of a listener reaches the code that commits,
- a rollback leaves the draft a draft and uses up no number; the model in memory still shows
  the issued state then, so load it again.

## Statuses

Status, payment, delivery, rendering and validation are separate columns. Their enums are
listed in [Enums](#enums).

### Status

`status` moves only forward: `draft` → `issued` → `partially_cancelled` → `cancelled`. It
changes only through `issue()`, `cancel()`, `cancelPartially()` and the issue of a
correction, never through `save()` or bulk updates (see [Immutability](#immutability)).

- `issued`: issued and not cancelled. Cancellations and corrections are themselves `issued`.
- `partially_cancelled`: at least one partial cancellation refers to the invoice.
- `cancelled`: cancelled completely (by `cancel()`, by partial cancellations that together
  cover everything, or by an issued correction). `cancelled_at` and `cancellation_reason`
  are set then, once.

`cancellations` counts the documents that cancel (part of) the invoice (relations `cancels`,
`partially_cancels`, `replaces`).

### Payment status

Payments are not recorded by the package in version 1.0 (planned from version 1.1): every
issued invoice stays `unpaid` until you change it. The payment columns stay changeable after
issuing, through the model or in bulk:

```php
use Quittio\Managed\Enums\PaymentStatus;
use Quittio\Managed\Models\Invoice;

$invoice->forceFill([
    'payment_status' => PaymentStatus::Paid,
    'paid_total' => $invoice->amount_due,
    'paid_at' => now(),
])->save();

Invoice::query()->whereIn('number', $numbers)->update(['payment_status' => 'paid', 'paid_at' => now()]);
```

| Case | Value |
|---|---|
| `Unpaid` | `unpaid` (default) |
| `PartiallyPaid` | `partially_paid` |
| `Paid` | `paid` |
| `Overpaid` | `overpaid` |
| `WrittenOff` | `written_off` |

The package does not check these values against the amounts, writes no audit entry and
dispatches no event for them. Amounts accept at most two decimals (`InvalidArgumentException`
otherwise).

### Open and overdue

"Overdue" is not stored, it is a query:

```php
Invoice::query()->open()->get();        // issued or partially cancelled, no cancellation, unpaid or partially paid
Invoice::query()->overdue()->get();     // open and due_date before today
Invoice::query()->overdue(CarbonImmutable::parse('2026-12-31'))->get();
```

`overdue()` compares with today in the application's time zone, or with the given date.
`open()` includes corrections and credit notes of kind `invoice`; filter by `type_code` if
you need to tell them apart.

### Delivery status

`delivery_status` of the invoice: `not_sent`, `sent` after a successful `send()`, `failed`
after a failed attempt (a failed attempt after a successful one keeps `sent`). Every attempt
is a row in `quittio_deliveries` with its own state `queued`, `sent` or `failed` (see
[Delivery](#delivery)).

### Render and validation status

`render_status`: `null` for drafts, `pending` after issuing, `rendering` while the documents
are rendered, `done` once they are archived, `failed` after the last attempt failed.
`render_attempts` counts the attempts.

`validation_status`: `not_checked` for drafts, then `valid`, `warnings` or `invalid`. It is
set from the validation when the invoice is issued and replaced by the validation of the
archived rendering, whose report is stored with the documents.

## Numbering

```php
// config/quittio-managed.php
'numbering' => [
    'pattern' => 'RE-{Y}-{seq:4}',          // null = country pack (DE: RE-{Y}-{seq:4})
    'reset' => 'yearly',                    // never | yearly | monthly, within the country pack's rules
    'start' => 1,                           // first value of every new sequence (and period)
    'per_kind' => [
        'cancellation' => 'ST-{Y}-{seq:4}', // own sequence, null = shared
        'correction' => null,
    ],
    'generator' => null,                    // class implementing NumberGenerator
],
```

The effective scheme of an invoice is taken per setting from the issuer's `numbering`
column, then from the configuration, then from the country pack:

```php
$issuer->forceFill(['numbering' => [
    'pattern' => 'B-{Y}-{seq:4}',
    'start' => 10,
    'per_kind' => ['cancellation' => 'BS-{Y}-{seq:4}'],
]])->save();
```

### Placeholders

| Placeholder | Replaced by |
|---|---|
| `{Y}` | Year of the issue date, four digits |
| `{y}` | Year of the issue date, two digits |
| `{m}` | Month of the issue date, two digits |
| `{seq}` | Running number without padding |
| `{seq:N}` | Running number with at least `N` digits (`N` has one or two digits), e.g. `{seq:4}` → `0042` |
| `{issuer}` | Key of the issuer |
| `{customer}` | Buyer identifier (BT-46), empty without one |

Patterns are checked when they are used (issuing, `validate()`, `quittio:sequence:set`) and
throw a `NumberingException` ("Invalid numbering pattern …") when

- they contain no or more than one `{seq}` / `{seq:N}`,
- they contain an unknown placeholder,
- the reset is `yearly` without `{Y}` or `{y}`, or `monthly` without a year and `{m}`
  (numbers would repeat),
- an own sequence per kind could produce the numbers of another sequence of the issuer:
  `RE-{Y}-{seq}` next to `RE-{Y}-{seq:4}` is rejected, and so is `RE{Y}-{seq:2}7` next to
  `RE{Y}-7{seq:2}` (both give `RE2026-707`); `ST-{Y}-{seq:4}` is fine. The check compares
  the patterns exactly; `{customer}` counts as any text.

A reset the country pack does not allow, or a `start` below 1, throws a
`ConfigurationException`.

### Sequences and resets

Every number comes from a sequence with the key `{issuer}:{sequence}:{period}`, e.g.
`default:invoice:2026`:

- `{issuer}` is the issuer key,
- `{sequence}` is `invoice` (shared by all kinds), or `cancellation` or `correction` when
  `per_kind` gives that kind a pattern of its own,
- `{period}` follows from the reset and the issue date: `2026` (yearly), `2026-10` (monthly)
  or `all` (never).

A backdated invoice uses the sequence of its period: an invoice dated 2026-12-30 and issued
in January 2027 gets the next number of `default:invoice:2026`. Resets apply to all
sequences of an issuer alike. `start` is the first value of every new sequence, i.e. also of
every new period; to continue the numbers of an old system once, raise the sequence with
`quittio:sequence:set` instead.

### Gapless numbers

Numbers are assigned inside the issuing transaction only: a draft has none, validation errors
and failed cancellations use none, and a rolled back transaction gives its number back.
Deleting a draft therefore never leaves a gap. The unique indexes `(number_space, number)`,
`(issuer_id, number)` and `(sequence_key, sequence_value)` are the last line of defence.

How concurrent issues are serialised by the default generator (`SequenceNumberGenerator`):

1. Before the transaction, a missing sequence row is created with `INSERT … OR IGNORE`, so
   that concurrent first invoices of a period do not deadlock (MySQL).
2. Inside the transaction, the row is locked (`SELECT … FOR UPDATE`), the number is formed
   and the counter is raised with a conditional update. If another process changed it in
   between (e.g. SQLite without an immediate transaction), the attempt fails and the
   transaction is retried.
3. Deadlocks, lock wait timeouts, serialisation failures (PostgreSQL `40001`, `40P01`) and
   `database is locked` (SQLite) are retried up to three attempts with a short random pause.

For SQLite in production set `'transaction_mode' => 'IMMEDIATE'` and a `busy_timeout` on the
connection; the numbering logs a warning once per process when the mode is missing.

### Number spaces

Numbers are unique per company (section 14 (4) no. 4 UStG). Every issuer has a number space
(`number_space`); issuers of one company use the same one and share their numbers: a number
that one of them used is taken for the others. Issued invoices store the number space of
their issuer, with the unique index `(number_space, number)` (and `(issuer_id, number)` as a
safeguard), which holds even when two issuers of the company issue at the same moment.

- **Default**, set when the issuer is created: `vat:{VAT id}`, else `tax:{tax number}`, else
  `issuer:{key}`, with the identifiers normalised (without spaces, also no-break and
  zero-width spaces, dots, slashes and dashes; upper case). Issuers with the same VAT id thus
  share a number space by default. Until the issuer issues its first invoice, a default
  follows changes of its VAT id, tax number or key; an explicit value stays.
- **Explicit**, e.g. for several brands of one company:
  `$brand->forceFill(['number_space' => $main->number_space])->save()`, or any name of your
  own (at most 128 characters, no `|`, no control characters, no surrounding whitespace;
  `InvalidIssuerProfile` otherwise).
- **One company, one space**: within a tenant, issuers that share a VAT id or a tax number must
  have the same number space; saving an issuer that would split a company (e.g. a brand with
  only the tax number of the main profile, or a tax number added later to a profile whose
  other brands use another space) throws `InvalidIssuerProfile` and names the other issuer.
  Sharing is therefore transitive (A with VAT id and tax number, B with the tax number, C with
  the VAT id: one space). To change the space of issuers that share an identifier, change them
  together: `NumberSpaces::assign([$a, $b], 'muster')` (`Quittio\Managed\Support\NumberSpaces`).
  Saves of issuers of one tenant are serialised (an advisory lock on PostgreSQL, `GET_LOCK()`
  on MySQL with a timeout of 10 seconds, after which `InvalidIssuerProfile` asks to try
  again), so concurrent saves cannot split a company either. The check also runs for saves
  without model events (`saveQuietly()`, `Event::fake()`).
- **Notations are not unified**: a German tax number in the 13-digit ELSTER format and in the
  regional format (`12/345/67890`) are different values. If the profiles of one company use
  different notations (or one has only the VAT id, another only the tax number), set the
  same `number_space` explicitly.
- **Tenants**: number spaces apply within the tenant of the issuer (`tenant_type`,
  `tenant_id`); the invoices store them qualified by the tenant (`team:7|vat:DE123456789` for
  `tenant_type` `team` and `tenant_id` 7, `NumberSpaces::qualified($issuer)`), so the same
  VAT id or space name in another tenant never blocks a number, and errors never name
  issuers of other tenants. With `tenancy.scope`, an issuer without a tenant is a tenant of
  its own (`issuer:{id}|…`; give the issuers of one company the same tenant); without it, the
  issuers without a tenant form one. Decide on `tenancy.scope` before issuing invoices: it
  changes the number spaces of issuers without a tenant.
- **Frozen**: once an issuer has issued invoices, its number space and its tenant cannot change
  (`NumberingException`); its VAT id and tax number still can, within the rule above.

With the same pattern, the second issuer of a number space cannot use a number the first one
took (`NumberingException` naming the other issuer): give such issuers different patterns, e.g.
with `{issuer}`, also for the sequences per kind (`numbering.per_kind` per issuer).

### Managing sequences

```bash
php artisan quittio:sequence:show                               # all sequences
php artisan quittio:sequence:show --issuer=default
php artisan quittio:sequence:set default:invoice:2026 1043      # only increases, asks
php artisan quittio:sequence:set default:invoice:2027 1 --force # creates a sequence ahead of time
```

`quittio:sequence:show` lists key, next value and pattern. `quittio:sequence:set` sets the
next value of a sequence (a positive integer above the current one, after a confirmation
unless `--force`). It only creates sequences the numbering would use: the issuer must exist,
the sequence must be `invoice` or a kind with its own pattern, and the period must match the
reset (`2026`, `2026-10` or `all`); the pattern comes from the configuration at that moment.
Sequences are created automatically when the first invoice of a period is issued.

The `Sequence` model never lowers a counter (also not from a stale model) and writes every
change to the audit log (`sequence_changed`); key and pattern never change, sequences cannot
be deleted, and bulk updates, increments, touches, upserts, deletes and `updateOrInsert()`
of these columns throw `NumberingException`. Change a counter through the model only:

```php
$sequence = Managed::sequenceModel()::query()->where('key', 'default:invoice:2026')->sole();
$sequence->forceFill(['next_value' => 1043])->save();     // lower values throw
```

### Own number generators

Numbers from another system (e.g. an ERP) come from a class implementing
`Quittio\Managed\Numbering\NumberGenerator`:

```php
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Support\Date;
use Quittio\Managed\Models\Invoice;
use Quittio\Managed\Numbering\IssuedNumber;
use Quittio\Managed\Numbering\NumberGenerator;

final class ErpNumbers implements NumberGenerator
{
    // Before the issuing transaction, e.g. to create a missing counter.
    public function prepare(Invoice $invoice, Date $issueDate, InvoiceData $data): void {}

    // The probable number, used to validate the invoice; must not consume a number.
    public function preview(Invoice $invoice, Date $issueDate, InvoiceData $data): string
    {
        return $this->erp->peek();
    }

    // Inside the issuing transaction; a rollback must give the number back.
    public function next(Invoice $invoice, Date $issueDate, InvoiceData $data): IssuedNumber
    {
        return new IssuedNumber($this->erp->take());   // sequenceKey and sequenceValue optional
    }
}

// config/quittio-managed.php
'numbering' => ['generator' => App\Billing\ErpNumbers::class],
```

An own generator is responsible for unique and gapless numbers; the unique index
`(number_space, number)` (and `(issuer_id, number)`) remains as a safeguard: a number already
used in the number space throws `NumberingException` ("The invoice number … is already
taken"). `IssuedNumber` takes `string $number, ?string $sequenceKey = null,
?int $sequenceValue = null`. To change only the format, extend `SequenceNumberGenerator` and
override its protected methods `scheme()` or `format()`.

## Rendering and archive

### Render job

After the issuing transaction has been committed, the job
`Quittio\Managed\Rendering\RenderInvoiceDocuments` renders and archives the documents of the
invoice, on `queue.connection` and `queue.queue` (default queue `quittio`). It is tried
`count(queue.backoff) + 1` times, by default after 10 s, 1 min, 5 min and 30 min again; after
the last attempt the invoice is marked `failed`, the audit entry `render_failed` is written and
`InvoiceRenderFailed` is dispatched. The invoice stays issued.

With `queue.render_sync`, the documents are rendered in the same request, right after the
commit and before `InvoiceIssued`. A failure is logged as an error and marks the invoice
`failed` at once (no retries); it never undoes the issue.

The documents are rendered from the frozen data snapshot with the format, profile, locale
and country pack of the invoice. Validation runs again but does not block: its result is
stored as the validation report and as `validation_status`.

### Archive layout

Documents are stored on the disk `quittio-managed.disk`, in the directory of
`quittio-managed.path` (default `{issuer}/{Y}/{m}/{number}/v{version}`):

| Placeholder | Replaced by |
|---|---|
| `{issuer}` | Issuer key |
| `{Y}`, `{m}` | Year and month of the issue date |
| `{number}` | Invoice number |
| `{uuid}` | UUID of the invoice |
| `{version}` | Document version (1, 2, …) |

`{issuer}` and `{number}` are made safe as single path segments (characters other than
letters, digits, `.`, `_` and `-` become `_`, so `RE/2026/1` becomes `RE_2026_1`). The
pattern must contain `{version}` and `{number}` or `{uuid}`; otherwise archiving throws a
`ConfigurationException` (every version, and every invoice, needs a directory of its own).
The file names follow `quittio.pdf.filename` (default `{title}_{number}.{ext}`), e.g.
`default/2026/10/RE-2026-0042/v1/Rechnung_RE-2026-0042.pdf`.

| Format | Archived files |
|---|---|
| `zugferd` (`factur-x`) | PDF/A-3 and the embedded XML as its own file (byte-identical) |
| `xrechnung-cii`, `xrechnung-ubl`, `cii`, `ubl`, `peppol` | XML |
| `pdf` | PDF |

Every version also holds `validation-report.json`, the validation result of the rendering.
The invoice keeps `disk`, `xml_path`, `pdf_path`, the SHA-256 hashes `xml_hash` and
`pdf_hash`, `validation_report_path` and `document_version`. Archiving writes the audit
entry `rendered` (version, disk, files, report, hashes) and dispatches
`InvoiceDocumentsStored`. For production, use an object store with object lock (WORM) and
versioning.

### Versions and re-rendering

Files are never overwritten: writing a file that exists with other content throws an
`ArchiveException` (identical content is accepted). Archiving an invoice again

- does nothing when the files of the current version are complete and unchanged (only the
  render status is set to `done`),
- renders a new version when files are missing (`ArchiveIntegrityViolation` with the problem
  `missing` is dispatched for each),
- never replaces damaged files: a hash mismatch dispatches `ArchiveIntegrityViolation` and
  throws an `ArchiveException`, so the files stay for the investigation.

A version directory that already holds files (e.g. of a failed attempt), or whose files are
recorded for another invoice, is skipped. Only the archiver changes the archive columns of an
issued invoice, and only together with a higher document version.

```bash
php artisan quittio:render RE-2026-0042    # number, UUID or id; renders missing or failed documents
```

`quittio:render {invoice}` prints the problems it found and the new version, or that the
documents are complete. Damaged files are not rendered again: the command reports the
`ArchiveException` and fails. A number that exists for several issuers must be given as UUID; drafts
cannot be rendered.

### Archive manifest

Every archive file is recorded in `quittio_archive_files` (invoice id, disk, path) before it
is written. The manifest lets `quittio:retention:check` delete the files of all versions,
also those of a rendering that failed halfway, and retry files it could not delete; it also
keeps two invoices with the same archive path from writing into the same directory. The
rows have no foreign key: they outlive their invoice until the files are gone.

### Integrity checks

```bash
php artisan quittio:verify                                   # all issued invoices
php artisan quittio:verify --from=2026-01-01 --to=2026-12-31 --issuer=default
```

`quittio:verify` checks the SHA-256 hashes of the current version of every issued invoice
(issue date from `--from` to `--to`, optionally of one issuer key) and also reports issued
invoices whose documents were never archived (render status `pending`, `rendering` or
`failed`). It dispatches `ArchiveIntegrityViolation` for every problem, writes the audit
entry `verified` (checked, violations, from, to), prints a table and exits with a failure code
if it found problems. `send()` checks the hashes before every delivery as well.

`ArchiveIntegrityViolation` carries the invoice, the path and the problem: `missing`,
`hash mismatch` or (from `quittio:verify`) `not archived (render status: …)`; the path is `-`
when the invoice has no archived file at all.

### Reading archived documents

```php
use Illuminate\Support\Facades\Storage;
use Quittio\Managed\Rendering\DocumentArchiver;

app(DocumentArchiver::class)->assertIntact($invoice);    // ArchiveException if missing or changed
return Storage::disk($invoice->disk)->download($invoice->pdf_path);

app(DocumentArchiver::class)->problems($invoice);        // ['path' => 'missing' | 'hash mismatch']
```

| `DocumentArchiver` method | Effect |
|---|---|
| `archive(Invoice $invoice): bool` | Renders and archives unless the current version is complete; `false` when nothing had to be done |
| `assertIntact(Invoice $invoice): void` | Throws `ArchiveException` (and dispatches `ArchiveIntegrityViolation`) unless the current version is complete and unchanged |
| `problems(Invoice $invoice): array` | Missing files and hash mismatches of the current version |

### Own archive storage

The archive goes through `Quittio\Managed\Rendering\ArchiveStorage` (default:
`FilesystemArchive` on Laravel disks). An own implementation must keep files write-once:

| Method | Contract |
|---|---|
| `put(string $disk, string $path, string $contents): void` | Never overwrites: identical content is accepted, other content throws `ArchiveException` |
| `get(string $disk, string $path): ?string` | `null` when the file is missing |
| `exists(string $disk, string $path): bool` | |
| `delete(string $disk, string $path): bool` | Used after the retention period |

## Cancellations and corrections

```php
$cancellation = $invoice->cancel(reason: 'Doppelt berechnet');
$cancellation = $invoice->cancel('Doppelt berechnet', '2026-10-06');   // with issue date

$cancellation = $invoice->cancelPartially([
    ['line' => '3'],                       // whole line (or what is left of it)
    ['line' => '1', 'quantity' => 1],      // 1 of 3
    ['line' => '2', 'amount' => '50.00'],  // amount, e.g. a price reduction
], reason: 'Teilrücknahme');

$draft = $invoice->correct();
$draft->updateData(fn (InvoiceBuilder $b) => $b->updateLine('2', netPrice: '80.00'));
$draft->issue();
```

The original is never changed: it only gets a new status. Every cancellation and correction is
an invoice of its own with its own number, built from the snapshot of the original, in the
same format, profile and locale, with the same `billable` and `invoiceable`, referring to the
original (BG-3 and `preceding_invoice_id`).

| Method | Signature | Returns |
|---|---|---|
| `cancel()` | `?string $reason = null, Date\|DateTimeInterface\|string\|null $issueDate = null` | The issued cancellation |
| `cancelPartially()` | `array $lines, ?string $reason = null, Date\|DateTimeInterface\|string\|null $issueDate = null` | The issued partial cancellation |
| `correct()` | – | A draft of kind `correction`; issue it with `issue()` |

| Allowed for | `cancel()` | `cancelPartially()` | `correct()` |
|---|---|---|---|
| `issued` | yes | yes | yes |
| `partially_cancelled` | yes (the remainder) | yes | no |
| `cancelled` | no (`NothingToCancel`) | no (`NothingToCancel`) | no |
| draft | no | no | no |
| kind `cancellation` | no | no | no |
| document level allowances or charges | yes | no | yes |

### Full cancellation

`cancel()`

1. checks the original (see the table: `InvalidInvoiceState` or `NothingToCancel`) and that
   the issuer is still the company that issued it,
2. builds the cancellation with the core (see [Getting started](getting-started.md)): for an
   invoice that was partially cancelled before, only what is left,
3. creates a draft of kind `cancellation` and issues it with its own number (the steps of
   [Issuing](#issuing)); inside the issuing transaction the original is locked and read again,
   the remainder is checked again, the relation `cancels` is recorded, the original becomes
   `cancelled` (with `cancelled_at`, `cancellation_reason` and a raised `cancellations`
   counter) and the audit entry `cancelled` is written on the original,
4. dispatches `InvoiceCancelled` after the commit.

If the cancellation cannot be issued (e.g. invalid), its draft is deleted again (audit entry
`deleted_draft`), the original model shows its stored state again and no number is used up.

The cancellation document

- has the type code of the sign strategy (see below) and the title of the country pack (DE:
  "Stornorechnung"),
- states in a note which invoice it cancels and the reason ("Stornierung der Rechnung
  RE-2026-0042 vom 05.10.2026. Grund: Doppelt berechnet"),
- keeps the regulatory and tax notes of the original (BT-22 with subject `REG` or `TXD`) and,
  when it cancels everything that is left, the prepaid and rounding amounts (BT-113, BT-114),
  so that all cancellations together reverse exactly the amount due,
- cannot be dated before the original.

### Partial cancellation

`cancelPartially()` takes a list of line selections:

| Selection | Cancels |
|---|---|
| `['line' => '3']` | The whole line, or what is left of it |
| `['line' => '1', 'quantity' => 1]` | A quantity (int or numeric string) |
| `['line' => '2', 'amount' => '50.00']` | An amount (string): the net amount, for a line with a price including VAT the amount including VAT |

`line` is the line id (BT-126, `InvoiceLine::$id`; the builder numbers lines `'1'`, `'2'`, …
unless you set ids). The rules:

- Per line, cancelled quantities and amounts are tracked; more than invoiced cannot be
  cancelled, also not by processes that cancel at the same time. Quantities and amounts are
  magnitudes: negative lines (discounts, returns) are reversed with their sign.
- After an amount cancellation, the rest of a line can only be cancelled by amount.
- The last part of a line cancels exactly its remaining net amount (as an amount), so the net
  amounts of all cancellations add up exactly to the original. A line whose net amount is
  cancelled completely is closed, even if rounding left a quantity.
- Lines with a price including VAT (gross price mode) are tracked and cancelled by their
  amount including VAT: the last part cancels exactly the remaining gross amount, so the
  amounts due add up exactly.
- Partial cancellations are not possible for invoices with document level allowances or
  charges (`InvalidArgumentException` of the core): cancel the invoice fully and issue a new
  one.

The original becomes `partially_cancelled` (relation `partially_cancels`, audit entry
`partially_cancelled`, event `InvoicePartiallyCancelled`), or `cancelled` once the partial
cancellations cover everything (then with `cancelled_at` and the reason of the last part,
audit entry `cancelled`, event `InvoiceCancelled`). `cancel()` on a partially cancelled
invoice cancels the remainder.

| Error | Exception |
|---|---|
| Empty list | `InvalidInvoiceState` |
| Unknown line, line already cancelled completely, more than is left, a quantity after an amount cancellation | `UnexpectedValueException` (message names the line and what is left) |
| Document level allowances or charges | `InvalidArgumentException` |
| Quantity or amount given as float (`2.5` instead of `'2.5'`) | `InvalidArgumentException` |
| Another partial cancellation was issued meanwhile | `InvalidInvoiceState` ("… partially cancelled in the meantime; try again"), no number used up |

### VAT of partial cancellations

- VAT is calculated per document and VAT group (category and rate) and rounded there
  (EN 16931 BR-CO-17). A partial cancellation rounds it cumulatively with the earlier
  partial cancellations of the same original: per VAT group the target is round2(taxable
  amount of all cancellations so far × rate), or the original's VAT (BT-117) once all lines
  of the group are cancelled, and the cancellation carries the target minus the VAT already
  cancelled. Three parts of 9.67, 9.67 and 9.66 net at 19 % carry 1.84, 1.83 and 1.84 = 5.51
  VAT (rounded per document they would carry 3 × 1.84 = 5.52). In gross price mode the
  taxable amount follows the same way from the gross amount: per VAT group round2(gross
  amount cancelled so far / (1 + rate)), as for the original, or the original's taxable
  amount (BT-116) once the group is closed; the rounding amount (BT-114) follows, so that the
  amount due of every part stays the gross amount of its lines. 3 × 9.99 including 19 %
  (25.18 net, 4.78 VAT, BT-114 0.01) are cancelled as 8.39, 8.40 and 8.39 net (per document
  3 × 8.39 = 25.17).
- What holds for every sequence of partial cancellations (and the rest with `cancel()`):
  per VAT group the taxable amounts (BT-116, the taxable amount of section 17 (1) UStG) and
  the VAT (BT-117) of all cancellations add up exactly to the original's, and with them the
  totals and amounts due (BT-109, BT-110, BT-112, BT-114, BT-115). Every single cancellation
  differs by at most one cent from round2(its own taxable amount × rate), and its VAT has the
  sign of its taxable amount (0.00 on 0.00). BR-CO-17 and BR-S-09 accept up to one currency
  unit (the official Schematron checks exactly this tolerance), so every document stays
  valid. Such a cancellation says so in its note ("Die Umsatzsteuer ist auf die bereits
  stornierten Teilbeträge abgestimmt.") and the validation reports the information
  `QUITTIO-CANCELLATION-VAT-ALIGNED` instead of the warning `QUITTIO-VAT-ROUNDING`, which still
  reports a larger difference or another sign. The VAT total in accounting currency (BT-111,
  VAT accounting currency BT-6) is aligned the same way: round2(VAT cancelled so far ×
  exchange rate), and the original's BT-111 with the last part.
- The taxable amount, the VAT, the share of BT-111 and in gross price mode the gross amount
  per VAT group of every cancellation are recorded with its relation (column `vat`); a
  partial cancellation that another process issues in the meantime changes the VAT of the
  next part, which is then rejected ("partially cancelled in the meantime; try again")
  without consuming a number. A single full cancellation reverses the original exactly.
- Drafts keep a declared VAT breakdown only for such aligned cancellations: every other
  invoice is recalculated with Quittio's rounding when it is issued, also a draft started with
  `InvoiceBuilder::from()` on data another system rounded.

The one-cent alignment is to be confirmed by a tax adviser (see the legal review in the
README).

### Sign strategies and credit notes

| Strategy (`quittio.corrections.cancellation_strategy`) | Type code | Amounts |
|---|---|---|
| `credit_note` (default, also of the German pack) | 381 | positive; UBL writes a `CreditNote` document |
| `negative_invoice` | 380 | negative |

The key lives in `config/quittio.php` (env `QUITTIO_CANCELLATION_STRATEGY`); `null` takes the
country pack's default. A credit note (381 and the other credit note type codes) is
cancelled with an invoice (380) with the same positive amounts in both strategies.

Credit notes can be issued as ordinary managed invoices: set the type code in the builder
(`->type(InvoiceTypeCode::CreditNote)`). They are cancelled with a 380 invoice and corrected
with a credit note, with the strategy `cancel_and_reissue` only.

### Cancellation ledger

Relations (`quittio_invoice_relations`) point from the new document (`invoice_id`) to the
original (`related_invoice_id`). The cancelling ones (`cancels`, `partially_cancels`,
`replaces`) make up the cancellation ledger of the original:

| Column | Content |
|---|---|
| `type` | `cancels`, `partially_cancels`, `corrects`, `replaces` |
| `amount` | Cancellations: the cancelled net amount, always positive |
| `lines` | Cancellations: per original line `line`, `quantity` (`null` for amount cancellations), `net` and, for gross price lines, `gross` |
| `vat` | Cancellations: per VAT group `key` (e.g. `S\|19.00`), `category`, `rate`, `taxable`, `tax`, `tax_in_tax_currency`, `gross`, signed like the original |
| `created_at` | Time of the relation |

```php
$invoice->inverseRelations;                 // cancellations and corrections of this invoice
$cancellation->relations;                   // what this document cancels or corrects
$cancellation->precedingInvoice;            // the original
$relation->cancelledLines();                // decoded lines
$relation->cancelledVat();                  // decoded VAT groups, null if not recorded
```

The service `Quittio\Managed\Corrections\CancellationLedger` computes what is left:

```php
use Quittio\Managed\Corrections\CancellationLedger;

$ledger = app(CancellationLedger::class);
foreach ($ledger->balances($invoice) as $lineId => $balance) {
    // LineBalance: quantity, amount, remainingQuantity, remainingAmount, amountCancelled, gross
    echo $lineId, ': ', $balance->remainingQuantity, ' / ', $balance->remainingAmount, PHP_EOL;
}
$ledger->isFullyCancelled($invoice);
$ledger->remainder($invoice);               // null if nothing was cancelled, otherwise the open selections
$ledger->count($invoice);                   // number of cancelling documents
```

| Method | Returns |
|---|---|
| `balances(Invoice $original)` | `LineBalance` per line id: `isOpen()`, `byAmount()`, `isUntouched()` |
| `remainder(Invoice $original)` | Selections for the rest (`NothingToCancel` if nothing is left), `null` if untouched |
| `isFullyCancelled(Invoice $original)` | Whether no line is open |
| `count(Invoice $original)` | Number of cancelling relations |
| `normalize(Invoice $original, array $requested)` | The selections checked against the remainder (whole lines become quantities or amounts) |
| `history(Invoice $original, array $recorded)` | `CancellationHistory` of the core: VAT cancelled so far per group |
| `record(InvoiceData $cancellation, ?array $requested)` | The `lines` entry of a relation |
| `vat(InvoiceData $original, InvoiceData $cancellation)` | The `vat` entry of a relation |

The amount of a line is its net amount, for gross price lines its gross amount (as
magnitudes). Relations cannot be changed and are deleted only together with their invoice
after its retention period.

### Corrections

`correct()` creates a draft of kind `correction` with the data of the original: no number, the
type code 380 (a credit note keeps its credit note type code), a reference to the original
(BG-3, number and date) and no due date (it follows from the payment terms again when the
correction is issued). Change it with `updateData()`, preview it, then `issue()` it. Issuing
follows `quittio-managed.correction.strategy` (env `QUITTIO_CORRECTION_STRATEGY`, `null` = the
country pack's default, DE: `cancel_and_reissue`):

| Strategy | Issuing the correction |
|---|---|
| `cancel_and_reissue` | Cancels the original completely (reason from the translation `quittio-managed::invoice.correction_reason`, "Rechnungskorrektur."), then issues the correction as a new invoice with the relation `corrects`. Events `InvoiceCancelled` and `InvoiceCorrected`; two numbers are used. |
| `corrected_invoice` | Issues the correction with type code 384 and the title of the country pack (DE: "Rechnungskorrektur"), relation `replaces`; the original becomes `cancelled` with the reason `quittio-managed::invoice.replaced_by` ("Ersetzt durch Rechnung RE-2026-0043."). Event `InvoiceCorrected` (no `InvoiceCancelled`). |

Both steps run in one transaction: either both documents exist or none (an invalid correction
also rolls back the cancellation). The audit entry `corrected` (correction number and
relation type) is written on the original.

- Only `issued` invoices can be corrected, not cancellations and not partially cancelled ones
  (`InvalidInvoiceState`): cancel the remainder with `cancel()` and issue a new invoice.
- A credit note is corrected with a credit note and only with `cancel_and_reissue`;
  `corrected_invoice` throws `InvalidInvoiceState` for credit notes, because 384 is a corrected
  invoice.
- An unknown strategy throws `ConfigurationException`. The strategy is checked when the draft
  is created and again when it is issued.
- The correction cannot be dated before the original, and the issuer must still be the company
  that issued it.
- Corrections can themselves be cancelled, partially cancelled and corrected.
- A correction draft whose original was cancelled, partially cancelled or replaced meanwhile
  can no longer be issued; `quittio:drafts:prune` deletes it.

The default strategy is to be confirmed by a tax adviser (see the legal review in the README).

### Left-behind drafts

A draft of a cancellation exists only while `cancel()` or `cancelPartially()` issues it,
and a correction draft can only be issued while its original can be corrected.

```bash
php artisan quittio:drafts:prune                     # asks
php artisan quittio:drafts:prune --older-than=120 --force
```

`quittio:drafts:prune` deletes cancellation drafts older than `--older-than` minutes (default
60, left behind e.g. by a crash) and correction drafts whose original can no longer be
corrected (cancelled, partially cancelled or replaced meanwhile, or deleted). A draft that has
been issued meanwhile is never deleted; every deletion writes `deleted_draft` to the audit
log.

### Self-billing

Self-billing (the buyer issues the invoice, "Gutschrift" in the sense of the UStG) is not part
of version 1.0: the seller of every managed invoice is its issuer. The core keeps self-billed
documents self-billed when it cancels them (type codes 389 and 261).

## Delivery

```php
$delivery = $invoice->send();                                    // address from the billable or the buyer
$delivery = $invoice->send('buchhaltung@kunde.de', ['chef@kunde.de']);
```

`send(?string $to = null, array $cc = [])` returns the `Delivery` row. It

1. refuses drafts (`InvalidInvoiceState`); cancelled invoices, cancellations and corrections
   can be sent,
2. renders and archives the documents first if the render status is not `done` (in the
   request, so it can throw),
3. checks the hashes of the archived files: missing or damaged files dispatch
   `ArchiveIntegrityViolation` and throw an `ArchiveException`; nothing is sent and no
   delivery is logged,
4. takes the recipient from `$to` or the recipient resolver (`NoRecipient` if there is none),
5. attaches the archived PDF (ZUGFeRD, PDF), otherwise the archived XML (XRechnung, CII, UBL,
   Peppol); with `delivery.mail.xrechnung_visualization` a PDF visualisation of an XML-only
   invoice is rendered from the snapshot and attached as well (it is not archived),
6. logs the attempt in `quittio_deliveries` (status `queued`) and sends the mail at once
   with the default mailer (`sendNow()`, also an own Mailable that implements `ShouldQueue`),
   to `$to`, with `$cc` and a blind copy to `delivery.mail.bcc_archive`,
7. on success: delivery `sent` with `message_id` (`null` when the mailer returns no message,
   e.g. with `Mail::fake()`) and `sent_at`; invoice `delivery_status` `sent`, `sent_at` and
   `channel` `mail`; audit entry `sent`; event `InvoiceSent`,
8. on failure: delivery `failed` with the error message; invoice `delivery_status` `failed`
   (unless it was sent before); audit entry `delivery_failed`; event `InvoiceDeliveryFailed`;
   the exception is rethrown.

Every call is a new attempt and a new row; `$invoice->deliveries` lists them. `send()` sends
synchronously: to send in the background, dispatch an own job that calls `send()`.

### Recipients

The recipient comes from `delivery.recipient_resolver`. The default
(`DefaultRecipientResolver`) takes the first valid address of

1. `invoiceEmail()` of the billable, when it implements
   `Quittio\Managed\Delivery\HasInvoiceEmail`,
2. the `email` attribute of the billable,
3. the buyer's contact e-mail (BT-58) in the invoice data,
4. the buyer's electronic address (BT-49) with scheme `EM`.

```php
use Quittio\Managed\Delivery\RecipientResolver;
use Quittio\Managed\Models\Invoice;

final class AccountingRecipient implements RecipientResolver
{
    public function resolve(Invoice $invoice): ?string
    {
        return $invoice->billable?->accounting_email;
    }
}
```

### Mail content

The default Mailable `Quittio\Managed\Delivery\InvoiceMail` uses the locale of the invoice
(else of the issuer, else `en`), a subject and text by kind, the due date line (not for
cancellations and invoices without due date) and the seller's name below the closing. The texts
are translations of the namespace `quittio-managed` (publish them with
`--tag=quittio-managed-lang`, the Markdown view with `--tag=quittio-managed-views`):

| Key | German default |
|---|---|
| `mail.subject.invoice` | `Rechnung :number von :seller` |
| `mail.subject.correction` | `Rechnungskorrektur :number von :seller` |
| `mail.subject.cancellation` | `Stornorechnung :number von :seller` |
| `mail.greeting` | `Guten Tag,` |
| `mail.body.invoice` | `anbei erhalten Sie die Rechnung :number über :amount.` |
| `mail.body.correction` | `anbei erhalten Sie die Rechnung :number über :amount. Sie korrigiert eine frühere Rechnung.` |
| `mail.body.cancellation` | `anbei erhalten Sie die Stornorechnung :number.` |
| `mail.due` | `Bitte überweisen Sie den Betrag bis zum :due_date.` |
| `mail.closing` | `Mit freundlichen Grüßen` |
| `invoice.draft_watermark` | `ENTWURF` (preview) |
| `invoice.correction_reason` | `Rechnungskorrektur.` (reason of the cancellation of `cancel_and_reissue`) |
| `invoice.replaced_by` | `Ersetzt durch Rechnung :number.` (reason with `corrected_invoice`) |

Placeholders: `:number`, `:seller`, `:amount` (amount due, formatted for the locale) and
`:due_date`. English translations are included.

An own Mailable is configured in `delivery.mail.mailable`. It is built by the container with
the named arguments `invoice` (the `Invoice`) and `files` (list of `Illuminate\Mail\Attachment`
with the archived documents); return the files from `attachments()`:

```php
use Illuminate\Mail\Mailables\Content;
use Quittio\Managed\Delivery\InvoiceMail;

final class BrandedInvoiceMail extends InvoiceMail
{
    public function content(): Content
    {
        return new Content(markdown: 'mail.invoice', with: ['invoice' => $this->invoice]);
    }
}
```

`send()` sends it at once even if it implements `ShouldQueue`, so that the delivery log holds
the result of the transport; to send in the background, dispatch an own job that calls
`send()`.

## Audit log

`quittio_audit_logs` is append-only. Every entry has an action, a subject (the invoice,
issuer or sequence, `null` for `verified`), a JSON payload, the causer (the authenticated user
of the default guard, any Eloquent model) and, for HTTP requests, IP address and user agent
(at most 255 characters); console commands and queue jobs record neither.

| Action | Subject | Payload |
|---|---|---|
| `drafted` | invoice | `kind`, `preceding` (number of the original) |
| `issued` | invoice | `number`, `sequence_key`, `issue_date`, `gross_total` |
| `rendered` | invoice | `version`, `disk`, `files`, `report`, `hashes` |
| `render_failed` | invoice | `error` |
| `sent` | invoice | `channel`, `delivery` (id), `attachments` |
| `delivery_failed` | invoice | `channel`, `delivery` (id) |
| `cancelled` | original | `cancellation` (number), `reason`, `net`, `lines` |
| `partially_cancelled` | original | `cancellation` (number), `reason`, `net`, `lines` |
| `corrected` | original | `correction` (number), `type` (`corrects` or `replaces`) |
| `deleted_draft` | invoice | `number`, `retention_until` |
| `deleted` | invoice | `number`, `retention_until` |
| `sequence_changed` | sequence | `key`, `from`, `to` |
| `issuer_created` | issuer | `key`, `changes` (per column `old` `null`, `new`) |
| `issuer_updated` | issuer | `key`, `changes` (per changed column `old`, `new`) |
| `issuer_deleted` | issuer | `key`, `changes` (per column `old`, `new` `null`) |
| `verified` | – | `checked`, `violations`, `from`, `to` |

```php
$invoice->auditLogs()->orderBy('id')->get();
AuditLog::query()->where('action', 'issued')->where('causer_id', $user->id)->get();
$entry->causer;     // MorphTo
$entry->subject;    // MorphTo
```

Not logged: changes of drafts (`updateData()`, `save()`), previews and validations, bulk
deletes of drafts, payment, legal hold and retention changes, archive integrity violations
outside `quittio:verify` (they are events) and read access. Recipients and other customer
data are not part of the entries: `sent` and `delivery_failed` refer to the delivery row,
which is deleted with the invoice (see [Personal data](#personal-data)).

`audit.enabled => false` switches the log off completely. An own implementation of
`Quittio\Managed\Audit\Auditor` (`record(?Model $subject, string $action, array $payload = []):
?AuditLog`) can be bound in the container, e.g. to write to another store as well.

## Authorisation

With `policies.register`, `InvoicePolicy` and `IssuerPolicy` are registered for the
configured invoice and issuer models. The package does not check them itself: call them in
your controllers (`$this->authorize('issue', $invoice)`, `Gate::authorize()`, `@can`). Every
ability needs an authenticated user; it combines the state of the record with an optional gate
named `quittio.{ability}`. Without such a gate, every authenticated user is allowed.

| Policy ability | Allowed when | Gate |
|---|---|---|
| `viewAny` (invoice class) | always | `quittio.viewAny` |
| `view` | always | `quittio.view` |
| `createDraft` (invoice class) | always | `quittio.createDraft` |
| `updateDraft` | the invoice is a draft | `quittio.updateDraft` |
| `deleteDraft` | the invoice is a draft | `quittio.deleteDraft` |
| `issue` | a draft that is not a cancellation draft | `quittio.issue` |
| `cancel` (also for partial cancellations) | issued or partially cancelled, no cancellation | `quittio.cancel` |
| `correct` | issued (not partially cancelled), no cancellation | `quittio.correct` |
| `send` | issued (any status but draft) | `quittio.send` |
| `viewAuditLog` | always | `quittio.viewAuditLog` |
| `viewAny`, `view` (issuer) | always | `quittio.manageIssuers` |
| `manageIssuers` | always | `quittio.manageIssuers` |
| `manageSequences` | always | `quittio.manageSequences` |

```php
Gate::define('quittio.issue', fn (User $user, Invoice $invoice) => $user->isAccountant());
Gate::define('quittio.manageSequences', fn (User $user) => $user->isAdmin());

$user->can('createDraft', Managed::invoiceModel());
$user->can('manageSequences', Managed::issuerModel());
```

Gates receive the user and, for record abilities, the invoice or issuer (`manageIssuers` and
`manageSequences` receive the issuer or `null`).

## Immutability

Issued invoices are protected on three levels: the model (`ImmutableInvoiceGuard`), its query
builder (`InvoiceQuery`) and, recommended for production, database triggers. Violations
throw `InvoiceImmutable` (model and query builder) or a database error (triggers).

### What can still change

After issuing, the model only lets these columns change; everything else throws
`InvoiceImmutable` ("… is issued and immutable; these attributes cannot be changed: …"):

| Columns | Rule |
|---|---|
| `payment_status`, `paid_total`, `paid_at` | Free (see [Payment status](#payment-status)) |
| `delivery_status`, `sent_at`, `channel`, `lifecycle_status` | Free |
| `render_status`, `render_attempts` | Free |
| `legal_hold` | `true`, `false`, `1`, `0`, `"1"` or `"0"` only |
| `retention_until` | Can only be extended |
| `status` | Forward only, and only through `issue()`, `cancel()`, `cancelPartially()` and the issue of a correction |
| `cancelled_at`, `cancellation_reason` | Written once, together with the status `cancelled` |
| `cancellations` | Rises with every cancelling document, up to the number of their relations; the status becomes (partially) cancelled only together with such a rise |
| `document_version`, `disk`, `xml_path`, `pdf_path`, `xml_hash`, `pdf_hash`, `validation_status`, `validation_report_path` | Archiver only, together with a higher document version |
| `updated_at` | Free |

Columns you add to `quittio_invoices` yourself are frozen after issuing as well. Invoices
are inserted as drafts only. Drafts hold no number, number space, sequence key or sequence
value (`issue()` sets them) and keep their kind and the invoice they refer to
(`kind`, `preceding_invoice_id`). Issued invoices can only be deleted one by one after the
retention period (plus two days, see [Retention and deletion](#retention-and-deletion)) and
never under legal hold.

### Optimistic concurrency

A save or delete only applies while the row still has the status (and the other stored
values) the model was loaded with: a stale model, e.g. a draft that another process issued
meanwhile, or a model whose original values were changed, throws `InvoiceImmutable` ("… was
changed (e.g. issued or cancelled) by another process since it was loaded; load it again")
and changes nothing. Partly loaded models (`select('id')`) are checked against the stored
row. Load the invoice again in that case.

### Bulk queries

The query builder applies the same rules to bulk operations:

- bulk updates, touches and increments of frozen columns only reach drafts, also with
  `orWhere()` and with qualified column names (`quittio_invoices.status`) or other letter
  cases;
- on issued invoices, bulk updates may change payment, delivery and rendering columns, set
  `legal_hold` (`true`, `1` or `"1"`; other values than these and `false`, `0`, `"0"`
  throw) and extend `retention_until` (rows whose period would shrink are left out); the
  status never changes in bulk (it throws), and a legal hold is only released through the
  model;
- bulk updates and inserts cannot set `number`, `number_space`, `sequence_key`,
  `sequence_value`, `kind` or `preceding_invoice_id`;
- upserts may only change the bulk columns and insert drafts only; inserts create drafts
  only; bulk deletes, `forceDelete()` and `truncate()` throw if they would reach an issued
  invoice;
- `updateOrInsert()`, `updateFrom()`, `insertUsing()` and `insertOrIgnoreUsing()` are not
  available.

### Append-only tables

The audit log and the invoice relations are append-only in the same way: updates, upserts,
increments, touches and deletes through the model or its query builder throw; relations are
only deleted together with their invoice after its retention period. Number sequences only
change through the `Sequence` model (see [Managing sequences](#managing-sequences)).

### Database triggers

Only the base query builder (`toBase()`, `getQuery()`, `DB::table()`) and raw SQL bypass the
rules above. The database triggers, recommended for production, enforce them against direct
queries as well (MySQL/MariaDB, PostgreSQL, SQLite):

```bash
php artisan vendor:publish --tag=quittio-managed-triggers
php artisan migrate
```

- issued invoices: frozen columns, forward-only status that only becomes (partially)
  cancelled together with a rise of the `cancellations` counter, which cannot exceed the
  number of cancelling relations, write-once cancellation columns (only together with the
  status `cancelled`), a retention period that can only be extended, archive columns only
  with a higher document version, deletes only two days after the retention period in the
  database clock and never under legal hold;
- drafts: inserted and kept without number, number space and sequence value, fixed kind and
  original, issued only with a number, a number space and a retention period and without
  cancellation columns;
- the audit log: no updates, no deletes;
- the invoice relations: no updates, deletes only once their invoice is past its retention
  period;
- the number sequences: no lower counter, no new key or pattern, no deletes;
- PostgreSQL only: none of these tables can be truncated.

The triggers raise errors such as `quittio: issued invoice is immutable`, `quittio: audit log
is append-only`, `quittio: invoice relations are immutable` and `quittio: number sequences only
increase`. On MySQL with binary logging, creating triggers needs the `TRIGGER` privilege and
`log_bin_trust_function_creators` (or `SUPER`). Unlike the model, the triggers do not limit
releasing a legal hold or which code changes the status.

Frozen are all columns of an issued invoice but those of
[What can still change](#what-can-still-change). On PostgreSQL the trigger compares the whole
row without them, so columns you add to `quittio_invoices` later are frozen as well. On MySQL,
MariaDB and SQLite the trigger lists the columns that exist when it is created: recreate it
in the migration that adds columns, with `ImmutabilityTriggers::refresh()`
(`Quittio\Managed\Immutability`):

```php
Schema::table('quittio_invoices', function (Blueprint $table): void {
    $table->string('erp_reference')->nullable();
});
ImmutabilityTriggers::refresh();
```

`refresh()` recreates only the check of invoice updates, with the columns the table has now,
and returns `false` without doing anything when the triggers are not installed. On SQLite and
PostgreSQL it runs in one transaction; MySQL commits each statement, so between dropping and
creating the update trigger (milliseconds) direct updates are not checked: run it with the
migrations, not under load. On PostgreSQL it is not needed for new columns; it replaces the
trigger function, which switches triggers installed with an earlier version (a column list) to
the comparison of the whole row.

### What is not prevented

- MySQL fires no triggers on `TRUNCATE`: the foreign keys keep invoices and relations from
  being truncated, but the audit log and the sequences are not protected. Give the database
  user of the application no `DROP` privilege on the `quittio_` tables (`TRUNCATE` needs it)
  and run migrations with a separate user.
- Without the triggers, the base query builder and raw SQL can change anything.
- Issuer profiles are protected by their model only: change number spaces, tenants, keys and
  seller identifiers with model saves (`$issuer->save()`, `NumberSpaces::assign()`). Bulk
  updates of issuers through the query builder or raw SQL bypass the freeze and the
  consistency check; the unique index on number space and number still prevents duplicate
  numbers within a space.
- Deliveries are ordinary rows without protection.
- The archived files are protected by the archive (write-once) and the hashes; a disk without
  object lock cannot keep an administrator from deleting them, `quittio:verify` detects it.

## Retention and deletion

`retention_until` is set when the invoice is issued: the rule of the country pack (DE: 8 years
after the end of the year of issue, section 14b UStG, section 147 AO; without a pack 10 years),
counted from the later of the issue date and the day it is actually issued. `retention.years`
can only lengthen it. The period can be extended per invoice, and an invoice can be put on
legal hold:

```php
$invoice->forceFill(['retention_until' => '2040-12-31'])->save();   // only later dates
$invoice->forceFill(['legal_hold' => true])->save();                // also in bulk
$invoice->forceFill(['legal_hold' => false])->save();               // release: model only
```

The period ends with `retention_until` in the issuer's time zone; the command, the model and
the triggers compare with their own clock and count an invoice as past its period two days
later (`retain until 2034-12-31` → from 2035-01-03), when it has ended in every time zone (time
zones differ by up to 26 hours).

```bash
php artisan quittio:retention:check              # list, dispatch RetentionExpired
php artisan quittio:retention:check --delete     # delete, asks for confirmation
php artisan quittio:retention:check --delete --force
```

`quittio:retention:check` lists the issued invoices past their retention period that are not
on legal hold and dispatches `RetentionExpired` for each of them, before anything is
deleted. With `--delete`, it deletes invoices by group: an invoice together with all
invoices connected to it through cancellations, partial cancellations, corrections and
replacements (transitively), and only when every one of them is past its retention period
and none is on legal hold; otherwise the whole group is kept and the command says which
invoice keeps it. The rows of a group (invoices, relations, deliveries with recipients and
errors, and drafts that refer to one of the invoices) are deleted in one transaction with
audit entries (`deleted`, `deleted_draft`), then all archived files of all document versions
from the manifest. A file that cannot be deleted stays recorded: the next run reports it
(`… archived file(s) of deleted invoices could not be deleted yet`) and, with `--delete`,
tries again. With `--delete`, the command fails (exit code 1, `Not everything could be
deleted: …`) when a group or an archived file could not be deleted, after it has dealt with
the others; groups kept for a legal hold or a retention period are no failure. Schedule the
command (e.g. monthly) to keep the archive clean.

Delete issued invoices through this command: a single `$invoice->delete()` after the retention
period is allowed by the model, but fails on the foreign keys while relations or
cancellations refer to the invoice, and it leaves deliveries and archived files behind.

### Personal data

The audit log is append-only and stays after the retention period (GDPR Art. 17 and GoBD), so
it holds no recipients or other data of the customers. `sent` and `delivery_failed` refer to
the delivery (`delivery`: id of the row in `quittio_deliveries`), which is deleted with the
invoice; the invoice data itself is in the invoice row and the archived files. The audit log
keeps the causer, IP address and user agent of the users who acted, and the changes of issuer
profiles (the company's own master data). There is no function to anonymise issued invoices
before the end of their retention period.

## Events

All events are in the namespace `Quittio\Managed\Events` and have public readonly properties.

| Event | Dispatched | Properties |
|---|---|---|
| `InvoiceDrafted` | A draft was created (also drafts of cancellations and corrections), after the commit when it was created inside a transaction: the cancellation draft of a correction that rolls back is not announced | `invoice` |
| `InvoiceIssuing` | A draft is about to be issued: validated, no number yet; a listener that throws stops the issue. Not followed by `InvoiceIssued` when the issue fails afterwards | `invoice` |
| `InvoiceIssued` | After the issuing transaction was committed and the render job was queued (or the documents rendered) | `invoice` |
| `InvoiceDocumentsStored` | A new document version was rendered and archived | `invoice` |
| `InvoiceRenderFailed` | Rendering failed after the last attempt (with `render_sync`: after the first); the invoice stays issued | `invoice`, `exception` |
| `InvoiceCancelled` | After the commit: the original was cancelled completely (`cancel()`, the last partial cancellation, `cancel_and_reissue`) | `invoice` (original), `cancellation` |
| `InvoicePartiallyCancelled` | After the commit: parts of the original were cancelled | `invoice` (original), `cancellation` |
| `InvoiceCorrected` | After the commit: a correction of the original was issued | `invoice` (original), `correction` |
| `InvoiceSent` | The mail was sent | `invoice`, `delivery` |
| `InvoiceDeliveryFailed` | Sending failed (before the exception is rethrown) | `invoice`, `delivery`, `exception` |
| `ArchiveIntegrityViolation` | An archived file is missing or changed (archiving, `send()`, `quittio:verify`) | `invoice`, `path`, `problem` |
| `RetentionExpired` | `quittio:retention:check` found the invoice past its retention period and not on legal hold, before anything is deleted | `invoice` |

After a cancellation is committed, the order is: render job of the cancellation,
`InvoiceIssued` (of the cancellation), then `InvoiceCancelled` or `InvoicePartiallyCancelled`.
A listener of an after-commit event that throws does not undo anything: the exception reaches
the caller after all after-commit work has run.

```php
Event::listen(InvoiceIssued::class, fn (InvoiceIssued $e) => $e->invoice->billable?->notify(new InvoiceReady($e->invoice)));
Event::listen(ArchiveIntegrityViolation::class, fn ($e) => Log::critical('Archive', [$e->invoice->label(), $e->path, $e->problem]));
```

## Exceptions

All exceptions of the Managed layer implement `Quittio\Managed\Exceptions\ManagedException`,
which extends `Quittio\Core\Exceptions\QuittioException`.

| Exception | Base class | Thrown when |
|---|---|---|
| `InvalidInvoiceState` | `LogicException` | The action is not possible in the current state: issuing a cancellation draft directly, cancelling or correcting what cannot be (see the table in [Cancellations and corrections](#cancellations-and-corrections)), a date before the original, another company, a seller with another VAT id or tax number, sending a draft, an empty partial cancellation, a cancellation that raced with another one |
| `InvoiceNotDraft` | `LogicException` | `updateData()`, `preview()` or `issue()` on an issued invoice, or a draft issued or deleted by another process meanwhile |
| `InvoiceImmutable` | `LogicException` | A frozen column, status, retention, archive or cancellation column would change, an issued invoice would be deleted, a bulk operation would reach one, a stale model is saved, or the audit log or relations would change |
| `NothingToCancel` | `LogicException` | `cancel()` or `cancelPartially()` of an invoice that is cancelled completely |
| `NoRecipient` | `LogicException` | `send()` finds no e-mail address |
| `NumberingException` | `RuntimeException` | Invalid pattern, number already taken, a sequence lowered, changed or deleted, an issuer key used by sequences changed, a frozen number space or tenant changed, an invalid sequence key; `$e->retryable` is `true` for concurrent sequence changes, which are retried automatically |
| `ArchiveException` | `RuntimeException` | An archive file exists with other content, cannot be written, is missing or damaged, or an invoice has no archived documents |
| `InvalidIssuerProfile` | `InvalidArgumentException` | Branding template or logo the rendering would refuse; an invalid number space, one that would split a company, or a tenant lock that timed out |
| `IssuerNotFound` | `RuntimeException` | The default resolver finds no issuer marked as default and not exactly one issuer |

Other exceptions you may meet:

| Exception | When |
|---|---|
| `Quittio\Core\Validation\ValidationException` | `issue()` (also of cancellations and corrections) of an invalid invoice; `$e->result` |
| `Quittio\Core\Exceptions\ConfigurationException` | Invalid configuration: model classes, resolver, generator and recipient classes, Mailable, archive path, reset, start, `retention.years`, correction strategy, value types |
| `UnexpectedValueException` | Invalid partial cancellation selections, malformed relation JSON, an invoice not found or ambiguous in `quittio:render` |
| `InvalidArgumentException` | Partial cancellation of an invoice with document level allowances or charges, an unknown cancellation strategy (core); an amount with more than two decimals |
| `Illuminate\Database\QueryException` | Foreign keys (e.g. deleting an issuer with invoices) and the triggers |

```php
try {
    $invoice = $draft->issue();
} catch (ValidationException $e) {
    return back()->withErrors(array_map(fn ($m) => $m->message, $e->result->errors()));
} catch (ManagedException $e) {
    report($e);
}
```

## Models and enums

All models extend `Quittio\Managed\Models\Model`, which takes table prefix and connection from
the configuration.

### Invoice

| Column | Meaning |
|---|---|
| `id`, `uuid` | Key and UUID (generated) |
| `issuer_id` | Issuer |
| `billable_type`, `billable_id`, `invoiceable_type`, `invoiceable_id` | References to your models |
| `kind` | `Kind`: `invoice`, `cancellation`, `correction` |
| `type_code` | Type code (UNTDID 1001), e.g. `380`, `381`, `384` |
| `status`, `payment_status`, `delivery_status` | See [Statuses](#statuses) |
| `format`, `profile` | Draft: chosen (or `null` for the automatic choice); issued: used |
| `country_pack` | Country pack the invoice was issued under |
| `buyer_country`, `currency`, `locale` | From the data |
| `standard_version` | EN 16931 version of the data |
| `channel` | `mail` after a successful delivery |
| `lifecycle_status` | Reserved for network status, not set in 1.0 |
| `number`, `number_space`, `sequence_key`, `sequence_value` | Set when issued |
| `issue_date`, `due_date`, `service_date`, `service_period_start`, `service_period_end` | Dates (`CarbonImmutable`) |
| `net_total`, `tax_total`, `gross_total`, `prepaid_total`, `amount_due` | Totals, set when issued (strings with two decimals, e.g. `"2747.71"`) |
| `paid_total`, `paid_at` | Payments (default `"0.00"`) |
| `data`, `data_version` | Data snapshot and its version |
| `preceding_invoice_id` | Original of a cancellation or correction |
| `cancelled_at`, `cancellation_reason`, `cancellations` | See [Status](#status) |
| `render_status`, `render_attempts`, `document_version` | Rendering |
| `disk`, `xml_path`, `pdf_path`, `xml_hash`, `pdf_hash`, `validation_status`, `validation_report_path` | Archive |
| `retention_until`, `legal_hold` | Retention |
| `issued_at`, `sent_at` | Times of issue and last delivery |
| `issued_by_type`, `issued_by_id` | Authenticated user who issued the invoice |
| `created_by_type`, `created_by_id` | Authenticated user who created the draft (frozen once issued) |
| `created_at`, `updated_at` | Timestamps |

Columns are guarded: write them with `forceFill()`.

| Method | Description |
|---|---|
| `Invoice::draft()`, `Invoice::draftFor()` | New draft (see [Drafts](#drafts)) |
| `issuer()` | `BelongsTo` issuer |
| `billable()`, `invoiceable()` | `MorphTo` your models |
| `precedingInvoice()` | `BelongsTo` the original |
| `createdBy()`, `issuedBy()` | `MorphTo` the users who created the draft and issued the invoice |
| `relations()` | `HasMany` relations of this invoice to earlier ones |
| `inverseRelations()` | `HasMany` relations of later invoices to this one (its cancellations and corrections) |
| `deliveries()` | `MorphMany` delivery attempts |
| `auditLogs()` | `MorphMany` audit entries |
| `isDraft()`, `isIssued()` | Status checks (`isIssued()` is true for every status but draft) |
| `assertDraft()` | Throws `InvoiceNotDraft` unless draft |
| `label()` | The number, or `#id` for drafts |
| `updateData(callable $change)` | Changes the draft data |
| `preview()` | Draft PDF with watermark |
| `validate()` | Full validation without issuing |
| `issue($issueDate = null)` | Issues the draft |
| `cancel($reason = null, $issueDate = null)` | Full cancellation |
| `cancelPartially(array $lines, $reason = null, $issueDate = null)` | Partial cancellation |
| `correct()` | Correction draft |
| `send(?string $to = null, array $cc = [])` | Mail delivery |
| `toInvoiceData()` | The data (draft or frozen snapshot), upcast to the current snapshot version; the stored snapshot is never rewritten |

| Query method | Restricts to |
|---|---|
| `drafts()` | Drafts |
| `issued()` | Issued invoices, including (partially) cancelled ones |
| `open()` | Issued or partially cancelled, not a cancellation, unpaid or partially paid |
| `overdue(?CarbonImmutable $today = null)` | Open and due before today |
| `forBillable(Model $billable)` | Invoices of a billable |
| `ofKind(Kind $kind)` | One kind |
| `forIssuer(Issuer\|int $issuer)` | One issuer |

### Other models

| Model | Columns | Methods |
|---|---|---|
| `Issuer` | see [Columns](#columns) | see [The issuer builder](#the-issuer-builder) |
| `Sequence` | `key` (unique), `next_value`, `pattern_snapshot`, timestamps | – |
| `InvoiceRelation` | `invoice_id`, `related_invoice_id`, `type`, `amount`, `lines`, `vat`, `created_at` | `invoice()`, `related()`, `cancelledLines()`, `cancelledVat()` |
| `Delivery` | `deliverable_type`, `deliverable_id`, `channel`, `recipient`, `cc`, `attachments` (file names), `status`, `message_id`, `error`, `sent_at`, timestamps | `deliverable()` |
| `AuditLog` | `subject_type`, `subject_id`, `action`, `causer_type`, `causer_id`, `payload`, `ip`, `user_agent`, `created_at` | `subject()`, `causer()` |

### Enums

Namespace `Quittio\Managed\Enums`:

| Enum | Cases (value) | Methods |
|---|---|---|
| `Status` | `Draft` (`draft`), `Issued` (`issued`), `PartiallyCancelled` (`partially_cancelled`), `Cancelled` (`cancelled`) | `rank()`, `canMoveTo(Status $next)`, `isIssued()` |
| `Kind` | `Invoice` (`invoice`), `Cancellation` (`cancellation`), `Correction` (`correction`) | – |
| `PaymentStatus` | `Unpaid` (`unpaid`), `PartiallyPaid` (`partially_paid`), `Paid` (`paid`), `Overpaid` (`overpaid`), `WrittenOff` (`written_off`) | – |
| `DeliveryStatus` | `NotSent` (`not_sent`), `Sent` (`sent`), `Failed` (`failed`) | – |
| `DeliveryState` | `Queued` (`queued`), `Sent` (`sent`), `Failed` (`failed`) | – |
| `RenderStatus` | `Pending` (`pending`), `Rendering` (`rendering`), `Done` (`done`), `Failed` (`failed`) | – |
| `ValidationStatus` | `Valid` (`valid`), `Warnings` (`warnings`), `Invalid` (`invalid`), `NotChecked` (`not_checked`) | `ValidationStatus::of(ValidationResult $result)` |
| `RelationType` | `Cancels` (`cancels`), `PartiallyCancels` (`partially_cancels`), `Corrects` (`corrects`), `Replaces` (`replaces`) | `RelationType::cancelling()`: the values of the cancelling relations |

## Console commands

| Command | Purpose | See |
|---|---|---|
| `quittio:install --managed` | Publish configuration and migrations, migrate, create the first issuer | [Installation](#installation) |
| `quittio:render {invoice}` | Render missing or failed documents of a stored invoice as a new version | [Versions and re-rendering](#versions-and-re-rendering) |
| `quittio:verify {--from=} {--to=} {--issuer=}` | Check the archive hashes | [Integrity checks](#integrity-checks) |
| `quittio:sequence:show {--issuer=}` | List the number sequences | [Managing sequences](#managing-sequences) |
| `quittio:sequence:set {key} {value} {--force}` | Raise or create a number sequence | [Managing sequences](#managing-sequences) |
| `quittio:retention:check {--delete} {--force}` | List and delete invoices past their retention period | [Retention and deletion](#retention-and-deletion) |
| `quittio:drafts:prune {--older-than=60} {--force}` | Delete left-behind cancellation and correction drafts | [Left-behind drafts](#left-behind-drafts) |

The commands ignore the tenancy scope. See [Artisan commands](commands.md) for all signatures.
For example, scheduled in production:

```php
// routes/console.php
Schedule::command('quittio:verify')->daily();
Schedule::command('quittio:drafts:prune --force')->hourly();
Schedule::command('quittio:retention:check')->monthly();
```

## Testing

### Factories

```php
use Quittio\Core\Data\InvoiceData;
use Quittio\Managed\Models\Invoice;
use Quittio\Managed\Models\Issuer;

$issuer = Issuer::factory()->default()->create();                 // key default, Muster GmbH, DE123456789
$other = Issuer::factory()->company('Andere AG', 'DE111111111')->create(['key' => 'other']);

$draft = Invoice::factory()->for($issuer)->create();              // draft with two sample lines
$draft = Invoice::factory()->data(InvoiceData::factory()->reverseCharge()->make())->create();
$invoice = Invoice::factory()->create()->issue('2026-10-05');
```

| Factory | Definition and states |
|---|---|
| `Issuer::factory()` | Random key, Muster GmbH with a random VAT id (a company of its own), Germany, `Europe/Berlin`, one bank account, `defaults` locale `de` and 14 due days |
| `->default()` | Key `default`, `is_default`, VAT id `DE123456789` |
| `->company(string $name, string $vatId)` | Another company with its own name and VAT id |
| `Invoice::factory()` | Draft with `InvoiceData::factory()->withLines(2)` data and a new issuer |
| `->data(InvoiceData $data)` | Draft with the given data |

The factories make the configured models (`quittio-managed.models.invoice` and `.issuer`),
also through the factory of a subclass. Factory drafts are saved directly: they skip the
seller check, the issuer defaults and the audit entry `drafted`. Issue them with `issue()`; the seller is taken from the issuer then.

Useful in tests:

- `Queue::fake()` keeps the render job from running (render status stays `pending`);
  `config(['quittio-managed.queue.render_sync' => true])` renders at once.
- `Mail::fake()` for `send()`, a temporary local disk for `quittio-managed.disk`.
- Fake single events (`Event::fake([InvoiceIssued::class])`): faking all events also stops the
  model events that write the issuer and deletion audit entries.
- SQLite in memory works; set `'transaction_mode' => 'IMMEDIATE'` on the test connection to
  avoid the numbering warning.

See [Testing](testing.md) for `Quittio::fake()` and the e-invoice assertions.

### Testing against MySQL and PostgreSQL

The package's own test suite (in the repository of `quittio/managed`) runs against SQLite by
default and against MySQL and PostgreSQL from `tools/docker/databases`:

```bash
docker compose -f tools/docker/databases/compose.yaml up -d --wait
QUITTIO_DB=mysql vendor/bin/pest --testsuite=managed
QUITTIO_DB=pgsql QUITTIO_DB_TRIGGERS=1 vendor/bin/pest --testsuite=managed
```

`QUITTIO_DB` selects `sqlite` (default, in memory), `mysql` (port 33061) or `pgsql` (port
54321); `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD` override the
connection. `QUITTIO_DB_TRIGGERS=1` also installs the immutability triggers. The group
`concurrency` forks processes that issue invoices at the same time (needs `pcntl` and MySQL or
PostgreSQL); the group `official` validates the archived documents with the KoSIT validator
and is skipped when it is not installed.

## Known limitations

- Payments, bank reconciliation, dunning runs, subscriptions, advance and final invoices,
  self-billing, inbound invoices, Peppol transmission and DATEV exports are not part of 1.0;
  the payment columns are maintained by your application.
- Delivery is by mail only (`channel` `mail`); `lifecycle_status`, `registrations` and
  `network_ids` are stored but not evaluated.
- Partial cancellations of invoices with document level allowances or charges are not
  supported: cancel fully and issue a new invoice.
- Issuer profiles are protected by their model only; bulk updates of issuers bypass the
  number space checks.
- Without the triggers, the base query builder and raw SQL bypass the immutability rules;
  MySQL fires no triggers on `TRUNCATE`; on MySQL, MariaDB and SQLite the triggers cover the
  invoice columns that existed when they were created (see `ImmutabilityTriggers::refresh()`).
- Different notations of one tax number are not unified for the number spaces.
- Issued invoices can be deleted only after their retention period; there is no early
  anonymisation.
- The default cancellation and correction strategies and the one-cent VAT alignment of partial
  cancellations are to be confirmed by a tax adviser (see the legal review in the README).
