<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="art/banner-dark.svg">
    <img src="art/banner-light.svg" alt="Quittio: Invoices, settled. E-invoicing for PHP and Laravel" width="1280">
  </picture>
</p>

<p align="center">
  <a href="https://github.com/Quittio/quittio/actions/workflows/ci.yml"><img src="https://github.com/Quittio/quittio/actions/workflows/ci.yml/badge.svg?branch=master" alt="CI"></a>
  <a href="https://packagist.org/packages/quittio/quittio"><img src="https://img.shields.io/packagist/v/quittio/quittio" alt="Latest version on Packagist"></a>
  <a href="https://packagist.org/packages/quittio/quittio"><img src="https://img.shields.io/packagist/dependency-v/quittio/quittio/php" alt="PHP version"></a>
  <a href="LICENSE"><img src="https://img.shields.io/packagist/l/quittio/quittio" alt="Licence"></a>
</p>

# Quittio

E-invoicing for Laravel. Quittio creates, validates, reads and renders electronic invoices based on the European standard EN 16931. Germany is the first market; everything country-specific lives in country packs, so the core stays country-neutral.

**Documentation: [docs.quittio.dev](https://docs.quittio.dev)**

> **Status:** version 0.3. Implemented: data model, calculation, validation L1–L7, CII/UBL writers and readers, ZUGFeRD/Factur-X PDF/A-3, XRechnung, Peppol BIS, Laravel integration and the country pack Germany. The Managed layer (issuer profiles, drafts, gapless numbering, archive, cancellations, corrections, mail delivery, audit log) will follow as the separate commercial package `quittio/managed`; it is not released yet. APIs may still change until version 1.0, and the legal wording is under review (see [Legal review](#legal-review)).

## Packages

All open source packages are developed in this repository under [`packages/`](packages/). Each one is also published as a read-only repository for Composer, which a GitHub Action updates on every push and tag.

| Package | Code | Purpose |
|---|---|---|
| `quittio/core` | [`packages/core`](packages/core) → [Quittio/core](https://github.com/Quittio/core) | Country-neutral core: EN 16931 data model, calculation, profiles, CII and UBL writers/readers, ZUGFeRD/Factur-X PDF/A-3, validation. Usable without Laravel. |
| `quittio/laravel` | [`packages/laravel`](packages/laravel) → [Quittio/laravel](https://github.com/Quittio/laravel) | Laravel integration: facade, configuration, Blade templates, commands, testing helpers. |
| `quittio/country-de` | [`packages/country-de`](packages/country-de) → [Quittio/country-de](https://github.com/Quittio/country-de) | Country pack for Germany: UStG mandatory fields, tax presets, XRechnung profiles and BR-DE rules, DIN 5008 letter layouts. |
| `quittio/quittio` | this repository | Everything in one package: core, Laravel integration and the country pack Germany. |
| `quittio/managed` | commercial, not released yet, see [Managed layer](docs/managed.md) | Managed layer (commercial licence): issuer profiles, drafts, gapless numbering, archive with hashes, cancellations and corrections, mail delivery, audit log, retention. |

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

The documentation is published at **[docs.quittio.dev](https://docs.quittio.dev)** (with search). The same pages are in [`docs/`](docs/):

- [Getting started](docs/getting-started.md)
- [Examples](docs/examples.md)
- [Building invoices](docs/building-invoices.md)
- [Formats and profiles](docs/formats.md)
- [Validation](docs/validation.md)
- [Templates and design](docs/templates.md)
- [Quotes, delivery notes and other documents](docs/documents.md)
- [Reading incoming invoices](docs/reading.md)
- [Laravel integration](docs/laravel.md)
- [Configuration](docs/configuration.md)
- [Country packs](docs/country-packs.md)
- [Testing](docs/testing.md)
- [Artisan commands](docs/commands.md)
- [Managed layer](docs/managed.md) (preview)
- [Troubleshooting](docs/troubleshooting.md) and [FAQ](docs/faq.md)
- [Field reference](docs/field-reference.md) (generated)
- [AI assistants](docs/ai.md): llms.txt, the MCP server `https://docs.quittio.dev/mcp` and Laravel Boost

The website is built from these pages with Jekyll and the theme Just the Docs (`.github/workflows/docs.yml`). Local preview: `cd docs && bundle install && bundle exec jekyll serve`.

## Development

```bash
composer install
composer test        # Pest
composer analyse     # PHPStan, level max
composer lint        # Pint
```

Development happens in this repository: see [CONTRIBUTING.md](CONTRIBUTING.md) for the setup, the test groups and pull requests, [ARCHITECTURE.md](ARCHITECTURE.md) for how the packages fit together and [RELEASING.md](RELEASING.md) for releases. `packages/core`, `packages/laravel` and `packages/country-de` are copied to the read-only repositories `quittio/core`, `quittio/laravel` and `quittio/country-de` on every push and tag once CI passed (`.github/workflows/split.yml`, called by `ci.yml`); open issues and pull requests here.

Official validation (KoSIT validator and veraPDF) runs in CI and locally:

```bash
tools/schemas/download.sh          # KoSIT validator, XRechnung configuration, Peppol rules
tools/fixtures/download.sh         # XRechnung test suite (golden files)
tools/validate-fixtures.sh         # all scenarios through KoSIT and veraPDF
vendor/bin/pest --group=official   # tests that need Java or Docker
vendor/bin/pest --group=visual     # visual regression of the default template (pdftoppm)
tools/visual/check-linux.sh        # the same in a container matching the CI image
php tools/docs/generate.php        # JSON schema and field reference after model changes
```

## Keeping data current

- Base rate (Basiszinssatz, section 247 BGB): the values up to 1 July 2026 are included. Later values come from `php artisan quittio:base-rate:update` (Deutsche Bundesbank) or from `quittio.countries.de.base_rates` (`config/quittio/countries/de.php`), without a new release ([Base rate table](docs/country-packs.md#base-rate-table)).
- Code lists follow the EN 16931 Schematron 1.3.16 of the XRechnung configuration 2026-08-31 (`quittio:codelists:update`).

## Legal review

The legal wording, legal references and defaults of the country pack Germany and the Managed layer are being reviewed by a tax adviser and may change before version 1.0. Under review:

- **Cancellations:** type code 381 with positive amounts by default, configurable (`corrections.cancellation_strategy`). The VAT of partial cancellations is rounded cumulatively, so that all of them together reverse exactly the VAT of the original; a single part may differ by one cent from taxable amount × rate (within BR-CO-17, noted on the document).
- **Corrections:** cancel and reissue by default, corrected invoice 384 configurable (`quittio-managed.correction.strategy`).
- **Price reductions:** when a price reduction needs a new e-invoice or a document at all (e.g. a used cash discount), a document type of its own instead of a partial cancellation, reductions over several invoices such as annual bonuses (section 17 (4) UStG).
- **Final invoices:** `->de()->deductAdvance()` creates the EN 16931 variant (full service with full VAT, advance invoices referenced, deducted in BT-113 and itemised with their VAT per rate, section 14 (5) sentence 2 UStG). Under review: the EXTENDED variant, unpaid and partially paid advance invoices, deducting advance invoices as negative lines, deducting self-billed invoices (389).
- **Continuing invoices:** representation of the payment schedule in the XML.
- **Self-billed invoices:** their cancellation (type code 261, title "Storno-Gutschrift").
- **XRechnung:** the optional visualisation PDF sent with the XML.
- **Texts and rules:** wording and legal references of the tax presets, legal texts and UStG rules, including the prepayment notice, a text for non-taxable transactions in Germany, the severity of the rules (e.g. `DE-USTG-06`) and the rules `DE-USTG-14` and `DE-USTG-15`.
- **Dunning:** default fees, the flat rate of section 288 (5) BGB together with dunning fees, the start of default, dunning charges without VAT.
- **E-invoicing obligation:** whether a plain PDF and the profiles MINIMUM and BASIC WL are blocked or only warned about for domestic B2B invoices (section 27 (38) UStG).
- **Retention:** 8 years from the end of the year of issue, and which files are kept.

## Licence

`quittio/core`, `quittio/laravel`, `quittio/country-de` and `quittio/quittio` are released under the MIT licence, see [LICENSE](LICENSE). The Managed layer `quittio/managed` will be a separate package under a commercial licence; it is not released yet.
