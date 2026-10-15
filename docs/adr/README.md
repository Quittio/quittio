# Architecture decision records

Short records of decisions that shape the code base, in the format of [MADR](https://adr.github.io/madr/): context, decision, alternatives and consequences. They are for contributors and are not published on the documentation website.

A record is not changed once it is accepted. A new decision that replaces an old one gets a new record, and the old one is marked as superseded.

| No. | Decision | Status |
|---|---|---|
| [0001](0001-monorepo-with-lockstep-versions.md) | One repository, read-only splits and lockstep versions | Accepted |
| [0002](0002-mpdf-as-pdf-renderer.md) | mPDF as the default PDF renderer | Accepted |
| [0003](0003-no-floats.md) | No floats for amounts, prices, rates and quantities | Accepted |
| [0004](0004-open-core.md) | Open core: MIT packages and a separate Managed layer | Accepted |
| [0005](0005-cancellation-sign-strategy.md) | Sign strategy of cancellations | Accepted, under legal review |

New records: copy the structure of an existing one, take the next number, and add it to this table.
