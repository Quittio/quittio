# 0005: Sign strategy of cancellations

- Status: accepted, under legal review
- Date: 2026-10-03

## Context

A cancellation (Storno) reverses an issued invoice with a new document that has its own number and refers to the original (BG-3). EN 16931 allows two representations: a credit note (type code 381) with positive amounts, or an invoice (380) with negative quantities and amounts. Receivers and countries prefer different representations, and in Germany only a document issued by the buyer (self-billing) may be called "Gutschrift".

## Decision

`Corrections/Cancellation` supports both strategies:

- `credit_note` (default): type code 381 with positive amounts; UBL writes a `CreditNote` document.
- `negative_invoice`: type code 380 with negated quantities and allowance and charge amounts.

The strategy comes from the call, then `quittio.corrections.cancellation_strategy` (`Config::$cancellationStrategy`), then the `CorrectionPolicy` of the leading country pack (`credit_note` in the base class and in `CountryDE`). Further rules:

- The cancellation of a credit note is an invoice (380) with the same positive amounts in both strategies.
- Self-billed documents stay self-billed: a self-billed invoice (389) or debit note (527) is cancelled by a self-billed credit note (261) or a negative self-billed invoice (389).
- The original is never changed. Partial cancellations round their VAT cumulatively when the history of earlier partial cancellations is passed (`CancellationHistory`), so that all parts together reverse exactly the VAT of the original.

## Alternatives

- **Only one representation:** both are permitted in Germany, and users have to match what their receivers and accounting expect.
- **Only credit notes:** the XRechnung extension in UBL needs `negative_invoice` (`DE-XRECHNUNG-EXT-01`).

## Consequences

- Both representations must be tested in both syntaxes and read back by the readers.
- The defaults and titles are part of the legal review listed in the README and may change before 1.0.
