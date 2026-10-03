# Getting started

## Installation

```bash
composer require quittio/quittio          # Laravel integration + country pack Germany
php artisan quittio:install               # publishes config/quittio.php
```

Without Laravel, require `quittio/core` (and a country pack such as `quittio/country-de`) and use `Quittio\Core\Quittio::create()` (see below).

Set the seller in `config/quittio.php` or via the `QUITTIO_SELLER_*` environment variables, then render the sample invoices to check the template:

```bash
php artisan quittio:preview --scenario=simple
```

## Building an invoice

`InvoiceBuilder` creates the immutable `InvoiceData` (EN 16931 model). Totals and the VAT breakdown are calculated when rendering; never pass them in.

```php
use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Enums\UnitCode;
use Quittio\Country\DE\Tax\VatPreset;
use Quittio\Laravel\Seller;

$invoice = Seller::apply(InvoiceBuilder::make())       // seller and bank account from the config
    ->number('RE-2026-0042')
    ->issueDate('2026-10-03')
    ->deliveryDate('2026-09-30')                         // service date, mandatory in Germany
    ->buyer(fn ($b) => $b
        ->name('Kunde AG')
        ->vatId('DE987654321')
        ->address('Bahnhofstraße 5', '54321', 'Beispielstadt', 'DE')
        ->electronicAddress('ap@kunde.de', 'EM'))
    ->line(fn ($l) => $l
        ->name('Web development')
        ->description('Customer portal, sprint 3')       // Markdown subset
        ->quantity(24, UnitCode::Hour)
        ->netPrice('95.00')
        ->vat(VatPreset::Standard19))
    ->paymentTerms(fn ($t) => $t->dueInDays(14)->cashDiscount(days: 7, percent: '2.00'))
    ->build();
```

Amounts and quantities are strings (or `Brick\Math` values) to avoid floating point errors. Rounding follows EN 16931 (half up, two decimals for amounts).

Models can implement `Quittio\Core\Contracts\ProvidesInvoiceData`, `ProvidesParty` and `ProvidesInvoiceLine` and are then passed directly: `Quittio::make($order)`.

## Rendering

```php
use Quittio\Laravel\Facades\Quittio;

$result = Quittio::make($invoice)->render();          // format chosen automatically
$result = Quittio::make($invoice)->format('zugferd')->profile('en16931')->locale('de')->render();

$result->pdf();            // PDF/A-3b with embedded factur-x.xml
$result->xml();            // CII XML
$result->filename();       // Rechnung_RE-2026-0042.pdf
$result->hashes();         // SHA-256 of PDF and XML
$result->validation();     // ValidationResult (warnings included)

$result->save('invoices', '2026/');                    // filesystem disk and path
return $result->download();                             // or ->stream(), or return $result
Mail::to($customer)->send(new InvoiceMail($result));    // RenderedInvoice is Attachable
Quittio::make($invoice)->queue()->save('invoices', '2026/');
```

Cancel an issued invoice (a new invoice with its own number and a reference to the original):

```php
$cancellation = Quittio::cancellation($original, 'ST-2026-0001', reason: 'Charged twice');
Quittio::make($cancellation)->render();
```

The sign strategy is `credit_note` (type code 381, positive amounts, default in Germany) or `negative_invoice` (type code 380, negative amounts); set it per call or in `corrections.cancellation_strategy`. Both are permitted in Germany; the document is called "Stornorechnung", never "Gutschrift".

Several formats from one calculation:

```php
$result = Quittio::make($invoice)->formats(['zugferd', 'xrechnung-cii'])->render();
$result->as('xrechnung-cii')->xml();
```

## Without Laravel

```php
use Quittio\Core\Quittio;
use Quittio\Country\DE\CountryDE;

$quittio = Quittio::create([
    'validation' => ['mode' => 'error', 'levels' => ['L1', 'L2', 'L3', 'L4', 'L7']],
    'pdf' => ['b2c_embed_xml' => false],
], [new CountryDE]);

$result = $quittio->make($invoice)->format('zugferd')->render();
file_put_contents($result->filename(), $result->pdf());
```

The core uses plain PHP templates (`resources/templates/default.php`); pass your own `TemplateEngine` to `Quittio::create()` for Twig or others.

## JSON

`InvoiceData::toArray()`, `toJson()`, `fromArray()` and `fromJson()` convert the model losslessly. The JSON Schema is shipped in `packages/core/resources/schema/invoice.schema.json`; with `opis/json-schema` installed, `(new JsonSchemaValidator)->validate($json)` checks input before hydration. All fields are listed in the [field reference](field-reference.md).
