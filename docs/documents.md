# Quotes, delivery notes and other documents

Documents share parties, lines, calculation and templates with invoices but never produce e-invoice XML.

| Type | Builder | Specials |
|---|---|---|
| Quote | `DocumentBuilder::quote()` | `validUntil()`, `optionalLine()`, `alternativeLine()`, acceptance field |
| Order confirmation | `orderConfirmation()` | `reference()` to the quote, delivery date |
| Delivery note | `deliveryNote()` | no prices, `serialisedLine()`, `package()` |
| Proforma invoice | `proforma()` | marked as not being an invoice for tax purposes |
| Payment reminder | `paymentReminder($level)` | `openItem()`, `fee()`, `interest()`, `deadline()` |
| Account statement | `accountStatement()` | `statementPeriod()`, `openingBalance()`, `openItem()` |

```php
use Quittio\Core\Documents\DocumentBuilder;

$quote = DocumentBuilder::quote()
    ->number('AN-2026-0007')
    ->seller(Seller::fromConfig())
    ->buyer(fn ($b) => $b->name('Kunde AG')->address('Weg 1', '54321', 'Beispielstadt', 'DE'))
    ->validUntil('2026-10-31')
    ->line(fn ($l) => $l->name('Consulting')->quantity(10, 'HUR')->netPrice('120.00')->vat(VatPreset::Standard19))
    ->optionalLine(fn ($l) => $l->name('Training')->quantity(1, 'DAY')->netPrice('800.00')->vat(VatPreset::Standard19))
    ->build();

Quittio::document($quote)->locale('de')->render()->download();

$invoice = InvoiceBuilder::fromDocument($quote)->number('RE-2026-0100')->deliveryDate('2026-11-15')->build();
```

Optional and alternative lines are shown with their amounts but excluded from the totals and from `fromDocument()`. German default interest (`Quittio\Country\DE\Dunning\DefaultInterest`) splits periods at every change of the base rate; add new base rates with `BaseRateTable::withRate()`.
