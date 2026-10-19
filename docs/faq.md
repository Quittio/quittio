# FAQ

Short answers to questions that come up before and after the first invoice, with links to the pages that explain the details. For error messages, see [Troubleshooting](troubleshooting.md).

## Does Quittio send invoices?

No. Quittio creates, validates, reads and renders invoices; how they reach the buyer is up to your application:

- **E-mail:** attach the result to a mailable; `RenderedInvoice` implements Laravel's `Attachable` ([Laravel integration](laravel.md)).
- **Peppol:** the format `peppol` writes UBL according to Peppol BIS Billing 3.0. Sending it needs a Peppol access point, a service provider connected to the network; hand it the XML from `$result->xml()` ([Peppol BIS Billing 3.0](formats.md#peppol-bis-billing-30)). A Peppol lookup lets the automatic choice pick `peppol` for buyers that are reachable there ([Peppol lookup](formats.md#peppol-lookup)).
- **Public buyers in Germany:** upload the XRechnung to the portal the authority names, or send it via Peppol ([Examples: Public sector](examples.md#public-sector)).

## Which format meets the German e-invoicing obligation?

Since 1 January 2025 every business in Germany must be able to receive e-invoices. Sending them becomes mandatory step by step: the transitional rules of section 27 (38) UStG still allow other invoices for domestic business-to-business supplies until the end of 2026, and in 2027 for businesses whose total turnover of the previous year was at most 800,000 euros. From 2028 domestic business invoices must be e-invoices. Invoices to consumers and small amount invoices up to 250 euros are not affected ([E-invoicing basics](concepts.md)).

An e-invoice in this sense is a structured format that conforms to EN 16931. With Quittio these are:

| Format | Profile |
|---|---|
| `zugferd` / `factur-x` (PDF with embedded XML) | `en16931` (the default) or `extended`; not `minimum` or `basic-wl` |
| `xrechnung-cii`, `xrechnung-ubl` | `xrechnung` (required for public buyers) |
| `cii`, `ubl` | `en16931` |
| `peppol` | `peppol` |

A plain `pdf` is no e-invoice. MINIMUM and BASIC WL carry no lines and do not conform to EN 16931; the validation warns with `QUITTIO-PROFILE-NOT-EINVOICE`. The German country pack chooses `zugferd` for domestic business invoices, XRechnung for public buyers and `pdf` for consumers and small amount invoices ([German format choice](country-packs.md#format-choice)). If you choose another format yourself, it checks the obligation by the supply date: a warning during the transition (`DE-EINVOICE-02`), an error once an e-invoice is required (`DE-EINVOICE-01`, [E-invoice obligation](country-packs.md#e-invoice-obligation)).

This is a summary, not legal advice. The legal wording of the country pack was reviewed by a tax adviser in October 2026; see the legal review and the disclaimer in the README of the repository.

## Do I need Java?

Only for the official Schematron check (validation level L5) with the KoSIT validator, which runs on Java. Building, calculating, validating levels L1 to L4 and L7, writing XML, rendering PDF/A and reading invoices are pure PHP.

The KoSIT validator can run as a daemon in another container or on another host, so the PHP application itself does not need Java ([KoSIT validator](validation.md#kosit-validator-l5)). The PDF/A check (L6) uses veraPDF, locally or through Docker. Both are off by default.

## Which exceptions does reading throw?

Reading throws only when the file cannot be read as an invoice at all: `UnsafeXmlException` (too large, not well-formed, DOCTYPE, limits), `PdfException` (encrypted, malformed or too large PDF) and `UnreadableInvoiceException` (unknown format, ZUGFeRD 1.0, a PDF without embedded invoice, a missing file). All three implement `Quittio\Core\Exceptions\QuittioException`. Everything else, for example totals that differ from a recalculation, becomes a finding of the result ([Reading: Errors](reading.md#errors)).

```php
use Quittio\Core\Exceptions\QuittioException;
use Quittio\Laravel\Facades\Quittio;

try {
    $read = Quittio::readFile($path);
} catch (QuittioException $e) {
    // not an invoice Quittio can read: reject the upload
}
```

## Where does mPDF write temporary files?

mPDF needs a writable temporary directory for fonts and images; Quittio also caches prepared logos in its `logos` subdirectory. In Laravel it is `pdf.cache_path` (default `storage/framework/cache/quittio`), without Laravel `temp_dir` of the configuration (default `sys_get_temp_dir()/quittio-mpdf`). On read-only file systems and in containers, point it to a writable volume ([Configuration](configuration.md)).

## How much time and memory does rendering take?

It depends on the template, the number of lines and pages, logos and fonts. Measure it on your server with the sample invoices:

```bash
php artisan quittio:benchmark --scenario=many-lines --count=20
```

The command prints the average, minimum, 95th percentile and maximum time and the peak memory ([Artisan commands](commands.md)). Invoices over several pages take longer, because the line table is laid out again for the carry-over at each page break. For many invoices at once, render in the queue.

## What happens to queued invoices after an update?

A queued render (`->queue()->save()`) stores the invoice as a versioned JSON snapshot and the render options as versioned JSON, not as PHP objects. When the data model changes between the dispatch and the run of the job, the configured upcasters bring the snapshot up to date (`quittio.snapshots.upcasters`), so the job renders the current model ([Queueing](laravel.md#queueing), [Snapshots](configuration.md#snapshots)). Queued documents (`Quittio::document($quote)->queue()->save()`) work the same way with their own snapshot version and `quittio.snapshots.document_upcasters` ([Documents: queueing](documents.md#queueing)). Let the queue run empty before an update anyway, as for any job whose code changes.
