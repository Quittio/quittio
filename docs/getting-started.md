# Getting started

In this tutorial you install Quittio, enter your company once and then create, render and cancel a first invoice. It takes about 15 minutes. If e-invoices, ZUGFeRD or XRechnung are new to you, read [E-invoicing basics](concepts.md) first (5 minutes); you can also start right away and look terms up there.

You need PHP 8.3 or newer and, for the Laravel integration, Laravel 13. Without Laravel, switch the code blocks to PHP; [Without Laravel](#without-laravel) describes the setup of the core.

## Installation

```bash laravel
composer require quittio/quittio          # core, Laravel integration and country pack Germany
php artisan quittio:install               # publishes config/quittio.php (and config/quittio/countries/de.php for Germany)
```

```bash plain
composer require quittio/core quittio/country-de   # core and country pack Germany
```

## Set up your company

Every invoice names the seller: your company. You enter it once in `config/quittio.php`; `Quittio::invoice()` then adds it to every invoice. Without Laravel there is no configuration file: you pass the seller and its bank account to `Quittio::create()`, and `$quittio->invoice()` adds them to every invoice; the plain PHP code on this page uses this instance `$quittio`. The keys you need first:

```php laravel
// config/quittio.php (excerpt)
'seller' => [
    'name' => env('QUITTIO_SELLER_NAME'),               // e.g. Muster GmbH
    'vat_id' => env('QUITTIO_SELLER_VAT_ID'),           // e.g. DE123456789
    'tax_number' => env('QUITTIO_SELLER_TAX_NUMBER'),   // e.g. 12/345/67890
    'address' => ['line1' => 'Hauptstraße 1', 'postcode' => '12345', 'city' => 'Musterstadt', 'country' => 'DE'],
    'contact' => ['name' => 'Max Muster', 'phone' => '+49 123 456789', 'email' => 'rechnung@muster.de'],
    'electronic_address' => ['value' => 'rechnung@muster.de', 'scheme' => 'EM'],
    'bank_accounts' => [
        ['iban' => 'DE02120300000000202051', 'bic' => 'BYLADEM1001', 'name' => 'Muster GmbH'],
    ],
    // ...
],
```

```php plain
use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Builder\Party;
use Quittio\Core\Quittio;
use Quittio\Core\Tax\CountryDefaultVat;
use Quittio\Country\DE\CountryDE;

$quittio = Quittio::create([
    'seller' => Party::make('Muster GmbH')
        ->vatId('DE123456789')
        ->taxNumber('12/345/67890')
        ->address('Hauptstraße 1', '12345', 'Musterstadt', 'DE')
        ->contact('Max Muster', '+49 123 456789', 'rechnung@muster.de')
        ->electronicAddress('rechnung@muster.de', 'EM'),
    'bank_account' => ['iban' => 'DE02120300000000202051', 'bic' => 'BYLADEM1001', 'name' => 'Muster GmbH'],
    'due_days' => 14,                                    // payment target for invoices without one
], [new CountryDE]);
InvoiceBuilder::useDefaultVat(new CountryDefaultVat($quittio->countries()));   // lines without vat(): 19 %
```

| Key | Needed | Why |
|---|---|---|
| `name`, `address` | Always | German invoices must name the seller with the full address |
| `vat_id` or `tax_number` | Always | German invoices must show one of them; the VAT identification number (USt-IdNr.) is preferred |
| `contact`, `bank_accounts`, `electronic_address` | Recommended | Printed on the invoice or written to the XML; required for XRechnung (public buyers). The first account with an IBAN is used; without Laravel, one account as `bank_account` |
| `small_business` | Small businesses only | `true` (or `QUITTIO_SELLER_SMALL_BUSINESS=true`) marks every invoice as small business invoice without VAT (German Kleinunternehmer); without Laravel, `'small_business' => true` in `Quittio::create()` |

A seller without VAT identification number (e.g. a small business with a tax number only) also needs a trade register number (`legal_registration`) or another identifier (`identifiers`, as `[['value' => '…', 'scheme' => null]]`), because EN 16931 requires one of them (rule `BR-CO-26`). Every other key, such as logo, colours, default VAT and payment target, is explained in [Configuration](configuration.md) and in the comments of `config/quittio.php`.

To see the PDF design with sample data:

```bash
php artisan quittio:preview --scenario=simple             # storage/app/quittio/preview/simple.pdf
```

## Building an invoice

Start an invoice with `Quittio::invoice()`, add what is specific to this invoice, and end with `render()`. Without Laravel, start with `$quittio->invoice()` in the same way:

```php laravel
use Quittio\Core\Builder\Party;
use Quittio\Core\Enums\UnitCode;
use Quittio\Country\DE\Tax\VatPreset;
use Quittio\Laravel\Facades\Quittio;

$result = Quittio::invoice()                             // your company from config/quittio.php
    ->number('RE-2026-0042')
    ->deliveryDate('2026-09-30')                         // date of supply, mandatory in Germany
    ->buyer(Party::make('Kunde AG')
        ->vatId('DE987654321')
        ->address('Bahnhofstraße 5', '54321', 'Beispielstadt', 'DE'))
    ->line('Web development', netPrice: '95.00', quantity: 24, unit: UnitCode::Hour,
        description: 'Customer portal, sprint 3')
    ->line('Hosting', netPrice: '49.00', unit: UnitCode::Month)
    ->line('Handbook of VAT law', netPrice: '39.00', vat: VatPreset::Reduced)
    ->dueInDays(14)->cashDiscount(days: 7, percent: '2.00')
    ->render();

return $result->download();                              // or $result->save('invoices', '2026/')
```

```php plain
use Quittio\Core\Builder\Party;
use Quittio\Core\Enums\UnitCode;
use Quittio\Country\DE\Tax\VatPreset;

$result = $quittio->invoice()                            // your company from Quittio::create()
    ->number('RE-2026-0042')
    ->deliveryDate('2026-09-30')                         // date of supply, mandatory in Germany
    ->buyer(Party::make('Kunde AG')
        ->vatId('DE987654321')
        ->address('Bahnhofstraße 5', '54321', 'Beispielstadt', 'DE'))
    ->line('Web development', netPrice: '95.00', quantity: 24, unit: UnitCode::Hour,
        description: 'Customer portal, sprint 3')
    ->line('Hosting', netPrice: '49.00', unit: UnitCode::Month)
    ->line('Handbook of VAT law', netPrice: '39.00', vat: VatPreset::Reduced)
    ->dueInDays(14)->cashDiscount(days: 7, percent: '2.00')
    ->render();

$result->save(__DIR__.'/invoices/2026/');                // invoices/2026/Rechnung_RE-2026-0042.pdf
```

What the lines do:

- `number()` is your invoice number. The invoice date is today unless you call `issueDate('2026-10-03')`. Gapless numbering is your application's job, or that of the [Managed layer](managed.md).
- `deliveryDate()` is the date the goods were delivered or the service was performed. German invoices must state it, even when it is the invoice date.
- `buyer()` is your customer. `Party::make()` takes the name; add the address and, for businesses, the VAT identification number. You can also pass your own customer model ([Application models](#application-models)).
- `line()` adds a line with a name and named values. `netPrice` is the price per unit without VAT. Without `quantity` the quantity is 1; without `vat` the line gets the standard rate of your country (19 % in Germany). The third line uses the reduced rate of 7 %.
- `dueInDays(14)` sets the payment target, `cashDiscount()` an early payment discount (Skonto).
- `render()` calculates totals and VAT, checks the invoice, chooses the format and produces the PDF and the XML ([Rendering](#rendering)).

Totals and VAT are always calculated by Quittio; you never pass them in. Amounts and quantities are strings (`'95.00'`), because floating point numbers cannot store every amount exactly; floats are rejected. Rounding follows EN 16931: half up, two decimals for amounts, VAT per rate.

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

The seller comes from the configuration; set it in code only for another company (see [Starting invoices](laravel.md#starting-invoices)). Without Laravel it comes from `Quittio::create()` in the same way.

References, notes, further parties, allowances and charges, direct debit, attachments, gross prices and all other options are described in [Building invoices](building-invoices.md); the German VAT presets (`VatPreset::Standard`, `Reduced`, `ReverseChargeEu` …) in [Tax presets](country-packs.md#tax-presets).

### Application models

Your own models can provide invoice data through three contracts in `Quittio\Core\Contracts`: `ProvidesParty` (`fillParty(PartyBuilder $party)`, e.g. a customer as buyer), `ProvidesInvoiceLine` (`fillLine(LineBuilder $line)`, e.g. order items) and `ProvidesInvoiceData` (`toInvoiceData()`, e.g. an order for `Quittio::make($order)`).

```php laravel
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

```php plain
use Quittio\Core\Builder\PartyBuilder;
use Quittio\Core\Contracts\ProvidesParty;

class Customer implements ProvidesParty
{
    public function fillParty(PartyBuilder $party): void
    {
        $party->name($this->company ?? $this->name)
            ->vatId($this->vat_id)
            ->address($this->street, $this->postcode, $this->city, $this->country)
            ->electronicAddress($this->email, 'EM');
    }
}

$invoice = $quittio->invoice()
    ->buyer($customer)                                   // ProvidesParty
    ->lines($order->items)                               // items implement ProvidesInvoiceLine
    // ...
    ->build();
```

The invoice keeps a copy of these values; changing the model later does not change invoices that were already built. More in [Application models](building-invoices.md#application-models); to store invoices and link them to the user (`HasInvoices` trait, `$user->invoices()`), see [Customers and references](managed.md#customers-and-references) in the Managed layer.

## Rendering

`render()` without argument chooses the format for you: for a German seller ZUGFeRD for businesses (a PDF with the XML inside), XRechnung for public authorities and a plain PDF for consumers ([Which format do I need?](concepts.md#which-format-do-i-need)). Name a format when a customer asks for one:

```php laravel
$result = Quittio::invoice()
    ->number('RE-2026-0043')
    ->deliveryDate('2026-09-30')
    ->buyer(Party::make('Stadt Beispielstadt')
        ->address('Rathausplatz 1', '54321', 'Beispielstadt', 'DE')
        ->electronicAddress('rechnung@beispielstadt.de', 'EM'))   // XRechnung needs it
    ->buyerReference('04011000-12345-03')                        // Leitweg-ID of the authority
    ->line('Consulting', netPrice: '120.00')
    ->render('xrechnung-cii');
```

```php plain
$result = $quittio->invoice()
    ->number('RE-2026-0043')
    ->deliveryDate('2026-09-30')
    ->buyer(Party::make('Stadt Beispielstadt')
        ->address('Rathausplatz 1', '54321', 'Beispielstadt', 'DE')
        ->electronicAddress('rechnung@beispielstadt.de', 'EM'))   // XRechnung needs it
    ->buyerReference('04011000-12345-03')                        // Leitweg-ID of the authority
    ->line('Consulting', netPrice: '120.00')
    ->render('xrechnung-cii');
```

The result holds everything that was produced:

```php laravel
$result->pdf();            // the PDF (PDF/A-3 with embedded XML for ZUGFeRD)
$result->xml();            // the XML
$result->filename();       // Rechnung_RE-2026-0042.pdf
$result->validation();     // findings of the validation, including warnings

$result->save('invoices', '2026/');                    // to a filesystem disk and path
return $result->download();                             // or ->stream(), or return $result
Mail::to($customer)->send(new YourInvoiceMail($result)); // your Mailable; the result is Attachable
```

```php plain
$result->pdf();            // the PDF (PDF/A-3 with embedded XML for ZUGFeRD)
$result->xml();            // the XML
$result->filename();       // Rechnung_RE-2026-0042.pdf
$result->validation();     // findings of the validation, including warnings

$result->save('/path/to/invoices/2026/');                // to a local directory or file
header('Content-Type: '.$result->mimeType());            // send it to the browser
header('Content-Disposition: attachment; filename="'.$result->filename().'"');
echo $result->contents();
$email->attach($result->contents(), $result->filename(), $result->mimeType());   // e.g. a Symfony Mailer Email
```

Every render calculates, validates and renders. An invalid invoice throws a `ValidationException` that names the rule, the field and how to fix it ([Troubleshooting](troubleshooting.md)).

For invoice data you built earlier (`->build()` instead of `->render()`), or for more control, use `Quittio::make()` (without Laravel `$quittio->make()`):

```php laravel
use Quittio\Laravel\Facades\Quittio;

$result = Quittio::make($invoice)->render();                                   // format chosen automatically
$result = Quittio::make($invoice)->format('zugferd')->locale('en')->render();  // format and language
$result = Quittio::make($invoice)->formats(['zugferd', 'xrechnung-cii'])->render();   // several formats at once
$result->as('xrechnung-cii')->xml();
Quittio::make($invoice)->queue()->save('invoices', '2026/');                   // render in a queue job
```

```php plain
$result = $quittio->make($invoice)->render();                                   // format chosen automatically
$result = $quittio->make($invoice)->format('zugferd')->locale('en')->render();  // format and language
$result = $quittio->make($invoice)->formats(['zugferd', 'xrechnung-cii'])->render();   // several formats at once
$result->as('xrechnung-cii')->xml();
```

All formats and profiles are described in [Formats and profiles](formats.md); storage, downloads, queues, events and mail in [Laravel integration](laravel.md).

## Cancellation

An issued invoice is never changed or deleted. To cancel it, you issue a cancellation invoice (German Stornorechnung): a new invoice with its own number that refers to the original and reverses its amounts.

```php laravel
$cancellation = $result->cancel('ST-2026-0001', reason: 'Charged twice');   // rendered, in the formats of $result
```

```php plain
$cancellation = $quittio->make($quittio->cancellation($result->data(), 'ST-2026-0001', reason: 'Charged twice'))
    ->formats($result->formats())->render();                                   // in the formats of $result
```

For an invoice you stored as data, build the cancellation and render it:

```php laravel
$cancellation = Quittio::make(Quittio::cancellation($original, 'ST-2026-0001', reason: 'Charged twice'))->render();
```

```php plain
$cancellation = $quittio->make($quittio->cancellation($original, 'ST-2026-0001', reason: 'Charged twice'))->render();
```

`$original` is the issued, calculated invoice, e.g. `$result->data()` or `InvoiceData::fromJson()` of what you stored. In Germany the cancellation is a credit note with positive amounts by default (type code 381); `strategy: 'negative_invoice'` produces an invoice with negative amounts instead (type code 380, also `corrections.cancellation_strategy`). Both are permitted in Germany; the document is called "Stornorechnung" (cancellation invoice), never "Gutschrift" (which means a self-billed invoice in German law).

`lines:` cancels part of an invoice (whole lines, quantities or amounts):

```php laravel
$part = $result->cancel('ST-2026-0002', lines: [
    ['line' => '1', 'quantity' => '1'],
    ['line' => '2', 'amount' => '40.00'],
]);
```

```php plain
$part = $quittio->make($quittio->cancellation($result->data(), 'ST-2026-0002', lines: [
    ['line' => '1', 'quantity' => '1'],
    ['line' => '2', 'amount' => '40.00'],
]))->formats($result->formats())->render();
```

When an invoice is cancelled in several parts, pass the earlier parts as `history:`, so that the VAT of all parts adds up exactly to the VAT of the original. Strategies, partial cancellations and the history are described in [Cancellations and corrections](building-invoices.md#cancellations-and-corrections); the Managed layer keeps the history itself ([Managed layer](managed.md#cancellations-and-corrections)).

## Without Laravel

The core (`quittio/core`, plus a country pack such as `quittio/country-de`) works in any PHP application. The code blocks above show it when you switch them to PHP; this section sums up the setup. Create an instance with `Quittio\Core\Quittio::create()`; there is no configuration file, so the seller and its bank account are passed there and the default VAT is registered once. `$quittio->invoice()` then starts invoices like `Quittio::invoice()` in Laravel:

```php
use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Builder\Party;
use Quittio\Core\Quittio;
use Quittio\Core\Tax\CountryDefaultVat;
use Quittio\Country\DE\CountryDE;

$quittio = Quittio::create([
    'seller' => Party::make('Muster GmbH')->vatId('DE123456789')->address('Hauptstraße 1', '12345', 'Musterstadt', 'DE'),
    'bank_account' => ['iban' => 'DE02120300000000202051'],
    'validation' => ['mode' => 'error', 'levels' => ['L1', 'L2', 'L3', 'L4', 'L7']],
    'pdf' => ['b2c_embed_xml' => false],
], [new CountryDE]);
InvoiceBuilder::useDefaultVat(new CountryDefaultVat($quittio->countries()));   // lines without vat(): 19 %

$result = $quittio->invoice()                            // with the seller and its bank account
    // ... as above
    ->render('zugferd');
file_put_contents($result->filename(), $result->pdf());      // or $result->save('/path/to/dir')

$cancellation = $quittio->cancellation($result->data(), 'ST-2026-0001', reason: 'Charged twice');
```

`$quittio->invoice()` applies the seller as `Quittio::invoice()` does: `->seller(fn ($s) => …)` changes the configured seller, another seller (`->seller(Party::make(…))`) drops its bank account and small business flag, `payment()` with an IBAN replaces the account and `creditTransfer()` adds a further one. It ends with `->render()`, `->render('xrechnung-cii')`, `->render(['zugferd', 'xrechnung-cii'])` or `->check()`. `$quittio->quote()`, `orderConfirmation()`, `deliveryNote()`, `proforma()`, `paymentReminder()`, `bonusStatement()` and `accountStatement()` start documents with the same seller and end with `->render()`. For anything special, such as several sellers in one application, `InvoiceBuilder::make()` still starts an empty invoice: set the seller with `->seller()` and render it with `$quittio->make($builder)->render()`.

`Quittio::create(Config|array $config = [], iterable $countryPacks = [], ?PdfRenderer $renderer = null, ?TemplateEngine $templates = null, ?LoggerInterface $logger = null, ?FormatResolver $formatResolver = null)` takes the configuration as array or as `Quittio\Core\Config` object (`new Config(...)`, `Config::fromArray([...])`, `$config->with([...])`). The array keys are flatter than those of the Laravel `config/quittio.php`; the Laravel integration translates its configuration itself:

| Key | Default | Laravel key |
|---|---|---|
| `seller` | `null` (a `Party::make()` builder or a `SellerParty`: the seller of `invoice()` and the document methods) | `seller.*` |
| `bank_account` | `null` (`['iban' => …, 'bic' => …, 'name' => …]`, added as SEPA credit transfer; needs `seller`) | `seller.bank_accounts` (the first account with an IBAN) |
| `small_business` | `false` (flags every invoice as small business invoice; needs `seller`) | `seller.small_business` |
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

The validation keys are described in [Validation](validation.md), the output keys in [Output configuration](formats.md#output-configuration), the Laravel keys in [Configuration](configuration.md). The instance offers the methods of the facade: `invoice()` and the document methods, `make()`, `edit()` (continue existing invoice data), `cancellation()`, `document()`, `read()`, `readFile()`, `validateFile()` and `validatePath()`, plus `registerCountry()`, `setRenderer()`, `setTemplateEngine()`, `setFormatResolver()`, `setPostProcessor()` (PDF/A and XML embedding for renderers that cannot embed), `addSerializer()`, `addValidator()`, `clearValidators()`, `configureRules()` and `ruleCodes()` ([switching rules off](validation.md#switching-rules-off-and-changing-their-severity)) and `onMandatoryFieldsMissing()`. A custom `Quittio\Core\Calculation\TotalsCalculator`, passed to `Quittio::create(calculator: …)` (or the constructor), calculates render runs, documents, read invoices, edits and cancellations; `calculator()` returns it. Without one, the EN 16931 calculator applies the calculation policy of `calculation.declared_amounts` ([Declared amounts](building-invoices.md#declared-amounts)).

The core uses plain PHP templates (`resources/templates/default.php`); pass your own `TemplateEngine` to `Quittio::create()` for Twig or others.

## JSON

`InvoiceData::toArray()`, `toJson()`, `fromArray()` and `fromJson()` convert the model losslessly. The JSON Schema is shipped in `packages/core/resources/schema/invoice.schema.json`; with `opis/json-schema` installed, `(new JsonSchemaValidator)->validate($json)` checks input before hydration. Details in [Arrays and JSON](building-invoices.md#arrays-and-json); all fields are listed in the [field reference](field-reference.md).

## Next steps

- [Examples](examples.md): complete invoices for common situations, from small businesses and XRechnung to customers abroad and reminders.
- [Building invoices](building-invoices.md): every builder method.
- [Templates and design](templates.md): logo, colours, texts and your own template.
- [Validation](validation.md) and [Troubleshooting](troubleshooting.md): what the checks do and how to fix errors.
- [Laravel integration](laravel.md): storage, queues, events and mail.
