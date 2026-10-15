# Quotes, delivery notes and other documents

Documents share parties, lines, calculation, design and templates with invoices but are no invoices: they never produce e-invoice XML, are not validated and are rendered as PDF or HTML only.

| Type | `DocumentType` | Builder | Lines | Prices and totals | Payment block |
|---|---|---|---|---|---|
| Quote | `Quote` (`quote`) | `DocumentBuilder::quote()` | required | yes | none (no bank details, QR code or amount due) |
| Order confirmation | `OrderConfirmation` (`order_confirmation`) | `orderConfirmation()` | required | yes | none |
| Delivery note | `DeliveryNote` (`delivery_note`) | `deliveryNote()` | required | no | none |
| Proforma invoice | `Proforma` (`proforma`) | `proforma()` | required | yes | payment terms, bank details, QR code |
| Payment reminder | `PaymentReminder` (`payment_reminder`) | `paymentReminder(int $level = 0)` | not allowed | open items, fees, interest | bank details, QR code over the total |
| Account statement | `AccountStatement` (`account_statement`) | `accountStatement()` | not allowed | open items, balance | bank details |

In Laravel, `Quittio::quote()`, `Quittio::orderConfirmation()`, `Quittio::deliveryNote()`, `Quittio::proforma()`, `Quittio::paymentReminder(int $level = 0)` and `Quittio::accountStatement()` start the builder with the seller, the first bank account and the small business setting of `config/quittio.php`; `->render()` at the end renders the PDF ([Starting invoices](laravel.md#starting-invoices)). Without Laravel, start with the static methods of `DocumentBuilder` and set the seller yourself.

`DocumentBuilder::make(DocumentType $type)` creates a builder for any type (reminders then have level 0). `DocumentType` has `showsPrices()` (`false` for delivery notes), `hasLineTotals()` (quotes, order confirmations, proformas) and `labelKey()` (`document_{value}`).

```php
use Quittio\Core\Builder\Line;
use Quittio\Core\Builder\Party;
use Quittio\Laravel\Facades\Quittio;

$quote = Quittio::quote()
    ->number('AN-2026-0007')
    ->issueDate('2026-10-06')
    ->buyer(Party::make('Kunde AG')->address('Weg 1', '54321', 'Beispielstadt', 'DE'))
    ->validUntil('2026-10-31')
    ->line(Line::make('Consulting')->quantity(10, 'HUR')->netPrice('120.00'))
    ->optionalLine(Line::make('Training')->quantity(1, 'DAY')->netPrice('800.00'))
    ->alternativeLine('1', Line::make('Senior consulting')->quantity(10, 'HUR')->netPrice('150.00'))
    ->build();

Quittio::document($quote)->locale('de')->render()->download();
```

## Builder methods

Parties, lines, notes, discounts and payment data work exactly like in the [invoice builder](building-invoices.md): lines as `Line::make()`, arrays, models or callables, parties as `Party::make()`, models or callables, and lines without `vat()` get the [default VAT](building-invoices.md#default-vat). Dates accept `Date`, `DateTimeInterface` or a string; amounts are strings.

| Method | Effect |
|---|---|
| `number(string $number)` | Document number |
| `issueDate($date)` | Document date; default today (in the time zone of the document) |
| `currency(string $currency)` | Currency; default `EUR` |
| `seller(SellerParty\|PartyBuilder\|ProvidesParty\|callable $seller)`, `buyer(BuyerParty\|PartyBuilder\|ProvidesParty\|callable $buyer)` | Parties ([Seller and buyer](building-invoices.md#seller-and-buyer)) |
| `line(InvoiceLine\|LineBuilder\|ProvidesInvoiceLine\|array\|callable $line, ?DocumentLineOptions $options = null)` | Line ([Lines](building-invoices.md#lines)) |
| `defaultVat(TaxPreset\|VatCategory $presetOrCategory, …)` | VAT of lines without `vat()` ([Default VAT](building-invoices.md#default-vat)) |
| `lines(iterable $lines)` | Several lines |
| `optionalLine(InvoiceLine\|LineBuilder\|array\|callable $line)` | Optional quote line: shown with its amounts, not in the totals |
| `alternativeLine(string $alternativeTo, InvoiceLine\|LineBuilder\|array\|callable $line)` | Alternative to the line with identifier `$alternativeTo` (BT-126; lines are numbered `1`, `2`, … unless set with `LineBuilder::id()`); shown, not in the totals |
| `serialisedLine(InvoiceLine\|LineBuilder\|array\|callable $line, array $serialNumbers)` | Delivery note line with serial numbers |
| `note(string $content, ?NoteSubjectCode $subject = null)` | Note |
| `discountPercent(string $percent, ?string $reason = null)`, `discountAmount(string $amount, ?string $reason = null)` | Document discount, on the lines that count towards the totals |
| `paymentTerms(PaymentTerms\|string\|callable $terms)` | Payment terms |
| `creditTransfer(string $iban, ?string $bic = null, ?string $accountName = null)` | Bank account |
| `buyerReference(?string $reference)`, `purchaseOrderReference(?string $reference)` | Buyer's references |
| `reference(string $number, $date = null)` | Preceding document, e.g. the quote of an order confirmation or the order of a delivery note |
| `validUntil($date)` | Validity of a quote |
| `deliveryDate($date)` | Delivery date |
| `deliverTo(?string $recipientName, ?Address $address = null)` | Deliver-to party |
| `package(Package $package)` | Package of a delivery note |
| `openItem(string $invoiceNumber, $invoiceDate, string $amount, $dueDate = null, ?string $paid = null, ?string $description = null)` | Invoice of a reminder or statement |
| `fee(string $description, string $amount)` | Reminder fee |
| `interest(string $base, BigDecimal\|string $ratePercent, $from, $until, ?string $description = null, ?int $dayCount = InterestCharge::ACTUAL_ACTUAL)` | Default interest of a reminder |
| `deadline($date)` | Payment deadline of a reminder |
| `statementPeriod($start, $end)` | Period of an account statement |
| `openingBalance(string $amount)` | Opening balance of an account statement |
| `presentation(Presentation\|callable $presentation)` | Design of this document ([Templates](templates.md#presentation)) |
| `locale(string $locale)` | Locale |
| `custom(string $key, mixed $value)` | Custom value (`meta.custom` in the view model) |
| `tap(callable $callback)` | Access to the underlying `InvoiceBuilder` |
| `build(): DocumentData` | Builds the immutable document |

`DocumentBuilder` is `Macroable`. `tap()` reaches every invoice builder method, but the document keeps only number, date, currency, parties, lines, allowances, charges, notes, buyer reference, purchase order reference, delivery, payment terms, payment instructions, presentation and meta; other values set through `tap()` are dropped.

`build()` throws an `InvalidArgumentException` when a quote, order confirmation, proforma or delivery note has no line, or when a reminder or account statement has lines, allowances or charges.

## Quotes and order confirmations

Quotes show `validUntil`, mark optional lines ("[Optional]") and alternative lines ("[Alternative to pos. 1]") and end with an acceptance field ("Order placed:" with a signature line). Optional and alternative lines get their line amounts but are excluded from the totals, the VAT breakdown and `InvoiceBuilder::fromDocument()`; document discounts apply to the remaining lines. Offers are no request to pay: the view model has no payment block, no amount due and no due date text.

Order confirmations reference the quote with `reference('AN-2026-0007', '2026-10-06')` and state the delivery date with `deliveryDate()`.

## Delivery notes

Delivery notes print quantities without prices or totals. Lines given as builder, array or callable get the net price 0 and VAT category O (not subject to VAT) unless they set a price or VAT, so they need only name, quantity and unit. `InvoiceLine` objects and `ProvidesInvoiceLine` models are taken as they are.

```php
use Quittio\Core\Documents\Package;
use Brick\Math\BigDecimal;

$note = Quittio::deliveryNote()
    ->number('LS-2026-0012')
    ->buyer($buyer)
    ->reference('AB-2026-0003')
    ->deliveryDate('2026-10-02')
    ->serialisedLine(Line::make('Router')->quantity(2, 'C62'), ['SN-1001', 'SN-1002'])
    ->line(['name' => 'Cable', 'quantity' => [5, 'MTR']])
    ->package(new Package('P1', type: 'Box', weightKg: BigDecimal::of('2.5'), dimensions: '30 × 20 × 10 cm', trackingNumber: '00340434161094042557'))
    ->build();
```

`Package` has `id`, `type`, `weightKg` (`BigDecimal`), `dimensions` and `trackingNumber`. The template lists the packages and the serial numbers below each line.

## Proforma invoices

A proforma is not an invoice for tax purposes. Build it with `DocumentBuilder::proforma()`: it is rendered like an invoice (prices, totals, payment terms, bank details and QR code) with the title "Proforma invoice" and the notice "This proforma invoice is not an invoice for tax purposes." among the legal notes, and never carries e-invoice XML. An invoice with type code 325 (`InvoiceTypeCode::ProformaInvoice`) in the invoice pipeline fails validation with `QUITTIO-PROFORMA`, because e-invoice XML with VAT amounts would be booked as an invoice.

## Payment reminders

```php
$reminder = Quittio::paymentReminder(1)                  // 0 = payment reminder (Zahlungserinnerung), 1 = 1. Mahnung, 2 = 2. Mahnung …
    ->number('M-2026-0003')
    ->buyer($buyer)
    ->openItem('RE-2026-0042', '2026-08-01', '1190.00', dueDate: '2026-08-15', paid: '190.00')
    ->fee('Reminder costs', '2.50')
    ->interest('1000.00', '10.27', from: '2026-08-16', until: '2026-10-04')   // until is exclusive: last day 03.10.
    ->deadline('2026-10-18')
    ->build();

$reminder->dunning->total('EUR');            // open items + fees + interest, no VAT
$reminder->dunning->remittanceText();        // "RE-2026-0042" – open invoice numbers for the transfer
```

The title is "Payment reminder" for level 0 and "Reminder no. :level" ("1. Mahnung") from level 1. The template lists the open items (invoice number, date, due date, amount, paid, open), the fees, the interest lines and "Total to pay", then the deadline text ("Please pay the total amount by …"). The payment block uses the open invoice numbers as remittance information (the document number if none is open) and the GiroCode carries the total of the reminder.

- Reminders have no lines, allowances or charges; the builder rejects them. The amount to pay is `Dunning::total()`: open items plus fees plus interest.
- Fees and default interest carry no VAT. They are damages, not consideration for a supply (UStAE 1.3 Abs. 6); the same applies to the flat rate of section 288 (5) BGB.
- Only actual costs may be claimed as reminder fees; Quittio has no default fee. The documents layer does not track which claims already carried a flat rate.
- `interest()` takes the first day with interest (`from`, e.g. the day after the due date) and the day after the last day with interest (`until`, exclusive). `InterestCharge::lastDay()` is the last day with interest; the default description uses it ("Default interest 10.27% p.a. on 1,000.00 € from … to …").
- Interest is simple interest counted actual/actual: the days of each calendar year are divided by 365 or 366, so that a full year yields exactly the annual rate. Pass `dayCount: 365` or `dayCount: 360` for a fixed basis; other values and a period that ends before it starts throw an `InvalidArgumentException`. The amount is rounded half up once.

`Dunning` (`$document->dunning`): `level`, `openItems`, `deadline`, `fees` (`DunningFee`: `description`, `amount`), `interest`, `openTotal($currency)`, `feeTotal($currency)`, `interestTotal($currency)`, `total($currency)`, `openInvoiceNumbers()` (unsettled items, without duplicates) and `remittanceText(int $maxLength = 140)` (as many complete numbers as fit, comma separated). A negative level throws an `InvalidArgumentException`.

`InterestCharge`: `base`, `ratePercent`, `from`, `until`, `description`, `amount` (a stated amount replaces the calculation), `dayCount`, `days()`, `lastDay()`, `total()`.

`OpenItem`: `invoiceNumber`, `invoiceDate`, `amount`, `dueDate`, `paid`, `description`, `open()` (amount minus paid; negative for overpaid items and credits) and `isSettled()` (nothing open).

### German default interest

The statutory rates of section 288 BGB (base rate plus 9 or 5 percentage points, periods split at every change of the base rate) come from the German country pack; add its charges to the reminder:

```php
use Brick\Money\Money;
use Quittio\Core\Support\Date;
use Quittio\Country\DE\Dunning\DefaultInterest;

foreach ((new DefaultInterest)->calculate(Money::of('1000.00', 'EUR'), Date::parse('2026-06-16'), Date::parse('2026-10-05'), consumer: false) as $charge) {
    $builder->interest((string) $charge->base->getAmount(), $charge->ratePercent, $charge->from, $charge->until, $charge->description);
}
```

Base rate table, flat rate and the conditions of default are described in [Country packs](country-packs.md). The country-neutral contract is `CountryPack::dunningLaw()` (`DunningLaw`: `b2bInterestPoints`, `b2cInterestPoints`, `b2bFlatRate`, `dayCount`, `consumerDefaultAfterDays`, `referenceRateName`).

## Account statements

```php
$statement = Quittio::accountStatement()
    ->number('KA-2026-09')
    ->buyer($buyer)
    ->statementPeriod('2026-09-01', '2026-09-30')
    ->openingBalance('100.00')
    ->openItem('RE-2026-0050', '2026-09-10', '595.00', dueDate: '2026-09-24', paid: '595.00')
    ->openItem('RE-2026-0061', '2026-09-28', '238.00', dueDate: '2026-10-12')
    ->build();

$statement->statement->balance('EUR');       // 338.00
```

The balance is the opening balance plus the open amounts (amount minus paid) of the listed items. The opening balance is the balance at the start of the period and already contains every invoice dated before it, so:

- with `openingBalance()`, only items dated within `statementPeriod()` are accepted;
- without an opening balance the statement is an open item list and may list older items;
- items dated after the end of the period are always rejected;
- without `statementPeriod()` the period ends on the document date.

Violations throw an `InvalidArgumentException`, as does a period that ends before it starts. `AccountStatement` (`$document->statement`) has `period`, `items`, `openingBalance`, `openItems()` (unsettled items) and `balance($currency)`. The template lists all items, the period, the opening balance and the balance; the payment block shows the bank details without QR code.

## DocumentData

`build()` returns `Quittio\Core\Documents\DocumentData` (immutable, `with()`): `type`, `number`, `issueDate`, `currency`, `seller`, `buyer`, `lines`, `lineOptions` (`DocumentLineOptions` keyed by line identifier), `allowances`, `charges`, `notes`, `validUntil`, `reference`, `referenceDate`, `buyerReference`, `purchaseOrderReference`, `delivery`, `packages`, `dunning`, `statement`, `paymentTerms`, `paymentInstructions`, `totals`, `vatBreakdown`, `presentation` and `meta`.

| Method | Returns |
|---|---|
| `options(string $lineId)` | `DocumentLineOptions` of a line (`optional`, `alternativeTo`, `serialNumbers`, `isBillable()`) |
| `billableLines()` | Lines that count towards the totals (without optional and alternative lines) |
| `isCalculated()` | Totals are set (or there is nothing to calculate) |
| `toInvoiceDraft(bool $billableOnly = true)` | The document as `InvoiceData` for calculation and rendering; never use it as an invoice |

Documents are calculated by `DocumentCalculator` with the invoice calculator when they are rendered: every line gets its amounts, the totals and the VAT breakdown come from the billable lines.

## Rendering

```php
$result = Quittio::document($quote)          // DocumentData; in Laravel also a DocumentBuilder
    ->locale('en')
    ->country('DE')
    ->template('invoices.my-design.layout')
    ->presentation(new Presentation(title: 'Offer'))
    ->render();

$html = Quittio::document($quote)->htmlOnly()->render()->html();
```

| `PendingDocument` method | Effect |
|---|---|
| `template(string $template)` | Template ([selection rules](templates.md#selecting-a-template)) |
| `locale(string $locale)` | Locale; otherwise the document's locale, the configured locale, the country default |
| `country(string $country)` | Country pack |
| `presentation(Presentation $presentation)` | Design defaults of this call; the document's own presentation wins |
| `htmlOnly()` | HTML instead of PDF |
| `render()` | `RenderedDocument` |

Documents use the invoice templates and the same view model; the node `documentInfo` holds the document specific values ([view model](templates.md#documentinfo)), `document.kind` the type. The PDF is PDF/A-3b when `pdf.pdfa` is set and no watermark is used, never with embedded XML. Documents run no validation, no mandatory field guard and dispatch no events.

`RenderedDocument` (core): `data()`, `html()`, `hasPdf()`, `pdf()` (`LogicException` after `htmlOnly()`), `contents()` (PDF, otherwise HTML), `mimeType()`, `filename(?string $extension = null)` and `save(string $path)` (a file, or a directory for the generated file name). The Laravel class adds `core()`, `download(?string $filename = null)`, `stream(?string $filename = null)`, `save(?string $disk = null, ?string $path = null)` (default disk `quittio.storage.disk`; a path ending in `/` gets the file name appended), is `Responsable` (download) and `Attachable` for mails ([Laravel integration](laravel.md)).

The file name follows `pdf.filename` (`{title}_{number}.{ext}`; `{format}` is the document type, `{date}` the document date): `Angebot_AN-2026-0007.pdf`, `2._Mahnung_M-2026-0003.pdf`.

## From document to invoice

```php
use Quittio\Core\Builder\InvoiceBuilder;

$invoice = InvoiceBuilder::fromDocument($quote)
    ->number('RE-2026-0100')
    ->issueDate('2026-11-16')
    ->deliveryDate('2026-11-15')
    ->build();
```

`fromDocument()` starts an invoice from a quote, order confirmation, delivery note or proforma: it takes over the parties, the billable lines (amounts are recalculated), document allowances and charges, payment terms and instructions, delivery, buyer reference, purchase order reference, presentation and locale. Number and notes are not taken over; set the number, and the issue date (otherwise today). The number of an order confirmation becomes the sales order reference (BT-14), the number of a delivery note the despatch advice reference (BT-16); quotes and proformas are not referenced. Lines of a delivery note have no prices (net price 0, category O) and need prices and VAT before invoicing. The result is a normal `InvoiceBuilder`; validation and e-invoice formats apply when it is rendered.
