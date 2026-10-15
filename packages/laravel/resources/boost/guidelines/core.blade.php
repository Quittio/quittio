## Quittio

Quittio creates, validates, reads and renders electronic invoices according to EN 16931: ZUGFeRD/Factur-X (PDF/A-3 with XML), XRechnung, UBL, CII and Peppol BIS. Germany is supported by the country pack `quittio/country-de`. Documentation: https://docs.quittio.dev (plain Markdown: add `.md` to a page URL; MCP server: https://docs.quittio.dev/mcp). Use the `quittio-development` skill for details.

### Rules

- Start invoices with `Quittio::invoice()` (seller and bank account from `config/quittio.php`) and end the chain with `->render()`, or `->build()` for the immutable `InvoiceData`. Documents start with `Quittio::quote()`, `Quittio::paymentReminder($level)` etc. Never set totals or the VAT breakdown: Quittio calculates them when rendering.
- Pass amounts, prices and quantities as strings (`'95.00'`), never as floats; floats are rejected.
- Add lines with `->line('Name', netPrice: '120.00', quantity: 4, unit: UnitCode::Hour)` (also `vat:`, `description:`, `priceInclVat:` for prices including VAT), with `Line::make('Name', …)->…` for further values, or as arrays (`['name' => …, 'netPrice' => …]`); the quantity defaults to 1. Lines without `->vat()` get the default VAT: `quittio.defaults.vat`, otherwise the standard rate of the seller's country (Germany 19 %, small businesses the small business exemption). For other rates use the presets of the country pack (`Quittio\Country\DE\Tax\VatPreset::Reduced7`, `ReverseChargeEu`, `IntraCommunitySupply`, `VatPreset::exempt('§ 4 Nr. 14 UStG')`) instead of a category and rate, so that the mandatory notes are added.
- Pass customer models implementing `Quittio\Core\Contracts\ProvidesParty` directly: `->buyer($customer)`; otherwise `->buyer(Party::make('Kunde AG')->vatId(…)->address(…))`.
- German invoices need a delivery date or invoicing period (`->deliveryDate()`, also when it equals the invoice date); XRechnung needs the buyer reference (`->buyerReference()`, the Leitweg-ID for public buyers).
- Render with the facade `Quittio\Laravel\Facades\Quittio`. Without `format()` the format is chosen automatically. Validation errors throw `Quittio\Core\Validation\ValidationException`; `check()` validates without rendering.
- Never change or delete an issued invoice: cancel it with `$result->cancel('ST-2026-0001', reason: '…')` (or `Quittio::cancellation()` for stored data) and issue a new one.
- In tests, use `Quittio::fake()` and its assertions instead of rendering PDFs.

@verbatim
<code-snippet name="Build and render a German invoice" lang="php">
use Quittio\Core\Enums\UnitCode;
use Quittio\Country\DE\Tax\VatPreset;
use Quittio\Laravel\Facades\Quittio;

$result = Quittio::invoice()                         // seller from config/quittio.php
    ->number('RE-2026-0042')
    ->issueDate('2026-10-03')
    ->deliveryDate('2026-09-30')
    ->buyer($customer)                               // implements ProvidesParty
    ->line('Web development', netPrice: '95.00', quantity: 24, unit: UnitCode::Hour)   // 19 % by default
    ->line('Handbook', netPrice: '39.00', vat: VatPreset::Reduced7)
    ->paymentTerms(fn ($t) => $t->dueInDays(14))
    ->render();                                      // ZUGFeRD for German businesses

$result->save('invoices', '2026/');
</code-snippet>
@endverbatim
