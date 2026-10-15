# Laravel integration

This page explains how Quittio works in a Laravel application: you start an invoice, render it, save, download or mail the result, render in the queue, react to events and adapt templates, translations and drivers. Read [Getting started](getting-started.md) first for a first invoice. The terms used here (format, profile, ZUGFeRD, XRechnung) are explained in [E-invoicing basics](concepts.md).

`quittio/laravel` connects the country-neutral core with Laravel. It needs PHP 8.3 and Laravel 13.

```bash
composer require quittio/quittio        # core, Laravel integration and country pack Germany
php artisan quittio:install             # publishes config/quittio.php
```

Then set your company in `config/quittio.php` ([The keys you set first](configuration.md#the-keys-you-set-first)).

## Overview

A typical invoice goes through these steps:

```php
use Quittio\Core\Enums\UnitCode;
use Quittio\Laravel\Facades\Quittio;

$result = Quittio::invoice()                               // 1. start: seller from config/quittio.php
    ->number('RE-2026-0042')
    ->deliveryDate('2026-09-30')                           // date of supply, mandatory in Germany
    ->buyer($customer)                                     // a model that implements ProvidesParty
    ->line('Web development', netPrice: '95.00', quantity: 24, unit: UnitCode::Hour)
    ->dueInDays(14)
    ->render();                                            // 2. render: format chosen automatically

$result->save('invoices', '2026/');                        // 3. save on a filesystem disk …
return $result->download();                                //    … or download, or attach to a mail
```

| Step | What you use | Section |
|---|---|---|
| Start an invoice | `Quittio::invoice()` | [Starting invoices](#starting-invoices) |
| Render it | `->render()`, or `Quittio::make($invoice)` for more options | [Rendering invoices](#rendering-invoices) |
| Hand it out | `->save()`, `->download()`, `->stream()`, mail attachment | [Saving, downloading and mailing](#saving-downloading-and-mailing) |
| Render later | `->queue()->save()` | [Queueing](#queueing) |
| React to rendering | `InvoiceRendered` and other events | [Events](#events) |
| Cancel an invoice | `$result->cancel()` | [Cancellations](#cancellations) |
| Quotes, reminders and other documents | `Quittio::quote()` … | [Documents](#documents) |
| Adapt the output | Blade templates, translations, drivers | [Blade templates](#blade-templates) and the sections after it |

All calls go through the facade `Quittio\Laravel\Facades\Quittio`. Instead of the facade you can inject `Quittio\Laravel\QuittioManager`; both are the same singleton. Every method is listed in [Facade](#facade).

## Starting invoices

`Quittio::invoice()` starts an invoice. It returns an `InvoiceBuilder` ([Building invoices](building-invoices.md)) that already has your company as seller, its first bank account and the small business setting of `config/quittio.php` ([Seller](configuration.md#seller)). You add the number, the customer, the lines and the payment terms. Then you end the chain:

- `->render()` renders the invoice: PDF and XML, in a [`RenderedInvoice`](#renderedinvoice).
- `->build()` returns the data only (an `InvoiceData`), e.g. to store a draft or to render it later with `Quittio::make()`.

```php
use Quittio\Core\Enums\UnitCode;
use Quittio\Laravel\Facades\Quittio;

$builder = Quittio::invoice()
    ->number('RE-2026-0042')
    ->deliveryDate('2026-09-30')                           // date of supply, mandatory in Germany
    ->buyer($customer)                                     // a model that implements ProvidesParty
    ->line('Web development', netPrice: '95.00', quantity: 24, unit: UnitCode::Hour)
    ->dueInDays(14);

$result = $builder->render();                              // format chosen automatically
$result = $builder->render('xrechnung-cii');               // one format
$result = $builder->render(['zugferd', 'xrechnung-cii']);  // several formats; the first is the main result
$report = $builder->check();                               // validate only, no PDF
$invoice = $builder->build();                              // the data, not rendered
```

`render(string|array|null $format = null)` is the same as `Quittio::make($builder)->format($format)->render()`. For profile, locale, template or the queue, pass the builder to `Quittio::make()` ([Rendering invoices](#rendering-invoices)).

`check(string|array|null $format = null)` validates in the same way, including the XML, but renders no PDF. It returns the `ValidationResult` and does not throw for invalid data ([Validation](validation.md)).

The builder is a `Quittio\Laravel\Builder\RenderableInvoiceBuilder`, a subclass of `InvoiceBuilder`, so all builder methods and macros work.

Documents start the same way, with the seller and bank account of the configuration. They end with `->build()` or `->render()` (PDF); see [Documents](#documents):

| Method | Document |
|---|---|
| `Quittio::quote()` | Quote |
| `Quittio::orderConfirmation()` | Order confirmation |
| `Quittio::deliveryNote()` | Delivery note |
| `Quittio::proforma()` | Proforma invoice |
| `Quittio::paymentReminder(int $level = 0)` | Payment reminder (level 0) or reminder (level 1, 2 …) |
| `Quittio::accountStatement()` | Account statement |

### Another seller

The bank account and the small business setting belong to the seller of the configuration. So `->seller(...)` with another seller (`Party::make()`, a `SellerParty` or a model) removes everything the configuration set for its seller: the bank account and the small business flag. With the [Managed layer](#managed-layer) (the optional package that stores invoices in the database) it also removes the issuer's payment terms, design, locale, time zone and currency.

What you set on the builder yourself stays, also when you set it before `->seller()`. A callable changes the configured seller instead and keeps its values:

```php
use Quittio\Core\Builder\Party;
use Quittio\Core\Data\Meta;
use Quittio\Core\Enums\PaymentMeansCode;
use Quittio\Laravel\Facades\Quittio;

Quittio::invoice()
    ->seller(Party::make('Lisa Klein')->taxNumber('21/815/08150')->identifier('21/815/08150')->address('Gartenstraße 7', '12345', 'Musterstadt', 'DE'))
    ->payment(PaymentMeansCode::SepaCreditTransfer, iban: 'DE89370400440532013000')   // Lisa's account
    ->flag(Meta::FLAG_SMALL_BUSINESS);                                              // if she is a small business

Quittio::invoice()->seller(fn ($s) => $s->contact('Max Muster'));                     // same seller, same defaults
```

### Seller from the configuration

`Quittio\Laravel\Seller` turns the `seller` section of `config/quittio.php` ([Configuration](configuration.md#seller)) into invoice data:

| Method | Effect |
|---|---|
| `Seller::fromConfig(): SellerParty` | Builds the seller: name, VAT identifier, tax number, address, legal registration, identifiers, additional legal information, contact (when one of its values is set) and electronic address (when set) |
| `Seller::apply(InvoiceBuilder $builder): InvoiceBuilder` | Sets the seller on the builder and adds a credit transfer with the first bank account that has an IBAN. With `seller.small_business`, it flags the invoice as small business invoice (`Meta::FLAG_SMALL_BUSINESS`). Returns the same builder. |

`Quittio::invoice()` and the document methods use them through `ConfigInvoiceDefaults` (see below). Use them directly for builders you create yourself:

```php
use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Documents\DocumentBuilder;
use Quittio\Laravel\Seller;

$invoice = Seller::apply(InvoiceBuilder::make())->number('RE-2026-0042')/* ... */->build();

$quote = DocumentBuilder::quote()->seller(Seller::fromConfig());
```

With the Managed layer, issuer profiles hold the seller instead (`$issuer->builder()`).

**Taking the seller from somewhere else.** `Quittio::invoice()` gets its seller from the binding of `Quittio\Laravel\Contracts\InvoiceDefaults` (`apply(InvoiceBuilder $builder): void`; for documents it is applied to their invoice builder). The default binding, `ConfigInvoiceDefaults`, calls `Seller::apply()`. The Managed layer binds the current issuer. Bind your own class to take the seller from elsewhere, e.g. from the current tenant:

```php
use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Laravel\Contracts\InvoiceDefaults;

$this->app->bind(InvoiceDefaults::class, fn () => new class implements InvoiceDefaults
{
    public function apply(InvoiceBuilder $builder): void
    {
        $builder->seller(tenant()->billingParty());           // a SellerParty, PartyBuilder or ProvidesParty
    }
});
```

## Rendering invoices

Rendering turns invoice data into the output files. `Quittio::make()` starts a render call for data you built before: an `InvoiceData`, an `InvoiceBuilder`, or a model that implements `ProvidesInvoiceData`. It offers the options that `->render()` on the builder does not have:

```php
$result = Quittio::make($invoice)->render();                                   // format chosen automatically
$result = Quittio::make($order)->format('zugferd')->profile('en16931')->locale('de')->render();
$result = Quittio::make($invoice)->formats(['zugferd', 'xrechnung-cii'])->render();
```

A run does everything in one go:

1. It calculates the invoice: totals, VAT breakdown, and the due date from `defaults.due_days`. You never calculate totals yourself.
2. It chooses the format (the output, e.g. a ZUGFeRD PDF or an XRechnung XML, see [Formats](concepts.md#formats)) and the profile (how much of EN 16931 the XML contains, see [Profiles](concepts.md#profiles)).
3. It validates the invoice ([Validation per call](#validation-per-call)).
4. It writes the XML, renders the Blade template and produces the PDF/A (the archivable PDF standard) with the embedded XML.

All formats of one call share the same calculation.

### PendingInvoice

`Quittio::make()` returns a `Quittio\Laravel\Rendering\PendingInvoice`. It collects the options of one call; every setter returns the same object.

| Method | Effect |
|---|---|
| `format(string $format)` | One output format: `zugferd` (`factur-x`, `facturx`), `xrechnung-cii` (`xrechnung`), `xrechnung-ubl`, `cii`, `ubl`, `peppol`, `pdf`, `html`, `json`. Replaces earlier formats. |
| `formats(array $formats)` | Several formats from one calculation; duplicates are removed. The first is the main result. |
| `profile(Profile\|string $profile)` | Profile key (`minimum`, `basic-wl`, `basic`, `en16931`, `extended`, `peppol`; `xrechnung` and `xrechnung-extension` with the German country pack; aliases such as `comfort`) or a `Profile` object. Every requested format must allow it, otherwise the run throws a `ConfigurationException`. |
| `locale(string $locale)` | Locale of labels and formatting |
| `country(string $country)` | Leading country pack (upper-cased), instead of the seller's country |
| `template(string $template)` | Blade view or plain PHP template name; only allowed views (see [Blade templates](#blade-templates)) |
| `presentation(Presentation $presentation)` | Design for this call, merged over the configured design property by property; the invoice's own presentation wins (see [Templates and design](templates.md)) |
| `validate(bool $throwOnError = true)` | Validation mode `error` (`true`) or `warn` (`false`) for this call |
| `withoutValidation()` | Validation mode `off` for this call |
| `option(string $key, mixed $value)` | Option for the view model and the validation rules (see [Render options](#render-options)) |
| `render()` | Renders: `RenderedInvoice` |
| `check()` | Validates like `render()` without template and PDF: `ValidationResult` |
| `prepare()` | Calculates and validates without rendering: `PreparedInvoice` |
| `queue(?string $connection = null, ?string $queue = null)` | Renders in a queued job: `QueuedInvoice` (see [Queueing](#queueing)) |
| `data()` | The invoice data passed to `make()` (not yet calculated) |
| `options()` | The collected `Quittio\Core\Rendering\RenderOptions` |

### Fallback order

Without an explicit value, each choice falls back in this order:

| Choice | Order |
|---|---|
| Format | `format()`/`formats()` → `defaults.format` → automatic choice (when `defaults.format` is `null` or `'auto'`; see [Automatic choice](formats.md#automatic-choice)) |
| Profile | `profile()` → `defaults.profile`, where the format allows it → profile of the automatic choice → default profile of the format. CII formats switch to EXTENDED (the profile with more fields than EN 16931) when the invoice uses EXTENDED features and `profile()` was not called (`format_resolver.auto_upgrade_profile`). |
| Locale | `locale()` → invoice locale (`InvoiceBuilder::locale()`) → `defaults.locale` → country pack |
| Country pack | `country()` → seller country (BT-40, see [business terms](concepts.md#en-16931-and-business-terms)) → `defaults.country` → generic EN 16931 pack |
| Template | `template()` → `Presentation::$template` of the invoice → `template` of the presentation passed with `presentation()` → `pdf.template` |
| Validation | `validate()`/`withoutValidation()` → `validation.mode` |

### Render options

`option()` passes values to the view model and the validation rules. They override configured values of the same name:

| Key | Value | Effect |
|---|---|---|
| `watermark` | string | Watermark text, e.g. `'DRAFT'`. Only for the formats `pdf` and `html`: with any format that produces e-invoice XML, the run throws a `ConfigurationException`. Watermarked PDFs are not PDF/A. |
| `paper` | string | Paper size (like `pdf.paper`) |
| `markdown` | bool | Line descriptions and notes as Markdown (like `pdf.markdown`) |
| `fold_marks` | bool | Fold and punch marks (like `pdf.fold_marks`) |
| `girocode_amount` | `'due'`\|`'discounted'` | Amount in the GiroCode, the payment QR code (like `pdf.payment_qr.girocode_amount`) |

### Validation per call

Validation checks the invoice against the rules of EN 16931, the format and the country ([Rules and validation](concepts.md#rules-and-validation)). The validation mode decides what happens with errors:

- In mode `error`, a run with errors throws `Quittio\Core\Validation\ValidationException`. With `validation.block_on_warnings`, warnings throw as well. The exception's `result` property holds the `ValidationResult`.
- In mode `warn`, the result is attached and rendering continues.
- In mode `off`, nothing is validated.

```php
use Quittio\Core\Validation\ValidationException;
use Quittio\Laravel\Facades\Quittio;

try {
    $result = Quittio::make($invoice)->format('xrechnung-cii')->render();
} catch (ValidationException $e) {
    return back()->withErrors(array_map(fn ($m) => $m->message, $e->result->errors()));
}

$result = Quittio::make($invoice)->validate(false)->render();   // never throws on findings
$result->validation()->warnings();
```

`check()` always validates with the configured levels, whatever the mode, and never throws on findings. It does not render the template or the PDF, so the PDF/A check (level L6, veraPDF) does not run:

```php
$report = Quittio::make($invoice)->format('xrechnung-cii')->check();
$report->isValid();
```

`prepare()` returns a `Quittio\Core\Rendering\PreparedInvoice` with the decisions of a run:

| Member | Content |
|---|---|
| `data` | Calculated `InvoiceData` |
| `formats` | Format key => `Profile` (null for formats without XML) |
| `country`, `buyerCountry` | Leading country pack and the buyer's pack (or null) |
| `locale` | Chosen locale |
| `validation` | `ValidationResult` of the data and XML levels |
| `format()`, `profile()` | First format and its profile |

Validation levels and messages: [Validation](validation.md). Common errors and their fixes: [Troubleshooting](troubleshooting.md).

### RenderedInvoice

`render()` returns a `Quittio\Laravel\Rendering\RenderedInvoice` for the first format. It holds the files in memory. It implements `Illuminate\Contracts\Mail\Attachable` and `Illuminate\Contracts\Support\Responsable`, so you can attach it to a mail or return it from a controller.

| Method | Returns |
|---|---|
| `data()` | Calculated `InvoiceData` (totals, VAT breakdown, due date) |
| `format()` | Format key of this result |
| `formats()` | All format keys of the run |
| `as(string $format)` | The result of another format of the same run; a format that was not rendered throws a `LogicException` |
| `profile()` | `Profile` of the XML, `null` for formats without XML |
| `validation()` | `ValidationResult` of this format (warnings; in mode `warn` also errors) |
| `hasPdf()`, `hasXml()` | Whether the format produced a PDF or XML |
| `pdf()` | PDF bytes (`zugferd`, `pdf`); other formats throw a `LogicException` |
| `xml()` | XML (`zugferd`, `xrechnung-cii`, `xrechnung-ubl`, `cii`, `ubl`, `peppol`, `pdf` with `b2c_embed_xml`); other formats throw |
| `html()` | HTML (format `html` only); other formats throw |
| `json()` | The calculated invoice as JSON (`InvoiceData::toJson()`), for every format |
| `contents()` | Main output: PDF, otherwise XML, HTML or JSON |
| `mimeType()` | `application/pdf`, `application/xml`, `text/html` or `application/json` |
| `filename(?string $extension = null)` | File name from `pdf.filename`, e.g. `Rechnung_RE-2026-0042.pdf` ("Rechnung" is the German title, invoice); with `$extension` for another output, e.g. `filename('xml')` |
| `hashes()` | SHA-256 of the outputs: `['pdf' => …, 'xml' => …, 'html' => …]` (only those produced) |
| `download(?string $filename = null)` | HTTP response as attachment |
| `stream(?string $filename = null)` | HTTP response for inline display in the browser |
| `toResponse($request)` | Same as `download()`: return the result from a controller |
| `toMailAttachment()` | `Illuminate\Mail\Attachment` with file name and MIME type |
| `save(?string $disk = null, ?string $path = null)` | Stores the main output on a filesystem disk, returns the path; throws a `RuntimeException` when the disk does not store it |
| `cancel(string $number, ?string $reason = null, ?array $lines = null, Date\|DateTimeInterface\|string\|null $issueDate = null, ?string $strategy = null, ?CancellationHistory $history = null)` | Builds the cancellation of this invoice with `Quittio::cancellation()` and renders it in the formats of this run; returns its `RenderedInvoice` ([Cancellations](#cancellations)) |
| `core()` | The core result `Quittio\Core\Rendering\RenderedInvoice` (adds `extension()` and `save(string $localPath)` for local files) |

## Saving, downloading and mailing

A rendered result is not stored anywhere yet. Save it on a disk, return it as an HTTP response or attach it to a mail. The same methods work for documents (`RenderedDocument`, see [Documents](#documents)).

### File names

Results are named by the pattern `pdf.filename` (default `{title}_{number}.{ext}`). The placeholders are:

- `{title}`: the document title in the render locale, e.g. "Rechnung" (invoice), "Stornorechnung" (cancellation invoice) or, in English, "Invoice",
- `{number}`: the invoice number,
- `{date}`: the issue date as `Y-m-d`,
- `{format}`: the format key (for quotes and other documents: the document type),
- `{ext}`: the extension (`pdf`, `xml`, `html`, `json`).

Title and number are reduced to letters, digits, `.`, `_` and `-`.

```php
// 'filename' => '{date}_{number}.{ext}'
$result->filename();                        // 2026-10-03_RE-2026-0042.pdf
$result->as('xrechnung-cii')->filename();   // 2026-10-03_RE-2026-0042.xml
```

### Responses

Return the result from a controller to send it to the browser:

```php
public function show(Invoice $invoice)
{
    $result = Quittio::make($invoice)->format('zugferd')->render();

    return $result;                             // download (Content-Disposition: attachment)
    // return $result->download('invoice.pdf'); // own file name
    // return $result->stream();                // inline, e.g. in a browser tab or iframe
}
```

The response carries:

- the content as body,
- `Content-Type` from `mimeType()`,
- `Content-Disposition` with an ASCII fallback name and the UTF-8 name (`filename*=`),
- `Cache-Control: private, no-store`.

The ASCII fallback transliterates: `Rechnung Über.pdf` becomes `Rechnung_Uber.pdf`, and `ß` becomes `ss`. Every other character outside letters, digits, `.`, `_` and `-` becomes one `_`. `stream()` sends the complete file inline; it is not a chunked `StreamedResponse`.

### Storage

```php
$path = $result->save();                                  // quittio.storage.disk, file name of the result
$path = $result->save('invoices', '2026/');               // directory (ends with "/"): 2026/Rechnung_RE-2026-0042.pdf
$path = $result->as('xrechnung-cii')->save('invoices', '2026/RE-2026-0042.xml');
```

`save()` stores `contents()` (the main output) with `Storage::disk($disk)->put()` and returns the path:

- The disk defaults to `quittio.storage.disk`, and to the default disk when that is empty.
- The path defaults to `filename()`. A path that ends with `/` is a directory; the file name is added.
- Existing files are overwritten (unlike the archive of the Managed layer).

For a local path without a disk, use `$result->core()->save('/var/invoices')`.

### Mail attachments

`RenderedInvoice` and `RenderedDocument` are attachable. Return them from `attachments()` of a mailable; file name and MIME type come from the result:

```php
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Quittio\Laravel\Facades\Quittio;
use Quittio\Laravel\Rendering\RenderedInvoice;

final class InvoiceMail extends Mailable
{
    public function __construct(private RenderedInvoice $invoice) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Invoice '.$this->invoice->data()->number);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.invoice');
    }

    public function attachments(): array
    {
        return [$this->invoice];                         // file name and MIME type of the result
    }
}

Mail::to($customer)->send(new InvoiceMail(Quittio::make($invoice)->format('zugferd')->render()));
```

`->attach($result)` works in `build()` and in notification mail messages as well.

- For an XRechnung, attach the XML result (`$result->as('xrechnung-cii')`). If the recipient wants a readable PDF too, render a second format `pdf` in the same run and attach it as well.
- For queued mailables, consider storing the file first (`save()`) and attaching it with `Attachment::fromStorageDisk()`, so that the file does not travel through the queue.

The Managed layer ships its own mailable and delivery tracking (see [Managed layer](managed.md#delivery)).

## Queueing

Render in a queued job when a request should not wait for the PDF, e.g. for many invoices at once. The job renders and saves the result on a disk:

```php
$job = Quittio::make($invoice)->format('zugferd')->queue()->save('invoices', '2026/');
$job = Quittio::make($invoice)->queue('redis', 'invoices')->save();
```

`queue()` returns a `Quittio\Laravel\Rendering\QueuedInvoice`. Its only method, `save(?string $disk = null, ?string $path = null)`, dispatches a `Quittio\Laravel\Jobs\RenderInvoice` job and returns it.

| Detail | Behaviour |
|---|---|
| Connection, queue | Arguments of `queue()`, else `quittio.queue.connection` and `quittio.queue.queue`. The default queue is `quittio`: start a worker with `--queue=quittio,…`. |
| Payload | The invoice as versioned JSON snapshot (`invoice`), the `RenderOptions` of the call as versioned JSON (`options`, see `RenderOptions::toArray()`; `renderOptions()` restores them), `disk` and `path` (public properties). A profile object travels by its key; options must be scalars, `null` or arrays of them. |
| Dispatch | Inside a database transaction after the commit, and not at all after a rollback (`quittio.queue.after_commit`, default `true`) |
| Execution | `handle()` renders with the stored options (events included), calls `save($disk, $path)` and dispatches `QueuedInvoiceStored`. A `null` disk is resolved when the job runs. |
| Retries | `$tries = 3` with 10 and 60 seconds between the attempts, for errors that may pass (a disk that is not reachable, a KoSIT daemon that restarts). Errors that another attempt cannot fix fail the job at once: validation errors, invalid or unreadable data, configuration errors (`RenderInvoice::PERMANENT_ERRORS`). |
| Failure | `failed()` dispatches `QueuedInvoiceFailed` with the job and the exception |
| Upgrades | Snapshots of older data model versions are migrated by `quittio.snapshots.upcasters` ([Snapshots](configuration.md#snapshots)); `data()` returns the restored invoice. Jobs queued by Quittio 0.1.0 (options as PHP object) still run. |

With the `sync` connection the job runs immediately.

## Events

Events let you react to rendering, e.g. to log every invoice or to store its hashes. All events are in the namespace `Quittio\Laravel\Events`. They are dispatched by `QuittioManager::renderInvoice()`, i.e. by `PendingInvoice::render()`, by queued `RenderInvoice` jobs and by the rendering of the Managed layer.

| Event | Dispatched | Payload |
|---|---|---|
| `InvoiceRendering` | Before the invoice is calculated, validated and rendered | `invoice` (`InvoiceData` as passed, not calculated), `options` (`RenderOptions`) |
| `InvoiceValidated` | After all formats were rendered, before `InvoiceRendered`, when the run validated and validation did not block it: in mode `error` and in mode `warn`; not in mode `off` (nothing was validated) | `invoice` (calculated `InvoiceData`), `result` (`ValidationResult` of the main format; in mode `warn` it may contain errors, so check `isValid()`) |
| `InvoiceValidationFailed` | When validation blocked the run (mode `error`); the `ValidationException` is thrown afterwards | `invoice` (`InvoiceData` as passed), `result` (`ValidationResult`) |
| `InvoiceRendered` | After all requested formats were produced | `result` (`Quittio\Laravel\Rendering\RenderedInvoice`) |
| `QueuedInvoiceStored` | After a queued `RenderInvoice` job stored the main output, after `InvoiceRendered` | `result` (`RenderedInvoice`), `disk` (of the job, `null` = configured disk), `path` (the stored path) |
| `QueuedInvoiceFailed` | When a queued `RenderInvoice` job failed for good (last attempt, or at once for errors another attempt cannot fix) | `job` (`RenderInvoice`; `$job->data()` returns the invoice), `exception` |
| `MandatoryFieldMissing` | When the template omitted mandatory values ([Mandatory field guard](validation.md#mandatory-field-guard)), in the guard modes `exception` (before the exception is thrown) and `log` | `invoice` (calculated `InvoiceData`), `fields` (list of `Quittio\Core\Countries\MandatoryField` with `accessKey`, `description`, `legalBasis`) |

No events are dispatched for documents, `check()`, `prepare()`, reading or validating files, or while `Quittio::fake()` is active.

```php
use Illuminate\Support\Facades\Event;
use Quittio\Laravel\Events\InvoiceRendered;

Event::listen(function (InvoiceRendered $event) {
    Log::info('Invoice rendered', [
        'number' => $event->result->data()->number,
        'format' => $event->result->format(),
        'hashes' => $event->result->hashes(),
    ]);
});
```

The Managed layer dispatches further events for the lifecycle of stored invoices (see [Managed layer](managed.md#events)).

## Cancellations

A cancellation reverses an issued invoice. It is a new invoice with its own number and a reference to the original ("Stornorechnung", cancellation invoice, on German invoices).

```php
$result = Quittio::make($invoice)->render();
$cancellation = $result->cancel('ST-2026-0001', reason: 'Charged twice');      // rendered in the formats of $result

// stored data: build the cancellation and render it
$builder = Quittio::cancellation($original, 'ST-2026-0001', reason: 'Charged twice');
Quittio::make($builder)->render();

// partial cancellation of line 2 (one unit), with the earlier partial cancellations
Quittio::cancellation($original, 'ST-2026-0002', lines: [['line' => '2', 'quantity' => 1]], history: $history);
```

`$result->cancel()` takes the arguments of `Quittio::cancellation()` (`reason`, `lines`, `issueDate`, `strategy`, `history`). It renders the cancellation in the formats of the invoice, so a cancellation of an XRechnung is an XRechnung again.

The sign strategy decides how the amounts are written: `credit_note` is a credit note (type code 381) with positive amounts, `negative_invoice` an invoice (type code 380) with negative amounts. Both are permitted in Germany.

- The sign strategy comes from the argument, then `corrections.cancellation_strategy`, then the country pack.
- The locale comes from the original, then `defaults.locale`, then the country pack.

Details: [Cancellation](getting-started.md#cancellation), [Cancellations and corrections](building-invoices.md#cancellations-and-corrections) and, for stored invoices, [Managed layer](managed.md#cancellations-and-corrections).

## Documents

Quotes, order confirmations, delivery notes, proforma invoices, payment reminders and account statements are documents: PDFs without e-invoice XML. Start them with the methods of [Starting invoices](#starting-invoices). End the chain with `->render()`, or pass the builder to `Quittio::document()` for more options:

```php
use Quittio\Core\Builder\Party;
use Quittio\Core\Enums\UnitCode;
use Quittio\Laravel\Facades\Quittio;

$quote = Quittio::quote()->number('AN-2026-0007')->issueDate('2026-10-01')
    ->buyer(Party::make('Kunde AG')->address('Weg 1', '54321', 'Beispielstadt', 'DE'))
    ->line('Consulting', netPrice: '100.00', quantity: 2, unit: UnitCode::Hour);

return Quittio::document($quote)->locale('de')->render()->download();
```

`Quittio::document()` returns a `Quittio\Laravel\Rendering\PendingDocument`:

| Method | Effect |
|---|---|
| `template(string $template)` | Template (same allowlist as invoices) |
| `locale(string $locale)` | Locale |
| `country(string $country)` | Leading country pack |
| `presentation(Presentation $presentation)` | Design for this call |
| `htmlOnly()` | Render HTML only, no PDF |
| `render()` | `RenderedDocument` |

Documents never contain XML and are not validated. `RenderedDocument` offers:

- `data()` (`DocumentData`), `hasPdf()` (`false` after `htmlOnly()`), `pdf()` (throws after `htmlOnly()`), `html()`, `contents()`, `mimeType()`, `filename(?string $extension = null)` and `core()`,
- like invoices: `download()`, `stream()`, `toResponse()`, `toMailAttachment()` and `save()`.

Document types and their data: [Quotes and other documents](documents.md).

## Blade templates

The sections from here on describe how to adapt the output. The PDF is rendered from a Blade template. The core prepares the invoice for display (the view model: formatted amounts, labels, design values) and renders it with `Quittio\Laravel\Templates\BladeTemplateEngine`. A view receives one variable, `$invoice` (`Quittio\Core\View\InvoiceViewModel`, see [View model](templates.md#view-model)).

| View | Use |
|---|---|
| `quittio::templates.default.layout` | Default template (`pdf.template`) |
| `quittio::templates.default.partials.*` | Its partials (`letterhead`, `recipient`, `lines`, `totals`, `payment`, …) |
| `invoices.{name}.layout` | Templates created by `make:quittio-template {name}` |

Customise the design in levels (see [Templates and design](templates.md)):

1. the configuration (`pdf.*`, see [PDF and design](configuration.md#pdf-and-design)),
2. published partials: `php artisan vendor:publish --tag=quittio-views` copies them to `resources/views/vendor/quittio/templates`, which Laravel prefers over the package views,
3. an own template (`make:quittio-template`),
4. an own renderer ([Extensions](#extensions)).

Template names may come from invoice data (`Presentation::$template`) or issuer branding. So only these views can be rendered:

- `default`, which means the view of `pdf.template`,
- the view configured in `pdf.template`,
- the shipped views under `quittio::templates.` (not their partials),
- the views of `pdf.templates` (default `['invoices.*']`). An entry ending in `*` allows every view below that prefix, e.g. `mytheme::*`. Views containing `.partials.` never qualify.

Any other existing view fails with an `InvalidArgumentException`. A name that is no Blade view falls back to the plain PHP templates of the core. They resolve names inside their template directories only, never file paths.

```php
// config/quittio.php
'pdf' => [
    'template' => 'invoices.acme.layout',                     // every invoice
    'templates' => ['invoices.*', 'mytheme::*'],              // selectable per call or per invoice
],

Quittio::make($invoice)->template('mytheme::invoice')->render();
```

## Translations

Invoices are printed in the language of the invoice, with Quittio's labels ("Rechnung", "Invoice" …). Labels, rule messages, code names and terms come from the core's language files `labels.php`, `rules.php`, `codes.php` and `terms.php`. They exist in German, English and French; French has no rule messages. They are in the translation namespace `quittio`.

The core translates through Laravel's translator, so the usual overrides work:

```bash
php artisan vendor:publish --tag=quittio-lang     # lang/vendor/quittio/{locale}/*.php
```

A published file only needs the keys you change; Laravel merges it over the package file.

```php
// lang/vendor/quittio/de/labels.php: "Bitte überweisen" (please transfer) instead of "Zahlbetrag" (amount due)
return ['amount_due' => 'Bitte überweisen'];

// or at runtime
app('translator')->addLines(['labels.amount_due' => 'Bitte überweisen'], 'de', 'quittio');
```

- Country packs add their own namespace `quittio-{code}`, e.g. `quittio-de` (`rules.php`, `tax.php`). Override it in `lang/vendor/quittio-de/{locale}/`.
- The Managed layer uses `quittio-managed` (`--tag=quittio-managed-lang`).
- Labels of a single invoice can be changed with `Presentation::$labels`.
- Invoices are translated in the render locale, not in the application locale.

## Preview route

The preview shows the sample invoices in the browser with your design, so you can check logo, colours and texts without creating an invoice. With `quittio.preview.enabled` (default: only in the `local` environment), the provider registers two routes under `quittio.preview.path`, with the middleware `quittio.preview.middleware`:

| Route | Name | Response |
|---|---|---|
| `GET /_quittio/preview` | `quittio.preview.index` | List of the sample scenarios with HTML and PDF links |
| `GET /_quittio/preview/{scenario}` | `quittio.preview.show` | The scenario as HTML, with `?format=pdf` as inline PDF |

Query parameters of `show`:

- `format`: `pdf`; anything else means HTML.
- `template`: one of the views that rendering accepts (see [Blade templates](#blade-templates)): `default`, `pdf.template`, the shipped views under `quittio::templates.` and the views of `pdf.templates`, never partials. The view must exist.
- `locale`: a locale code such as `en` or `de_CH`.

Unknown scenarios, other or missing templates and invalid locales return 404, so no request can name a file or an arbitrary view.

The preview renders the [sample scenarios](testing.md#scenarios) with the configured design, validation mode `warn` and a watermark ("PREVIEW", in German "VORSCHAU"). The locale defaults to the scenario's locale. The routes are not registered while routes are cached (`php artisan route:cache`). If you enable them outside `local`, protect them with middleware such as `['web', 'auth']`. On the command line, use `php artisan quittio:preview` (see [Artisan commands](commands.md#quittiopreview)).

`Quittio\Laravel\Support\Preview` is the service behind route and command:

| Method | Description |
|---|---|
| `scenarios(): array` | Names of the sample scenarios |
| `allowsTemplate(string $template): bool` | Whether the preview may render this template: an existing view that rendering accepts (`BladeTemplateEngine::fromSettings()`), or `default` |
| `allowsLocale(string $locale): bool` | Whether the locale code is well-formed |
| `render(string $scenario, string $format = 'pdf', ?string $template = null, ?string $locale = null): RenderedInvoice` | Renders a scenario (`pdf` or `html`) with the watermark; invalid arguments throw an `InvalidArgumentException` |

## Extensions

`Quittio::extend(string $type, string $name, Closure $factory)` registers a driver: your own PDF renderer, validator, XML writer or format resolver.

- The factory receives the container and the driver's configuration array.
- It must return an instance of the expected interface, otherwise building the core fails with a `ConfigurationException`.
- Unknown types throw an `InvalidArgumentException`.
- `extend()` forgets the built core, so call it in the `boot()` method of a service provider.

| Type | Interface | Used when | Configuration passed |
|---|---|---|---|
| `renderer` | `Quittio\Core\Pdf\PdfRenderer` | `pdf.renderer` is `{name}` | `pdf.renderers.{name}` |
| `validator` | `Quittio\Core\Validation\ValidationDriver` | `{name}` is listed in `validation.levels` | `validation.drivers.{name}` |
| `serializer` | `Quittio\Core\Xml\XmlSerializer` | always; replaces the writer of its syntax | `serializers.{name}` |
| `format_resolver` | `Quittio\Core\Formats\FormatResolver` | `format_resolver.driver` is `{name}` | `format_resolver` (including `peppol_lookup`) |

```php
use Illuminate\Contracts\Container\Container;
use Quittio\Core\Pdf\Renderers\CallbackRenderer;
use Quittio\Laravel\Facades\Quittio;

public function boot(): void
{
    Quittio::extend('renderer', 'gotenberg', fn (Container $app, array $config) => new CallbackRenderer(
        fn (string $html) => $app->make(GotenbergClient::class)->pdf($html, $config),
    ));
}
// config/quittio.php: 'pdf' => ['renderer' => 'gotenberg', 'renderers' => ['gotenberg' => ['url' => '…']]]
```

- The callback of `Quittio\Core\Pdf\Renderers\CallbackRenderer` receives the HTML and the `RenderRequest` and returns any PDF. `CallbackRenderer` then turns it into PDF/A-3 and embeds the XML itself (design level 4 in [Templates and design](templates.md)).
- A class implementing `PdfRenderer` directly receives the `RenderRequest` (`html`, `title`, `author`, `paper`, `pdfa`, `embedded`, `font`) and must produce the PDF/A and embed the file itself.
- A validator driver returns its levels, decides with `supports()` which subjects it checks and returns a `ValidationResult` (see [Validation](validation.md)).

### Macros

`QuittioManager` and `PendingInvoice` are macroable (`macro()`, `mixin()`, `hasMacro()`, `flushMacros()`). Use a macro for a combination of options you need often:

```php
use Quittio\Laravel\Facades\Quittio;
use Quittio\Laravel\Rendering\PendingInvoice;

PendingInvoice::macro('forPublicBuyer', function () {
    /** @var PendingInvoice $this */
    return $this->format('xrechnung-cii')->locale('de');
});

Quittio::make($invoice)->forPublicBuyer()->render();
```

Builder macros are described in [Macros](building-invoices.md#macros).

## Peppol lookup

Peppol is an international network for delivering e-invoices ([Formats](concepts.md#formats)). The default format resolver chooses the format `peppol` only with a lookup that tells whether a buyer is reachable via Peppol (see [Formats and profiles](formats.md#peppol-lookup)). Quittio ships no Peppol directory client, so you provide the lookup.

Name an invokable class in `format_resolver.peppol_lookup`. It is resolved from the container, receives the `BuyerParty` and returns whether the buyer is reachable via Peppol:

```php
use Illuminate\Support\Facades\Cache;
use Quittio\Core\Data\Party\BuyerParty;

final class PeppolDirectoryLookup
{
    public function __construct(private PeppolDirectory $directory) {}

    public function __invoke(BuyerParty $buyer): bool
    {
        $address = $buyer->electronicAddress;                        // BT-49
        if ($address === null) {
            return false;
        }
        $participant = $address->scheme->value.':'.$address->value;  // e.g. 0208:0123456749

        return Cache::remember('peppol:'.$participant, now()->addDay(), fn (): bool => $this->directory->isRegistered($participant));
    }
}
// config/quittio.php: 'format_resolver' => ['peppol_lookup' => PeppolDirectoryLookup::class, …]
```

The lookup runs for every automatic choice with a business buyer that no domestic country rule decided (for German sellers: cross-border invoices), so cache its answers. A resolver registered with `Quittio::extend('format_resolver', …)` receives the value in its configuration and decides itself whether to use it.

## Logging

The manager passes Laravel's logger (`Psr\Log\LoggerInterface`, the default log channel) to the core. The logger receives the warnings of the mandatory field guard in mode `log` (`validation.mandatory_field_guard`; the default in production). To route them elsewhere, listen for the `MandatoryFieldMissing` event, which is dispatched in the modes `log` and `exception`.

## Country packs

Country packs bring the rules, tax presets and texts of a country. They are discovered from Composer metadata, listed in `quittio.countries` or registered with `Quittio::registerCountry()` (see [Configuration](configuration.md#country-packs)). Their translations, profiles, formats and rules are registered with them. Available packs and writing your own: [Country packs](country-packs.md).

## Managed layer

`quittio/managed` stores invoices in the database and builds on this package:

- It renders through the same `QuittioManager`, so configuration, templates, events and `Quittio::fake()` apply.
- It reads `pdf.template` and `pdf.templates` to check issuer branding.
- It uses `corrections.cancellation_strategy`.
- It adds commands and implements two contracts of this package:

| Contract | Used by |
|---|---|
| `Quittio\Laravel\Contracts\ManagedInstaller` | `php artisan quittio:install --managed` (migrations, first issuer profile) |
| `Quittio\Laravel\Contracts\ManagedInvoiceRenderer` | `php artisan quittio:render {invoice}` (render a stored invoice again) |

Issuer profiles replace the `seller` section, and the Managed archive uses its own disk and queue settings (`config/quittio-managed.php`). See [Managed layer](managed.md).

## Reference

### Facade

```php
use Quittio\Laravel\Facades\Quittio;
```

Instead of the facade you can inject `Quittio\Laravel\QuittioManager`; both are the same singleton.

| Method | Returns | Description |
|---|---|---|
| `make(InvoiceData\|ProvidesInvoiceData\|InvoiceBuilder $data)` | `PendingInvoice` | Starts a render call. A builder is built, a `ProvidesInvoiceData` model converted with `toInvoiceData()`. See [Rendering invoices](#rendering-invoices). |
| `invoice()` | `RenderableInvoiceBuilder` | A new invoice with the seller of the configuration (or of the current issuer of the Managed layer); ends with `build()` or `render()` (see [Starting invoices](#starting-invoices)). |
| `quote()`, `orderConfirmation()`, `deliveryNote()`, `proforma()`, `paymentReminder(int $level = 0)`, `accountStatement()` | `RenderableDocumentBuilder` | A new document with the seller of the configuration; ends with `build()` or `render()`. |
| `document(DocumentData\|DocumentBuilder $document)` | `PendingDocument` | Starts a render call for a quote, order confirmation, delivery note, proforma invoice, payment reminder or account statement (see [Documents](#documents)). |
| `cancellation(InvoiceData $original, string $number, ?string $reason = null, ?string $strategy = null, ?Date $issueDate = null, ?array $lines = null, ?CancellationHistory $history = null, ?string $title = null)` | `InvoiceBuilder` | Builder for the cancellation of an issued invoice; render it with `make()`. `$title` is the document title in the PDF, e.g. "Stornorechnung" (cancellation invoice); `null` = the title of the country pack's correction policy. See [Cancellations](#cancellations). |
| `check(InvoiceData $invoice, RenderOptions $options)` | `ValidationResult` | Validates like a render run; the fluent form is `Quittio::make($invoice)->check()`. |
| `prepare(InvoiceData $invoice, RenderOptions $options)` | `PreparedInvoice` | Calculates and validates without rendering; fluent form `->prepare()`. |
| `read(string $content)` | `ReadResult` | Reads an incoming XML or PDF e-invoice from its content (bytes, never a path). See [Reading incoming invoices](reading.md). |
| `readFile(string $path)` | `ReadResult` | The same from a file |
| `validateFile(string $content)` | `ValidationResult` | Reads and validates an incoming XML or PDF (content) with all configured levels. See [Validation](validation.md#incoming-files). |
| `validatePath(string $path)` | `ValidationResult` | The same from a file |
| `renderInvoice(InvoiceData $invoice, RenderOptions $options)` | `RenderedInvoice` | The render run behind `PendingInvoice::render()`, with events |
| `renderDocument(DocumentData $document, RenderOptions $options)` | `RenderedDocument` | The render run behind `PendingDocument::render()` |
| `extend(string $type, string $name, Closure $factory)` | `QuittioManager` | Registers a renderer, validator, serializer or format resolver (see [Extensions](#extensions)) |
| `registerCountry(CountryPack $pack)` | `QuittioManager` | Registers a country pack; it wins over configured and discovered packs with the same code |
| `defaultVat(?string $sellerCountry, bool $smallBusiness)` | `?TaxPreset` | Default VAT of lines without `->vat()`: `defaults.vat`, otherwise the standard preset of the seller's country pack, for small businesses its small business preset ([Default VAT](building-invoices.md#default-vat)). The service provider installs it with `InvoiceBuilder::useDefaultVat()`. |
| `countries()` | `CountryRegistry` | Active country packs: `all()`, `has($code)`, `get($code)`, `getOrGeneric($code)` |
| `profiles()` | `ProfileRegistry` | Profiles of the core and the country packs: `all()`, `has($key)`, `get($key)`, `bySpecificationId($id)` |
| `formats()` | `FormatRegistry` | Output formats: `all()`, `has($key)`, `get($key)` |
| `settings()` | `Settings` | Typed access to `config('quittio')` (see [Settings](#settings)) |
| `flush()` | `QuittioManager` | Forgets the built core; the next call builds it again. Configuration changes do not need it |
| `core()` | `Quittio\Core\Quittio` | The core instance, e.g. `Quittio::core()->config()` or `->validators()` |
| `coreWithLevels(array $levels)` | `Quittio\Core\Quittio` | A new core (the cached one stays unchanged) that validates with `validation.levels` plus `$levels`, e.g. `['kosit', 'verapdf']`, every driver with its settings of `validation.drivers.*`; used by `quittio:validate --kosit --verapdf` |
| `macro()`, `mixin()`, `hasMacro()`, `flushMacros()` | – | Macros of the manager (see [Macros](#macros)) |
| `fake()` | `QuittioFake` | Replaces rendering in tests (see [Testing](testing.md#fake)) |
| `assertRendered()`, `assertRenderedCount()`, `assertNothingRendered()`, `assertDocumentRendered()`, `rendered()`, `documents()` | – | Assertions and recordings of the fake, only after `fake()` |

The manager builds the core lazily, on the first call that needs it. It uses the configuration, the country packs, the Blade template engine, the PDF renderer, the logger and your extensions.

### Settings

`Quittio::settings()` returns `Quittio\Laravel\Support\Settings`: typed read access to `config('quittio')` with dot notation. Wrong types throw a `ConfigurationException` naming the key.

| Method | Returns |
|---|---|
| `get(string $key, mixed $default = null)` | Raw value |
| `string(string $key, string $default)` | String; `null` and `''` give the default |
| `nullableString(string $key)` | String or `null` |
| `bool(string $key, bool $default)` | Boolean |
| `int(string $key, int $default)` | Integer |
| `array(string $key)` | Array (missing = `[]`) |
| `strings(string $key)` | The string values of a list |
| `section(string $key)` | `Settings` for a sub-array, e.g. `section('validation.drivers.kosit')` |

### Service provider

The package registers itself through Laravel's package discovery (`composer.json`: `extra.laravel`): the provider `Quittio\Laravel\QuittioServiceProvider` and the alias `Quittio` for `Quittio\Laravel\Facades\Quittio`. With `"dont-discover": ["quittio/laravel"]` in your `composer.json`, register both yourself in `bootstrap/providers.php` and `config/app.php`.

| Registered | Details |
|---|---|
| Configuration | `config/quittio.php` merged with the package defaults (see [Configuration](configuration.md)) |
| `Quittio\Laravel\QuittioManager` | Singleton behind the facade, also available as `app('quittio')` |
| `Quittio\Laravel\Contracts\InvoiceDefaults` | Bound to `ConfigInvoiceDefaults` unless already bound (see [Seller from the configuration](#seller-from-the-configuration)) |
| `Quittio\Laravel\Support\Preview` | Singleton that renders the sample scenarios (see [Preview route](#preview-route)) |
| `Quittio\Core\Serialization\Snapshots\SnapshotUpcasterChain` | Singleton with the upcasters of `quittio.snapshots.upcasters`; also the default chain of `InvoiceData::fromSnapshot()` |
| Default VAT | `InvoiceBuilder::useDefaultVat()` with `Quittio::defaultVat()` (see [Default VAT](building-invoices.md#default-vat)) |
| Views | Namespace `quittio` (`quittio::templates.default.layout`, its partials, `quittio::preview.index`) |
| Translations | Namespace `quittio` with the labels and messages of the core; the core uses Laravel's translator (see [Translations](#translations)) |
| Routes | The preview routes, when enabled |
| Commands | `quittio:*` and `make:quittio-template` (console only, see [Artisan commands](commands.md)) |
| Publish tags | `quittio-config`, `quittio-views`, `quittio-lang`, `quittio-fonts` (see [Artisan commands](commands.md#publish-tags)) |
| `php artisan about` | Section "Quittio" with the active country packs and profiles |
