# Managed layer (`quittio/managed`)

> **Preview of an unreleased package.** `quittio/managed` is not released yet, and nothing on
> this page can be installed today. The page describes the package as it is planned to ship:
> commercially licensed and distributed separately from the open source packages. Names,
> configuration keys and behaviour may still change before the release.

The Managed layer stores your invoices in the database and handles what happens after you
build them: numbering, issuing, archiving, cancelling, correcting, sending, and deleting them
after the retention period. You need it when your Laravel application issues the invoices
itself and has to keep them. Read [Getting started](getting-started.md) first: the Managed
layer uses the same invoice builder. Terms such as e-invoice, format and profile are explained
in [E-invoicing basics](concepts.md).

## What Managed adds

The open source packages (`quittio/core`, `quittio/laravel` and a country pack) build,
calculate, validate and render an invoice. What happens with the result is up to you. The
Managed layer adds the records and rules around it:

| Task | Without Managed you … | With Managed … |
|---|---|---|
| Seller | set your company in the `seller` section of `config/quittio.php`, or pass `->seller()` per invoice | keep one or more issuer profiles in the database, each with bank accounts, defaults, texts, design and numbering ([Issuer profiles](#issuer-profiles)) |
| Customers | pass the buyer data for every invoice | still pass the buyer data, and every invoice also links to your own models, e.g. `$user->invoices()` ([Customers and references](#customers-and-references)) |
| Invoice numbers | set `->number('RE-2026-0042')` yourself and make sure numbers are unique and have no gaps | get the next number when you issue, from a pattern such as `RE-{Y}-{seq:4}`, gapless and unique per company ([Numbering](#numbering)) |
| Storing invoices | store the data, PDF and XML yourself | keep drafts and issued invoices as rows; drafts can be changed, previewed and deleted ([Drafts](#drafts)) |
| After issuing | have nothing that stops your code from changing an invoice | cannot change an issued invoice: the model, its query builder and optional database triggers block it ([Immutability](#immutability)) |
| Archive | save files with `$result->save()`, which overwrites existing files | get a write-once archive with SHA-256 hashes, document versions and an integrity check ([Rendering and archive](#rendering-and-archive)) |
| Cancellations and corrections | render a cancellation with `$result->cancel('ST-…')`, track what was cancelled and pass the history for partial cancellations | call `$invoice->cancel()`, `cancelPartially()`, `correct()` or `reducePrice()`; numbers, links, VAT and status are kept for you ([Cancellations and corrections](#cancellations-and-corrections), [Price reductions](#price-reductions)) |
| Sending | write your own Mailable | call `$invoice->send()`, with a log of every delivery attempt ([Delivery](#delivery)) |
| Audit and retention | keep your own records | get an append-only audit log, a retention period per invoice, a legal hold and a command that deletes expired invoices ([Audit log](#audit-log), [Retention and deletion](#retention-and-deletion)) |

The Managed layer uses only the public API of `quittio/laravel` and `quittio/core`:
everything it produces could also be produced by hand with the core.

### When to use it

Use only the open source packages when another system (an ERP, accounting software or a
shop) keeps the invoices and assigns their numbers, and Quittio only produces the files.

Use the Managed layer when your application is the system that issues the invoices. Then the
numbers must be unique and without gaps, an issued invoice must never change, and the
documents must be kept for the retention period. In Germany these rules come from the VAT Act
(UStG), the Fiscal Code (AO) and the [GoBD](concepts.md#german-terms), the German rules for
digital bookkeeping; invoices must be kept for 8 years.

With the Managed layer installed, `Quittio::invoice()` takes the seller from the current
issuer profile instead of `config/quittio.php` (see [The issuer builder](#the-issuer-builder)).
It therefore needs an issuer profile and throws `IssuerNotFound` without one (see
[Current issuer](#current-issuer)). The installer asks for the first profile.

Not part of version 1.0: recording payments (the payment columns exist, see
[Payment status](#payment-status)), bank reconciliation, dunning runs (automatic payment
reminders), subscriptions and recurring invoices, advance and final invoices,
[self-billing](#self-billing), inbound invoices, transmission over the
[Peppol network](concepts.md#formats) and exports for DATEV (German accounting software). See
[Known limitations](#known-limitations).

## Overview

The Managed layer works with these records:

| Concept | Model | Description |
|---|---|---|
| Issuer profile | `Issuer` | Your company as seller: master data, bank accounts, defaults, texts, branding, numbering |
| Draft | `Invoice` (status `draft`) | Freely changeable, no number, can be deleted |
| Issued invoice | `Invoice` (status `issued` …) | Number, data snapshot, dates and totals frozen; archived as XML and/or PDF |
| Cancellation, correction, price reduction | `Invoice` (kind `cancellation`, `correction`, `price_reduction`) | Invoices of their own, with their own numbers, that refer to the original |
| Relation | `InvoiceRelation` | Link from a cancellation, correction or price reduction to the original, with the cancelled lines and VAT |
| Sequence | `Sequence` | Counter of a number sequence (`{issuer}:{sequence}:{period}`) |
| Delivery | `Delivery` | One delivery attempt by mail |
| Audit entry | `AuditLog` | Append-only record of every business relevant action |

An invoice starts as a draft. `issue()` gives it a number and freezes it. After that, only
cancellations and corrections change its status:

```text
draft ──issue()──▶ issued ──cancelPartially()──▶ partially_cancelled ──▶ … ──▶ cancelled
                     └──────── cancel() or an issued correction ─────────────▶ cancelled
```

A price reduction does not change the status of the invoice it reduces (see
[Price reductions](#price-reductions)).

## Installation

These steps apply once `quittio/managed` is released.

```bash
composer require quittio/managed
php artisan quittio:install --managed
php artisan vendor:publish --tag=quittio-managed-triggers   # recommended for production
php artisan migrate
```

Requirements: PHP 8.3 or later, Laravel 13, and `quittio/core` and `quittio/laravel` 0.7.1 or
later (`^0.7.1`). Composer installs both from Packagist.

`quittio:install --managed` runs these steps (see also [Artisan commands](commands.md)):

1. It publishes `config/quittio.php` and `config/quittio-managed.php` (`--force` overwrites
   them).
2. It publishes the migrations, unless they were published before. It recognises them by
   their file name, because `vendor:publish` gives migrations a new timestamp each time.
3. It asks "Run the migrations now?" and runs `migrate --force`.
4. If no issuer profile exists, it asks for the first one: key (default `default`), company
   name (required), street and number, postcode, city, country (default `DE`), VAT id, tax
   number (asked only without a VAT id), e-mail address for invoices, IBAN, BIC (asked only
   with an IBAN) and time zone. The e-mail address is stored as contact e-mail and as
   electronic address with scheme `EM` (e-mail). The time zone defaults to `app.timezone`;
   when that is `UTC`, to `Europe/Berlin` for Germany and `UTC` for other countries. The
   profile is marked as default.

With `--no-interaction`, the migrations run without asking and no issuer is created. The
installer does not publish the immutability triggers: publish them with the third command
above (see [Database triggers](#database-triggers)).

The migrations use the database connection and table prefix of `config/quittio-managed.php`
(`connection`, `table_prefix`, see [Database settings](#database-settings)): set both before
migrating. To change them, answer "no" when the installer asks to run the migrations, edit
the configuration, run `php artisan migrate` and run the installer again for the issuer
profile.

The country pack of the issuer's country supplies the defaults for numbering, retention and
corrections (e.g. `quittio/country-de`, see [Country packs](country-packs.md)). For a country
without a pack, generic rules apply: pattern `INV-{Y}-{seq:4}`, yearly reset, retention 10
years after the end of the year, cancellation by credit note, correction by cancel and
reissue (see [Cancellations and corrections](#cancellations-and-corrections)).

### Databases

Supported: SQLite, MySQL 8 / MariaDB and PostgreSQL (the test matrix uses MySQL 8.4 and
PostgreSQL 16).

| Database | Notes |
|---|---|
| SQLite | Set `'transaction_mode' => 'IMMEDIATE'` and a `busy_timeout` (e.g. `5000`) on the connection in `config/database.php`. Without the immediate transaction mode, the numbering logs a warning once per process. Concurrent issuing is then retried, but this is not recommended for production. |
| MySQL, MariaDB | `number`, `number_space` and the archive paths use the collation `utf8mb4_bin` (case sensitive, as on the other databases). Creating the triggers with binary logging needs the `TRIGGER` privilege and `log_bin_trust_function_creators` (or `SUPER`). MySQL fires no triggers on `TRUNCATE` (see [What is not prevented](#what-is-not-prevented)). |
| PostgreSQL | The invoice data is stored as `jsonb`. The triggers also reject `TRUNCATE`. |

### Queue worker

Issued invoices are rendered by the job `RenderInvoiceDocuments` on the queue `quittio` (see
[Rendering and archive](#rendering-and-archive)). Run a worker that listens to it, e.g.
`php artisan queue:work --queue=quittio,default`, or set `queue.render_sync` to render in the
request.

### Morph map

Invoices, deliveries and the audit log keep references to your own models (`billable`,
`invoiceable`, `causer`, `tenant`, see [Customers and references](#customers-and-references))
for many years. Eloquent stores such polymorphic references with the class name, unless a
morph map gives the class a short name. Register a morph map for your models
(`Relation::enforceMorphMap()`), so that renaming a class does not break old references.

The package registers `quittio.issuer`, `quittio.invoice`, `quittio.sequence` and
`quittio.delivery` itself (for the configured model classes); `enforceMorphMap()` merges your
entries with these.

## A first invoice

This walkthrough creates an issuer profile, a draft, an issued invoice and its cancellation.
It assumes that the installation is done and a queue worker runs (or `queue.render_sync` is
set).

**1. Issuer profile.** The installer asks for the first one. To create it in code instead,
e.g. in a seeder:

```php
use Quittio\Core\Builder\Party;
use Quittio\Managed\Models\Issuer;

$issuer = new Issuer;
$issuer->setSellerParty(Party::make('Muster GmbH')
    ->vatId('DE123456789')
    ->address('Hauptstraße 1', '12345', 'Musterstadt', 'DE')
    ->electronicAddress('rechnung@muster.de', 'EM')
    ->buildSeller());
$issuer->forceFill([
    'key' => 'default',                                  // part of the sequence keys and archive paths
    'timezone' => 'Europe/Berlin',
    'bank_accounts' => [['iban' => 'DE02120300000000202051', 'bic' => 'BYLADEM1001', 'name' => 'Muster GmbH']],
    'defaults' => ['locale' => 'de', 'due_days' => 14],
    'is_default' => true,                                // Issuer::current() returns it
])->save();
```

**2. Draft.** Build the invoice as in [Getting started](getting-started.md), but pass the
builder to `Invoice::draft()` instead of calling `render()`. `Quittio::invoice()` starts with
the seller, bank account and defaults of the current issuer. Leave out `->number()` and
`->issueDate()`: `issue()` sets both.

```php
use Quittio\Core\Builder\Party;
use Quittio\Core\Enums\UnitCode;
use Quittio\Laravel\Facades\Quittio;
use Quittio\Managed\Models\Invoice;

$draft = Invoice::draft(Quittio::invoice()
    ->buyer(Party::make('Kunde AG')
        ->vatId('DE987654321')
        ->address('Bahnhofstraße 5', '54321', 'Beispielstadt', 'DE')
        ->electronicAddress('ap@kunde.de', 'EM'))
    ->deliveryDate('2026-09-30')
    ->line('Consulting', netPrice: '120.00', quantity: 8, unit: UnitCode::Hour));

$preview = $draft->preview();                // PDF with the watermark "ENTWURF" (draft), no number;
                                             // return it from a controller to show it in the browser
```

**3. Issue.** `issue()` validates the invoice, takes the next number and freezes the data.
The PDF and XML are rendered and archived by the queued job. If the validation fails, a
`ValidationException` is thrown and no number is used up.

```php
$invoice = $draft->issue();                  // today in the issuer's time zone
$invoice->number;                            // RE-2026-0001 (German default pattern)
```

**4. Send.** `send()` mails the archived documents. Without an address, it takes the buyer's
e-mail address, here `ap@kunde.de`.

```php
$invoice->send();
```

**5. Cancel.** An issued invoice never changes. To reverse it, issue a cancellation: an
invoice of its own, with its own number, that refers to the original.

```php
$cancellation = $invoice->cancel(reason: 'Charged twice');
$cancellation->number;                       // RE-2026-0002 (cancellations share the sequence by default)
$invoice->status;                            // Status::Cancelled
```

Next steps: link invoices to your customer model
([Customers and references](#customers-and-references)), choose a number pattern
([Numbering](#numbering)), cancel only some lines
([Partial cancellation](#partial-cancellation)) or correct an invoice
([Corrections](#corrections)).

## Issuer profiles

An issuer profile is the company that issues the invoices. Every invoice belongs to exactly
one issuer, and the issuer is always its seller. The profile replaces the `seller` section of
`config/quittio.php` and adds bank accounts, defaults, texts, design and numbering. Most
applications need one profile. Create more for several companies, brands or tenants.

```php
use Quittio\Core\Builder\Party;
use Quittio\Managed\Models\Issuer;

$issuer = new Issuer;
$issuer->setSellerParty(Party::make('Muster GmbH')
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
    // German texts on the PDF: "Thank you for your order.", "Payable within 14 days."
    'texts' => ['de' => ['intro' => 'Vielen Dank für Ihren Auftrag.', 'payment_terms' => 'Zahlbar innerhalb von 14 Tagen.']],
    'numbering' => ['pattern' => 'RE-{Y}-{seq:5}'],
    'is_default' => true,
])->save();
```

`setSellerParty()` stores the seller (`Party::make()` returns a `PartyBuilder`, see
[Building invoices](building-invoices.md)) and sets `country` from its address. The country
selects the country pack. The logo must exist when the profile is saved (see
[Branding check](#branding-check)). All columns are listed in [Columns](#columns).

### The issuer builder

`$issuer->builder()` returns an `InvoiceBuilder` with the issuer's data:

- the seller and the time zone,
- the first bank account (as credit transfer),
- the payment terms (text and `due_days`),
- locale and currency,
- the presentation (branding and texts),
- the small business flag (see [Small business](#small-business)).

`Quittio::invoice()` and the document methods (`Quittio::quote()`,
`Quittio::paymentReminder()` …) start with the current issuer in the same way. The Managed
layer binds `InvoiceDefaults` to `Issuer::current()->applyTo($builder)` (see
[Starting invoices](laravel.md#starting-invoices)). The first two drafts below are therefore
the same:

```php
use Quittio\Laravel\Facades\Quittio;
use Quittio\Managed\Models\Invoice;
use Quittio\Managed\Models\Issuer;

$draft = Invoice::draft(Quittio::invoice()->buyer($customer)->line('Consulting', netPrice: '120.00'));
$draft = Invoice::draft(Issuer::current()->builder()->buyer($customer)->line('Consulting', netPrice: '120.00'));

// another issuer than the current one: start from its builder and pass it to draft()
$draft = Invoice::draft($issuer->builder()->buyer($customer)->line('Consulting', netPrice: '120.00'), $issuer);
```

`->seller(...)` with another seller removes the issuer's values again (see
[Starting invoices](laravel.md#starting-invoices)). The Managed layer still checks that the
seller of a draft is its issuer.

The builder copies these values into the draft. Later changes of the issuer do not change
existing drafts, except the seller: it is taken from the profile again when the draft is
issued.

Further methods of `Issuer`:

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

### Defaults, texts and branding

The JSON columns `defaults`, `tax_settings`, `texts` and `branding` hold the settings of the
issuer:

| Setting | Meaning |
|---|---|
| `defaults.locale` | Locale of `builder()`, of the texts, the preview watermark and the mail |
| `defaults.currency` | Currency of `builder()` |
| `defaults.due_days` | Integer: payment terms in days; the due date is the issue date plus these days |
| `defaults.format` | Format of new drafts (`zugferd`, `xrechnung-cii`, `xrechnung-ubl`, `cii`, `ubl`, `peppol`, `pdf`, see [Formats](formats.md)); `null` or `auto` = automatic choice when the invoice is issued |
| `defaults.profile` | [Profile](concepts.md#profiles) of new drafts (e.g. `xrechnung`) |
| `tax_settings.small_business` | Small business that charges no VAT (in Germany a Kleinunternehmer under section 19 UStG, see [German terms](concepts.md#german-terms)). See [Small business](#small-business). |
| `texts.{locale}.intro`, `closing` | Intro and closing text of the PDF |
| `texts.{locale}.payment_terms` | Text of the payment terms |
| `texts.{locale}.footer_columns` | Footer columns, a list of lists of strings |
| `branding.template` | PDF template (see [Branding check](#branding-check)) |
| `branding.logo` | Logo: local file, data URI or public http(s) URL |
| `branding.colors` | `primary` (default `#2741d9`), `text` (`#15171c`), `muted` (`#5f6470`), `secondary` |
| `branding.font`, `sender_line`, `letter_layout` | Font, sender line above the address, letter layout (e.g. DIN 5008, the German standard for business letters) |
| `branding.payment_qr` | [Payment QR code](templates.md#payment-qr-codes) on the PDF: `auto` (default), `girocode` (the EPC QR code for SEPA credit transfers), `swiss-qr-bill` or `none` |

Texts are looked up for the locale, then under the key `default`, then the first entry. See
[Templates](templates.md) for what the presentation settings do in the PDF.

### Small business

With `tax_settings.small_business`, `builder()` sets the flag `Meta::FLAG_SMALL_BUSINESS`.
`Invoice::draft()` sets it also on data that was not built with `builder()`. The country pack
decides what follows. The German pack rejects invoices that charge VAT (rule DE-USTG-09, see
[Rules and validation](concepts.md#rules-and-validation)). It also rejects the tax exemption
of an [intra-community supply](concepts.md#german-terms) except for new vehicles
(DE-USTG-14). Such a draft cannot be issued. Give the lines the preset
`VatPreset::SmallBusiness` of the German pack (see [Country packs](country-packs.md)).

### Current issuer

`Issuer::current()` asks the resolver configured in `quittio-managed.issuer_resolver`. The
default resolver returns:

1. the issuer marked as default (`is_default`; the lowest id if several are),
2. otherwise the only issuer, if there is exactly one,
3. otherwise it throws `IssuerNotFound`.

`Invoice::draft()`, `HasInvoices` and the tenancy scope use the current issuer when none is
given. In an application with several companies, write a resolver that picks the issuer of
the logged-in user, e.g. by team:

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

A Closure (`'issuer_resolver' => fn () => …`) works as well. It must return an `Issuer`
(`ConfigurationException` otherwise). Laravel cannot cache a configuration that contains a
Closure (`config:cache`), so prefer a class in production.

### Tenancy scope

In a multi-tenant application, one installation serves several customers (tenants, e.g.
teams), each with its own issuer profiles. The tenancy scope restricts every invoice query to
the current issuer.

With `tenancy.scope`, the invoice model gets the global scope `Invoice::TENANCY_SCOPE`
(`'quittio.issuer'`). Every invoice query is then restricted to `issuer_id` of the current
issuer, including relations such as `$user->invoices()` and route model binding. Every such
query calls the resolver. Remove the scope where needed:

```php
Invoice::query()->withoutGlobalScope(Invoice::TENANCY_SCOPE)->count();
```

The jobs and commands of the package bypass the scope. It applies to invoices only, not to
issuers, sequences, deliveries or audit entries. It also changes the number spaces of issuers
without a tenant (see [Number spaces](#number-spaces)): decide on it before issuing invoices.

### Seller identity

The seller of an invoice is always its issuer:

- A draft whose seller has another VAT id or tax number than the issuer is rejected
  (`InvalidInvoiceState`).
- When a draft is issued, the seller (name, address, VAT id, tax number, legal information)
  is taken from the issuer profile as it is then. Payment instructions and terms stay as the
  draft sets them.
- Issued invoices keep their own snapshot, so later changes of the issuer never affect them.

Identifiers are compared in upper case and without spaces (also no-break and zero-width
spaces), dots, slashes and dashes.

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

### Branding check

`branding.template` and `branding.logo` are checked when the issuer is saved, with the rules
the rendering applies. Other values throw `InvalidIssuerProfile`. The reason: the logo becomes
part of the invoice data, and an issued invoice whose logo cannot be rendered can never be
archived.

The template is checked by the engine of the rendering and the preview
(`BladeTemplateEngine::fromSettings()`). It must be one of:

- `default` or the template configured in `quittio.pdf.template`,
- a view under `quittio::templates.` (no partials),
- a view of `quittio.pdf.templates` (`invoices.*` allows every view below the prefix),
- a plain PHP template of the core.

The logo must be one of:

- a readable local file of at most 10 MB, given as a path (not as a `file://` URI),
- a data URI (`data:image/…`) that decodes to at most 10 MB (base64 strictly),
- an http(s) URL of a public host. IP addresses must be public. Host names are resolved and
  checked when the logo is downloaded. Other spellings of addresses (`2130706433`,
  `localhost.`) are refused.

The value must have no surrounding whitespace, no backslashes and no leading `//`. An SVG logo
must be self-contained (no external references such as `href="http://…"` or `url(…)`, no
entity declarations). A raster logo may have at most 25 megapixels.

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

All columns except `id` are mass assignable (`Issuer::create([...])`, `fill()`). The JSON
columns accept arrays only.

## Customers and references

Every invoice can point to two of your own models. Both are optional and polymorphic, so any
Eloquent model works (user, team, company, order, subscription):

| Reference | Meaning | Example |
|---|---|---|
| `billable` | who is invoiced, the customer | `User`, `Team` |
| `invoiceable` | what is invoiced | `Order`, `Subscription` |

Prepare the customer model with the `HasInvoices` trait. `ProvidesParty` supplies the buyer
data. `HasInvoiceEmail` supplies the delivery address; it is optional, without it the `email`
attribute is used.

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

Register short names for these models in the morph map (see [Morph map](#morph-map)):

```php
// AppServiceProvider::boot()
Relation::enforceMorphMap(['user' => User::class, 'order' => Order::class]);
```

The trait adds three methods:

| Method | Returns |
|---|---|
| `invoices()` | `MorphMany` relation of all invoices, cancellations, corrections and price reductions of the model (drafts included) |
| `invoiceBuilder(?Issuer $issuer = null)` | `$issuer->builder()` (default: the current issuer) with the model as buyer when it implements `ProvidesParty` |
| `draftInvoice($data, ?Issuer $issuer = null, ?Model $invoiceable = null)` | A new draft with the model as billable |

`draftInvoice()` accepts:

- a callable: it receives the builder of `invoiceBuilder()`, so the model is already the buyer,
- a builder or `InvoiceData`: used as they are, including their buyer,
- an application model implementing `ProvidesInvoiceData`: used as it is; the model becomes
  the invoiceable unless `$invoiceable` is given.

```php
use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Enums\UnitCode;
use Quittio\Managed\Enums\Kind;

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

Without the trait, pass the references yourself. `forBillable()` queries without a relation
on the model:

```php
use Quittio\Managed\Models\Invoice;

$draft = Invoice::draft($builder, $issuer, billable: $user, invoiceable: $order);
$draft = Invoice::draftFor($order, billable: $order->user);   // $order becomes the invoiceable

Invoice::query()->forBillable($user)->open()->get();

$invoice->billable;                                  // the user, or null if it was deleted
$invoice->invoiceable;
```

Laravel Cashier's `Billable` trait also has an `invoices()` method (Stripe invoices) and
calls it internally. If you use both, keep Cashier's method and rename Quittio's relation:

```php
use Billable, HasInvoices {
    Billable::invoices insteadof HasInvoices;
    HasInvoices::invoices as quittioInvoices;
}

$user->quittioInvoices()->open()->get();
```

Rules worth knowing:

- The references only link records. What counts legally is the buyer copied into the
  invoice data when the draft was built. When the user changes the address later, issued
  invoices stay as they are (section 14 UStG, the German VAT Act, and the
  [GoBD](concepts.md#german-terms)).
- A draft keeps its buyer until you set it again:
  `$draft->updateData(fn (InvoiceBuilder $b) => $b->buyer($user))`.
- Cancellations, partial cancellations, corrections and price reductions take `billable` and
  `invoiceable` over from the original invoice.
- There is no foreign key to your models. Deleting a user leaves the invoices in place,
  because they must be kept for eight years (section 14b UStG and section 147 AO, the German
  Fiscal Code). `billable` then returns `null`. The buyer data in the archived files is
  unaffected.
- With `tenancy.scope`, the queries above are additionally restricted to the current issuer.

## Drafts

A draft is an invoice that is not issued yet. It has no number, can be changed freely and
can be deleted without leaving a gap in the numbers.

### Creating drafts

```php
use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Managed\Models\Invoice;

$draft = Invoice::draft($builder);                                    // current issuer
$draft = Invoice::draft($builder, $issuer, billable: $user, invoiceable: $order);
$draft = Invoice::draft($invoiceData, $issuer);                       // InvoiceData works too
$draft = Invoice::draftFor($order);                 // ProvidesInvoiceData, $order becomes the invoiceable
$draft = Invoice::draftFor($order, $issuer, billable: $order->user);
$draft = $user->draftInvoice(fn (InvoiceBuilder $b) => $b->line('Consulting', netPrice: '120.00'));   // HasInvoices
```

| Method | Parameters |
|---|---|
| `Invoice::draft()` | `InvoiceData\|InvoiceBuilder $data, ?Issuer $issuer = null, ?Model $billable = null, ?Model $invoiceable = null` |
| `Invoice::draftFor()` | `ProvidesInvoiceData $source, ?Issuer $issuer = null, ?Model $billable = null` |

Creating a draft:

1. takes the given issuer or the current one,
2. builds the data without calculating or validating it (`InvoiceBuilder::buildDraft()`;
   lines are not yet required),
3. rejects a seller with another VAT id or tax number than the issuer and a self-billed
   document (type code 389, 261 or 527, see [Self-billing](#self-billing)) with
   `InvalidInvoiceState`,
4. sets the small business flag of the issuer, the format and profile of
   `defaults.format` (`auto` = `null`) and `defaults.profile`, kind `invoice`, status `draft`
   and `created_by` (the authenticated user, if any),
5. stores the data without number and totals, and copies the type code (the document type,
   e.g. `380` invoice), currency, buyer country, due date, service date, service period and
   locale into columns for queries,
6. writes the audit entry `drafted` and dispatches `InvoiceDrafted` once the draft is
   committed (at once outside a transaction).

`$invoice->createdBy` and `$invoice->issuedBy` return the users who created the draft and
issued the invoice (`MorphTo`; `null` without an authenticated user, e.g. in queued jobs).

A draft holds no number, number space, sequence key or sequence value: `issue()` sets them.

### Changing drafts

```php
use Quittio\Core\Builder\InvoiceBuilder;

$draft->updateData(fn (InvoiceBuilder $b) => $b->line('Travel costs', netPrice: '80.00')->buyerReference('PO-4711'));

$draft->forceFill(['format' => 'xrechnung-cii', 'profile' => 'xrechnung'])->save();
$draft->billable()->associate($user)->save();
```

`updateData()` starts an `InvoiceBuilder` from the current data (`InvoiceBuilder::from()`),
passes it to the callable and stores the result. It throws `InvoiceNotDraft` for issued
invoices, and `InvalidInvoiceState` when the change makes the draft a self-billed document.

The other columns of a draft can be changed through the model. They are guarded, so use
`forceFill()`. Exceptions: `kind` and `preceding_invoice_id` are fixed when the draft is
created, and the number columns are set only by `issue()`. Unsaved changes of the model are
saved together with `issue()`. Draft changes are not written to the audit log.

A draft keeps no totals: the invoice is calculated again when it is issued. This also applies
to a draft started with `InvoiceBuilder::from()` on data another system rounded. Only the VAT
breakdown of an aligned partial cancellation is kept (see
[VAT of partial cancellations](#vat-of-partial-cancellations)).

### Preview and validation

```php
return $draft->preview();                 // Responsable: PDF in the browser
$draft->preview()->download('draft.pdf');
$result = $draft->validate();             // Quittio\Core\Validation\ValidationResult
$result->isValid();
```

`preview()` renders a PDF without validation:

- always in the format `pdf`,
- with the watermark of the translation `quittio-managed::invoice.draft_watermark`
  ("ENTWURF" in German, "DRAFT" in English), in the locale of the draft or of the issuer,
- without a number,
- dated today in the issuer's time zone.

It returns a `RenderedInvoice` of `quittio/laravel` (`pdf()`, `download()`, usable as response
or mail attachment). It throws `InvoiceNotDraft` for issued invoices.

`validate()` runs the full validation as if the draft were issued today with the next number.
The number comes from the number generator's preview; nothing is used up. It uses the format,
profile and locale of the draft. On an issued invoice it validates the frozen data with the
format, profile, locale and country pack it was issued with. See [Validation](validation.md)
for the result.

The draft of a correction with the strategy `corrected_invoice` is previewed and validated as
the corrected invoice it will be issued as (see [Corrections](#corrections)).

### Deleting drafts

```php
$draft->delete();                                         // audit entry deleted_draft
Invoice::query()->drafts()->where('created_at', '<', now()->subYear())->delete();
```

Deleting a draft leaves no gap in the numbers, since drafts hold none. A bulk delete:

- only removes drafts,
- throws `InvoiceImmutable` if the query would reach an issued invoice,
- writes no audit entries (it runs without model events).

Drafts of cancellations, corrections and price reductions that can no longer be issued are
removed by `quittio:drafts:prune` (see [Left-behind drafts](#left-behind-drafts)). Ordinary
drafts are never deleted automatically.

## Issuing

```php
$invoice = $draft->issue();                 // today in the issuer's time zone
$invoice = $draft->issue('2026-10-05');     // string, DateTimeInterface or Quittio\Core\Support\Date
```

`issue()` returns the same model, now issued. In short: it validates the invoice, takes the
next number inside a short transaction, freezes the data and queues the rendering. When
anything fails before the commit, the draft stays a draft and no number is used up.

The steps in detail:

1. The invoice must be a draft (`InvoiceNotDraft`). Drafts of cancellations are issued by
   `cancel()` and `cancelPartially()` only (`InvalidInvoiceState`). Drafts of corrections
   follow the correction strategy (see [Corrections](#corrections)). Drafts of price
   reductions record their relation to the original (see [Price reductions](#price-reductions)).
2. The issue date is the given date or today in the issuer's time zone. Cancellations,
   corrections and price reductions cannot be dated before the invoice they refer to. The
   issuer must still be the company that issued the original (same VAT id or tax number).
   Otherwise `InvalidInvoiceState` is thrown.
3. The seller is taken from the issuer profile as it is now, and the issue date is set. A
   self-billed document is refused with `InvalidInvoiceState` (see
   [Self-billing](#self-billing)). The number generator prepares the sequence (outside the
   transaction). The data is calculated and fully validated with the probable number, with
   the format, profile and locale of the draft.
   - A format that produces neither a PDF nor an XML invoice (`html`, `json`) is refused with
     `InvalidInvoiceState`, because the archive must hold the invoice itself.
   - Validation errors throw `Quittio\Core\Validation\ValidationException` (`$e->result`
     holds the messages). With `quittio.validation.block_on_warnings`, warnings do as well.
     No number is used up.
4. `InvoiceIssuing` is dispatched. A listener that throws stops the issue before a number is
   assigned.
5. A short transaction:
   - locks the draft (`SELECT … FOR UPDATE`) and checks again that it is a draft (another
     process may have issued or deleted it),
   - takes the next number,
   - reads the issuer with a shared lock and checks that no other issuer of the number space
     used the number,
   - freezes the invoice: number, number space, sequence key and value, status `issued`,
     `issued_at`, issue date, totals, the calculated data snapshot, standard version, the
     format and profile actually used, country pack, locale, render status `pending`,
     validation status, retention period and `issued_by` (the authenticated user, if any).
     Cancellation and archive columns are reset.
   - writes the audit entry `issued`.

   Deadlocks, serialisation failures and concurrent sequence changes are retried (three
   attempts).
6. After the commit, the documents are rendered by the job `RenderInvoiceDocuments` (or at
   once with `queue.render_sync`). Then `InvoiceIssued` is dispatched. If a listener throws,
   the exception reaches the caller, but the invoice is issued: the model shows the committed
   state and the job is queued.

The retention period (`retention_until`) follows the rule of the country pack (DE: 8 years
after the end of the year). It is counted from the year of issue, but never from a year
before the invoice is actually issued: a backdated invoice keeps the full period.

| Exception | When |
|---|---|
| `ValidationException` (core) | The calculated invoice is invalid (or has warnings with `block_on_warnings`) |
| `InvoiceNotDraft` | Not a draft, or issued or deleted by another process meanwhile |
| `InvalidInvoiceState` | Cancellation draft, date before the original, another company, issuer deleted, self-billed document, format without PDF or XML (`html`, `json`) |
| `NumberingException` | Invalid pattern, number already taken (also by another issuer of the number space) |
| `ConfigurationException` (core) | Reset not allowed by the country pack, `retention.years` too short, invalid generator |
| `InvoiceImmutable` | The draft row was changed by another process since it was loaded |

### Issuing inside your own transaction

`issue()` (and `cancel()`, `cancelPartially()` and issuing a correction or a price
reduction) can run inside a database transaction of your application on the Managed
connection. Then:

- there are no retries: your transaction decides,
- the render job and the events `InvoiceIssued`, `InvoiceCancelled`, `PriceReduced` … run
  only when your transaction commits, and an exception of a listener reaches the code that
  commits,
- a rollback leaves the draft a draft and uses up no number. The model in memory still shows
  the issued state then, so load it again.

## Statuses

Status, payment, delivery, rendering and validation are separate columns. Their enums are
listed in [Enums](#enums).

### Status

`status` moves only forward: `draft` → `issued` → `partially_cancelled` → `cancelled`. It
changes only through `issue()`, `cancel()`, `cancelPartially()` and the issue of a
correction, never through `save()` or bulk updates (see [Immutability](#immutability)).

- `issued`: issued and not cancelled. Cancellations, corrections and price reductions are
  themselves `issued`.
- `partially_cancelled`: at least one partial cancellation refers to the invoice.
- `cancelled`: cancelled completely, by `cancel()`, by partial cancellations that together
  cover everything, or by an issued correction. `cancelled_at` and `cancellation_reason`
  are set then, once.

`cancellations` counts the documents that cancel (part of) the invoice (relations `cancels`,
`partially_cancels`, `replaces`). A price reduction (relation `reduces`) changes neither the
status nor this counter.

### Payment status

The package does not record payments in version 1.0 (planned from version 1.1): every issued
invoice stays `unpaid` until you change it. The payment columns stay changeable after
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
use Carbon\CarbonImmutable;
use Quittio\Managed\Models\Invoice;

Invoice::query()->open()->get();        // issued or partially cancelled, no cancellation or price reduction, unpaid or partially paid
Invoice::query()->overdue()->get();     // open and due_date before today
Invoice::query()->overdue(CarbonImmutable::parse('2026-12-31'))->get();
```

`overdue()` compares with today in the application's time zone, or with the given date.
`open()` leaves out cancellations and price reductions, which the buyer does not pay. It
includes corrections and credit notes of kind `invoice`; filter by `type_code` if you need to
tell them apart.

### Delivery status

`delivery_status` of the invoice:

- `not_sent`,
- `sent` after a successful `send()`,
- `failed` after a failed attempt (a failed attempt after a successful one keeps `sent`).

Every attempt is a row in `quittio_deliveries` with its own state `queued`, `sent` or
`failed` (see [Delivery](#delivery)).

### Render and validation status

`render_status`: `null` for drafts, `pending` after issuing, `rendering` while the documents
are rendered, `done` once they are archived, `failed` after the last attempt failed.
`render_attempts` counts the attempts.

`validation_status`: `not_checked` for drafts, then `valid`, `warnings` or `invalid`. It is
set from the validation when the invoice is issued. It is replaced by the validation of the
archived rendering, whose report is stored with the documents.

## Numbering

An invoice gets its number when it is issued, from a pattern and a counter (sequence). With
the German defaults, the invoices of 2026 are numbered `RE-2026-0001`, `RE-2026-0002` …, and
the counter starts again in 2027. Cancellations, corrections and price reductions take the
next number of the same sequence, unless you give them their own pattern.

Change the scheme in `config/quittio-managed.php`. This example keeps the German pattern and
gives cancellations their own sequence (`ST-2026-0001` …):

```php
// config/quittio-managed.php
'numbering' => [
    'pattern' => 'RE-{Y}-{seq:4}',          // null = country pack (DE: RE-{Y}-{seq:4})
    'reset' => 'yearly',                    // never | yearly | monthly, within the country pack's rules
    'start' => 1,                           // first value of every new sequence (and period)
    'per_kind' => [
        'cancellation' => 'ST-{Y}-{seq:4}', // own sequence, null = shared
        'correction' => null,
        'price_reduction' => null,          // e.g. 'GS-{Y}-{seq:4}'
    ],
    'generator' => null,                    // class implementing NumberGenerator
],
```

An issuer can override `pattern`, `reset`, `start` and `per_kind` in its `numbering` column.
Each setting is taken from the issuer, then from the configuration, then from the country
pack:

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
| `{customer}` | Buyer identifier (BT-46, see [Business terms](concepts.md#en-16931-and-business-terms)), empty without one |

Patterns are checked when they are used (issuing, `validate()`, `quittio:sequence:set`). They
throw a `NumberingException` ("Invalid numbering pattern …") when:

- they contain no `{seq}` / `{seq:N}`, or more than one,
- they contain an unknown placeholder,
- the reset is `yearly` without `{Y}` or `{y}`, or `monthly` without a year and `{m}`
  (numbers would repeat),
- an own sequence per kind could produce the numbers of another sequence of the issuer.
  `RE-{Y}-{seq}` next to `RE-{Y}-{seq:4}` is rejected, and so is `RE{Y}-{seq:2}7` next to
  `RE{Y}-7{seq:2}` (both give `RE2026-707`); `ST-{Y}-{seq:4}` is fine. The check compares
  the patterns exactly; `{customer}` counts as any text.

A reset the country pack does not allow, or a `start` below 1, throws a
`ConfigurationException`.

### Sequences and resets

Every number comes from a sequence with the key `{issuer}:{sequence}:{period}`, e.g.
`default:invoice:2026`:

- `{issuer}` is the issuer key,
- `{sequence}` is `invoice` (shared by all kinds), or `cancellation`, `correction` or
  `price_reduction` when `per_kind` gives that kind a pattern of its own,
- `{period}` follows from the reset and the issue date: `2026` (yearly), `2026-10` (monthly)
  or `all` (never).

A backdated invoice uses the sequence of its period: an invoice dated 2026-12-30 and issued
in January 2027 gets the next number of `default:invoice:2026`. Resets apply to all
sequences of an issuer alike.

`start` is the first value of every new sequence, i.e. also of every new period. To continue
the numbers of an old system once, raise the sequence with `quittio:sequence:set` instead
(see [Managing sequences](#managing-sequences)).

### Gapless numbers

Numbers are assigned inside the issuing transaction only. A draft has none, validation errors
and failed cancellations use none, and a rolled back transaction gives its number back.
Deleting a draft therefore never leaves a gap. The unique indexes `(number_space, number)`,
`(issuer_id, number)` and `(sequence_key, sequence_value)` are the last line of defence.

How the default generator (`SequenceNumberGenerator`) serialises concurrent issues:

1. Before the transaction, a missing sequence row is created with `INSERT … OR IGNORE`, so
   that concurrent first invoices of a period do not deadlock (MySQL).
2. Inside the transaction, the row is locked (`SELECT … FOR UPDATE`), the number is formed
   and the counter is raised with a conditional update. If another process changed it in
   between (e.g. SQLite without an immediate transaction), the attempt fails and the
   transaction is retried.
3. Deadlocks, lock wait timeouts, serialisation failures (PostgreSQL `40001`, `40P01`) and
   `database is locked` (SQLite) are retried up to three attempts with a short random pause.

For SQLite in production, set `'transaction_mode' => 'IMMEDIATE'` and a `busy_timeout` on
the connection. The numbering logs a warning once per process when the mode is missing.

### Number spaces

Invoice numbers must be unique per company (section 14 (4) no. 4 UStG, the German VAT Act).
A company can have several issuer profiles, e.g. one per brand. The number space
(`number_space`) groups the profiles of one company: they share their numbers, so a number
that one of them used is taken for the others.

Issued invoices store the number space of their issuer. The unique index
`(number_space, number)` (and `(issuer_id, number)` as a safeguard) holds even when two
issuers of the company issue at the same moment.

- **Default.** When the issuer is created, its number space is `vat:{VAT id}`, otherwise
  `tax:{tax number}`, otherwise `issuer:{key}`. The identifiers are normalised: upper case,
  without spaces (also no-break and zero-width spaces), dots, slashes and dashes. Issuers with
  the same VAT id thus share a number space by default. Until the issuer issues its first
  invoice, a default follows changes of its VAT id, tax number or key; an explicit value
  stays.
- **Explicit.** For several brands of one company, give them the same space:
  `$brand->forceFill(['number_space' => $main->number_space])->save()`. Any name of your own
  works as well: at most 128 characters, no `|`, no control characters, no surrounding
  whitespace (`InvalidIssuerProfile` otherwise).
- **One company, one space.** Within a tenant, issuers that share a VAT id or a tax number
  must have the same number space. Saving an issuer that would split a company throws
  `InvalidIssuerProfile` and names the other issuer. Examples: a brand with only the tax
  number of the main profile, or a tax number added later to a profile whose other brands use
  another space.
  - Sharing is transitive: A with VAT id and tax number, B with the tax number and C with the
    VAT id share one space.
  - To change the space of issuers that share an identifier, change them together:
    `NumberSpaces::assign([$a, $b], 'muster')` (`Quittio\Managed\Support\NumberSpaces`).
  - Saves of issuers of one tenant are serialised (an advisory lock on PostgreSQL,
    `GET_LOCK()` on MySQL with a timeout of 10 seconds, after which `InvalidIssuerProfile`
    asks to try again). So concurrent saves cannot split a company either.
  - The check also runs for saves without model events (`saveQuietly()`, `Event::fake()`).
- **Notations are not unified.** A German tax number in the 13-digit format of ELSTER (the
  German tax authorities' electronic filing system) and in the regional format
  (`12/345/67890`) are different values. If the profiles of one company use different
  notations, or one has only the VAT id and another only the tax number, set the same
  `number_space` explicitly.
- **Tenants.** Number spaces apply within the tenant of the issuer (`tenant_type`,
  `tenant_id`). The invoices store them qualified by the tenant, e.g. `team:7|vat:DE123456789`
  for `tenant_type` `team` and `tenant_id` 7 (`NumberSpaces::qualified($issuer)`). So the same
  VAT id or space name in another tenant never blocks a number, and errors never name issuers
  of other tenants. With `tenancy.scope`, an issuer without a tenant is a tenant of its own
  (`issuer:{id}|…`; give the issuers of one company the same tenant). Without it, the issuers
  without a tenant form one tenant. Decide on `tenancy.scope` before issuing invoices: it
  changes the number spaces of issuers without a tenant.
- **Frozen.** Once an issuer has issued invoices, its number space and its tenant cannot
  change (`NumberingException`). Its VAT id and tax number still can, within the rule above.

Issuers that share a number space and use the same pattern compete for the same numbers: the
second one cannot use a number the first one took (`NumberingException` naming the other
issuer). Give such issuers different patterns, e.g. with `{issuer}`, also for the sequences
per kind (`numbering.per_kind` per issuer).

### Managing sequences

```bash
php artisan quittio:sequence:show                               # all sequences
php artisan quittio:sequence:show --issuer=default
php artisan quittio:sequence:set default:invoice:2026 1043      # only increases, asks
php artisan quittio:sequence:set default:invoice:2027 1 --force # creates a sequence ahead of time
```

`quittio:sequence:show` lists key, next value and pattern.

`quittio:sequence:set` sets the next value of a sequence: a positive integer above the
current one, after a confirmation unless `--force`. It only creates sequences the numbering
would use:

- the issuer must exist,
- the sequence must be `invoice` or a kind with its own pattern,
- the period must match the reset (`2026`, `2026-10` or `all`).

The pattern comes from the configuration at that moment. Sequences are created automatically
when the first invoice of a period is issued.

The `Sequence` model never lowers a counter (also not from a stale model) and writes every
change to the audit log (`sequence_changed`). Key and pattern never change, and sequences
cannot be deleted. Bulk updates, increments, touches, upserts, deletes and
`updateOrInsert()` of these columns throw `NumberingException`. Change a counter through the
model only:

```php
use Quittio\Managed\Managed;

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

An own generator is responsible for unique and gapless numbers. The unique index
`(number_space, number)` (and `(issuer_id, number)`) remains as a safeguard: a number already
used in the number space throws `NumberingException` ("The invoice number … is already
taken"). `IssuedNumber` takes `string $number, ?string $sequenceKey = null,
?int $sequenceValue = null`. To change only the format, extend `SequenceNumberGenerator` and
override its protected methods `scheme()` or `format()`.

## Rendering and archive

Issuing only stores data. The documents (PDF and/or XML) are rendered afterwards by a queued
job and stored in the archive. The archive is write-once: a file is never overwritten. For
every file, a SHA-256 hash (a fingerprint that changes when the file changes) is stored with
the invoice, so that changed or missing files are detected.

### Render job

After the issuing transaction has been committed, the job
`Quittio\Managed\Rendering\RenderInvoiceDocuments` renders and archives the documents of the
invoice. It runs on `queue.connection` and `queue.queue` (default queue `quittio`).

It is tried `count(queue.backoff) + 1` times, by default again after 10 s, 1 min, 5 min and
30 min. After the last attempt, the invoice is marked `failed`, the audit entry
`render_failed` is written and `InvoiceRenderFailed` is dispatched. The invoice stays issued.

With `queue.render_sync`, the documents are rendered in the same request, right after the
commit and before `InvoiceIssued`. A failure is logged as an error and marks the invoice
`failed` at once (no retries). It never undoes the issue.

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

`{issuer}` and `{number}` are made safe as single path segments: characters other than
letters, digits, `.`, `_` and `-` become `_`, so `RE/2026/1` becomes `RE_2026_1`. The pattern
must contain `{version}` and `{number}` or `{uuid}`, because every version and every invoice
needs a directory of its own. Otherwise archiving throws a `ConfigurationException`.

The file names follow `quittio.pdf.filename` (default `{title}_{number}.{ext}`), e.g.
`default/2026/10/RE-2026-0042/v1/Rechnung_RE-2026-0042.pdf` ("Rechnung" is the German title,
invoice).

| Format | Archived files |
|---|---|
| `zugferd` (`factur-x`) | PDF/A-3 and the embedded XML as its own file (byte-identical) |
| `xrechnung-cii`, `xrechnung-ubl`, `cii`, `ubl`, `peppol` | XML and its readable PDF (see [Readable PDF of XML-only e-invoices](#readable-pdf-of-xml-only-e-invoices)) |
| `pdf` | PDF |

See [Formats](concepts.md#formats) for what these formats are. Every version also holds
`validation-report.json`, the validation result of the rendering.

The invoice keeps `disk`, `xml_path`, `pdf_path`, `visualization_path`, the SHA-256 hashes
`xml_hash`, `pdf_hash` and `visualization_hash`, `validation_report_path` and
`document_version`. Archiving writes the audit entry `rendered` (version, disk, files,
report, hashes) and dispatches `InvoiceDocumentsStored`.

For production, use an object store with object lock (WORM: write once, read many) and
versioning, so that nobody can delete or replace the files.

### Readable PDF of XML-only e-invoices

An XML-only e-invoice (`xrechnung-cii`, `xrechnung-ubl`, `cii`, `ubl` or `peppol`) cannot be
read without a viewer. The archive therefore also keeps a readable PDF of it, when its profile
complies with EN 16931 (not MINIMUM or BASIC WL). The PDF is rendered in the same run as the
XML, from the same data, and stored in the same version directory. Its path and SHA-256 hash
are kept in `visualization_path` and `visualization_hash`.

Only the XML is the invoice. The PDF says so in its legal notes: "Lesbare Darstellung der
E-Rechnung (XRechnung 3.0) Nr. RE-2026-0042 – inhaltsgleiches Mehrstück. Maßgeblich ist
ausschließlich die XML-Datei." (readable copy of the e-invoice, a copy with the same content;
only the XML file is authoritative; label `einvoice_visualization`, see
[Examples: Public sector](examples.md#public-sector)). The Managed layer keeps the PDF together
with the XML, as an additional copy of the invoice.

The readable PDF is treated like the other archived files:

- Archiving, `send()` and `quittio:verify` check its hash. A missing copy leads to a new
  document version. A changed copy dispatches `ArchiveIntegrityViolation` and throws an
  `ArchiveException`, as a changed XML does.
- `quittio:retention:check --delete` deletes it with the other files.
- It is PDF/A-3b when `pdf.pdfa` in `config/quittio.php` is `true` (the default).

No readable PDF is archived:

- for ZUGFeRD and `pdf`, whose PDF is the invoice itself,
- with `visualization` set to `false` in `config/quittio-managed.php`,
- with `format_resolver.b2c_embed_xml` set to `true` in `config/quittio.php`, because the PDF
  would embed a second XML.

`send()` attaches the readable PDF with `delivery.mail.xrechnung_visualization` (see
[Delivery](#delivery)).

### Versions and re-rendering

Files are never overwritten: writing a file that exists with other content throws an
`ArchiveException` (identical content is accepted). Archiving an invoice again:

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
`ArchiveException` and fails. A number that exists for several issuers must be given as UUID.
Drafts cannot be rendered.

### Archive manifest

Every archive file is recorded in `quittio_archive_files` (invoice id, disk, path) before it
is written. The manifest has two purposes:

- `quittio:retention:check` can delete the files of all versions, also those of a rendering
  that failed halfway, and retry files it could not delete.
- Two invoices with the same archive path cannot write into the same directory.

The rows have no foreign key: they outlive their invoice until the files are gone.

### Integrity checks

```bash
php artisan quittio:verify                                   # all issued invoices
php artisan quittio:verify --from=2026-01-01 --to=2026-12-31 --issuer=default
```

`quittio:verify` checks the SHA-256 hashes of the current version of every issued invoice
(issue date from `--from` to `--to`, optionally of one issuer key). It also reports issued
invoices whose documents were never archived (render status `pending`, `rendering` or
`failed`).

It dispatches `ArchiveIntegrityViolation` for every problem, writes the audit entry
`verified` (checked, violations, from, to), prints a table and exits with a failure code if
it found problems. `send()` checks the hashes before every delivery as well.

`ArchiveIntegrityViolation` carries the invoice, the path and the problem: `missing`,
`hash mismatch` or (from `quittio:verify`) `not archived (render status: …)`. The path is `-`
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

Bind it in a service provider (see [Own services](#own-services)).

## Cancellations and corrections

An issued invoice is never changed. To reverse or fix it, you issue a new document that
refers to it:

- `cancel()` reverses the whole invoice (or what is left of it), e.g. when it was charged
  twice. The result is a cancellation invoice, in Germany titled "Stornorechnung".
- `cancelPartially()` reverses some lines, quantities or amounts, e.g. a returned item.
- `correct()` starts a draft with the data of the original. You change it and issue it as the
  corrected invoice.

When the invoice was correct and only the consideration changed later (a cash discount taken,
a reduction after a complaint about defects, a bonus), issue a price reduction instead (see
[Price reductions](#price-reductions)).

```php
use Quittio\Core\Builder\InvoiceBuilder;

$cancellation = $invoice->cancel(reason: 'Charged twice');
$cancellation = $invoice->cancel('Charged twice', '2026-10-06');   // with issue date

$cancellation = $invoice->cancelPartially([
    ['line' => '3'],                       // whole line (or what is left of it)
    ['line' => '1', 'quantity' => 1],      // 1 of 3
    ['line' => '2', 'amount' => '50.00'],  // amount, e.g. a price reduction
], reason: 'Partial return');

$draft = $invoice->correct();
$draft->updateData(fn (InvoiceBuilder $b) => $b->updateLine('2', netPrice: '80.00'));
$draft->issue();
```

The original only gets a new status. Every cancellation and correction is an invoice of its
own with its own number. It is built from the snapshot of the original,
in the same format, profile and locale, with the same `billable` and `invoiceable`. It refers
to the original (preceding invoice reference, BG-3, and `preceding_invoice_id`).

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
| kind `price_reduction` | yes | yes | no (cancel it and reduce again) |
| invoice with price reductions | no (cancel the price reductions first) | yes, within what the price reductions leave | no (cancel the price reductions first) |
| self-billed document (389, 261, 527) | no | no | no |
| document level allowances or charges | yes | no (`InvalidArgumentException`) | yes |

The "no" cases throw `InvalidInvoiceState` unless another exception is named. See
[Price reductions](#price-reductions) and [Self-billing](#self-billing).

### Full cancellation

`cancel()`:

1. checks the original (see the table above: `InvalidInvoiceState` or `NothingToCancel`) and
   that the issuer is still the company that issued it,
2. builds the cancellation with the core (see
   [Getting started](getting-started.md#cancellation)): for an invoice that was partially
   cancelled before, only what is left,
3. creates a draft of kind `cancellation` and issues it with its own number (the steps of
   [Issuing](#issuing)). Inside the issuing transaction:
   - the original is locked and read again, and the remainder (and the limit of its price
     reductions) is checked again,
   - the relation `cancels` is recorded,
   - the original becomes `cancelled` (with `cancelled_at`, `cancellation_reason` and a
     raised `cancellations` counter),
   - the audit entry `cancelled` is written on the original,
4. dispatches `InvoiceCancelled` after the commit.

If the cancellation cannot be issued (e.g. because it is invalid), its draft is deleted again
(audit entry `deleted_draft`), the original model shows its stored state again and no number
is used up.

The cancellation document:

- has the type code of the sign strategy (see
  [Sign strategies and credit notes](#sign-strategies-and-credit-notes)) and the title of the
  country pack (DE: "Stornorechnung", cancellation invoice),
- states in a note which invoice it cancels and the reason, e.g. "Stornierung der Rechnung
  RE-2026-0042 vom 05.10.2026. Grund: Charged twice" (Cancellation of invoice RE-2026-0042 of
  05.10.2026. Reason: Charged twice),
- keeps the regulatory and tax notes of the original (invoice notes, BT-22, with the subject
  codes `REG` or `TXD`),
- keeps, when it cancels everything that is left, the paid amount (BT-113) and the rounding
  amount (BT-114), so that all cancellations together reverse exactly the amount due,
- cannot be dated before the original.

### Partial cancellation

`cancelPartially()` takes a list of line selections:

| Selection | Cancels |
|---|---|
| `['line' => '3']` | The whole line, or what is left of it |
| `['line' => '1', 'quantity' => 1]` | A quantity (int or numeric string) |
| `['line' => '2', 'amount' => '50.00']` | An amount (string): the net amount; for a line with a price including VAT, the amount including VAT |

`line` is the line id (BT-126, `InvoiceLine::$id`). The builder numbers lines `'1'`, `'2'`, …
unless you set ids. The rules:

- Cancelled quantities and amounts are tracked per line. More than invoiced cannot be
  cancelled, also not by processes that cancel at the same time.
- Quantities and amounts are magnitudes: negative lines (discounts, returns) are reversed with
  their sign.
- After an amount cancellation, the rest of a line can only be cancelled by amount.
- The last part of a line cancels exactly its remaining net amount (as an amount), so the net
  amounts of all cancellations add up exactly to the original. A line whose net amount is
  cancelled completely is closed, even if rounding left a quantity.
- Lines with a price including VAT (gross price mode) are tracked and cancelled by their
  amount including VAT. The last part cancels exactly the remaining gross amount, so the
  amounts due add up exactly.
- Partial cancellations are not possible for invoices with document level allowances or
  charges (`InvalidArgumentException` of the core): cancel the invoice fully and issue a new
  one.

The original becomes `partially_cancelled` (relation `partially_cancels`, audit entry
`partially_cancelled`, event `InvoicePartiallyCancelled`). Once the partial cancellations
cover everything, it becomes `cancelled` instead, with `cancelled_at` and the reason of the
last part, the audit entry `cancelled` and the event `InvoiceCancelled`. `cancel()` on a
partially cancelled invoice cancels the remainder.

| Error | Exception |
|---|---|
| Empty list | `InvalidInvoiceState` |
| Unknown line, line already cancelled completely, more than is left, a quantity after an amount cancellation | `UnexpectedValueException` (message names the line and what is left) |
| Document level allowances or charges | `InvalidArgumentException` |
| Quantity or amount given as float (`2.5` instead of `'2.5'`) | `InvalidArgumentException` |
| Another partial cancellation was issued meanwhile | `InvalidInvoiceState` ("… partially cancelled in the meantime; try again"), no number used up |
| Together with the price reductions, more than the net amount of a VAT group would be credited | `InvalidInvoiceState` (see [Price reductions](#price-reductions)) |

### VAT of partial cancellations

VAT is calculated per document and VAT group (category and rate) and rounded there (EN 16931
rule BR-CO-17, see [Rules and validation](concepts.md#rules-and-validation)). If every partial
cancellation rounded its VAT on its own, all parts together could differ from the original by
a cent or more. A partial cancellation therefore rounds its VAT cumulatively, together with
the earlier partial cancellations of the same original:

- Per VAT group, the target is round2(taxable amount of all cancellations so far × rate).
  Once all lines of the group are cancelled, the target is the original's VAT amount
  (BT-117).
- The cancellation carries the target minus the VAT already cancelled.

Example: three parts of 9.67, 9.67 and 9.66 net at 19 % carry 1.84, 1.83 and 1.84 VAT, 5.51
in total. Rounded per document, they would carry 3 × 1.84 = 5.52.

In gross price mode, the taxable amount follows the same way from the gross amount. Per VAT
group it is round2(gross amount cancelled so far / (1 + rate)), as for the original, or the
original's taxable amount (BT-116) once the group is closed. The rounding amount (BT-114)
follows, so that the amount due of every part stays the gross amount of its lines. Example:
3 × 9.99 including 19 % (25.18 net, 4.78 VAT, rounding amount 0.01) are cancelled as 8.39,
8.40 and 8.39 net (rounded per document: 3 × 8.39 = 25.17).

This holds for every sequence of partial cancellations (and the rest with `cancel()`):

- Per VAT group, the taxable amounts (BT-116, the taxable amount of section 17 (1) UStG) and
  the VAT amounts (BT-117) of all cancellations add up exactly to the original's. So do the
  totals and amounts due (BT-109 net total, BT-110 VAT total, BT-112 gross total, BT-114
  rounding amount, BT-115 amount due).
- Every single cancellation differs by at most one cent from round2(its own taxable amount ×
  rate), and its VAT has the sign of its taxable amount (0.00 on 0.00).
- BR-CO-17 and BR-S-09 accept up to one currency unit (the official Schematron validation
  rules check exactly this tolerance), so every document stays valid.
- Such a cancellation says so in its note: "Rundungsausgleich: Die Umsatzsteuer dieses
  Teilstornos ist so bemessen, dass alle Teilstornos zur Rechnung RE-… zusammen deren
  Umsatzsteuer exakt berichtigen." (Rounding adjustment: all partial cancellations together
  correct the VAT of the invoice exactly.) The
  validation reports the information `QUITTIO-CANCELLATION-VAT-ALIGNED` instead of the
  warning `QUITTIO-VAT-ROUNDING`. That warning still reports a larger difference or another
  sign.
- The VAT total in accounting currency (BT-111, with a VAT accounting currency BT-6) is
  aligned the same way: round2(VAT cancelled so far × exchange rate), and the original's
  BT-111 with the last part.

How it is recorded:

- The relation of every cancellation records (column `vat`), per VAT group, the taxable
  amount, the VAT, the share of BT-111 and, in gross price mode, the gross amount.
- A partial cancellation that another process issues in the meantime changes the VAT of the
  next part. That part is then rejected ("partially cancelled in the meantime; try again")
  without using up a number.
- A single full cancellation reverses the original exactly.
- Drafts keep a declared VAT breakdown only for such aligned cancellations and price
  reductions (see [VAT of price reductions](#vat-of-price-reductions)). Every other invoice is
  recalculated with Quittio's rounding when it is issued, also a draft started with
  `InvoiceBuilder::from()` on data another system rounded.

The one-cent alignment was confirmed by a tax adviser in October 2026 (see the legal review in
the README).

### Sign strategies and credit notes

A cancellation is either a credit note with positive amounts or an invoice with negative
amounts. The type code (the document type, BT-3, from the UN code list UNTDID 1001) tells
which:

| Strategy (`quittio.corrections.cancellation_strategy`) | Type code | Amounts |
|---|---|---|
| `credit_note` (default, also of the German pack) | 381 | positive; the [UBL syntax](concepts.md#syntaxes-cii-and-ubl) writes a `CreditNote` document |
| `negative_invoice` | 380 | negative |

The key lives in `config/quittio.php` (env `QUITTIO_CANCELLATION_STRATEGY`); `null` takes the
country pack's default. A credit note (381 and the other credit note type codes) is
cancelled with an invoice (380) with the same positive amounts in both strategies.

Credit notes can be issued as ordinary managed invoices: set the type code in the builder
(`->type(InvoiceTypeCode::CreditNote)`). They are cancelled with a 380 invoice and corrected
with a credit note, with the strategy `cancel_and_reissue` only. Their consideration cannot be
reduced with `reducePrice()`.

### Cancellation ledger

Relations (`quittio_invoice_relations`) point from the new document (`invoice_id`) to the
original (`related_invoice_id`). The cancelling ones (`cancels`, `partially_cancels`,
`replaces`) make up the cancellation ledger of the original. Price reductions (`reduces`)
cancel nothing and are no part of it, but they count towards the limit per VAT group (see
[Price reductions](#price-reductions)):

| Column | Content |
|---|---|
| `type` | `cancels`, `partially_cancels`, `corrects`, `replaces`, `reduces` |
| `amount` | Cancellations and price reductions: the cancelled or reduced net amount, always positive |
| `lines` | Cancellations: per original line `line`, `quantity` (`null` for amount cancellations), `net` and, for gross price lines, `gross` |
| `vat` | Cancellations and price reductions: per VAT group `key` (e.g. `S\|19.00`), `category`, `rate`, `taxable`, `tax`, `tax_in_tax_currency`, `gross`, signed like the original |
| `created_at` | Time of the relation |

```php
$invoice->inverseRelations;                 // cancellations, corrections and price reductions of this invoice
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
| `reduced(Invoice $original)` | Net amount per VAT group (key as in `vat`) by which the price reductions still reduce the original, less what cancellations of the price reductions reversed |
| `normalize(Invoice $original, array $requested)` | The selections checked against the remainder (whole lines become quantities or amounts) |
| `history(Invoice $original, array $recorded)` | `CancellationHistory` of the core: VAT cancelled so far per group |
| `record(InvoiceData $cancellation, ?array $requested)` | The `lines` entry of a relation |
| `vat(InvoiceData $original, InvoiceData $cancellation)` | The `vat` entry of a relation |

The amount of a line is its net amount, for gross price lines its gross amount (as
magnitudes). Relations cannot be changed. They are deleted only together with their invoice
after its retention period.

### Corrections

`correct()` creates a draft of kind `correction` with the data of the original:

- no number,
- the type code 380 (a credit note keeps its credit note type code),
- a reference to the original (BG-3, number and date),
- no due date: it follows from the payment terms again when the correction is issued.

Change it with `updateData()`, preview it, then `issue()` it. Issuing follows
`quittio-managed.correction.strategy` (env `QUITTIO_CORRECTION_STRATEGY`; `null` = the
country pack's default, DE: `cancel_and_reissue`):

| Strategy | Issuing the correction |
|---|---|
| `cancel_and_reissue` | Cancels the original completely, then issues the correction as a new invoice with the relation `corrects`. The reason of the cancellation comes from the translation `quittio-managed::invoice.correction_reason` ("Rechnungskorrektur.", invoice correction). Events `InvoiceCancelled` and `InvoiceCorrected`; two numbers are used. |
| `corrected_invoice` | Issues the correction as a corrected invoice (type code 384) that replaces the original, relation `replaces`. The original becomes `cancelled` with the reason `quittio-managed::invoice.replaced_by` ("Ersetzt durch Rechnung RE-2026-0043.", replaced by invoice RE-2026-0043). Event `InvoiceCorrected` (no `InvoiceCancelled`). |

With `corrected_invoice`, issuing turns the draft into the corrected invoice with
`Correction::of()` of the core, as `Quittio::correction()` does (see
[Corrected invoices](building-invoices.md#corrected-invoices)):

- type code 384 and the reference to the original (BG-3),
- the title of the country pack (DE: "Rechnungskorrektur"),
- as first note, in the locale of the invoice: "Diese Rechnung berichtigt und ersetzt die
  Rechnung RE-2026-0042 vom 05.10.2026." (This invoice corrects and replaces invoice
  RE-2026-0042 of 05.10.2026, section 31 (5) UStDV). Correcting a corrected invoice replaces
  its earlier note.

Your changes to the draft (lines, notes, buyer data) are kept. `preview()` and `validate()`
show the draft as it will be issued. The stored draft keeps your data until it is issued.

Both steps run in one transaction: either both documents exist or none (an invalid correction
also rolls back the cancellation). The audit entry `corrected` (correction number and
relation type) is written on the original.

- Only `issued` invoices can be corrected, not cancellations, price reductions or partially
  cancelled invoices (`InvalidInvoiceState`). For a partially cancelled invoice, cancel the
  remainder with `cancel()` and issue a new invoice. A price reduction is cancelled and issued
  again instead.
- An invoice with price reductions cannot be corrected (`InvalidInvoiceState`): cancel the
  price reductions first (see [Price reductions](#price-reductions)).
- A credit note is corrected with a credit note and only with `cancel_and_reissue`.
  `corrected_invoice` throws `InvalidInvoiceState` for credit notes, because 384 is a
  corrected invoice.
- Self-billed documents cannot be corrected (`InvalidInvoiceState`, see
  [Self-billing](#self-billing)).
- An unknown strategy throws `ConfigurationException`. The strategy is checked when the draft
  is created and again when it is issued.
- The correction cannot be dated before the original, and the issuer must still be the company
  that issued it.
- Corrections can themselves be cancelled, partially cancelled, corrected and reduced.
- A correction draft whose original was cancelled, partially cancelled or replaced meanwhile
  can no longer be issued; `quittio:drafts:prune` deletes it.

The default strategy, cancel and reissue, was confirmed by a tax adviser in October 2026 (see the
legal review in the README).

### Left-behind drafts

A draft of a cancellation exists only while `cancel()` or `cancelPartially()` issues it. A
correction draft can only be issued while its original can be corrected, a price reduction
draft while its original can be reduced. Drafts that are left behind are removed by a
command:

```bash
php artisan quittio:drafts:prune                     # asks
php artisan quittio:drafts:prune --older-than=120 --force
```

`quittio:drafts:prune` deletes:

- cancellation drafts older than `--older-than` minutes (default 60), left behind e.g. by a
  crash,
- correction drafts whose original can no longer be corrected (cancelled, partially cancelled
  or replaced meanwhile, or deleted),
- price reduction drafts whose original can no longer be reduced (cancelled or replaced
  meanwhile, or deleted; a partially cancelled original can still be reduced).

A draft that has been issued meanwhile is never deleted. Every deletion writes
`deleted_draft` to the audit log.

### Self-billing

Self-billing means that the buyer issues the invoice for the seller's supply (in Germany a
"Gutschrift" in the sense of the UStG; see [German terms](concepts.md#german-terms)). It is not
part of version 1.0: the seller of every managed invoice is its issuer.

The Managed layer refuses self-billed documents with `InvalidInvoiceState`:

- Drafts with the type code 389, 261 or 527 (also set by `->de()->selfBilled()`) cannot be
  created, changed or issued.
- A self-billed invoice cannot be cancelled, corrected or reduced.

Credit notes the issuer grants as seller (381) are not affected. To create a self-billed
document, use the core without the Managed layer (`Quittio::invoice()->de()->selfBilled()`, see
[Self-billing](country-packs.md#self-billing-gutschrift)). Full support, with the issuer as
buyer, the supplier's agreement and its right to object, is planned for a later version.

## Price reductions

A price reduction (German "Entgeltminderung") documents that the consideration of an issued
invoice was reduced afterwards: a cash discount (Skonto) the buyer took, a reduction after a
complaint about defects, or a bonus (section 17 UStG). The invoice was correct and stays
correct. It is neither cancelled nor corrected and keeps its status.

```php
use Quittio\Core\Corrections\PriceReductionReason;

$draft = $invoice->reducePrice(PriceReductionReason::CashDiscount, percent: '2');   // 2 % of every VAT group
$draft->preview();
$reduction = $draft->issue();

$draft = $invoice->reducePrice(PriceReductionReason::Defect, amount: '100.00');      // net, invoice with one VAT group
$draft = $invoice->reducePrice(PriceReductionReason::Bonus, amounts: ['S:19' => '50.00', 'S:7' => '5.00']);
```

| Method | Signature | Returns |
|---|---|---|
| `reducePrice()` | `PriceReductionReason $reason, BigDecimal\|int\|string\|null $percent = null, BigDecimal\|int\|string\|null $amount = null, ?array $amounts = null, ?string $title = null` | A draft of kind `price_reduction`; issue it with `issue()` |

- Give exactly one of `percent`, `amount` (only for an invoice with one VAT group) and
  `amounts` (net amounts per VAT group, keyed `category:rate`, e.g. `S:19`).
- The reasons (`Quittio\Core\Corrections\PriceReductionReason`) are `CashDiscount`, `Defect`,
  `Bonus` and `Other`.
- `title` replaces the title of the document; by default it is the title of the country pack
  (DE: "Entgeltminderung").

The draft is built with `Quittio::priceReduction()` of the core (see
[Price reductions](building-invoices.md#price-reductions)):

- a credit note (type code 381, positive amounts) with one line "Minderung des Entgelts" per
  reduced VAT group,
- the reference to the original (BG-3) and the meta flag `price_reduction`,
- a note that names the reason, the net amount and the VAT per rate, e.g. "Minderung des
  Entgelts zur Rechnung RE-2026-0042 vom 05.10.2026 wegen in Anspruch genommenen Skontos
  (2 %). Minderung Entgelt 50,00 €, Umsatzsteuer 19 %: 9,50 €." (reduction of the
  consideration for invoice RE-2026-0042 because of a cash discount taken),
- the regulatory and tax notes of the original, and no due date,
- the format, profile and locale of the original, and its `billable` and `invoiceable`.

Change, preview, validate and delete the draft like any draft. `issue()`:

1. checks the original again (see the rules below) and the draft: it must still be a credit
   note with type code 381 (`InvalidInvoiceState`),
2. gives the price reduction its own number, from the shared sequence or, with
   `numbering.per_kind.price_reduction` (e.g. `GS-{Y}-{seq:4}`), from a sequence of its own
   (see [Numbering](#numbering)),
3. issues it with the steps of [Issuing](#issuing). It cannot be dated before the original.
   Inside the issuing transaction:
   - the original is locked and read again, and the reduction is checked again against what
     is left of each VAT group,
   - its VAT is checked against the cancellations and price reductions issued since the draft
     was created (see [VAT of price reductions](#vat-of-price-reductions)),
   - the relation `reduces` is recorded with the net amount and the VAT per group,
   - the audit entry `price_reduced` is written on the original,
4. dispatches `PriceReduced` after the commit.

The original keeps its status and its `cancellations` counter. If the price reduction cannot
be issued, it stays a draft.

Rules:

- Only `issued` and `partially_cancelled` invoices can be reduced, and only by the company
  that issued them. Cancellations, price reductions, credit notes and self-billed documents
  cannot be reduced (`InvalidInvoiceState`).
- A reduction must be greater than zero and at most the net amount of its VAT group. Several
  values, an unknown VAT group or `amount` on an invoice with several VAT groups throw an
  `InvalidArgumentException` of the core.
- Per VAT group, the cancellations and the price reductions together cannot exceed the net
  amount of the original. A price reduction above what is left throws an
  `UnexpectedValueException` ("VAT group S:19 of invoice RE-2026-0042: only … can still be
  reduced …"), when the draft is created and again when it is issued. A cancellation above it
  throws `InvalidInvoiceState`.
- To cancel or correct a reduced invoice completely, first cancel its price reductions with
  `$reduction->cancel()`. A cancelled price reduction no longer counts.
- A price reduction is cancelled (fully or partially) like any invoice, with a 380 invoice. A
  wrong price reduction is cancelled and issued again, not corrected: `correct()` throws
  `InvalidInvoiceState`.

Price reductions are kept and deleted together with their invoice (see
[Retention and deletion](#retention-and-deletion)). `Invoice::query()->open()` does not list
them, and the mail has its own subject and text, without a due date (see
[Mail content](#mail-content)).

In Germany a document on a price reduction is only required in the cases of section 17 (4)
UStG, and it need not be an e-invoice. A cash discount taken can also just be booked when the
payment arrives.

### VAT of price reductions

A price reduction rounds its VAT like a partial cancellation (see
[VAT of partial cancellations](#vat-of-partial-cancellations)): cumulatively, per VAT group,
together with everything that credits the same invoice, its partial cancellations and its
earlier price reductions.

- Per VAT group, the target is round2(net amount credited so far × rate), where the net amount
  credited so far is that of the cancellations and price reductions including this one. Once
  they credit the whole net amount of the group, the target is the invoice's VAT amount
  (BT-117).
- The price reduction carries the target minus the VAT already credited. A cancellation does
  the same with the price reductions counted in.
- A cancelled price reduction no longer counts, as far as it is cancelled.

Example: an invoice of 1.50 net at 19 % carries 0.29 VAT. Three price reductions of 0.50 each,
rounded on their own, would credit 3 × 0.10 = 0.30. Rounded cumulatively they carry 0.10, 0.09
and 0.10, together exactly 0.29. The same holds if one of them is a partial cancellation of
0.50, in whatever order.

So, per VAT group, the cancellations and price reductions of an invoice never credit more VAT
than the invoice has, and exactly its VAT once they credit its whole net amount. Every single
document differs by at most one cent from round2(its own net amount × rate), which BR-CO-17
accepts:

- A price reduction whose VAT differs from its own rounding declares it (meta flag
  `cancellation_vat_aligned`, validation information `QUITTIO-CANCELLATION-VAT-ALIGNED`). Its
  note names that VAT ("Minderung Entgelt 0,50 €, Umsatzsteuer 19 %: 0,09 €.") and adds
  "Rundungsausgleich: Die Umsatzsteuer dieser Entgeltminderung ist so bemessen, dass alle
  Entgeltminderungen und Stornos zur Rechnung RE-… zusammen die Umsatzsteuer des geminderten
  und stornierten Entgelts exakt berichtigen." (Rounding adjustment: all price reductions and
  cancellations of the invoice together correct exactly the VAT of the reduced and cancelled
  consideration.)
- `$reduction->cancel()` reverses exactly that declared VAT.
- The VAT is set when the draft is created. If a cancellation or price reduction of the same
  invoice is issued before the draft (or the draft's amounts change), `issue()` throws
  `InvalidInvoiceState` ("… was cancelled or reduced since this price reduction was drafted
  …") without using up a number. Delete the draft and create it again.
- After price reductions were cancelled, the VAT already credited can be one cent off the
  cumulative rounding. A price reduction that would then have to differ by more than one cent
  from its own rounding is rejected (`InvalidInvoiceState`); reduce by another amount, or cancel
  the remaining price reductions and reduce again.

### Payments after a price reduction

A price reduction changes neither the invoice's `amount_due` (BT-115, fixed when it was issued)
nor its `payment_status`, `paid_total` or `paid_at`. The package does not record payments in
version 1.0 (see [Payment status](#payment-status)): your application sets these columns. After
a cash discount, for example, the invoice stays open for its full amount, and
`Invoice::query()->open()` lists it, until you mark it as paid:

```php
use Quittio\Core\Corrections\PriceReductionReason;
use Quittio\Managed\Enums\PaymentStatus;

// 1,000.00 + 190.00 VAT were invoiced, the buyer paid 1,166.20 after 2 % cash discount.
$invoice->reducePrice(PriceReductionReason::CashDiscount, percent: '2')->issue();
$invoice->forceFill([
    'payment_status' => PaymentStatus::Paid,
    'paid_total' => '1166.20',
    'paid_at' => now(),
])->save();
```

The price reduction itself is not a document the buyer pays: `open()` leaves it out.

## Delivery

`send()` mails the archived documents of an issued invoice and logs every attempt.

```php
$delivery = $invoice->send();                                    // address from the billable or the buyer
$delivery = $invoice->send('accounting@kunde.de', ['cfo@kunde.de']);
```

`send(?string $to = null, array $cc = [])` returns the `Delivery` row. It:

1. refuses drafts (`InvalidInvoiceState`); cancelled invoices, cancellations, corrections and
   price reductions can be sent,
2. renders and archives the documents first if the render status is not `done` (in the
   request, so it can throw),
3. checks the hashes of the archived files: missing or damaged files dispatch
   `ArchiveIntegrityViolation` and throw an `ArchiveException`; nothing is sent and no
   delivery is logged,
4. takes the recipient from `$to` or the recipient resolver (`NoRecipient` if there is none),
5. attaches the archived PDF (ZUGFeRD, PDF), otherwise the archived XML (XRechnung, CII, UBL,
   Peppol). With `delivery.mail.xrechnung_visualization`, the readable PDF archived with an
   XML-only invoice is attached as well, after the same hash check (see
   [Readable PDF of XML-only e-invoices](#readable-pdf-of-xml-only-e-invoices)). An invoice
   archived without it (`visualization` off) gets it rendered from the snapshot for the mail,
   with the notice; that copy is not archived,
6. logs the attempt in `quittio_deliveries` (status `queued`) and sends the mail at once with
   the default mailer (`sendNow()`, also for an own Mailable that implements `ShouldQueue`):
   to `$to`, with `$cc` and a blind copy to `delivery.mail.bcc_archive`,
7. on success: delivery `sent` with `message_id` (`null` when the mailer returns no message,
   e.g. with `Mail::fake()`) and `sent_at`; invoice `delivery_status` `sent`, `sent_at` and
   `channel` `mail`; audit entry `sent`; event `InvoiceSent`,
8. on failure: delivery `failed` with the error message; invoice `delivery_status` `failed`
   (unless it was sent before); audit entry `delivery_failed`; event `InvoiceDeliveryFailed`;
   the exception is rethrown.

Every call is a new attempt and a new row; `$invoice->deliveries` lists them. `send()` sends
synchronously. To send in the background, dispatch an own job that calls `send()`.

### Recipients

The recipient comes from `delivery.recipient_resolver`. The default
(`DefaultRecipientResolver`) takes the first valid address of:

1. `invoiceEmail()` of the billable, when it implements
   `Quittio\Managed\Delivery\HasInvoiceEmail`,
2. the `email` attribute of the billable,
3. the buyer's contact e-mail (BT-58) in the invoice data,
4. the buyer's electronic address (BT-49) with scheme `EM`.

To take the address from elsewhere, implement `RecipientResolver` and set it in
`delivery.recipient_resolver`:

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

The default Mailable `Quittio\Managed\Delivery\InvoiceMail`:

- uses the locale of the invoice, else of the issuer, else `en`,
- has a subject and text by kind (invoice, correction, cancellation, price reduction),
- has a due date line, except for cancellations, price reductions and invoices without due
  date,
- has the seller's name below the closing.

The texts are translations of the namespace `quittio-managed`. Publish them with
`--tag=quittio-managed-lang`, the Markdown view with `--tag=quittio-managed-views`:

| Key | German default | English default |
|---|---|---|
| `mail.subject.invoice` | `Rechnung :number von :seller` | `Invoice :number from :seller` |
| `mail.subject.correction` | `Rechnungskorrektur :number von :seller` | `Corrected invoice :number from :seller` |
| `mail.subject.cancellation` | `Stornorechnung :number von :seller` | `Cancellation :number from :seller` |
| `mail.subject.price_reduction` | `Entgeltminderung :number von :seller` | `Price reduction :number from :seller` |
| `mail.greeting` | `Guten Tag,` | `Hello,` |
| `mail.body.invoice` | `anbei erhalten Sie die Rechnung :number über :amount.` | `please find attached invoice :number for :amount.` |
| `mail.body.correction` | `anbei erhalten Sie die Rechnung :number über :amount. Sie korrigiert eine frühere Rechnung.` | `please find attached invoice :number for :amount, which corrects an earlier invoice.` |
| `mail.body.cancellation` | `anbei erhalten Sie die Stornorechnung :number.` | `please find attached the cancellation :number.` |
| `mail.body.price_reduction` | `anbei erhalten Sie den Beleg :number über die Minderung des Entgelts einer früheren Rechnung.` | `please find attached the document :number on a reduction of the price of an earlier invoice.` |
| `mail.due` | `Bitte überweisen Sie den Betrag bis zum :due_date.` | `Please pay the amount by :due_date.` |
| `mail.closing` | `Mit freundlichen Grüßen` | `Kind regards` |
| `invoice.draft_watermark` | `ENTWURF` | `DRAFT` (preview watermark) |
| `invoice.correction_reason` | `Rechnungskorrektur.` | `Correction of the invoice.` (reason of the cancellation of `cancel_and_reissue`) |
| `invoice.replaced_by` | `Ersetzt durch Rechnung :number.` | `Replaced by invoice :number.` (reason with `corrected_invoice`) |

Placeholders: `:number`, `:seller`, `:amount` (amount due, formatted for the locale) and
`:due_date`.

An own Mailable is configured in `delivery.mail.mailable`. The container builds it with the
named arguments `invoice` (the `Invoice`) and `files` (list of `Illuminate\Mail\Attachment`
with the archived documents). Return the files from `attachments()`; extending `InvoiceMail`
does that for you:

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
the result of the transport. To send in the background, dispatch an own job that calls
`send()`.

## Audit log

The audit log records who did what to invoices, issuers and sequences. `quittio_audit_logs`
is append-only: entries are never changed or deleted. Every entry has:

- an action,
- a subject (the invoice, issuer or sequence; `null` for `verified`),
- a JSON payload,
- the causer: the authenticated user of the default guard (any Eloquent model),
- for HTTP requests, IP address and user agent (at most 255 characters). Console commands and
  queue jobs record neither.

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
| `price_reduced` | original | `reduction` (number), `net`, `vat` (per VAT group `key`, `taxable`, `tax`) |
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

Not logged:

- changes of drafts (`updateData()`, `save()`), previews and validations,
- bulk deletes of drafts,
- payment, legal hold and retention changes,
- archive integrity violations outside `quittio:verify` (they are events),
- read access.

Recipients and other customer data are not part of the entries: `sent` and `delivery_failed`
refer to the delivery row, which is deleted with the invoice (see
[Personal data](#personal-data)).

`audit.enabled => false` switches the log off completely. An own implementation of
`Quittio\Managed\Audit\Auditor` (`record(?Model $subject, string $action, array $payload = []):
?AuditLog`) can be bound in the container, e.g. to write to another store as well.

## Authorisation

The package ships two policies, `InvoicePolicy` and `IssuerPolicy`. With `policies.register`,
they are registered for the configured invoice and issuer models. The package does not check
them itself: call them in your controllers (`$this->authorize('issue', $invoice)`,
`Gate::authorize()`, `@can`).

Every ability needs an authenticated user. It combines the state of the record with an
optional gate named `quittio.{ability}`. Without such a gate, every authenticated user is
allowed.

| Policy ability | Allowed when | Gate |
|---|---|---|
| `viewAny` (invoice class) | always | `quittio.viewAny` |
| `view` | always | `quittio.view` |
| `createDraft` (invoice class) | always | `quittio.createDraft` |
| `updateDraft` | the invoice is a draft | `quittio.updateDraft` |
| `deleteDraft` | the invoice is a draft | `quittio.deleteDraft` |
| `issue` | a draft that is not a cancellation draft | `quittio.issue` |
| `cancel` (also for partial cancellations) | issued or partially cancelled, no cancellation | `quittio.cancel` |
| `correct` | issued (not partially cancelled), no cancellation or price reduction | `quittio.correct` |
| `reducePrice` | issued or partially cancelled; no cancellation, price reduction, credit note or self-billed document | `quittio.reducePrice` |
| `send` | issued (any status but draft) | `quittio.send` |
| `viewAuditLog` | always | `quittio.viewAuditLog` |
| `viewAny`, `view` (issuer) | always | `quittio.manageIssuers` |
| `manageIssuers` | always | `quittio.manageIssuers` |
| `manageSequences` | always | `quittio.manageSequences` |

```php
use Illuminate\Support\Facades\Gate;
use Quittio\Managed\Managed;
use Quittio\Managed\Models\Invoice;

Gate::define('quittio.issue', fn (User $user, Invoice $invoice) => $user->isAccountant());
Gate::define('quittio.manageSequences', fn (User $user) => $user->isAdmin());

$user->can('createDraft', Managed::invoiceModel());
$user->can('manageSequences', Managed::issuerModel());
```

Gates receive the user and, for record abilities, the invoice or issuer. `manageIssuers` and
`manageSequences` receive the issuer or `null`.

## Immutability

An issued invoice must stay as it was issued; changes are made with cancellations and
corrections. Issued invoices are protected on three levels:

1. the model (`ImmutableInvoiceGuard`),
2. its query builder (`InvoiceQuery`),
3. database triggers, recommended for production.

Violations throw `InvoiceImmutable` (model and query builder) or a database error (triggers).

### What can still change

After issuing, the model only lets these columns change. Everything else throws
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
| `document_version`, `disk`, `xml_path`, `pdf_path`, `visualization_path`, `xml_hash`, `pdf_hash`, `visualization_hash`, `validation_status`, `validation_report_path` | Archiver only, together with a higher document version |
| `updated_at` | Free |

Further rules:

- Columns you add to `quittio_invoices` yourself are frozen after issuing as well.
- Invoices are inserted as drafts only.
- Drafts hold no number, number space, sequence key or sequence value (`issue()` sets them).
  They keep their kind and the invoice they refer to (`kind`, `preceding_invoice_id`).
- Issued invoices can only be deleted one by one after the retention period (plus two days,
  see [Retention and deletion](#retention-and-deletion)) and never under legal hold.

### Optimistic concurrency

A save or delete only applies while the row still has the status (and the other stored
values) the model was loaded with. Otherwise it throws `InvoiceImmutable` ("… was changed
(e.g. issued or cancelled) by another process since it was loaded; load it again") and
changes nothing. Examples: a draft that another process issued meanwhile, or a model whose
original values were changed.

Partly loaded models (`select('id')`) are checked against the stored row. Load the invoice
again in that case.

### Bulk queries

The query builder applies the same rules to bulk operations:

- Bulk updates, touches and increments of frozen columns only reach drafts. This also holds
  with `orWhere()` and with qualified column names (`quittio_invoices.status`) or other letter
  cases.
- On issued invoices, bulk updates may:
  - change payment, delivery and rendering columns,
  - set `legal_hold` (`true`, `1` or `"1"`; values other than these and `false`, `0`, `"0"`
    throw),
  - extend `retention_until` (rows whose period would shrink are left out).

  The status never changes in bulk (it throws), and a legal hold is only released through the
  model.
- Bulk updates and inserts cannot set `number`, `number_space`, `sequence_key`,
  `sequence_value`, `kind` or `preceding_invoice_id`.
- Upserts may only change the bulk columns and insert drafts only. Inserts create drafts
  only. Bulk deletes, `forceDelete()` and `truncate()` throw if they would reach an issued
  invoice.
- `updateOrInsert()`, `updateFrom()`, `insertUsing()` and `insertOrIgnoreUsing()` are not
  available.

### Append-only tables

The audit log and the invoice relations are append-only in the same way: updates, upserts,
increments, touches and deletes through the model or its query builder throw. Relations are
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

The triggers protect:

- issued invoices: frozen columns; a forward-only status that only becomes (partially)
  cancelled together with a rise of the `cancellations` counter, which cannot exceed the
  number of cancelling relations; write-once cancellation columns (only together with the
  status `cancelled`); a retention period that can only be extended; archive columns only
  with a higher document version; deletes only two days after the retention period in the
  database clock and never under legal hold,
- drafts: inserted and kept without number, number space and sequence value; fixed kind and
  original; issued only with a number, a number space and a retention period and without
  cancellation columns,
- the audit log: no updates, no deletes,
- the invoice relations: no updates; deletes only once their invoice is past its retention
  period,
- the number sequences: no lower counter, no new key or pattern, no deletes,
- on PostgreSQL only: none of these tables can be truncated.

The triggers raise errors such as `quittio: issued invoice is immutable`, `quittio: audit log
is append-only`, `quittio: invoice relations are immutable` and `quittio: number sequences only
increase`. On MySQL with binary logging, creating triggers needs the `TRIGGER` privilege and
`log_bin_trust_function_creators` (or `SUPER`). Unlike the model, the triggers do not limit
releasing a legal hold or which code changes the status.

Frozen are all columns of an issued invoice except those of
[What can still change](#what-can-still-change). On PostgreSQL the trigger compares the whole
row without them, so columns you add to `quittio_invoices` later are frozen as well. On MySQL,
MariaDB and SQLite the trigger lists the columns that exist when it is created. Recreate it in
the migration that adds columns, with `ImmutabilityTriggers::refresh()`
(`Quittio\Managed\Immutability`):

```php
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Quittio\Managed\Immutability\ImmutabilityTriggers;

Schema::table('quittio_invoices', function (Blueprint $table): void {
    $table->string('erp_reference')->nullable();
});
ImmutabilityTriggers::refresh();
```

`refresh()` recreates only the check of invoice updates, with the columns the table has now.
It returns `false` without doing anything when the triggers are not installed.

- On SQLite and PostgreSQL it runs in one transaction.
- MySQL commits each statement, so between dropping and creating the update trigger
  (milliseconds) direct updates are not checked: run it with the migrations, not under load.
- On PostgreSQL it is not needed for new columns. It replaces the trigger function, which
  switches triggers installed with an earlier version (a column list) to the comparison of
  the whole row.

### What is not prevented

- MySQL fires no triggers on `TRUNCATE`. The foreign keys keep invoices and relations from
  being truncated, but the audit log and the sequences are not protected. Give the database
  user of the application no `DROP` privilege on the `quittio_` tables (`TRUNCATE` needs it)
  and run migrations with a separate user.
- Without the triggers, the base query builder and raw SQL can change anything.
- Issuer profiles are protected by their model only: change number spaces, tenants, keys and
  seller identifiers with model saves (`$issuer->save()`, `NumberSpaces::assign()`). Bulk
  updates of issuers through the query builder or raw SQL bypass the freeze and the
  consistency check. The unique index on number space and number still prevents duplicate
  numbers within a space.
- Deliveries are ordinary rows without protection.
- The archived files are protected by the archive (write-once) and the hashes. A disk without
  object lock cannot keep an administrator from deleting them; `quittio:verify` detects it.

## Retention and deletion

Invoices must be kept for a retention period. In Germany it is 8 years after the end of the
year of issue (section 14b UStG, the German VAT Act, and section 147 AO, the German Fiscal
Code). Without a country pack, it is 10 years.

`retention_until` is set when the invoice is issued, by the rule of the country pack. It is
counted from the later of the issue date and the day the invoice is actually issued.
`retention.years` can only lengthen it.

The period can be extended per invoice. An invoice can also be put on legal hold: then it is
kept beyond its retention period, e.g. during a tax audit or a lawsuit.

```php
$invoice->forceFill(['retention_until' => '2040-12-31'])->save();   // only later dates
$invoice->forceFill(['legal_hold' => true])->save();                // also in bulk
$invoice->forceFill(['legal_hold' => false])->save();               // release: model only
```

The period ends with `retention_until` in the issuer's time zone. The command, the model and
the triggers compare with their own clock. They count an invoice as past its period two days
later (`retain until 2034-12-31` → from 2035-01-03), when it has ended in every time zone
(time zones differ by up to 26 hours).

```bash
php artisan quittio:retention:check              # list, dispatch RetentionExpired
php artisan quittio:retention:check --delete     # delete, asks for confirmation
php artisan quittio:retention:check --delete --force
```

`quittio:retention:check` lists the issued invoices past their retention period that are not
on legal hold. It dispatches `RetentionExpired` for each of them, before anything is deleted.

With `--delete`, it deletes invoices by group:

- A group is an invoice together with all invoices connected to it through cancellations,
  partial cancellations, corrections, replacements and price reductions (transitively).
- A group is deleted only when every one of its invoices is past its retention period and
  none is on legal hold. Otherwise the whole group is kept, and the command says which
  invoice keeps it.
- The rows of a group (invoices, relations, deliveries with recipients and errors, and drafts
  that refer to one of the invoices) are deleted in one transaction with audit entries
  (`deleted`, `deleted_draft`). Then all archived files of all document versions from the
  manifest are deleted.
- A file that cannot be deleted stays recorded. The next run reports it
  (`… archived file(s) of deleted invoices could not be deleted yet`) and, with `--delete`,
  tries again.
- With `--delete`, the command fails (exit code 1, `Not everything could be deleted: …`) when
  a group or an archived file could not be deleted, after it has dealt with the others. Groups
  kept for a legal hold or a retention period are no failure.

Schedule the command (e.g. monthly) to keep the archive clean.

Delete issued invoices through this command. A single `$invoice->delete()` after the
retention period is allowed by the model, but it fails on the foreign keys while relations or
cancellations refer to the invoice, and it leaves deliveries and archived files behind.

### Personal data

The audit log is append-only and stays after the retention period (GDPR Art. 17, the right to
erasure, and GoBD), so it holds no recipients or other data of the customers. `sent` and
`delivery_failed` refer to the delivery (`delivery`: id of the row in `quittio_deliveries`),
which is deleted with the invoice. The invoice data itself is in the invoice row and the
archived files.

The audit log keeps the causer, IP address and user agent of the users who acted, and the
changes of issuer profiles (the company's own master data). There is no function to
anonymise issued invoices before the end of their retention period.

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
seller check, the issuer defaults and the audit entry `drafted`. Issue them with `issue()`;
the seller is taken from the issuer then.

Useful in tests:

- `Queue::fake()` keeps the render job from running (render status stays `pending`).
  `config(['quittio-managed.queue.render_sync' => true])` renders at once.
- `Mail::fake()` for `send()`, and a temporary local disk for `quittio-managed.disk`.
- Fake single events (`Event::fake([InvoiceIssued::class])`). Faking all events also stops
  the model events that write the issuer and deletion audit entries.
- SQLite in memory works. Set `'transaction_mode' => 'IMMEDIATE'` on the test connection to
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

- `QUITTIO_DB` selects `sqlite` (default, in memory), `mysql` (port 33061) or `pgsql` (port
  54321). `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD` override the
  connection.
- `QUITTIO_DB_TRIGGERS=1` also installs the immutability triggers.
- The group `concurrency` forks processes that issue invoices at the same time (needs `pcntl`
  and MySQL or PostgreSQL).
- The group `official` validates the archived documents with the KoSIT validator (the
  official validator for XRechnung and EN 16931) and is skipped when it is not installed.

## Known limitations

- Payments, bank reconciliation, dunning runs, subscriptions, advance and final invoices,
  self-billing, inbound invoices, Peppol transmission and DATEV exports are not part of 1.0.
  The payment columns are maintained by your application.
- Self-billed documents (type codes 389, 261, 527) are refused when drafting, changing,
  issuing, cancelling, correcting and reducing; see [Self-billing](#self-billing).
- The Managed layer issues no payment reminders. `Quittio::paymentReminder()` starts with the
  data of the current issuer, but the reminder is neither numbered nor stored by the Managed
  layer. The flat rate for recovery costs (section 288 (5) BGB, 40 EUR, owed once per claim)
  is checked by `DocumentBuilder::flatRate()` only within one document. Across several
  reminders for the same invoice, your application has to make sure it is claimed only once
  (see [Payment reminders](documents.md#payment-reminders)).
- Delivery is by mail only (`channel` `mail`). `lifecycle_status`, `registrations` and
  `network_ids` are stored but not evaluated.
- Partial cancellations of invoices with document level allowances or charges are not
  supported: cancel fully and issue a new invoice.
- Issuer profiles are protected by their model only; bulk updates of issuers bypass the
  number space checks.
- Without the triggers, the base query builder and raw SQL bypass the immutability rules.
  MySQL fires no triggers on `TRUNCATE`. On MySQL, MariaDB and SQLite the triggers cover the
  invoice columns that existed when they were created (see `ImmutabilityTriggers::refresh()`).
- Different notations of one tax number are not unified for the number spaces.
- Issued invoices can be deleted only after their retention period; there is no early
  anonymisation.
- Invoices archived without the readable PDF (`visualization` off) get it rendered on the fly
  when they are mailed with `delivery.mail.xrechnung_visualization`; that copy is not
  archived.
- A future DATEV export has to show cash discounts taken per VAT rate with the reduction of
  net amount and VAT, the invoice number as document field and the payment date, as the
  reduction is corrected in the period of the payment (section 17 UStG).

## Configuration

The settings of the Managed layer are in `config/quittio-managed.php`. The installer
publishes it; otherwise publish it with `--tag=quittio-managed-config`.

Values are read when they are used. Exceptions: the classes of the resolvers and the number
generator are resolved once per process, and the morph map, the policies and the tenancy scope
are registered when the application boots. A value of the wrong type throws a
`ConfigurationException` ("… must be a string", "… must be a boolean", "… must be an
integer"). An empty string counts as `null` for string values.

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
| `disk` | string | `'local'` | `QUITTIO_STORAGE_DISK` | Filesystem disk of the archive; for production an object store with object lock (WORM) and versioning |
| `path` | string | `'{issuer}/{Y}/{m}/{number}/v{version}'` | – | Directory of a document version; placeholders `{issuer}` `{Y}` `{m}` `{number}` `{uuid}` `{version}` (see [Archive layout](#archive-layout)) |
| `visualization` | bool | `true` | – | Archive a readable PDF, with the notice that only the XML is authoritative, next to XML-only e-invoices (see [Readable PDF of XML-only e-invoices](#readable-pdf-of-xml-only-e-invoices)) |

`QUITTIO_STORAGE_DISK` also sets `storage.disk` of `config/quittio.php` (see
[Configuration](configuration.md)). Set `disk` in `config/quittio-managed.php` to give the
archive a disk of its own.

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
| `numbering.per_kind.price_reduction` | string\|null | `null` | Own sequence and pattern for price reductions (e.g. `GS-{Y}-{seq:4}`); `null` = shared invoice sequence |
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
| `delivery.mail.xrechnung_visualization` | bool | `false` | – | Attach the archived readable PDF to mails of XML-only formats (rendered on the fly if the invoice has none) |
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
| `pdf.pdfa` | The readable PDF of XML-only e-invoices is PDF/A-3b when `true` (default) |
| `format_resolver.b2c_embed_xml` | `true` = no readable PDF is archived next to XML-only e-invoices |
| `countries` | Country packs (numbering, retention, correction policy, titles) |

See [Configuration](configuration.md) for these keys. The `seller` section is not used for
invoices of the Managed layer: issuer profiles replace it.

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

The class must extend the package model, otherwise a `ConfigurationException` ("… must be a
subclass of …") is thrown. The package resolves every model through `Managed::issuerModel()`,
`Managed::invoiceModel()`, `Managed::sequenceModel()`, `Managed::relationModel()`,
`Managed::deliveryModel()` and `Managed::auditLogModel()` (class `Quittio\Managed\Managed`).
Use them in your code as well. The morph map, the policies and the tenancy scope use the
configured classes.

Keep the query builders of the package models (`InvoiceQuery`, `SequenceQuery`,
`AppendOnlyQuery`): the invoice model throws a `LogicException` without its `InvoiceQuery`.

Further helpers: `Managed::table('invoices')` (table name with prefix),
`Managed::connection()` and `Managed::settings()` (typed access to the configuration).

### Own services

The package binds these services as singletons. Replace them in a service provider, or, where
a key exists, in the configuration:

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
contract (`ConfigurationException` otherwise). Only `issuer_resolver` accepts a Closure.

## Console commands

| Command | Purpose | See |
|---|---|---|
| `quittio:install --managed` | Publish configuration and migrations, migrate, create the first issuer | [Installation](#installation) |
| `quittio:render {invoice}` | Render missing or failed documents of a stored invoice as a new version | [Versions and re-rendering](#versions-and-re-rendering) |
| `quittio:verify {--from=} {--to=} {--issuer=}` | Check the archive hashes | [Integrity checks](#integrity-checks) |
| `quittio:sequence:show {--issuer=}` | List the number sequences | [Managing sequences](#managing-sequences) |
| `quittio:sequence:set {key} {value} {--force}` | Raise or create a number sequence | [Managing sequences](#managing-sequences) |
| `quittio:retention:check {--delete} {--force}` | List and delete invoices past their retention period | [Retention and deletion](#retention-and-deletion) |
| `quittio:drafts:prune {--older-than=60} {--force}` | Delete left-behind cancellation, correction and price reduction drafts | [Left-behind drafts](#left-behind-drafts) |

The commands ignore the tenancy scope. See [Artisan commands](commands.md) for all
signatures. For example, scheduled in production:

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('quittio:verify')->daily();
Schedule::command('quittio:drafts:prune --force')->hourly();
Schedule::command('quittio:retention:check')->monthly();
```

### Publish tags

`php artisan vendor:publish --tag=…`:

| Tag | Publishes |
|---|---|
| `quittio-managed-config` | `config/quittio-managed.php` |
| `quittio-managed-migrations` | the migrations |
| `quittio-managed-triggers` | the immutability triggers (recommended, see [Database triggers](#database-triggers)) |
| `quittio-managed-views` | the mail view (`resources/views/vendor/quittio-managed`) |
| `quittio-managed-lang` | the translations (`lang/vendor/quittio-managed`) |

## Events

All events are in the namespace `Quittio\Managed\Events` and have public readonly properties.

| Event | Dispatched | Properties |
|---|---|---|
| `InvoiceDrafted` | A draft was created (also drafts of cancellations, corrections and price reductions); after the commit when it was created inside a transaction, so the cancellation draft of a correction that rolls back is not announced | `invoice` |
| `InvoiceIssuing` | A draft is about to be issued: validated, no number yet; a listener that throws stops the issue. Not followed by `InvoiceIssued` when the issue fails afterwards | `invoice` |
| `InvoiceIssued` | After the issuing transaction was committed and the render job was queued (or the documents rendered) | `invoice` |
| `InvoiceDocumentsStored` | A new document version was rendered and archived | `invoice` |
| `InvoiceRenderFailed` | Rendering failed after the last attempt (with `render_sync`: after the first); the invoice stays issued | `invoice`, `exception` |
| `InvoiceCancelled` | After the commit: the original was cancelled completely (`cancel()`, the last partial cancellation, `cancel_and_reissue`) | `invoice` (original), `cancellation` |
| `InvoicePartiallyCancelled` | After the commit: parts of the original were cancelled | `invoice` (original), `cancellation` |
| `InvoiceCorrected` | After the commit: a correction of the original was issued | `invoice` (original), `correction` |
| `PriceReduced` | After the commit: a price reduction of the original was issued; the original keeps its status | `invoice` (original), `reduction` |
| `InvoiceSent` | The mail was sent | `invoice`, `delivery` |
| `InvoiceDeliveryFailed` | Sending failed (before the exception is rethrown) | `invoice`, `delivery`, `exception` |
| `ArchiveIntegrityViolation` | An archived file is missing or changed (archiving, `send()`, `quittio:verify`) | `invoice`, `path`, `problem` |
| `RetentionExpired` | `quittio:retention:check` found the invoice past its retention period and not on legal hold, before anything is deleted | `invoice` |

After a cancellation or a price reduction is committed, the order is: its render job, its
`InvoiceIssued`, then `InvoiceCancelled`, `InvoicePartiallyCancelled` or `PriceReduced`.
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
| `InvalidInvoiceState` | `LogicException` | The action is not possible in the current state: issuing a cancellation draft directly, cancelling, correcting or reducing what cannot be (see the table in [Cancellations and corrections](#cancellations-and-corrections) and [Price reductions](#price-reductions)), a cancellation that together with the price reductions would exceed the net amount, a price reduction draft that is no longer a credit note, a self-billed document, a date before the original, another company, a seller with another VAT id or tax number, sending a draft, an empty partial cancellation, a cancellation that raced with another one |
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
| `Quittio\Core\Validation\ValidationException` | `issue()` (also of cancellations, corrections and price reductions) of an invalid invoice; `$e->result` |
| `Quittio\Core\Exceptions\ConfigurationException` | Invalid configuration: model classes, resolver, generator and recipient classes, Mailable, archive path, reset, start, `retention.years`, correction strategy, value types |
| `UnexpectedValueException` | Invalid partial cancellation selections, a price reduction above what is left of a VAT group, malformed relation JSON, an invoice not found or ambiguous in `quittio:render` |
| `InvalidArgumentException` | Partial cancellation of an invoice with document level allowances or charges, an unknown cancellation strategy, invalid price reduction values (core); an amount with more than two decimals |
| `Illuminate\Database\QueryException` | Foreign keys (e.g. deleting an issuer with invoices) and the triggers |

```php
use Quittio\Core\Validation\ValidationException;
use Quittio\Managed\Exceptions\ManagedException;

try {
    $invoice = $draft->issue();
} catch (ValidationException $e) {
    return back()->withErrors(array_map(fn ($m) => $m->message, $e->result->errors()));
} catch (ManagedException $e) {
    report($e);
}
```

For common errors and their fixes, see also [Troubleshooting](troubleshooting.md).

## Models and enums

All models extend `Quittio\Managed\Models\Model`, which takes table prefix and connection from
the configuration.

### Tables

| Table | Model | Content |
|---|---|---|
| `quittio_issuers` | `Issuer` | Issuer profiles |
| `quittio_sequences` | `Sequence` | Number sequences |
| `quittio_invoices` | `Invoice` | Drafts and issued invoices, cancellations, corrections and price reductions |
| `quittio_invoice_relations` | `InvoiceRelation` | `cancels`, `partially_cancels`, `corrects`, `replaces`, `reduces` |
| `quittio_deliveries` | `Delivery` | Delivery attempts |
| `quittio_audit_logs` | `AuditLog` | Audit log |
| `quittio_archive_files` | – | Manifest of every archived file (no model, see [Archive manifest](#archive-manifest)) |

The names use the default prefix `quittio_` (`table_prefix`, see
[Database settings](#database-settings)).

### Invoice

| Column | Meaning |
|---|---|
| `id`, `uuid` | Key and UUID (generated) |
| `issuer_id` | Issuer |
| `billable_type`, `billable_id`, `invoiceable_type`, `invoiceable_id` | References to your models |
| `kind` | `Kind`: `invoice`, `cancellation`, `correction`, `price_reduction` |
| `type_code` | Document type code (BT-3, UNTDID 1001), e.g. `380` invoice, `381` credit note, `384` corrected invoice |
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
| `preceding_invoice_id` | Original of a cancellation, correction or price reduction |
| `cancelled_at`, `cancellation_reason`, `cancellations` | See [Status](#status) |
| `render_status`, `render_attempts`, `document_version` | Rendering |
| `disk`, `xml_path`, `pdf_path`, `xml_hash`, `pdf_hash`, `validation_status`, `validation_report_path` | Archive |
| `visualization_path`, `visualization_hash` | Readable PDF of an XML-only e-invoice and its SHA-256 hash (see [Readable PDF of XML-only e-invoices](#readable-pdf-of-xml-only-e-invoices)) |
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
| `inverseRelations()` | `HasMany` relations of later invoices to this one (its cancellations, corrections and price reductions) |
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
| `reducePrice(PriceReductionReason $reason, $percent = null, $amount = null, ?array $amounts = null, ?string $title = null)` | Price reduction draft (see [Price reductions](#price-reductions)) |
| `send(?string $to = null, array $cc = [])` | Mail delivery |
| `toInvoiceData()` | The data (draft or frozen snapshot), upcast to the current snapshot version; the stored snapshot is never rewritten |

| Query method | Restricts to |
|---|---|
| `drafts()` | Drafts |
| `issued()` | Issued invoices, including (partially) cancelled ones |
| `open()` | Issued or partially cancelled, not a cancellation or price reduction, unpaid or partially paid |
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
| `Kind` | `Invoice` (`invoice`), `Cancellation` (`cancellation`), `Correction` (`correction`), `PriceReduction` (`price_reduction`) | – |
| `PaymentStatus` | `Unpaid` (`unpaid`), `PartiallyPaid` (`partially_paid`), `Paid` (`paid`), `Overpaid` (`overpaid`), `WrittenOff` (`written_off`) | – |
| `DeliveryStatus` | `NotSent` (`not_sent`), `Sent` (`sent`), `Failed` (`failed`) | – |
| `DeliveryState` | `Queued` (`queued`), `Sent` (`sent`), `Failed` (`failed`) | – |
| `RenderStatus` | `Pending` (`pending`), `Rendering` (`rendering`), `Done` (`done`), `Failed` (`failed`) | – |
| `ValidationStatus` | `Valid` (`valid`), `Warnings` (`warnings`), `Invalid` (`invalid`), `NotChecked` (`not_checked`) | `ValidationStatus::of(ValidationResult $result)` |
| `RelationType` | `Cancels` (`cancels`), `PartiallyCancels` (`partially_cancels`), `Corrects` (`corrects`), `Replaces` (`replaces`), `Reduces` (`reduces`) | `RelationType::cancelling()`: the values of the cancelling relations (`cancels`, `partially_cancels`, `replaces`) |
