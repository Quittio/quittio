# Formats and profiles

## Output formats

| Format | Result | Profiles |
|---|---|---|
| `zugferd` (alias `factur-x`) | PDF/A-3b with embedded CII XML | MINIMUM, BASIC WL, BASIC, EN 16931 (default), EXTENDED, XRECHNUNG |
| `xrechnung-cii`, `xrechnung-ubl` | XRechnung 3.0 XML | `xrechnung`, `xrechnung-extension` (country pack DE) |
| `cii`, `ubl` | Plain XML | every profile of the syntax |
| `peppol` | UBL according to Peppol BIS Billing 3.0 | `peppol` |
| `pdf` | PDF without XML (optionally PDF/A, optionally with XML for consumers: `pdf.b2c_embed_xml`) | – |
| `html` | Rendered template | – |
| `json` | Calculated data model | – |

MINIMUM and BASIC WL are not e-invoices in the sense of the German VAT act; use them only on explicit request.

## Automatic choice

Without `->format()`, the format resolver decides (specification 9.3):

1. Domestic invoices: the rules of the seller's country pack. Germany: Leitweg-ID or scheme `0204` → `xrechnung-cii`; consumers and small amount invoices → `pdf`; otherwise `zugferd` with EN 16931.
2. A buyer known to be reachable via Peppol (optional lookup) → `peppol`.
3. Consumers → `pdf`.
4. Cross-border within the EU: the buyer's country pack, otherwise `zugferd` with EN 16931.
5. Outside the EU → `pdf`.
6. EXTENDED features (sub lines, EXTENDED-only fields) upgrade ZUGFeRD/CII to profile EXTENDED, or fail with `format_resolver.auto_upgrade_profile = false`.

Replace the logic with `Quittio::extend('format_resolver', 'mine', fn () => new MyResolver)` and `format_resolver.driver = 'mine'`.

## Embedded files

| Profile | File name in the PDF | AFRelationship | XMP conformance level |
|---|---|---|---|
| BASIC, EN 16931, EXTENDED | `factur-x.xml` | Alternative | BASIC / EN 16931 / EXTENDED |
| MINIMUM, BASIC WL | `factur-x.xml` | Data | MINIMUM / BASIC WL |
| XRECHNUNG | `xrechnung.xml` | Alternative | XRECHNUNG |

The embedded XML is byte-identical to the XML output. Foreign PDFs (Browsershot, Gotenberg …) can be turned into ZUGFeRD with the `CallbackRenderer` or `PassthroughRenderer`; the package adds PDF/A-3 metadata, output intent and the XML.
