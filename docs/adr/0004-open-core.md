# 0004: Open core: MIT packages and a separate Managed layer

- Status: accepted
- Date: 2026-10-03

## Context

Creating, validating, reading and rendering e-invoices is needed by every application that sends invoices, and it should be easy to adopt and audit. Storing issued invoices with gapless numbers, an archive, cancellations, corrections, delivery and an audit log is a product of its own, with more support and legal responsibility.

## Decision

- `quittio/core`, `quittio/laravel`, the country packs and `quittio/quittio` are open source under the MIT licence and developed in this public repository.
- The Managed layer `quittio/managed` is developed in a private repository and will be distributed separately under a commercial licence. It builds on the public packages only through their public API (e.g. `Quittio\Core\Quittio::prepare()`, the builders and the `InvoiceDefaults` contract of the Laravel package) and adds no code to them.
- The documentation of the Managed layer is published with the other documentation and marked as a preview until the package is released.

## Alternatives

- **Everything open source:** no funding for the maintenance of the stored records part.
- **Everything commercial:** less adoption, and the conformance of the invoice output could not be audited publicly.

## Consequences

- The open source packages must stay complete for applications that store invoices themselves: saving, cancelling and validating work without the Managed layer.
- Extension points that the Managed layer needs (e.g. `InvoiceDefaults`, `prepare()` without throwing) are public API of the open source packages and must be kept stable.
- Findings and issues of the Managed layer are tracked in its own repository.
