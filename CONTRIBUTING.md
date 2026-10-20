# Contributing

Thank you for helping with Quittio. Issues and pull requests belong in this repository ([Quittio/quittio](https://github.com/Quittio/quittio)); the package repositories `Quittio/core`, `Quittio/laravel` and `Quittio/country-de`, `-at`, `-be`, `-ch` and `-fr` are read-only copies. Security issues go through the private channel in [SECURITY.md](SECURITY.md), never into a public issue.

Before you change code, read [ARCHITECTURE.md](ARCHITECTURE.md) for how the packages fit together and the [decision records](docs/adr/README.md) for why.

## Prerequisites

| Tool | Needed for |
|---|---|
| PHP 8.3 or newer with `ctype`, `dom`, `gd`, `libxml`, `mbstring`, `xmlwriter`, `zlib`, plus `intl` and `bcmath` | everything; CI tests PHP 8.3, 8.4 and 8.5 |
| Composer 2 | everything |
| poppler (`pdftotext`, `pdftoppm`) | the page break tests of the PDF renderer and the visual tests; skipped without it |
| Java 11 or newer (CI uses 21) | the KoSIT validator in the `official` test group and `tools/validate-fixtures.sh` |
| Docker | veraPDF in the `official` group, `tools/visual/check-linux.sh`, `actionlint` |
| Ruby and Bundler | a local preview of the documentation website |

Install with:

```bash
composer install
```

## Tests

```bash
composer test            # all tests; tests whose tools are missing are skipped
composer test:fast       # what the CI test matrix runs: without the groups official and visual
composer test:core       # one package: test:core, test:laravel, test:country-de
composer ci              # lint, static analysis and test:fast, as CI runs them
composer test:coverage   # line coverage (pcov or Xdebug) → build/coverage/clover.xml
composer test:mutation   # mutation testing of Calculation, Validation/Rules, Xml and the German rules
```

`tests/Monorepo` holds the tests that need the whole repository (the documentation, the sources of all packages). Every package also runs on its own, as in its split repository: `composer install && composer test` in `packages/<name>`.

What the package tests still need from elsewhere:

| Package | Needs | Why |
|---|---|---|
| `core` | `quittio/country-de` (require-dev) | `CancellationVatAlignmentTest` checks cancellations with German VAT presets |
| `laravel` | `quittio/country-de` (require-dev) | the test application registers the German pack and a German seller |
| `country-de`, `country-at`, `country-be`, `country-fr` | the helper `Official` of the core tests | official validation tests; the split repository uses a stand-in (`tests/Standalone`) that skips them |
| all | `tools/validators`, the downloaded test suites | the group `official`; skipped without them (in CI, the veraPDF tests fail without the Docker image instead) |
| `core`, `laravel` | `docs/`, `resources/boost` of the monorepo | the field reference and Boost copy checks; skipped outside the monorepo |

Two groups need more than PHP:

- **`official`** checks generated invoices with the official validators. Download them first, then run the group:

  ```bash
  composer test:official             # downloads, then runs the group:
                                     #   tools/schemas/download.sh  KoSIT validator, XRechnung configuration, Peppol rules → tools/validators
                                     #   tools/fixtures/download.sh XRechnung test suite → packages/country-de/tests/Fixtures/xrechnung-testsuite
  tools/validate-fixtures.sh         # every example invoice through KoSIT, the PDFs through veraPDF
  ```

  The downloads are checked against pinned SHA-256 checksums. Other versions need `QUITTIO_UNPINNED=1`; see [RELEASING.md](RELEASING.md#updating-the-validators) for updating the pins.

- **`visual`** renders the sample invoices, rasterises the first page with `pdftoppm` and compares it with the baselines in `packages/laravel/tests/Visual/__snapshots__`:

  ```bash
  composer test:visual
  ```

  A failing comparison leaves `<scenario>.actual.png` next to the baseline (ignored by Git; CI uploads it as the artifact `visual-diff`). A missing baseline fails the test. When a design change is intended, delete the affected baselines and write them again with the flag `QUITTIO_UPDATE_BASELINES=1` (ignored on CI):

  ```bash
  QUITTIO_UPDATE_BASELINES=1 vendor/bin/pest --group=visual
  ```

  Then check them in the Linux image that CI uses, because macOS and Linux must produce the same images:

  ```bash
  tools/visual/check-linux.sh
  ```

### Snapshots and fixtures

- **XML snapshots:** the CII and UBL writer tests compare with `tests/.pest/snapshots`. XML output is deterministic, so a snapshot changes only when the output is meant to change. Update them with `vendor/bin/pest --update-snapshots` and review the diff.
- **Data snapshots:** `packages/core/tests/Fixtures/snapshots/v1` holds stored `InvoiceData::toSnapshot()` payloads. A change of the data model that old snapshots cannot be read with needs a new `InvoiceData::SNAPSHOT_VERSION`, an upcaster and fixtures of the new version.
- **Third-party fixtures** (FeRD samples, the XRechnung test suite) are not committed; the tests that need them are skipped.

## Generated files

| Change | Regenerate with |
|---|---|
| Data model (fields, attributes `#[Bt]`, `#[Cardinality]`, `#[Cii]`, `#[Ubl]` …) | `composer docs:generate` (`packages/core/resources/schema/invoice.schema.json` and `docs/field-reference.md`) |
| Code lists after a new XRechnung configuration | `tools/schemas/download.sh`, then `composer codelists:update` (`packages/core/resources/codelists`) |

Commit the regenerated files together with the change.

## Rule messages

Every validation rule has an official code (e.g. `BR-CO-25`, `BR-DE-15`) or a code of its own (`QUITTIO-…`, `DE-USTG-…`) and a message with a hint in English and German:

| Rules | Messages |
|---|---|
| Core rules (EN 16931, Peppol, `QUITTIO-…`) | `packages/core/resources/lang/en/rules.php` and `packages/core/resources/lang/de/rules.php` |
| Rules of the country pack Germany | `packages/country-de/resources/lang/en/rules.php` and `packages/country-de/resources/lang/de/rules.php` |

German messages address the reader formally (Sie). New rules are listed in `docs/validation.md` or, for the country pack, in `docs/country-packs.md`.

## Documentation

The documentation in `docs/` is the source of the website [docs.quittio.dev](https://docs.quittio.dev). Preview it locally:

```bash
cd docs && bundle install && bundle exec jekyll serve
```

- A new page needs an entry in `defaults` of `docs/_config.yml` (title and navigation order); `docs/_worker/build.mjs` fails without it.
- PHP code blocks in `docs/*.md` and the README must parse and import every class they use (`tests/Monorepo/DocsCodeBlocksTest.php`; fragments such as builder chains, configuration excerpts and class members are recognised). The code of `docs/examples.md` is executed (`tests/Monorepo/DocsExamplesTest.php`).
- The website is versioned: `node _versioning/build.mjs` (in `docs/`) builds the latest release at the root, the working tree under `/next/` and older minor versions under `/X.Y/` into `docs/_site` (see `RELEASING.md`). Changes to `docs/` appear under `/next/` until the next release.
- Contributor documents (this file, `ARCHITECTURE.md`, `RELEASING.md`, `docs/adr`) are not part of the website.

## Rules

- Code comments, docblocks and documentation are written in English.
- Money and quantities never use `float`; use `Brick\Math\BigDecimal` or `Brick\Money\Money` ([ADR 0003](docs/adr/0003-no-floats.md)).
- DTOs are `final readonly`. Changes create new instances via `with…()` methods.
- Every EN 16931 field carries its metadata attributes (`#[Bt]`, `#[Cardinality]`, …).
- XML output is deterministic. Snapshot tests must not change unless the output is meant to change.
- New validation rules reference their official rule code and come with a message in German and English.

## Pull requests

Before you open a pull request:

```bash
composer lint        # Pint; `composer format` fixes the style
composer analyse     # PHPStan, level max, no baseline
composer test
```

`composer ci` runs lint, analysis and the fast tests in one go.

- One topic per pull request, with tests for every fix and feature. A bug fix comes with a test that fails without it.
- Add an entry to `CHANGELOG.md` under `[Unreleased]`, in the section that fits (Added, Changed, Fixed, Security …).
- Update the documentation in `docs/` when behaviour, configuration or public methods change.
- Changes to the formats, profiles or rules should pass the `official` group locally if you have Java; CI runs it in any case.

## Commits

Conventional Commits (`feat:`, `fix:`, `docs:`, `test:`, `refactor:`, `chore:`, `ci:`, `build:`), scoped by package where useful, e.g. `feat(core): add UBL reader`.
