# Quittio
{: .fs-9 }

E-invoicing for Laravel. Quittio creates, validates, reads and renders electronic invoices based on the European standard EN 16931 – ZUGFeRD/Factur-X, XRechnung, UBL and Peppol BIS from one data model.
{: .fs-6 .fw-300 }

[Get started](getting-started.md){: .btn .btn-primary .fs-5 .mb-4 .mb-md-0 .mr-2 }
[Formats and profiles](formats.md){: .btn .fs-5 .mb-4 .mb-md-0 }

---

Germany is the first market; everything country-specific (VAT rates, legal texts, XRechnung) lives in country packs, so the core stays country-neutral.

> **Status:** released, before version 1.0.0. APIs may still change until version 1.0.0. The legal wording of the country pack Germany was reviewed by a tax adviser in October 2026; Quittio is no tax or legal advice (see the legal review and the disclaimer in the README).

## Packages

| Package | Purpose |
|---|---|
| `quittio/core` | Country-neutral core: EN 16931 data model, calculation, profiles, CII and UBL writers/readers, ZUGFeRD/Factur-X PDF/A-3, validation. Usable without Laravel. |
| `quittio/laravel` | Laravel integration: facade, configuration, Blade templates, commands, testing helpers. |
| `quittio/country-de` | Country pack for Germany: UStG mandatory fields, tax presets, XRechnung profiles and BR-DE rules, DIN 5008 letter layouts. |
| `quittio/quittio` | Everything in one package: core, Laravel integration and the country pack Germany. |
| `quittio/managed` | Managed layer (commercial licence, not released yet): issuer profiles, drafts, gapless numbering, archive with hashes, cancellations and corrections, mail delivery, audit log, retention. |
{: .packages }

## Requirements

- PHP 8.3 or newer with `ctype`, `dom`, `gd`, `libxml`, `mbstring`, `xmlwriter`, `zlib`; `intl` recommended, `zip` for `quittio:schemas:download`
- Laravel 13 (only for `quittio/laravel`)
- Optional: Java 11+ for the KoSIT validator, veraPDF or Docker for PDF/A checks, `opis/json-schema` for JSON input validation

## Supported formats

| Format | Content |
|---|---|
| `zugferd` / `factur-x` | PDF/A-3 with embedded CII XML (profiles MINIMUM to EXTENDED, XRECHNUNG) |
| `xrechnung-cii`, `xrechnung-ubl` | XRechnung 3.0 as plain XML |
| `cii`, `ubl` | Plain CII or UBL 2.1 XML in any profile |
| `peppol` | UBL according to Peppol BIS Billing 3.0 |
| `pdf`, `html`, `json` | PDF without XML, HTML preview, normalised data model |

## Quick example

```php
use Quittio\Core\Builder\Party;
use Quittio\Core\Enums\UnitCode;
use Quittio\Laravel\Facades\Quittio;

$result = Quittio::invoice()                                     // seller and bank account from config/quittio.php
    ->number('RE-2026-0042')
    ->deliveryDate('2026-09-30')
    ->buyer(Party::make('Kunde AG')                               // or a model: ->buyer($customer)
        ->vatId('DE987654321')
        ->address('Bahnhofstraße 5', '54321', 'Beispielstadt', 'DE'))
    ->line('Web development', netPrice: '95.00', quantity: 24, unit: UnitCode::Hour)
    ->line('Hosting', netPrice: '49.00')                          // quantity 1, VAT 19 % by default
    ->dueInDays(14)
    ->render();                                                   // ZUGFeRD: PDF/A-3 with embedded XML

$result->pdf();
$result->save('invoices', '2026/');
$result->cancel('ST-2026-0001', reason: 'Charged twice');        // the cancellation, rendered
```

## Documentation

New to Quittio? Follow [Getting started](getting-started.md); new to e-invoicing? Read [E-invoicing basics](concepts.md) first.

| Topic | Content |
|---|---|
| [Getting started](getting-started.md) | Installation, your company in the configuration, a first invoice, rendering, cancellation, use without Laravel |
| [E-invoicing basics](concepts.md) | EN 16931, CII and UBL, ZUGFeRD, XRechnung, Peppol, profiles, rule codes, which format you need, German terms |
| [Examples](examples.md) | Complete examples: service invoices, VAT rates, online shops, small businesses, XRechnung, customers abroad, final invoices, cancellations, orders, drafts, reminders, incoming invoices |
| [Building invoices](building-invoices.md) | Every builder method: parties, lines, VAT, payment, references, calculation, cancellations, data model |
| [Formats and profiles](formats.md) | Output formats, the automatic format choice, embedded files and XMP |
| [Validation](validation.md) | What is checked (levels L1 to L7), error messages, KoSIT and veraPDF |
| [Templates and design](templates.md) | Logo, colours, texts, partials, own templates, view model, letter layouts, fonts |
| [Quotes and other documents](documents.md) | Quotes, order confirmations, delivery notes, proforma invoices, reminders, statements |
| [Reading incoming invoices](reading.md) | XML and PDF input, findings, limits |
| [Laravel integration](laravel.md) | Facade, rendering, storage, queue, events, mail, Blade templates, preview |
| [Configuration](configuration.md) | Every key of `config/quittio.php` |
| [Artisan commands](commands.md) | All commands and publish tags |
| [Testing](testing.md) | Fake, assertions, factories, scenarios |
| [Country packs](country-packs.md) | The German pack: tax presets, rules, XRechnung, reminders; writing a pack |
| [Managed layer](managed.md) | Preview of the unreleased package: issuer profiles, customers, numbering, archive, cancellations and corrections, delivery, audit log, immutability, retention |
| [Troubleshooting](troubleshooting.md) | Common errors with cause and fix |
| [FAQ](faq.md) | Sending, the German e-invoicing obligation, Java, temporary files, queued invoices |
| [Field reference](field-reference.md) | Every field of the data model with BT, cardinality and XML paths (generated) |
| <a href="api/index.html">API reference</a> | Every public class and method with its parameters and doc comment (generated) |
| [AI assistants](ai.md) | llms.txt, the MCP server of this documentation, Laravel Boost guidelines and skill |

## Licence

Open core: `quittio/core`, `quittio/laravel`, `quittio/country-de` and `quittio/quittio` are released under the MIT licence. The Managed layer `quittio/managed` will be commercially licensed; it is not released yet.
