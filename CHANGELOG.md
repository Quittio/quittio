# Changelog

All notable changes to this project are documented in this file. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [0.1.0] - Unreleased

First release, covering the core scope of the roadmap (0.1 to 0.3).

### Added

- Monorepo with `quittio/core`, `quittio/laravel`, `quittio/country-de` and the `quittio/quittio` meta package.
- EN 16931 data model with mapping attributes (BT, cardinality, profile level, CII and UBL paths), builders, JSON conversion, generated JSON schema and field reference.
- Calculation according to EN 16931 including document allowances split by VAT rate, gross prices, foreign currency and third party payments.
- Profiles MINIMUM, BASIC WL, BASIC, EN 16931, EXTENDED, Peppol BIS 3; XRechnung 3.0 and the XRechnung extension in the country pack Germany.
- Validation levels L1 to L7: PHP rules (EN 16931, Peppol, BR-DE, UStG), XSD, KoSIT validator (daemon and CLI), veraPDF (CLI and Docker), plausibility checks.
- CII and UBL writers and readers with protection against XXE, entity expansion and oversized input; PDF reader for embedded invoices with decompression limits.
- ZUGFeRD/Factur-X PDF/A-3b with mPDF, XMP metadata and associated files; post processing of foreign PDFs; EPC GiroCode.
- View model with formatted and raw values, mandatory field guard, plain PHP and Blade default templates with partials, DIN 5008 letter layouts.
- Format resolver, entry point `Quittio\Core\Quittio` and Laravel facade with several formats per call, storage, downloads, mail attachments, queue, events, preview route and Artisan commands.
- Quotes, order confirmations, delivery notes, proforma invoices, payment reminders and account statements.
- Country pack Germany: tax presets, UStG rules, format rules, legal texts, BMF error classes, numbering, retention, dunning law with base rate table, correction policy, builder helpers.
- Testing helpers: fake, assertions, invoice factory, sample scenarios, visual regression (identical baselines on macOS and the Linux CI image).
- `quittio:schemas:download` compiles the Peppol BIS Billing 3.0 rules; KoSIT configuration accepts several scenario sets.
- Base rates up to 1 July 2026; small business invoices according to section 34a UStDV.
- Full cancellations (`Quittio::cancellation()`) with configurable sign strategy: type code 381 with positive amounts (default) or 380 with negative amounts (`corrections.cancellation_strategy`).
