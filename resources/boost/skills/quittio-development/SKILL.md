---
name: quittio-development
description: Build, render, validate, cancel, read and test e-invoices with Quittio (EN 16931, ZUGFeRD/Factur-X, XRechnung, UBL, Peppol BIS) in Laravel, including the German VAT rules of quittio/country-de.
---

# Quittio development

## When to use this skill

Use this skill when code creates, renders, sends, validates, cancels or reads invoices with Quittio, or tests such code. The full documentation is at https://docs.quittio.dev; every page is also plain Markdown (add `.md` to its URL), and the MCP server https://docs.quittio.dev/mcp searches it. Prefer the documentation over guessing method names.

## Packages

- `quittio/quittio`: core, Laravel integration and country pack Germany in one package (`composer require quittio/quittio`, then `php artisan quittio:install`).
- `quittio/core` works without Laravel: `Quittio\Core\Quittio::create($config, [new CountryDE])`.
- `quittio/managed` (commercial) adds issuer profiles, drafts, gapless numbering, an archive and cancellations with history on top.

## Building invoices

- `Quittio::invoice()` starts an `InvoiceBuilder` with the seller and bank account from `config/quittio.php` (with quittio/managed: the current issuer); end with `->render()` (optionally a format or a list of formats) or `->build()` for the immutable `InvoiceData`. Without Laravel: `InvoiceBuilder::make()->seller(Party::make(…))`. `Seller::apply($builder)` sets the seller and bank account from `config/quittio.php`.
- Parties: `->buyer(Party::make('Kunde AG')->vatId()->address($street, $postcode, $city, $country)->electronicAddress($email, 'EM'))` (`Quittio\Core\Builder\Party`). Models can implement `Quittio\Core\Contracts\ProvidesParty` (`fillParty()`), `ProvidesInvoiceLine` (`fillLine()`) or `ProvidesInvoiceData` (`toInvoiceData()`); pass them directly: `->buyer($user)`, `->lines($order->items)`, `Quittio::make($order)`. A callable (`->seller(fn ($s) => $s->contact(…))`) changes the party set before.
- Lines: `->line(Line::make('Name')->quantity('2', UnitCode::Piece)->netPrice('19.90'))` (`Quittio\Core\Builder\Line`) or arrays whose keys are the `LineBuilder` methods: `->lines([['name' => 'Hosting', 'quantity' => [1, UnitCode::Month], 'netPrice' => '49.00']])`. The quantity defaults to 1. Without `->vat()` a line gets the default VAT (`->defaultVat()` of the invoice, otherwise `quittio.defaults.vat`, otherwise the standard rate of the seller's country).
- Amounts, prices and quantities are strings or `Brick\Math` values; floats throw an `InvalidArgumentException`. Do not set totals: the calculator computes line amounts, the VAT breakdown and totals (EN 16931 rounding).
- Payment: `->paymentTerms(fn ($t) => $t->dueInDays(14)->cashDiscount(days: 7, percent: '2.00'))`, `->payment(PaymentMeansCode::SepaCreditTransfer, iban: '…')`.

## German VAT (quittio/country-de)

- Lines without `->vat()` get 19 % (`VatPreset::Standard19`), small business invoices (`quittio.seller.small_business`) `VatPreset::SmallBusiness`. For other cases use `Quittio\Country\DE\Tax\VatPreset`: `Standard19`, `Reduced7`, `ZeroRatedPv`, `SmallBusiness`, `ReverseChargeDomestic`, `ReverseChargeEu`, `IntraCommunitySupply`, `TriangularTransaction`, `Export`, `NotTaxableInGermany`, the margin schemes, `TravelAgency`, or `VatPreset::exempt('§ 4 Nr. 14 UStG')`. The presets add the mandatory notes.
- Mandatory: a delivery date or period (`->deliveryDate()`, `->invoicingPeriod()`), the seller's VAT identifier or tax number, full addresses. Reverse charge in another member state and triangular transactions need the VAT identifiers of both parties.
- Special cases are builder macros: `->selfBilled()` (Gutschrift), `->smallAmount()` (up to 250 EUR), `->newVehicle()`, `->propertyRelatedService()`, `->deductAdvance($advanceInvoice)` for final invoices.
- Small businesses: `VatPreset::SmallBusiness` on every line and `seller.small_business = true`; never VAT and never `IntraCommunitySupply` (except new vehicles).
- The rules are `DE-USTG-01` to `DE-USTG-15`; errors block rendering, warnings do not (unless `validation.block_on_warnings`).

## Rendering and formats

- `Quittio::make($invoice)->render()` chooses the format: XRechnung for public buyers with a Leitweg-ID, PDF for consumers and small amount invoices, otherwise ZUGFeRD (profile EN 16931).
- Explicit: `->format('zugferd'|'factur-x'|'xrechnung-cii'|'xrechnung-ubl'|'cii'|'ubl'|'peppol'|'pdf'|'html'|'json')`, `->profile('en16931')`, `->locale('de')`; several at once with `->formats([...])` and `$result->as('xrechnung-cii')`.
- The result: `pdf()`, `xml()`, `filename()`, `data()` (the calculated invoice), `validation()`, `save($disk, $path)`, `download()`, `stream()`; it is `Attachable` for mails. Queue with `Quittio::make($invoice)->queue()->save('invoices', '2026/')`.
- Validate without rendering: `Quittio::make($invoice)->format('xrechnung-cii')->check()` returns a `ValidationResult`. Rendering throws `Quittio\Core\Validation\ValidationException` on errors.
- Quotes, delivery notes and reminders: `Quittio::quote()`, `Quittio::deliveryNote()`, `Quittio::paymentReminder($level)` … (a `DocumentBuilder` with the seller from the configuration), ending with `->render()`.

## Cancellations and corrections

- Issued invoices are never changed. Cancel: `$result->cancel('ST-2026-0001', reason: 'Charged twice')` renders the cancellation (type 381 with positive amounts by default in Germany) in the formats of the invoice. For stored data, `Quittio::cancellation($data, 'ST-2026-0001', reason: '…')` returns an `InvoiceBuilder`; render it like an invoice.
- Partial: `lines: [['line' => '1', 'quantity' => '1'], ['line' => '2', 'amount' => '50.00']]`, with `history:` (a `CancellationHistory`) for later parts.
- With `quittio/managed`, use its issuer, numbering and cancellation features instead of numbering invoices yourself.

## Reading incoming invoices

`Quittio::read($bytes)` or `Quittio::readFile($path)` reads CII, UBL and PDFs with embedded XML and returns the invoice data and the detected profile.

## Testing

```php
use Quittio\Laravel\Facades\Quittio;

$fake = Quittio::fake();

$this->post('/orders/42/invoice')->assertOk();

Quittio::assertRenderedCount(1);
Quittio::assertRendered(fn ($result) => $result->format() === 'zugferd');
```

Without the fake, `Storage::fake()`, `Event::fake()`, `Queue::fake()` and `Mail::fake()` work as usual. `Quittio\Core\Testing\Scenarios::make('simple')` returns example invoices.

## Commands

`quittio:install`, `quittio:preview --scenario=simple`, `quittio:render`, `quittio:validate`, `quittio:read`, `quittio:schemas:download` (KoSIT validator), `quittio:base-rate:update` (German base rate for default interest).
