# 0001: One repository, read-only splits and lockstep versions

- Status: accepted
- Date: 2026-10-03

## Context

Quittio consists of a country-neutral core, a Laravel integration and country packs. Applications without Laravel need the core alone, Laravel applications usually want everything. Changes often touch several packages at once, for example a new field in the data model, its rule in the country pack and its configuration key in Laravel.

## Decision

- All open source packages are developed in this repository under `packages/`.
- `.github/workflows/split.yml` copies each package to a read-only repository (`Quittio/core`, `Quittio/laravel`, `Quittio/country-de`) for every push to `master` and every tag, from which Packagist publishes it. The split runs as the last job of `ci.yml`, after every other job has passed.
- The packages require each other with `"self.version"`, and the root `composer.json` (`quittio/quittio`) replaces all three. Every release tags all packages with the same version.
- The branch alias of `dev-master` is the same in every `composer.json`.

## Alternatives

- **Separate repositories with independent versions:** every cross-package change needs several coordinated pull requests and releases, and users have to find compatible combinations.
- **One package only:** applications without Laravel would install Laravel, and every country pack would ship with every installation.

## Consequences

- One pull request changes and tests all packages together; CI tests exactly the combination that is released.
- A fix in one package releases all of them under a new version, even if the others did not change.
- Issues and pull requests belong in this repository. The split repositories are read-only.
- The tests of a package may use the other packages and the root `docs/`, so the split repositories cannot always run their tests on their own.
