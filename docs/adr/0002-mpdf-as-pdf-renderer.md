# 0002: mPDF as the default PDF renderer

- Status: accepted
- Date: 2026-10-03

## Context

ZUGFeRD and Factur-X are PDF/A-3 files with the invoice XML embedded as an associated file and described in the XMP metadata. Invoices are rendered from HTML templates (plain PHP or Blade) so that applications can change the design. Quittio is installed with Composer into ordinary PHP hosting, queue workers and containers.

## Decision

The default renderer is mPDF (`Pdf/Renderers/MpdfRenderer`). It turns the HTML into PDF/A-3b and writes the embedded XML and the XMP metadata in the same pass, in pure PHP. Other renderers implement `Pdf\PdfRenderer`; when they cannot write PDF/A and embed files themselves (`supportsEmbedding()` returns `false`), `Pdf/PostProcessing/MpdfPostProcessor` adds PDF/A and the XML to their output.

## Alternatives

- **A headless browser (Chromium through Browsershot, Gotenberg or similar):** full CSS support, but an additional binary or service in every installation, and no PDF/A-3 with an embedded file without a further step.
- **dompdf or TCPDF:** pure PHP as well, but dompdf writes no PDF/A, and the HTML support of TCPDF is more limited than that of mPDF.
- **Generating the PDF without HTML:** templates would no longer be HTML and Blade, which is the design surface the Laravel users know.

## Consequences

- No system dependencies: `composer require` is enough to render ZUGFeRD.
- mPDF supports a subset of CSS. The templates use tables and simple blocks, and visual regression tests (`--group=visual`) protect the default design.
- Repeating the line table header and the carry-over across pages needs extra layout passes (`Pdf/Renderers/CarryOver`), which makes multi-page invoices slower.
- The renderer can be replaced without losing PDF/A or the embedded XML.
