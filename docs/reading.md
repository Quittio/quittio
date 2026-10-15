# Reading incoming invoices

This page is about e-invoices you receive, for example from suppliers. Quittio reads XML files (CII and UBL, including XRechnung and Peppol) and PDFs with embedded XML (ZUGFeRD, Factur-X). It returns their content in the same data model that you use to build invoices, so you can take the values into your accounting.

If these formats are new to you, see [Formats](concepts.md#formats), [Syntaxes](concepts.md#syntaxes-cii-and-ubl) and [Profiles](concepts.md#profiles) in the E-invoicing basics; field numbers such as BT-24 are explained in [EN 16931 and business terms](concepts.md#en-16931-and-business-terms).

## Reading a file

```php
use Quittio\Laravel\Facades\Quittio;

$read = Quittio::read($request->file('invoice')->get());   // content of an uploaded XML or PDF
$read = Quittio::readFile('/path/to/invoice.pdf');          // or a local file

$invoice = $read->data;          // InvoiceData as stated in the document
$invoice->number;                // e.g. "RE-2026-0101"
$invoice->seller->name;          // the supplier
$invoice->totals?->amountDue;    // amount due as stated in the document

$read->profile;                  // detected profile key, e.g. "en16931" or "xrechnung"
$read->findings;                 // problems found while reading, e.g. QUITTIO-DECLARED-TOTALS
$read->declaredTotals;           // totals as stated in the document
$read->embeddedFilename;         // for PDFs: factur-x.xml, xrechnung.xml, zugferd-invoice.xml …
```

You get back a `ReadResult` ([The result](#the-result)):

- `data` is an `InvoiceData` with the values as stated in the document. Amounts are never corrected. Totals that do not match the lines are reported as finding `QUITTIO-DECLARED-TOTALS` ([Declared amounts](#declared-amounts)).
- `findings` lists problems found while reading, e.g. a missing issue date ([Findings](#findings)).
- `profile` is the profile detected from the document, e.g. `xrechnung`.

`read()` always treats its argument as the content of the document, never as a file name. You can pass request bodies (`$request->getContent()`) and uploads directly.

`readFile()` reads a local file. It rejects paths with null bytes, missing or unreadable files, and files larger than the larger of the XML and PDF limits (`UnreadableInvoiceException`).

Without Laravel, both methods exist on `Quittio\Core\Quittio` and use the limits of its configuration ([Limits and security](#limits-and-security)). A file that is no supported e-invoice throws an exception ([Errors](#errors)).

## Checking an incoming invoice

Reading never validates the whole document. Before you book an invoice, check `$read->findings`, or validate the file:

```php
use Quittio\Laravel\Facades\Quittio;

$report = Quittio::validateFile($content);   // content of an XML or PDF
$report = Quittio::validatePath($path);      // or a local file

if (! $report->isValid()) {
    // reject the invoice, e.g. show $report->errors()
}
```

`validateFile()` and `validatePath()` read the file and run all configured validation levels on it ([Incoming files](validation.md#incoming-files)). A complete controller that validates an upload and extracts the values for accounting is in [Incoming e-invoices](examples.md#incoming-e-invoices).

## On the command line

```bash
php artisan quittio:read invoice.pdf          # number, date, type, syntax and profile, parties, lines, totals, findings
php artisan quittio:read invoice.xml --json   # InvoiceData as JSON
php artisan quittio:validate invoice.pdf      # validation report
```

See [quittio:read](commands.md#quittioread) and [quittio:validate](commands.md#quittiovalidate).

## Supported documents

| Input | Recognised by | Syntax | Content |
|---|---|---|---|
| CII XML | root `CrossIndustryInvoice` in `urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100` | `cii` | ZUGFeRD 2.x / Factur-X MINIMUM, BASIC WL, BASIC, EN 16931, EXTENDED; XRechnung CII including the extension |
| UBL XML | root `Invoice` or `CreditNote` in the UBL 2.1 namespaces | `ubl` | EN 16931, XRechnung UBL including the extension, Peppol BIS Billing 3.0 |
| PDF | content starts with `%PDF` (leading whitespace allowed) | of the embedded XML | ZUGFeRD 2.x / Factur-X, XRechnung embedded in a PDF |

Everything else fails:

- ZUGFeRD 1.0 (`urn:ferd:CrossIndustryDocument:invoice:1p0`) does not conform to EN 16931 and is rejected with a clear message.
- Other root elements fail with "Unknown invoice format".
- PDFs without embedded invoice fail with "The PDF does not contain an embedded e-invoice".

The profile is detected from the specification identifier (BT-24). In CII it is `GuidelineSpecifiedDocumentContextParameter`, in UBL `cbc:CustomizationID`. Quittio compares it exactly with the identifiers of the installed profiles, for EN 16931:2017 and EN 16931:2026. Country pack profiles such as XRechnung count once the pack is registered.

- An unknown identifier leaves `profile` `null` and is reported as information (`QUITTIO-READER-UNKNOWN-PROFILE`).
- An identifier starting with `urn:cen.eu:en16931:2026` sets the standard version of the data to EN 16931:2026.

## Errors

The reader throws an exception only when it cannot read the document at all:

| Exception | When |
|---|---|
| `Quittio\Core\Xml\UnsafeXmlException` | The XML is too large, not well-formed, has a DOCTYPE, an unsupported encoding or exceeds a tag limit |
| `Quittio\Core\Pdf\PdfException` | The PDF is too large, encrypted, malformed, exceeds a structure or decompression limit or uses an unsupported stream filter |
| `Quittio\Core\Reading\UnreadableInvoiceException` | Unknown format, ZUGFeRD 1.0, a PDF without embedded invoice, a file that does not exist, is not readable or is too large |

All three implement `Quittio\Core\Exceptions\QuittioException`. `validateFile()` and `validatePath()` throw the same exceptions. Everything the reader can read becomes a finding instead.

## The result

`Quittio\Core\Reading\ReadResult` (readonly):

| Property | Content |
|---|---|
| `data` | `InvoiceData` as stated: lines with their declared net amounts, the declared totals and VAT breakdown, all other business terms |
| `syntax` | `cii` or `ubl` |
| `specificationId` | BT-24 as written, or `null` |
| `profile` | key of the detected profile (`en16931`, `extended`, `xrechnung`, `peppol` …) or `null` |
| `declaredTotals` | `Totals` as stated (BT-106 to BT-115), `null` if the document has no monetary summation |
| `declaredVatBreakdown` | `list<VatBreakdown>` as stated (BG-23) |
| `findings` | `list<ValidationMessage>` (see [Findings](#findings)) |
| `xml` | the XML that was read (for a PDF: the embedded XML) |
| `raw` | the input: the PDF bytes, or the XML |
| `embeddedFilename` | name of the embedded file for PDFs, otherwise `null` |

`hasFindings()` tells whether there are findings. Extended data is read into the same data model as when building invoices ([field reference](field-reference.md)): Factur-X EXTENDED, sub lines from `ParentLineID` or `cac:SubInvoiceLine`, and XRechnung third party payments.

### Declared amounts

Totals are never corrected silently. The reader keeps the amounts as stated and recalculates a copy from the lines. Every difference is reported as `QUITTIO-DECLARED-TOTALS` (warning, level L3). It compares:

- the net amount of every line (BT-131),
- the sum of line net amounts (BT-106), the total without VAT (BT-109), the VAT total (BT-110), the total with VAT (BT-112) and the amount due (BT-115).

Documents without lines (Factur-X MINIMUM, BASIC WL) are not recalculated. MINIMUM does not transmit BT-106; it is derived from BT-109, BT-107 and BT-108 (`QUITTIO-READER-DERIVED`).

EN 16931 carries the VAT exemption reason (BT-120, BT-121) only on the VAT breakdown. The reader copies it back to the lines, allowances and charges of the VAT group, so a recalculation keeps it.

A read invoice can be the start of a builder (`InvoiceBuilder::from($read->data)`) or be cancelled ([Building invoices](building-invoices.md)). To keep its declared amounts there, the reader stamps a fingerprint of the amount inputs into the meta data (`$read->data->meta->amountsFingerprint()`). As long as lines, prices, allowances, charges, VAT breakdown and currency are unchanged, `InvoiceBuilder::from($read->data)` and cancellations of the read invoice keep:

- declared line net amounts within 0.02 of quantity × price ± allowances and charges,
- declared VAT amounts less than one currency unit from the recalculation, e.g. when another system rounded per line.

Data changed before (e.g. with `with()`) no longer matches the fingerprint and is recalculated completely. `Quittio::make($read->data)` recalculates everything.

The price base quantity (BT-149, BT-150) is read from the net price, or from the gross price if only the gross price states it. If both state different base quantities (CII), the gross price and discount are converted to the base quantity of the net price. With different units they are kept as stated, and a warning is reported.

## Findings

The reader is tolerant: it reads as much of the document as possible and reports problems as findings instead of failing. Codes starting with `BR-` are the EN 16931 rules that the problem breaks ([Rules and validation](concepts.md#rules-and-validation)). Findings have level L1 unless noted. Errors mean that values are missing or could not be read as stated:

| Code | Severity | Meaning |
|---|---|---|
| `BR-03` | error | The issue date (BT-2) is missing or unreadable. The placeholder `1970-01-01` is used, never the current date. |
| `BR-05`, `BR-CL-04` | error | The invoice currency (BT-5) is missing or not an ISO 4217 code. The amounts are read in the currency of the amounts, otherwise in `XXX` (no currency). |
| `QUITTIO-READER-CURRENCY` | error / warning | Amounts use another currency than BT-5 and are read as BT-5 (error); only the VAT total in accounting currency (BT-111) may differ. In CII a BT-110 without `currencyID` is read in the invoice currency (warning). |
| `QUITTIO-READER-EXCHANGE-RATE` | warning | The exchange rate (CII) converts other currencies than BT-5 and BT-6. |
| `QUITTIO-READER-DATE` | error | A date is not in the format of the syntax: CII format 102 (`YYYYMMDD`), UBL `YYYY-MM-DD` with an optional time zone. The date is not read. |
| `QUITTIO-READER-NUMBER` | error | A number is not a decimal number (exponents like `1e3`, separators, more than 40 characters) or a payment term period is no whole number. The value is not read. |
| `BR-DEC-…` | error | An amount whose business term has a BR-DEC rule has more than two decimals. It is rounded to two decimals. |
| `QUITTIO-READER-DECIMALS` | warning | Another amount has more than two decimals and is rounded. |
| `BR-12` … `BR-15`, `BR-45`, `BR-46` | error | A mandatory amount (BT-106, BT-109, BT-112, BT-115, BT-116, BT-117) is missing; 0.00 is used. Without the document totals (BG-22: CII `SpecifiedTradeSettlementHeaderMonetarySummation`, UBL `LegalMonetaryTotal`) `BR-12` (only with lines) to `BR-15` are reported and `data->totals` and `declaredTotals` stay `null`; `validateFile()` then reports these instead of `QUITTIO-NOT-CALCULATED`. |
| `QUITTIO-READER-DERIVED` | info | BT-106 was derived for a document without lines. |
| `BR-22`, `BR-23` | error | A line has no quantity (0 is used) or no unit. |
| `QUITTIO-READER-SUBLINE-QUANTITY`, `QUITTIO-READER-SUBLINE-UNIT` | info | A sub line of the extension omits quantity or unit. |
| `QUITTIO-READER-DUPLICATE-LINE` | warning | A line identifier is used more than once (CII). |
| `QUITTIO-READER-PARENT-LINE` | warning / error | A sub line refers to a parent line that does not exist (warning), to itself, to a cycle or to a hierarchy deeper than 32 levels (error); it is read as a top-level line (CII). |
| `QUITTIO-READER-BASE-QUANTITY` | info / warning | Net and gross price state different base quantities (CII): converted (info) or not convertible (warning). |
| `QUITTIO-READER-VAT-CATEGORY` | error | Unknown VAT category; `S` is assumed. |
| `QUITTIO-READER-UNKNOWN-CODE` | info / warning | A code that this package version does not know (unit, scheme, reason codes …) is kept as raw value (info); a VAT point date code outside the code list (warning). Codes of the official code lists without an enum case are read without finding. |
| `QUITTIO-READER-UNIT` | warning | A payment terms period is not given in days or weeks (CII); weeks are converted to days. |
| `QUITTIO-READER-INSTALLMENT` | warning | A partial payment (EXTENDED) without due date is skipped. |
| `QUITTIO-READER-ATTACHMENT` | warning | An attached document has invalid Base64, an unsupported MIME code or no usable file name and is skipped. |
| `QUITTIO-READER-UNKNOWN-PROFILE` | info | The specification identifier (BT-24) is not known to this installation. |
| `QUITTIO-READER-PDF-CANDIDATES` | warning | The PDF embeds several invoice files with different content, or several XML files without a known name; the chosen file is named. |
| `QUITTIO-READER-PDF-AF` | warning | The embedded file is not an associated file (`/AF`), as PDF/A-3 requires. |
| `QUITTIO-READER-PDF-RELATIONSHIP` | warning | The `/AFRelationship` of the embedded file does not match the profile (`Alternative`, or `Data` for MINIMUM and BASIC WL). |
| `QUITTIO-DECLARED-TOTALS` | warning (L3) | The declared amounts differ from a recalculation from the lines. |
| `QUITTIO-READER-RECALCULATION` | warning (L3) | The invoice could not be recalculated for the comparison. |

Unknown codes are errors in `validateFile()`, where the code list rules (`BR-CL-…`) run.

## PDF

A ZUGFeRD or Factur-X PDF carries the invoice XML as embedded file ([Embedded files](formats.md#embedded-files)). The reader finds embedded files in the associated files (`/AF`) of the document catalog and in the name tree (`/Names /EmbeddedFiles`). This works also in PDFs with cross-reference streams, object streams, hybrid files and incremental updates. Damaged cross-reference data is rebuilt by scanning the file.

The file is chosen in this order:

1. A known file name (`factur-x.xml`, `xrechnung.xml`, `zugferd-invoice.xml`, `ZUGFeRD-invoice.xml`, case-insensitive) before any other `.xml` file.
2. Among those, files referenced from `/AF` before files only in the name tree.
3. Then the order of the known names and of the document.

Up to three further candidates with a known name are compared with the chosen file; different content is reported (`QUITTIO-READER-PDF-CANDIDATES`).

Encrypted PDFs cannot be read. Streams must use `FlateDecode` (with PNG or TIFF predictors) or no filter.

## Limits and security

Incoming files come from outside, so the reader limits what it accepts:

| Protection | Limit |
|---|---|
| XML size | `quittio.reader.max_xml_bytes` (10 MB); without Laravel `reader.max_xml_bytes` |
| PDF size | `quittio.reader.max_pdf_bytes` (25 MB) |
| Decompressed size of every PDF stream | `quittio.reader.max_decompressed_bytes` (50 MB) |
| DOCTYPE | Rejected (also an internal subset), so there are no external entities, no DTDs and no entity expansion; libxml runs without network access and without entity substitution |
| Encoding | UTF-8, UTF-16, US-ASCII, ISO-8859-*, Windows-125*; UTF-32, UTF-7, EBCDIC and other encodings are rejected |
| Start tags | at most 64 KB and 256 attributes per element, at most 256 namespace declarations in scope (checked before parsing) |
| Numbers | decimal literals of at most 40 characters, no exponents |
| PDF structure | at most 1,000,000 objects, 100 cross-reference sections, reference chains of 32, 100,000 objects per object stream, 100,000 objects when rebuilding, nesting depth 64; name tree depth 10 and 1,000 nodes, 1,000 file specifications, every node visited once |
| Sub lines | hierarchies deeper than 32 levels are flattened (CII) |
| File names | embedded file names and attachment file names are never used as paths: directory parts and control and format characters (e.g. a right-to-left override) are removed, `.`, `..` and empty names are rejected |

Untrusted values are shortened in messages, and the currency of amounts is compared in PHP, never inside an XPath expression. The configuration keys are described in [Reader](configuration.md#reader).

## Own readers

`Quittio\Core\Reading\InvoiceReader` can be used directly:

```php
use Quittio\Core\Pdf\PdfEmbeddedFileExtractor;
use Quittio\Core\Reading\InvoiceReader;

$reader = new InvoiceReader(
    readers: null,                                                   // null = InvoiceReader::defaultReaders(): CII and UBL
    profiles: null,                                                  // null = ProfileRegistry::withDefaults() (no country pack profiles)
    pdfExtractor: new PdfEmbeddedFileExtractor(maxPdfBytes: 25 * 1024 * 1024, maxDecompressedBytes: 50 * 1024 * 1024),
    maxXmlBytes: 10 * 1024 * 1024,
);
$read = $reader->withReader(new MyFormatReader)->readFile('/path/to/invoice.xml');
```

A reader for another XML format implements `FormatReader` (`canRead(DOMDocument $document): bool`, `read(DOMDocument $document, string $xml): ReadResult`). `withReader()` returns a copy with the reader added after the default ones.

Country packs provide readers for national formats with `CountryPack::readers()`. It returns new instances on every call, because readers keep the state of one document. `Quittio::read()`, `readFile()`, `validateFile()` and `validatePath()` try them after the CII and UBL readers, for every registered pack. The XSD driver (L4) knows only CII and UBL and reports other root elements (`XSD-UNKNOWN-ROOT`); add a validator for a national format with `addValidator()`.

A PDF extractor implements one of two interfaces:

- `EmbeddedXmlExtractor::extract(string $pdf): ?array` returns file name and XML,
- `DiagnosingXmlExtractor::extractFile(string $pdf): ?ExtractedXml` returns `filename`, `xml`, `relationship` and `findings`.

`PdfEmbeddedFileExtractor::names($pdf)` lists the names of all embedded files.
