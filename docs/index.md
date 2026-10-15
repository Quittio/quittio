# Quittio
{: .fs-9 }

E-invoicing for Laravel. Quittio creates, validates, reads and renders electronic invoices based on the European standard EN 16931 – ZUGFeRD/Factur-X, XRechnung, UBL and Peppol BIS from one data model.
{: .fs-6 .fw-300 }

[Get started](getting-started.md){: .btn .btn-primary .fs-5 .mb-4 .mb-md-0 .mr-2 }
[Formats and profiles](formats.md){: .btn .fs-5 .mb-4 .mb-md-0 }

---

Germany is the first market; everything country-specific lives in country packs, so the core stays country-neutral.

> **Status:** version 0.1.0 in preparation. Not released yet; APIs may still change, and the legal wording of the country packs must be confirmed by a tax adviser.

## Packages

| Package | Purpose |
|---|---|
| `quittio/core` | Country-neutral core: EN 16931 data model, calculation, profiles, CII and UBL writers/readers, ZUGFeRD/Factur-X PDF/A-3, validation. Usable without Laravel. |
| `quittio/laravel` | Laravel integration: facade, configuration, Blade templates, commands, testing helpers. |
| `quittio/country-de` | Country pack for Germany: UStG mandatory fields, tax presets, XRechnung profiles and BR-DE rules, DIN 5008 letter layouts. |
| `quittio/quittio` | Everything in one package: core, Laravel integration and the country pack Germany. |
| `quittio/managed` | Managed layer (commercial licence): issuer profiles, drafts, gapless numbering, archive with hashes, cancellations and corrections, mail delivery, audit log, retention. |
{: .packages }

## Requirements

- PHP 8.3 or newer with `ctype`, `dom`, `gd`, `libxml`, `mbstring`, `xmlwriter`, `zlib`; `intl` recommended, `zip` for `quittio:schemas:download`
- Laravel 13 (only for `quittio/laravel`)
- Optional: Java 17+ for the KoSIT validator, veraPDF or Docker for PDF/A checks, `opis/json-schema` for JSON input validation

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
use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Enums\{InvoiceTypeCode, PaymentMeansCode, UnitCode};
use Quittio\Country\DE\Tax\VatPreset;
use Quittio\Laravel\Facades\Quittio;

$invoice = InvoiceBuilder::make()
    ->number('RE-2026-0042')
    ->issueDate('2026-10-03')
    ->type(InvoiceTypeCode::CommercialInvoice)
    ->currency('EUR')
    ->seller(Party::make('Muster GmbH')
        ->vatId('DE123456789')
        ->address('Hauptstraße 1', '12345', 'Musterstadt', 'DE')
        ->contact('Max Muster', '+49 123 456789', 'rechnung@muster.de')
        ->electronicAddress('rechnung@muster.de', 'EM'))
    ->buyer(Party::make('Kunde AG')
        ->address('Bahnhofstraße 5', '54321', 'Beispielstadt', 'DE')
        ->electronicAddress('ap@kunde.de', 'EM'))
    ->deliveryDate('2026-09-30')
    ->line('Web development', netPrice: '95.00', quantity: 24, unit: UnitCode::Hour, vat: VatPreset::Standard19)
    ->paymentTerms(fn ($t) => $t->dueInDays(14))
    ->payment(PaymentMeansCode::SepaCreditTransfer, iban: 'DE02120300000000202051')
    ->build();

$result = Quittio::make($invoice)->format('zugferd')->render();

$result->pdf();   // PDF/A-3 with embedded factur-x.xml
$result->xml();   // CII XML
```

## Documentation

| Topic | Content |
|---|---|
| [Getting started](getting-started.md) | Installation, building and rendering invoices, cancellations, use without Laravel, JSON |
| [Examples](examples.md) | Complete examples: service invoices, VAT rates, online shops, small businesses, XRechnung, customers abroad, final invoices, cancellations, orders, drafts, reminders, incoming invoices |
| [Building invoices](building-invoices.md) | Every builder method, parties, lines, VAT, payment, calculation, cancellations in the core, data model |
| [Formats and profiles](formats.md) | Output formats, automatic format choice, embedded files and XMP |
| [Validation](validation.md) | Levels L1 to L7, KoSIT and veraPDF, BMF error classes |
| [Templates and design](templates.md) | Design levels, view model, mandatory field guard, letter layouts, logos |
| [Quotes and other documents](documents.md) | Quotes, order confirmations, delivery notes, proforma invoices, reminders, statements |
| [Reading incoming invoices](reading.md) | XML and PDF input, findings, limits |
| [Laravel integration](laravel.md) | Facade, rendering API, storage, queue, events, mail, Blade templates, preview |
| [Configuration](configuration.md) | Every key of `config/quittio.php` |
| [Country packs](country-packs.md) | What a pack provides, the German pack, tax presets and rules |
| [Managed layer](managed.md) | Issuer profiles, customers, numbering, archive, cancellations and corrections, delivery, audit log, immutability, retention |
| [Testing](testing.md) | Fake, assertions, factories, scenarios |
| [Artisan commands](commands.md) | All commands and publish tags |
| [Field reference](field-reference.md) | Every field of the data model with BT, cardinality and XML paths (generated) |
| [AI assistants](ai.md) | llms.txt, the MCP server of this documentation, Laravel Boost guidelines and skill |

## Licence

Open core: `quittio/core`, `quittio/laravel`, `quittio/country-de` and `quittio/quittio` are released under the MIT licence. The Managed layer `quittio/managed` is commercially licensed.
