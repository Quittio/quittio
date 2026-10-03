# Templates and design

| Level | What you do |
|---|---|
| 1 – Configuration | Logo, colours, font, texts, footer and sender line in `config/quittio.php` (`pdf.*`) |
| 2 – Partials | `php artisan vendor:publish --tag=quittio-views` and change single partials |
| 3 – Own template | `php artisan make:quittio-template my-design`, then `->template('invoices.my-design.layout')` or `pdf.template` |
| 4 – Own renderer | `Quittio::extend('renderer', …)`; PDF/A and the embedded XML stay with the package |

## View model

Templates receive `$invoice` (`InvoiceViewModel`) and nothing else. Every value is available formatted (`{{ $invoice->totals->amountDue }}`) and raw (`$invoice->totals->amountDue->raw`). Templates contain no calculations. Main keys: `number`, `title`, `issueDate`, `dueDate`, `servicePeriod`, `seller`, `buyer`, `delivery`, `references`, `lineGroups`, `lines`, `allowances`, `charges`, `vatRows`, `totals`, `payment` (incl. `qrCode`), `paymentTerms`, `legalNotes`, `notes`, `attachments`, `texts`, `labels`, `design`, `document`, `documentInfo`.

Labels come from `lang/vendor/quittio/{locale}/labels.php` (publish with `--tag=quittio-lang`) and can be overridden per invoice via `Presentation::$labels`.

## Mandatory field guard

After rendering, the guard checks that the template printed every mandatory value (number, dates, parties, VAT identifiers, service date, VAT rows, amount due, exemption notes, "Gutschrift" …). A custom template that omits one fails with `MandatoryFieldMissing` (`validation.mandatory_field_guard = exception`, default outside production) or logs it (`log`, default in production). The event `MandatoryFieldMissing` is dispatched in both modes.

## mPDF rules

- Layout with tables, not flexbox or grid.
- Inside table cells, mPDF ignores `margin` and `padding` of blocks (`div`, `p`). Create spacing there with the padding of a table cell, e.g. a nested table with one row per block.
- Keep `pdf.renderers.mpdf.simple_tables` off: it drops the borders of table cells.
- Position elements on the page with `position: absolute` (measured from the page edge); `position: fixed` is measured from the page margins.
- Repeated table headers via `<thead>`.
- Headers and footers via `<htmlpageheader>` / `<htmlpagefooter>`, page numbers with `{PAGENO}` and `{nbpg}`.
- `page-break-inside: avoid` for the totals block.
- Images as PNG or SVG in target size; fonts must be embeddable (Inter is bundled).

## Letter layouts

Country packs provide letter layouts. Germany: `din5008a` (address field 27 mm from the top) and `din5008b` (45 mm, default); fold and punch marks with `pdf.fold_marks = true`.

## Preview

`/_quittio/preview` (local environment, or `quittio.preview.enabled = true`) shows the sample scenarios as HTML and PDF with a "PREVIEW" watermark. On the command line: `php artisan quittio:preview --scenario=many-lines --format=pdf`.
