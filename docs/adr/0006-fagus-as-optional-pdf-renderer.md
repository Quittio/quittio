# 0006: Fagus as an optional PDF renderer for the mPDF templates

- Status: accepted
- Date: 2026-11-17

## Context

mPDF stays the default renderer ([0002](0002-mpdf-as-pdf-renderer.md)), but it supports only a subset of CSS: templates are built from tables, mPDF ignores some selectors (for example descendant selectors inside positioned blocks), and the carry-over of the line table needs up to three layout passes. Fagus (`nforst/fagus`) is an engine for HTML and CSS to PDF by the same author that implements CSS Paged Media, writes PDF/A-3 and ZUGFeRD/Factur-X itself and runs as a binary next to PHP. It is a 0.x release, and its binary is downloaded per platform by a Composer plugin.

Users have templates written for mPDF: the default templates and their own, with `<htmlpageheader>`, `@page { header: html_… }`, `<pagebreak page-selector>`, `{PAGENO}`, `<watermarktext>`, positioned elements measured from the sheet and the carry-over markers of Quittio.

## Decision

Fagus is a built-in, opt-in renderer: `Pdf/Renderers/FagusRenderer`, in Laravel the driver `fagus` of `pdf.renderer` next to `mpdf`. mPDF stays the default for 1.x. `nforst/fagus` is suggested by the packages and a development dependency for the tests, not a requirement; selecting the renderer without it fails with a `ConfigurationException` that names the package.

There is one template set for both renderers. `FagusRenderer` translates the mPDF constructs of the HTML into standard CSS at render time (`Pdf/Renderers/MpdfMarkupConverter`): running elements and margin boxes for headers and footers, counters for page numbers, page breaks and named pages, a layer over the sheet for positioned elements, a watermark element, and table head and foot rows with the Fagus extension `-fagus-repeat: at-break` for the carry-over. Fonts keep their mPDF family names through `@font-face` rules on the same files. PDF/A and the embedded XML are written by Fagus.

## Alternatives

- **Fagus as the default or a requirement:** a 0.x dependency with a binary in every installation of a 1.x package, and `composer require` alone would no longer be enough to render ZUGFeRD.
- **A second template set in standard CSS:** two designs to keep in line, and own templates written for mPDF would not work with Fagus.
- **Fagus through `CallbackRenderer` and `MpdfPostProcessor`:** the PDF would be imported with FPDI to add PDF/A and the XML, which loses the structure Fagus writes and repeats work Fagus already does.
- **A separate package for the renderer:** one more package and split repository for two classes, while the dependency is optional anyway.

## Consequences

- Applications can switch with one configuration value and keep their templates; switching back is the same value.
- The converter supports the constructs the default templates use and the common ones of mPDF; others (`<columns>`, `<barcode>`, `<tocpagebreak>` …) are left as they are and documented as not translated. Its tests cover each construct, and the renderer tests compare the page breaks with mPDF.
- The output differs from mPDF where mPDF ignores CSS or breaks differently (table rows across pages, kerning, column widths); the differences are documented in [templates.md](../templates.md#fagus).
- Work-arounds for Fagus 0.2 (separate borders on the line table, the header of the first page of a named page) live in the converter and can go when Fagus changes. With Fagus 0.3 they went: the line table keeps collapsed borders, and the first page of a named page is selected with a page group (`:nth(1 of name)`).
- Tagged PDF marks margin boxes as artifacts, so positioned elements that a reader needs (the address field, the payment slip) are placed in the page area where they lie in it; only elements in the page margins, such as fold marks, stay in the layer over the sheet.
