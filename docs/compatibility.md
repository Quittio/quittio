# Versioning and compatibility

Quittio follows [Semantic Versioning](https://semver.org). This page says what that promise covers, so you know what can change under you in an update and what cannot.

## Before 1.0

The current releases are 0.x. Until 1.0:

- **Patch releases** (0.1.0 → 0.1.1) fix bugs and security issues. They do not remove or rename anything you use. A fix can still change behaviour that was wrong, e.g. reject data that produced invalid XML before.
- **Minor releases** (0.1 → 0.2) may break the public API. Every break is listed in the [changelog](https://github.com/Quittio/quittio/blob/master/CHANGELOG.md) under "Changed" or "Removed", with what to do.
- Where possible, an old name keeps working as a deprecated alias first (see [Deprecations](#deprecations)).

From 1.0 on, only major releases break the public API.

## What the promise covers

The public API is everything below that is not marked `@internal`:

| Area | Covered |
|---|---|
| PHP classes | Public classes, methods, properties and constants in `Quittio\Core`, `Quittio\Laravel` and `Quittio\Country\DE`: builders, data objects, `Quittio\Core\Quittio`, the facade and `QuittioManager`, results, events, exceptions |
| Contracts | Interfaces you implement, such as `CountryPack`, `ValidationDriver`, `Rule`, `PdfRenderer`, `TemplateEngine`, `XmlSerializer`, `FormatReader`, `FormatResolver`, `SnapshotUpcaster`, `PaymentQrGenerator`, `VatIdVerifier`, `InvoiceDefaults`. A method is added to an interface only in a major release; base classes such as `AbstractCountryPack` and `AbstractProfile` get new methods with a default implementation. New abilities of packs and profiles come as optional capability interfaces (`Countries\Capabilities\*`, `Profiles\Capabilities\*`), which are covered like the other contracts. |
| Configuration | The keys of `config/quittio.php` and of the country packs (`quittio.countries.de`, `config/quittio/countries/de.php`), their meaning and defaults, and the environment variables. A renamed key or variable keeps working under its old name until the next major release. |
| Rule codes | Codes of findings, both official (`BR-CO-10`, `BR-DE-1`, `PEPPOL-EN16931-R001`) and Quittio's (`QUITTIO-…`). A code keeps its meaning; its message text is not covered. Rule overrides (`validation.rules`, `Quittio::extend('rule', …)`) address rules by these codes. |
| Events | Class names and payload properties of the events in `Quittio\Laravel\Events` |
| View model | The keys templates read (`$invoice->seller->name`, also in array syntax) and their types, described by the node classes in `Quittio\Core\View\Model` ([Typed nodes](templates.md#typed-nodes)). New keys may be added. |
| Stored data | The snapshot formats of `InvoiceData::toSnapshot()` and `DocumentData::toSnapshot()` and the array format of `RenderOptions::toArray()`. A new version comes with an upcaster or a reader for the old one, so stored invoices and queued jobs stay readable. |
| Commands | Names, arguments, options and exit codes of the Artisan commands |
| Output | XML that conforms to the profile. The XML of the same data changes only when a standard or a profile changes, or to fix a conformance error. |

## What it does not cover

- Classes and methods marked `@internal`, e.g. `XPathReader`, `XmlBuilder`, the PDF parser in `Pdf\Parsing`, `ConfigMapper`, `CountryPackDiscovery`, the section factories of the view model in `View\Internal`, the preview controller and the command classes. Use the public contract instead: implement `FormatReader` instead of extending `XPathReader`, call the commands instead of their classes.
- Private and protected members of final classes.
- The markup and CSS of the shipped templates. Templates you published (`--tag=quittio-views`) are yours; a release lists template changes so you can merge them.
- The bytes of rendered PDFs, the wording of messages and labels, and the exact layout.
- New findings: a minor release may add rules. A new rule reports a warning unless a standard makes it an error; such errors are listed in the changelog.
- Data that follows its publisher: code lists, base rates, KoSIT configurations and Peppol rules are updated when they change upstream.

## Deprecations

A deprecated method, class, configuration key or environment variable

- is marked `@deprecated` with its replacement, so your IDE and static analysis show it,
- is listed under "Deprecated" in the changelog,
- keeps working until the next major release; deprecations of 0.x releases are removed in 1.0 at the earliest.

## Supported versions

| Dependency | Supported |
|---|---|
| PHP | 8.3 and newer |
| Laravel | the current major release (13). A new Laravel major is supported in the next Quittio minor release, which then drops the previous one. |

Security fixes are released for the latest minor release.
