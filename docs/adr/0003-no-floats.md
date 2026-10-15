# 0003: No floats for amounts, prices, rates and quantities

- Status: accepted
- Date: 2026-10-03

## Context

Invoices must be exact to the cent, and EN 16931 defines how and when amounts are rounded (e.g. two decimals for amounts, BR-DEC rules). Binary floating point cannot represent values such as 0.1 exactly, and the errors add up in sums and VAT calculations.

## Decision

- Amounts are `Brick\Money\Money` with a fixed scale of 2 (`Support/Amount`), prices, quantities and rates are `Brick\Math\BigDecimal` (`Support/Decimal`).
- Public APIs accept `BigDecimal`, `int` or numeric strings. Floats are rejected with an exception (`Decimal::rejectFloat()`), also in JSON and array input (`Serialization/ArrayHydrator`).
- Decimal strings are accepted in plain notation only (`Decimal::PLAIN_PATTERN`), so that input such as `1e999999999` cannot expand into a huge number.
- Comparisons use `isEqualTo()` and friends, never `==` on floats.

## Alternatives

- **Floats with rounding at the end:** simple to use, but results depend on the order of operations and differ by a cent in edge cases.
- **Integers in minor units:** exact for amounts, but prices and quantities have more decimals than currencies, and EN 16931 is defined in decimals.

## Consequences

- Callers must cast values from the database or forms to strings (`(string) $value`), which the error message of the float check explains.
- Calculations are slower than with floats; invoices are small, so this does not matter in practice.
- The rule is part of `CONTRIBUTING.md`.
