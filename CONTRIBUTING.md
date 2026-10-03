# Contributing

## Setup

```bash
composer install
composer test
```

## Rules

- Code comments, docblocks and documentation are written in English.
- Money and quantities never use `float`; use `Brick\Math\BigDecimal` or `Brick\Money\Money`.
- DTOs are `final readonly`. Changes create new instances via `with…()` methods.
- Every EN 16931 field carries its metadata attributes (`#[Bt]`, `#[Cardinality]`, …).
- XML output is deterministic. Snapshot tests must not change unless the output is meant to change.
- New validation rules reference their official rule code and come with a message in German and English.

## Checks before a pull request

```bash
composer lint
composer analyse
composer test
```

## Commits

Conventional Commits (`feat:`, `fix:`, `docs:`, `test:`, `refactor:`, `chore:`), scoped by package where useful, e.g. `feat(core): add UBL reader`.
