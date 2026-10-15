# Testing

Quittio ships a fake for fast feature tests, assertions for real e-invoices, a deterministic
invoice factory and named sample scenarios. The factory and the scenarios are part of
`quittio/core` and work without Laravel; the fake and the assertions need `quittio/laravel`.

## Fake

```php
use Quittio\Core\Data\InvoiceData;
use Quittio\Laravel\Facades\Quittio;
use Quittio\Laravel\Rendering\RenderedInvoice;

it('renders the invoice of an order', function () {
    $fake = Quittio::fake();

    $this->post('/orders/42/invoice')->assertOk();

    Quittio::assertRenderedCount(1);
    Quittio::assertRendered(fn (RenderedInvoice $r) => $r->format() === 'zugferd'
        && $r->data()->totals->amountDue->isEqualTo('1234.56'));
    expect($fake->rendered()[0]->data()->number)->toBe('RE-2026-0042');
});
```

`Quittio::fake()` replaces the manager with a `Quittio\Laravel\Testing\QuittioFake` and
returns it. It is swapped into the facade and into the container, so code that injects
`QuittioManager`, queued `RenderInvoice` jobs and the Managed layer use the fake as well.

The fake records every render call and returns results quickly. It takes the decisions of a
real run (`prepare()` of the core without validators) and skips validation, XML, template
and PDF:

| | Behaviour of the fake |
|---|---|
| Calculation | Real: totals, VAT breakdown and the due date of `defaults.due_days`, so `data()` holds the amounts |
| Format | As in a real run: `format()`/`formats()`, then `defaults.format`, then the automatic choice (country rules, `format_resolver`, Peppol lookup). Every format of the call is recorded under the main result, so `formats()` and `as()` work. |
| Profile | As in a real run: `profile()` with a key, alias or `Profile` object, `defaults.profile`, the profile of the automatic choice or of the format, the EXTENDED upgrade; `null` for formats without XML |
| Errors | An unknown format or profile, or a profile the format does not allow, throws a `ConfigurationException` like a real run |
| Validation | None: `validation()` is an empty `ValidationResult`, no `ValidationException` |
| Outputs | Placeholders according to the format: XML `<fake number="…"/>`, PDF `%PDF-1.7` with a comment, HTML `<p>{number}</p>`, JSON of the data |
| File name | `Invoice_{number}.{ext}` |
| Documents | Calculated; fake HTML and, unless `htmlOnly()`, a fake PDF; file name `{number}.{ext}` |
| Events | None |

Not faked: `check()`, `prepare()`, `read()`, `readFile()`, `validateFile()`,
`validatePath()` and `cancellation()` run the real core. Responses, `save()`, mail
attachments and `queue()` work on the fake results.

Assertions and helpers of the fake (also callable through the facade):

| Method | Asserts / returns |
|---|---|
| `assertRendered(?callable $callback = null)` | At least one invoice was rendered; with a callback, at least one `RenderedInvoice` for which it returns `true` |
| `assertRenderedCount(int $count)` | Exactly `$count` invoices were rendered |
| `assertNothingRendered()` | No invoice was rendered |
| `assertDocumentRendered(?callable $callback = null)` | At least one document (quote, reminder, …) was rendered; with a callback, one `RenderedDocument` matches |
| `rendered(): array` | The recorded `RenderedInvoice` results (the main format of each call), in order |
| `documents(): array` | The recorded `RenderedDocument` results, in order |

```php
$fake = Quittio::fake();

Quittio::document($quote)->htmlOnly()->render();

expect($fake->documents())->toHaveCount(1)
    ->and($fake->documents()[0]->hasPdf())->toBeFalse();
```

The fake stays active until the application is rebuilt for the next test.

## Laravel fakes

Without `Quittio::fake()` the real core renders, so the usual Laravel fakes test the
integration end to end:

```php
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Quittio\Core\Data\InvoiceData;
use Quittio\Laravel\Events\InvoiceRendered;
use Quittio\Laravel\Events\InvoiceValidationFailed;
use Quittio\Laravel\Facades\Quittio;
use Quittio\Laravel\Jobs\RenderInvoice;

it('stores the PDF', function () {
    Storage::fake('invoices');

    Quittio::make(InvoiceData::factory()->make())->format('zugferd')->render()->save('invoices', '2026/');

    Storage::disk('invoices')->assertExists('2026/Rechnung_RE-2026-0042.pdf');
});

it('dispatches the events', function () {
    Event::fake([InvoiceRendered::class, InvoiceValidationFailed::class]);

    Quittio::make(InvoiceData::factory()->make())->format('cii')->render();

    Event::assertDispatched(InvoiceRendered::class, fn ($e) => $e->result->format() === 'cii');
    Event::assertNotDispatched(InvoiceValidationFailed::class);
});

it('queues the rendering', function () {
    Queue::fake();

    Quittio::make(InvoiceData::factory()->make())->queue()->save('invoices', 'queued/');

    Queue::assertPushedOn('quittio', RenderInvoice::class, fn (RenderInvoice $job) => $job->path === 'queued/');
});
```

`Mail::fake()` with `Mail::assertSent(YourMail::class, fn ($mail) => $mail->hasAttachment($result))`
checks attachments, because rendered results are `Attachable`. After changing
`config('quittio.…')` inside a test, call `Quittio::flush()` (see
[Configuration](configuration.md)).

## Factory

`InvoiceData::factory()` (or `Quittio\Core\Testing\InvoiceFactory::new()`) builds
deterministic test invoices: no random values, so XML snapshots stay stable. Every method
returns a new factory.

```php
use Quittio\Core\Data\InvoiceData;

InvoiceData::factory()->make();                                   // one line, 19 %
InvoiceData::factory()->reverseCharge()->withLines(5)->make();
InvoiceData::factory()->intraCommunity()->calculated();
InvoiceData::factory()->b2c()->currency('USD')->number('X-1')->make();
InvoiceData::factory()->creditNote()->state(fn ($builder) => $builder->buyerReference('04011000-12345-03'))->make();
```

| Method | Effect |
|---|---|
| `withLines(int $count)` | Number of lines (at least 1). Line *i*: "Item *i*", quantity *i* pieces, net price 10 × *i*, VAT 19 % (or the category of the VAT mode) |
| `reverseCharge()` | Buyer Client SARL (Paris, `FR12345678901`), lines in category `AE` with `VATEX-EU-AE` |
| `intraCommunity()` | Buyer Cliente S.r.l. (Rome, `IT12345678901`), lines in category `K` with `VATEX-EU-IC` |
| `export()` | Buyer Customer Inc. (New York, no VAT identifier), lines in category `G` with `VATEX-EU-G` |
| `b2c()` | Consumer buyer Erika Mustermann (Berlin), no buyer reference |
| `currency(string $currency)` | Invoice currency; other than EUR adds the VAT accounting currency EUR (rate 0.9 of 2026-10-02) and a credit transfer |
| `number(string $number)` | Invoice number (default `RE-2026-0042`) |
| `creditNote()` | Type code 381 with the preceding invoice RE-2026-0001 of 2026-09-01 |
| `state(callable $state)` | Calls `$state(InvoiceBuilder $builder)` for free changes; several states run in order |
| `builder(): InvoiceBuilder` | The prepared builder |
| `make(): InvoiceData` | Invoice data, not calculated (as passed to `Quittio::make()`) |
| `calculated(): InvoiceData` | Invoice data with totals and VAT breakdown (`En16931Calculator`) |

The base invoice is the one of the scenarios (`Scenarios::base()`): number `RE-2026-0042`,
issue date 2026-10-03, delivery date 2026-09-30, locale `de`, seller Muster GmbH
(`DE123456789`, Musterstadt), buyer Kunde AG (`DE987654321`), buyer reference `PO-4711`,
payment terms 14 days and a SEPA credit transfer. With the German country pack the
automatic choice renders them as ZUGFeRD (with `b2c()` as plain PDF); `withLines(3)` gives
net 140.00, VAT 26.60 and an amount due of 166.60.

## Scenarios

`Quittio\Core\Testing\Scenarios` holds the sample invoices of the preview, the visual tests
and the official validation runs. They are country neutral (VAT categories, no tax presets).

| Method | Returns |
|---|---|
| `Scenarios::names(): array` | All scenario names |
| `Scenarios::make(string $name): InvoiceBuilder` | The builder of a scenario; an unknown name throws an `InvalidArgumentException` |
| `Scenarios::profile(string $name): string` | Best profile: `extended` for `sub-lines`, `peppol` for `peppol`, otherwise `en16931` |
| `Scenarios::base(): InvoiceBuilder` | Seller, buyer, dates and payment of all scenarios, without lines |

Each scenario also has a static method (`Scenarios::simple()`, `manyLines()`, `longTexts()`,
`mixedVat()`, `reverseCharge()`, `intraEu()`, `export()`, `cancellation()`, `subLines()`,
`foreignCurrency()`, `b2cGross()`, `allowances()`, `attachments()`, `peppol()`).

| Name | Content |
|---|---|
| `simple` | Two lines (24 h web development, monthly hosting with a period), 19 %, 2 % cash discount within 7 days |
| `many-lines` | 60 lines with item numbers, 7 % and 19 % (page breaks) |
| `long-texts` | Long note and description, item attributes |
| `mixed-vat` | 7 %, 19 % and zero rated lines, 10 % document discount |
| `reverse-charge` | French buyer, reverse charge (`AE`) |
| `intra-eu` | Italian buyer, intra-community supply (`K`), delivery address, country of origin, HS code |
| `export` | US buyer, export (`G`) |
| `cancellation` | Credit note ST-2026-0007 cancelling RE-2026-0042 |
| `sub-lines` | Line with three sub lines, invoicee and delivery terms (profile EXTENDED) |
| `foreign-currency` | USD invoice with the VAT accounting currency EUR |
| `b2c-gross` | Consumer buyer, gross prices, 7 % and 19 % |
| `allowances` | Line allowance and charge, document charge and allowance, price base quantity, list price with discount |
| `attachments` | Embedded CSV time sheet and an external document reference |
| `peppol` | Belgian buyer, Peppol endpoints (`0208`, `9930`), reverse charge |

```php
$invoice = Scenarios::make('sub-lines')->number('T-1')->build();
$result = Quittio::make($invoice)->profile(Scenarios::profile('sub-lines'))->format('zugferd')->render();
```

## Assertions

The trait `Quittio\Laravel\Testing\InteractsWithQuittio` adds two assertions to a test
case:

```php
// PHPUnit
use Quittio\Laravel\Testing\InteractsWithQuittio;

class InvoiceTest extends TestCase
{
    use InteractsWithQuittio;
}

// Pest: in a test file, or for a directory in tests/Pest.php
uses(InteractsWithQuittio::class);
pest()->use(InteractsWithQuittio::class)->in('Feature');
```

| Method | Asserts |
|---|---|
| `assertValidEInvoice(string $pdfOrXml, ?string $profile = null)` | Reads the PDF or XML content and validates it with all configured levels (`quittio.validation.levels`, see [below](#official-validators-in-ci)). Fails with the code and message of every error; warnings do not fail. With `$profile`, the profile read from the file must be that profile (aliases are resolved, e.g. `comfort`). |
| `assertXmlMatchesSnapshot(string $xml, ?string $name = null)` | Compares the canonicalised XML (whitespace between elements removed, re-indented) with a stored snapshot. The first run writes the snapshot and passes. Not well-formed XML fails. |

```php
$result = Quittio::make(InvoiceData::factory()->make())->formats(['zugferd', 'xrechnung-ubl'])->render();

$this->assertValidEInvoice($result->pdf(), 'en16931');
$this->assertValidEInvoice($result->as('xrechnung-ubl')->xml(), 'xrechnung');
$this->assertXmlMatchesSnapshot($result->xml(), 'zugferd-default');
```

Snapshots are stored in `__snapshots__/quittio/{name}.xml` next to the test file; the name is
reduced to letters, digits, `.`, `_` and `-`. Without a name it is
`{TestClass}__{test name}`; Pest generates class and test names, so pass a name there. To
accept a changed XML, delete the snapshot file and run the test again. Override the
protected methods `quittioSnapshotDirectory()` and `quittioSnapshotName()` to change the
location and the default name.

Outside test case classes (e.g. in Pest helper functions),
`Quittio\Laravel\Testing\EInvoiceAssertions` provides the same assertions:

```php
use Quittio\Laravel\Testing\EInvoiceAssertions;

(new EInvoiceAssertions)->assertValidEInvoice($pdf, 'xrechnung');
(new EInvoiceAssertions(base_path('tests/__snapshots__')))->assertXmlMatchesSnapshot($xml, 'order-42');
```

Its constructor takes the snapshot directory (default `tests/__snapshots__/quittio` below the
working directory); its default snapshot name is `xml`, so pass a name per snapshot.

## Official validators in CI

By default rendering and `assertValidEInvoice()` use the PHP rules and the XSD schemas
(`php`, `xsd`). The official Schematron rules (KoSIT validator, level L5) and the PDF/A
check (veraPDF, level L6) need Java and veraPDF. Run them in a separate test group:

```php
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Validation\Drivers\KositArtifacts;
use Quittio\Laravel\Facades\Quittio;

function useOfficialValidators(): void
{
    $root = storage_path('quittio/validators');            // php artisan quittio:schemas:download

    config()->set('quittio.validation.levels', ['php', 'xsd', 'kosit', 'verapdf']);
    config()->set('quittio.validation.drivers.kosit', [
        'mode' => 'cli',
        'jar' => $root.'/validator.jar',
        'scenarios' => $root.'/xrechnung/scenarios.xml',
        'repository' => $root.'/xrechnung',
        'configurations' => [['scenarios' => KositArtifacts::peppolScenarios(), 'repository' => $root]],
        'timeout' => 120,
    ]);
    config()->set('quittio.validation.drivers.verapdf', ['mode' => 'docker', 'image' => 'verapdf/cli']);
    Quittio::flush();
}

it('produces valid ZUGFeRD and XRechnung', function () {
    useOfficialValidators();
    $invoice = InvoiceData::factory()->state(fn ($b) => $b->buyerReference('04011000-12345-03'))->make();
    $result = Quittio::make($invoice)->formats(['zugferd', 'xrechnung-cii'])->render();

    $this->assertValidEInvoice($result->pdf(), 'en16931');
    $this->assertValidEInvoice($result->as('xrechnung-cii')->xml(), 'xrechnung');
})->group('official');
```

The values for `jar`, `scenarios`, `repository` and `configurations` are the ones
`php artisan quittio:schemas:download` prints. In mode `cli` every validation starts a JVM;
for many documents run the validator as a daemon (`mode` `daemon`, `QUITTIO_KOSIT_URL`; see
[Validation](validation.md#kosit-validator-l5)): the download command also prints the start
command, with `-P 8081` for the default URL `http://127.0.0.1:8081` (the validator's own
default port is 8080). An unreachable validator fails the validation with
`KOSIT-UNAVAILABLE` unless `fail_on_unavailable` is `false`.

A GitHub Actions job for this group:

```yaml
official-validation:
  runs-on: ubuntu-latest
  steps:
    - uses: actions/checkout@v4
    - uses: shivammathur/setup-php@v2
      with:
        php: '8.4'
        extensions: dom, libxml, mbstring, xmlwriter, zlib, intl, gd, bcmath, zip
    - uses: actions/setup-java@v4
      with:
        distribution: temurin
        java-version: '21'
    - uses: actions/cache@v4
      with:
        path: storage/quittio/validators
        key: quittio-validators-${{ hashFiles('composer.lock') }}
    - run: composer install --no-interaction --no-progress
    - run: php artisan quittio:schemas:download     # keeps existing files
    - run: docker pull verapdf/cli
    - run: vendor/bin/pest --group=official
```

Exclude the group from the fast test job with `--exclude-group=official`. To check a single
file by hand: `php artisan quittio:validate invoice.pdf --profile=en16931 --kosit --verapdf`
(see [Artisan commands](commands.md#quittiovalidate)).

## Managed layer factories

`quittio/managed` ships Eloquent factories for its models (namespace
`Quittio\Managed\Database\Factories`). Tests need the published Managed migrations
(`quittio:install --managed`, see [Managed layer](managed.md#installation)) and a refreshed
database.

`Issuer::factory()` (`IssuerFactory`) creates issuer profiles:

| State | Attributes |
|---|---|
| (definition) | Key `issuer-{10 random characters}`; seller Muster GmbH with a random German VAT identifier (`DE` + 9 digits), Hauptstraße 1, 12345 Musterstadt, contact Max Muster, e-mail and electronic address `rechnung@muster.de`; country `DE`, time zone `Europe/Berlin`, bank account `DE02120300000000202051` / `BYLADEM1001`, defaults `locale` `de` and `due_days` 14; not the default issuer. Every issuer is a company of its own and gets its own number space. |
| `default()` | Key `default`, default issuer, seller VAT identifier `DE123456789` |
| `company(string $name, string $vatId)` | Another company: own name and VAT identifier, Marktplatz 2, 54321 Anderstadt, electronic address `rechnung@{slug of the name}.de` |

`Invoice::factory()` (`InvoiceFactory`) creates drafts:

| State | Attributes |
|---|---|
| (definition) | Draft (`status` draft, `kind` invoice) with the data of `InvoiceData::factory()->withLines(2)->make()` (number empty, totals not yet calculated), for a new issuer (`Issuer::factory()`) |
| `data(InvoiceData $data)` | The columns of your invoice data instead |

```php
use Quittio\Core\Data\InvoiceData;
use Quittio\Managed\Models\Invoice;
use Quittio\Managed\Models\Issuer;

$issuer = Issuer::factory()->default()->create();
$invoice = Invoice::factory()->for($issuer)->create()->issue('2026-10-05');
$other = Issuer::factory()->company('Andere AG', 'DE111111111')->create(['key' => 'tenant-b']);
$draft = Invoice::factory()->for($issuer)->data(InvoiceData::factory()->reverseCharge()->make())->create();
```

Issuing takes the number from the issuer's sequence and renders the documents in a queued
job after the transaction (use `Queue::fake()` or `quittio-managed.queue.render_sync`). See
[Managed layer](managed.md) for the lifecycle and
[Testing against MySQL and PostgreSQL](managed.md#testing-against-mysql-and-postgresql).
