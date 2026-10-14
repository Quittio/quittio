## Quittio

Quittio creates, validates, reads and renders electronic invoices according to EN 16931: ZUGFeRD/Factur-X (PDF/A-3 with XML), XRechnung, UBL, CII and Peppol BIS. Germany is supported by the country pack `quittio/country-de`. Documentation: https://docs.quittio.dev (plain Markdown: add `.md` to a page URL; MCP server: https://docs.quittio.dev/mcp). Use the `quittio-development` skill for details.

### Rules

- Build invoices with `Quittio\Core\Builder\InvoiceBuilder`; `build()` returns the immutable `InvoiceData`. Never set totals or the VAT breakdown: Quittio calculates them when rendering.
- Pass amounts, prices and quantities as strings (`'95.00'`), never as floats; floats are rejected.
- Set VAT with the presets of the country pack (`Quittio\Country\DE\Tax\VatPreset::Standard19`, `Reduced7`, `ReverseChargeEu`, `IntraCommunitySupply`, `SmallBusiness`, `VatPreset::exempt('§ 4 Nr. 14 UStG')`) instead of a category and rate, so that the mandatory notes are added.
- German invoices need a delivery date or invoicing period (`->deliveryDate()`, also when it equals the invoice date); XRechnung needs the buyer reference (`->buyerReference()`, the Leitweg-ID for public buyers).
- Render with the facade `Quittio\Laravel\Facades\Quittio`. Without `format()` the format is chosen automatically. Validation errors throw `Quittio\Core\Validation\ValidationException`; `check()` validates without rendering.
- Never change or delete an issued invoice: cancel it with `Quittio::cancellation()` and issue a new one.
- In tests, use `Quittio::fake()` and its assertions instead of rendering PDFs.

@verbatim
<code-snippet name="Build and render a German invoice" lang="php">
use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Enums\UnitCode;
use Quittio\Country\DE\Tax\VatPreset;
use Quittio\Laravel\Facades\Quittio;
use Quittio\Laravel\Seller;

$invoice = Seller::apply(InvoiceBuilder::make())     // seller from config/quittio.php
    ->number('RE-2026-0042')
    ->issueDate('2026-10-03')
    ->deliveryDate('2026-09-30')
    ->buyer(fn ($b) => $b
        ->name('Kunde AG')
        ->vatId('DE987654321')
        ->address('Bahnhofstraße 5', '54321', 'Beispielstadt', 'DE'))
    ->line(fn ($l) => $l
        ->name('Web development')
        ->quantity(24, UnitCode::Hour)
        ->netPrice('95.00')
        ->vat(VatPreset::Standard19))
    ->paymentTerms(fn ($t) => $t->dueInDays(14))
    ->build();

$result = Quittio::make($invoice)->format('zugferd')->render();
$result->save('invoices', '2026/');
</code-snippet>
@endverbatim
