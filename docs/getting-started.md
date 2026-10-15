# Getting started

This tutorial creates, renders and cancels a first invoice. The references for each step are [Building invoices](building-invoices.md) (every builder method, VAT, calculation, cancellations, data model), [Formats and profiles](formats.md) (outputs and the automatic format choice), [Laravel integration](laravel.md) (facade, storage, queue, mail) and [Configuration](configuration.md) (every key of `config/quittio.php`). Complete examples for common situations, from small businesses to XRechnung and incoming invoices, are on [Examples](examples.md).

## Installation

```bash
composer require quittio/quittio          # core, Laravel integration and country pack Germany
php artisan quittio:install               # publishes config/quittio.php
```

Without Laravel, require `quittio/core` (and a country pack such as `quittio/country-de`) and use `Quittio\Core\Quittio::create()` (see [Without Laravel](#without-laravel)).

Set the seller in `config/quittio.php` (name, VAT identifier and tax number also via `QUITTIO_SELLER_NAME`, `QUITTIO_SELLER_VAT_ID` and `QUITTIO_SELLER_TAX_NUMBER`; small businesses set `seller.small_business`), then render the sample invoices to check the template.

Without a VAT identifier (e.g. a small business with a tax number only), EN 16931 requires a seller identifier (BT-29, `seller.identifiers` as `[['value' => '…', 'scheme' => null]]`) or the trade register number (BT-30, `seller.legal_registration`); otherwise rendering fails with BR-CO-26. `Seller::apply()` uses the first entry of `seller.bank_accounts` that has an IBAN.

```bash
php artisan quittio:preview --scenario=simple
```

## Building an invoice

`InvoiceBuilder` creates the immutable `InvoiceData` (EN 16931 model). Totals and the VAT breakdown are calculated when rendering; never pass them in.

```php
use Quittio\Core\Builder\Party;
use Quittio\Core\Enums\UnitCode;
use Quittio\Country\DE\Tax\VatPreset;
use Quittio\Laravel\Facades\Quittio;

$invoice = Quittio::invoice()                          // seller and bank account from the config
    ->number('RE-2026-0042')
    ->issueDate('2026-10-03')
    ->deliveryDate('2026-09-30')                         // service date, mandatory in Germany
    ->buyerReference('PO-4711')                          // BT-10, required by XRechnung
    ->buyer(Party::make('Kunde AG')
        ->vatId('DE987654321')
        ->address('Bahnhofstraße 5', '54321', 'Beispielstadt', 'DE')
        ->electronicAddress('ap@kunde.de', 'EM'))
    ->line('Web development', netPrice: '95.00', quantity: 24, unit: UnitCode::Hour,
        description: 'Customer portal, sprint 3')        // 19 %: the default VAT
    ->line('Hosting', netPrice: '49.00', unit: UnitCode::Month)
    ->line('Handbook of VAT law', netPrice: '39.00', vat: VatPreset::Reduced7)
    ->dueInDays(14)->cashDiscount(days: 7, percent: '2.00')
    ->build();
```

`Quittio::invoice()` starts an `InvoiceBuilder` with the seller, the first bank account and the small business setting of `config/quittio.php`; `build()` returns the data, `render()` instead renders it right away ([Rendering](#rendering)).

### One way to write it

Every part of an invoice has one usual form. The documentation and the examples use these forms; the other forms exist for special cases and are explained in [Building invoices](building-invoices.md).

| You need | Write |
|---|---|
| A line with price, quantity, unit, VAT or description | `->line('Hosting', netPrice: '49.00', quantity: 12, unit: UnitCode::Month)`. Without `quantity` it is 1, without `vat` the [default VAT](building-invoices.md#default-vat) applies (19 % for a German seller). |
| A line with more than that: item number, line discount, list price, period, sub lines, line group | `->line(Line::make('Pump', netPrice: '1450.00', quantity: 2)->sellerId('P-200'))`. `Line::make()` takes the same named values as `line()` and returns the line builder for the other methods. |
| Many lines from your data | `->lines($order->items)` with models that implement `ProvidesInvoiceLine`, or `->lines($rows)` with arrays such as `['name' => 'Hosting', 'netPrice' => '49.00']` |
| The customer | `->buyer($customer)` with a model that implements `ProvidesParty`, otherwise `->buyer(Party::make('Kunde AG')->address(…))` |
| Payment terms | `->dueInDays(14)->cashDiscount(days: 7, percent: '2.00')` |
| The result | `->render()` for PDF and XML, `->build()` for the data only, e.g. to store a draft |

The seller comes from the configuration; set it in code only for another company (see [Starting invoices](laravel.md#starting-invoices)).

Amounts and quantities are strings (or `Brick\Math` values) to avoid floating point errors; floats are rejected with an `InvalidArgumentException`. Rounding follows EN 16931 (half up, two decimals for amounts, VAT per VAT group). References, notes, further parties, allowances and charges, payment means such as direct debit, attachments, gross prices and all other options are described in [Building invoices](building-invoices.md); the German tax presets (`VatPreset::Standard19`, `Reduced7`, `ReverseChargeEu` …) in [Tax presets](country-packs.md#tax-presets).

### Application models

Your own models can provide invoice data through three contracts in `Quittio\Core\Contracts`: `ProvidesParty` (`fillParty(PartyBuilder $party)`, e.g. a customer as buyer), `ProvidesInvoiceLine` (`fillLine(LineBuilder $line)`, e.g. order items) and `ProvidesInvoiceData` (`toInvoiceData()`, e.g. an order for `Quittio::make($order)`).

```php
use Quittio\Core\Builder\PartyBuilder;
use Quittio\Core\Contracts\ProvidesParty;
use Quittio\Laravel\Facades\Quittio;

class User extends Authenticatable implements ProvidesParty
{
    public function fillParty(PartyBuilder $party): void
    {
        $party->name($this->company ?? $this->name)
            ->vatId($this->vat_id)
            ->address($this->street, $this->postcode, $this->city, $this->country)
            ->electronicAddress($this->email, 'EM');
    }
}

$invoice = Quittio::invoice()
    ->buyer($user)                                       // ProvidesParty
    ->lines($order->items)                               // items implement ProvidesInvoiceLine
    // ...
    ->build();
```

The invoice keeps a copy of these values; changing the model later does not change invoices that were already built. More in [Application models](building-invoices.md#application-models); to store invoices and link them to the user (`HasInvoices` trait, `$user->invoices()`), see [Customers and references](managed.md#customers-and-references) in the Managed layer.

## Rendering

Instead of `build()`, end the chain with `render()`: without argument in the automatically chosen format, otherwise in the given format or formats.

```php
$result = Quittio::invoice()->buyer($user)->line('Consulting', netPrice: '120.00')->render();
$result = Quittio::invoice()->buyer($user)->line('Consulting', netPrice: '120.00')->render('xrechnung-cii');
```

For data you built before, or for more options (profile, locale, template, queue):

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
Mail::to($customer)->send(new YourInvoiceMail($result)); // your Mailable; RenderedInvoice is Attachable
Quittio::make($invoice)->queue()->save('invoices', '2026/');
```

Without `format()` (and without `defaults.format`), the format resolver chooses: in Germany XRechnung for public buyers with a Leitweg-ID, a PDF for consumers and small amount invoices, ZUGFeRD in profile EN 16931 for all other businesses ([Automatic choice](formats.md#automatic-choice)). Every run calculates, validates (errors throw a `ValidationException`, see [Validation](validation.md)) and renders.

Several formats from one calculation (XRechnung requires the buyer reference BT-10, for public buyers the Leitweg-ID):

```php
$result = Quittio::make($invoice)->formats(['zugferd', 'xrechnung-cii'])->render();
$result->as('xrechnung-cii')->xml();
```

All formats and profiles, the embedded files and own PDF renderers are described in [Formats and profiles](formats.md); storage, downloads, queues, events and mail in [Laravel integration](laravel.md).

## Cancellation

Cancel an issued invoice (a new invoice with its own number and a reference to the original):

```php
$cancellation = $result->cancel('ST-2026-0001', reason: 'Charged twice');   // rendered, in the formats of $result
```

For an invoice you stored as data, build the cancellation and render it:

```php
$cancellation = Quittio::make(Quittio::cancellation($original, 'ST-2026-0001', reason: 'Charged twice'))->render();
```

`$original` is the issued, calculated invoice, e.g. `$result->data()` or `InvoiceData::fromJson()` of what you stored. The sign strategy is `credit_note` (type code 381, positive amounts, default in Germany) or `negative_invoice` (type code 380, negative amounts); set it per call (`strategy:`) or in `corrections.cancellation_strategy`. Both are permitted in Germany; the document is called "Stornorechnung", never "Gutschrift".

`lines:` cancels part of an invoice (whole lines, quantities or amounts):

```php
$part = $result->cancel('ST-2026-0002', lines: [
    ['line' => '1', 'quantity' => '1'],
    ['line' => '2', 'amount' => '50.00'],
]);
```

Pass the earlier partial cancellations as `history:` (`CancellationHistory`, e.g. built with `withCancellation()` and the VAT groups the new part closes), so that the VAT is rounded cumulatively and all parts together reverse exactly the VAT of the original. Strategies, partial cancellations and the history are described in [Cancellations and corrections](building-invoices.md#cancellations-and-corrections); the Managed layer keeps the history itself (see [Managed layer](managed.md#cancellations-and-corrections)).

## Without Laravel

```php
use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Builder\Party;
use Quittio\Core\Quittio;
use Quittio\Core\Tax\CountryDefaultVat;
use Quittio\Country\DE\CountryDE;

$quittio = Quittio::create([
    'validation' => ['mode' => 'error', 'levels' => ['L1', 'L2', 'L3', 'L4', 'L7']],
    'pdf' => ['b2c_embed_xml' => false],
], [new CountryDE]);
InvoiceBuilder::useDefaultVat(new CountryDefaultVat($quittio->countries()));   // lines without vat(): 19 %

$invoice = InvoiceBuilder::make()
    ->seller(Party::make('Muster GmbH')->vatId('DE123456789')->address('Hauptstraße 1', '12345', 'Musterstadt', 'DE'))
    // ... as above, without Quittio::invoice()
    ->build();

$result = $quittio->make($invoice)->format('zugferd')->render();
file_put_contents($result->filename(), $result->pdf());      // or $result->save('/path/to/dir')

$cancellation = $quittio->cancellation($result->data(), 'ST-2026-0001', reason: 'Charged twice');
```

`Quittio::create(Config|array $config = [], iterable $countryPacks = [], ?PdfRenderer $renderer = null, ?TemplateEngine $templates = null, ?LoggerInterface $logger = null, ?FormatResolver $formatResolver = null)` takes the configuration as array or as `Quittio\Core\Config` object (`new Config(...)`, `Config::fromArray([...])`, `$config->with([...])`). The array keys are flatter than those of the Laravel `config/quittio.php`; the Laravel integration translates its configuration itself:

| Key | Default | Laravel key |
|---|---|---|
| `format` | `null` (automatic choice; also `'auto'`) | `defaults.format` |
| `profile` | `en16931` | `defaults.profile` |
| `locale`, `country` | `null` (country pack default, seller country) | `defaults.locale`, `defaults.country` |
| `due_days` | `null` | `defaults.due_days` |
| `template`, `template_paths` | `default`, `[]` (directories with `{name}.php`) | `pdf.template` (Blade view) |
| `filename` | `{title}_{number}.{ext}` | `pdf.filename` |
| `pdf.pdfa`, `pdf.b2c_embed_xml`, `pdf.girocode_amount` | `true`, `false`, `due` | `pdf.pdfa`, `format_resolver.b2c_embed_xml`, `pdf.payment_qr.girocode_amount` |
| `auto_upgrade_profile` | `true` | `format_resolver.auto_upgrade_profile` |
| `validation.mode` | `error` (`warn`, `off`) | `validation.mode` |
| `validation.levels` | `['L1', 'L2', 'L3', 'L4', 'L7']` | `validation.levels` (`php`, `xsd`) |
| `validation.block_on_warnings` | `false` | `validation.block_on_warnings` |
| `validation.locale` | `null` (the language of the invoice; `'app'` = the language of the translator; or a locale) | `validation.locale` (default: the language of the application) |
| `validation.kosit`, `validation.verapdf` | disabled; `['enabled' => true, 'mode' => …, …]` enables L5 and L6 (KoSIT scenario sets only in `configurations`) | `validation.levels` (`kosit`, `verapdf`) and `validation.drivers.*` |
| `mandatory_fields` | `exception` (`log`, `off`) | `validation.mandatory_field_guard` |
| `temp_dir` | system temp directory | `pdf.cache_path` |
| `options` | `[]` (e.g. `paper`, `markdown`, `fold_marks`, `watermark`) | `pdf.paper`, `pdf.markdown`, `pdf.fold_marks` |
| `presentation` | `null` (a `Presentation` object with the design defaults) | `pdf.*` design keys |
| `reader.max_xml_bytes`, `reader.max_pdf_bytes`, `reader.max_decompressed_bytes` | 10, 25 and 50 MB | `reader.*` |
| `corrections.cancellation_strategy` | `null` (country pack default) | `corrections.cancellation_strategy` |

The validation keys are described in [Validation](validation.md), the output keys in [Output configuration](formats.md#output-configuration), the Laravel keys in [Configuration](configuration.md). The instance offers the methods of the facade: `make()`, `cancellation()`, `document()`, `read()`, `readFile()`, `validateFile()` and `validatePath()`, plus `registerCountry()`, `withRenderer()`, `withTemplateEngine()`, `withFormatResolver()`, `withSerializer()`, `withValidator()`, `withoutValidators()` and `onMandatoryFieldsMissing()`.

The core uses plain PHP templates (`resources/templates/default.php`); pass your own `TemplateEngine` to `Quittio::create()` for Twig or others.

## JSON

`InvoiceData::toArray()`, `toJson()`, `fromArray()` and `fromJson()` convert the model losslessly. The JSON Schema is shipped in `packages/core/resources/schema/invoice.schema.json`; with `opis/json-schema` installed, `(new JsonSchemaValidator)->validate($json)` checks input before hydration. Details in [Arrays and JSON](building-invoices.md#arrays-and-json); all fields are listed in the [field reference](field-reference.md).
