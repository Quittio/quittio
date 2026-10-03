# Artisan commands

| Command | Purpose |
|---|---|
| `quittio:install {--views} {--fonts}` | Publish configuration, optionally templates and fonts |
| `quittio:preview {--scenario=} {--template=} {--format=pdf\|html} {--locale=} {--output=}` | Render a sample invoice |
| `quittio:render {--json=} {--format=} {--profile=} {--output=}` | Render an invoice from a JSON file |
| `quittio:validate {file} {--profile=} {--kosit} {--verapdf} {--json}` | Validate an XML or PDF file |
| `quittio:read {file} {--json}` | Read an e-invoice and show its content |
| `quittio:benchmark {--count=} {--scenario=} {--format=*}` | Measure rendering performance |
| `quittio:countries` | Show installed country packs |
| `quittio:schemas:download {--path=} {--force}` | Download the KoSIT validator and the XRechnung configuration |
| `quittio:codelists:update {xsl?} {--target=}` | Regenerate the code lists (development) |
| `make:quittio-template {name}` | Create a template from the default template |

Publish tags: `quittio-config`, `quittio-views`, `quittio-lang`, `quittio-fonts`.

Events: `InvoiceRendering`, `InvoiceRendered`, `InvoiceValidated`, `InvoiceValidationFailed`, `MandatoryFieldMissing` (namespace `Quittio\Laravel\Events`).
