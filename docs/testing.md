# Testing

## Fake

```php
use Quittio\Laravel\Facades\Quittio;
use Quittio\Laravel\Rendering\RenderedInvoice;

Quittio::fake();

// … code that renders invoices …

Quittio::assertRenderedCount(1);
Quittio::assertRendered(fn (RenderedInvoice $r) => $r->data()->totals->amountDue->isEqualTo('1234.56'));
Quittio::assertNothingRendered();
```

The fake calculates the invoice but skips validation, XML and PDF.

## Factory

```php
use Quittio\Core\Data\InvoiceData;

InvoiceData::factory()->reverseCharge()->withLines(5)->make();
InvoiceData::factory()->intraCommunity()->calculated();
InvoiceData::factory()->b2c()->currency('USD')->state(fn ($builder) => $builder->number('X-1'))->make();
```

## Assertions

Use the trait `Quittio\Laravel\Testing\InteractsWithQuittio` (Pest: `uses(InteractsWithQuittio::class)`):

```php
$this->assertValidEInvoice($result->pdf(), 'en16931');
$this->assertXmlMatchesSnapshot($result->xml());
```

Outside test classes, `(new EInvoiceAssertions)->assertValidEInvoice(...)` provides the same checks.

## Sample scenarios

`Quittio\Core\Testing\Scenarios` contains the scenarios of specification 11.12 (`simple`, `many-lines`, `long-texts`, `mixed-vat`, `reverse-charge`, `intra-eu`, `export`, `cancellation`, `sub-lines`, `foreign-currency`, `b2c-gross`, `allowances`, `attachments`, `peppol`).
