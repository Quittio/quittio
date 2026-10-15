# Quotes, delivery notes and other documents

Besides invoices, a business sends other documents: quotes, order confirmations, delivery notes, proforma invoices, payment reminders and account statements. This page shows how to create them with Quittio. It assumes you know the invoice builder from [Getting started](getting-started.md).

Documents share parties, lines, calculation, design and templates with invoices, but they are no invoices. They never produce e-invoice XML ([What an e-invoice is](concepts.md#what-an-e-invoice-is)), are not validated and are rendered as PDF or HTML only.

| Type | `DocumentType` | Builder | Lines | Prices and totals | Payment block |
|---|---|---|---|---|---|
| Quote | `Quote` (`quote`) | `DocumentBuilder::quote()` | required | yes | none (no bank details, QR code or amount due) |
| Order confirmation | `OrderConfirmation` (`order_confirmation`) | `orderConfirmation()` | required | yes | none |
| Delivery note | `DeliveryNote` (`delivery_note`) | `deliveryNote()` | required | no | none |
| Proforma invoice | `Proforma` (`proforma`) | `proforma()` | required | yes | payment terms, bank details, QR code |
| Payment reminder | `PaymentReminder` (`payment_reminder`) | `paymentReminder(int $level = 0)` | not allowed | open items, fees, interest | bank details, QR code over the total |
| Account statement | `AccountStatement` (`account_statement`) | `accountStatement()` | not allowed | open items, balance | bank details |

The printed title depends on the locale. In English it is the name in the first column; reminders from level 1 on are titled "Reminder no. 1", "Reminder no. 2" … ([Payment reminders](#payment-reminders)). German documents are titled "Angebot" (quote), "Auftragsbestätigung" (order confirmation), "Lieferschein" (delivery note), "Proforma-Rechnung" (proforma invoice), "Zahlungserinnerung" (payment reminder), "1. Mahnung" (first reminder) and "Kontoauszug" (account statement).

## Creating a document

In Laravel, start with the facade: `Quittio::quote()`, `Quittio::orderConfirmation()`, `Quittio::deliveryNote()`, `Quittio::proforma()`, `Quittio::paymentReminder(int $level = 0)` or `Quittio::accountStatement()`. Like `Quittio::invoice()`, these methods start the builder with the seller, the first bank account and the small business setting of `config/quittio.php` ([Starting invoices](laravel.md#starting-invoices)). `->render()` at the end of the chain renders the PDF.

```php
use Quittio\Core\Builder\Party;
use Quittio\Core\Enums\UnitCode;
use Quittio\Laravel\Facades\Quittio;

$quote = Quittio::quote()
    ->number('AN-2026-0007')
    ->issueDate('2026-10-06')
    ->buyer(Party::make('Kunde AG')->address('Weg 1', '54321', 'Beispielstadt', 'DE'))
    ->validUntil('2026-10-31')
    ->line('Consulting', netPrice: '120.00', quantity: 10, unit: UnitCode::Hour)
    ->optionalLine('Training', netPrice: '800.00', unit: UnitCode::Day)            // shown, not in the totals
    ->alternativeLine('1', 'Senior consulting', netPrice: '150.00', quantity: 10, unit: UnitCode::Hour)   // alternative to line 1
    ->build();

Quittio::document($quote)->locale('de')->render()->download();
```

This downloads a German quote as `Angebot_AN-2026-0007.pdf` ([File names](#file-names)). Use `->build()` to get the document data, e.g. to store it, and `Quittio::document()` to render it with more options ([Rendering](#rendering)).

Without Laravel, start with the static methods of `DocumentBuilder` (`DocumentBuilder::quote()` …) and set the seller yourself with `->seller()`.

`DocumentBuilder::make(DocumentType $type)` creates a builder for any type; reminders created this way have level 0. `DocumentType` has three methods:

- `showsPrices()`: `false` for delivery notes;
- `hasLineTotals()`: `true` for quotes, order confirmations and proformas;
- `labelKey()`: the label key of the title, `document_{value}`.

## Quotes and order confirmations

A quote offers goods or services at a price; an order confirmation confirms an order.

Quotes print the validity date (`validUntil()`). They mark optional lines with "[Optional]" and alternative lines with "[Alternative to pos. 1]", and they end with an acceptance field ("Order placed:" with a signature line), where the customer can sign the quote.

Optional and alternative lines get their line amounts, but they are excluded from the totals, the VAT breakdown and `InvoiceBuilder::fromDocument()`. Document discounts apply to the remaining lines.

Quotes and order confirmations are no request to pay: the [view model](templates.md#view-model) has no payment block, no amount due and no due date text.

Order confirmations reference the quote with `reference('AN-2026-0007', '2026-10-06')` and state the delivery date with `deliveryDate()`.

## Delivery notes

A delivery note accompanies a shipment. It prints quantities without prices or totals.

Lines get the net price 0 and the VAT category O (not subject to VAT) unless they set a price or VAT themselves, so they need only name, quantity and unit. This applies to lines given by name, as builder, array, model (`ProvidesInvoiceLine`) or callable. Finished `InvoiceLine` objects are taken as they are.

```php
use Brick\Math\BigDecimal;
use Quittio\Core\Documents\Package;
use Quittio\Core\Enums\UnitCode;
use Quittio\Laravel\Facades\Quittio;

$note = Quittio::deliveryNote()
    ->number('LS-2026-0012')
    ->buyer($buyer)
    ->reference('AB-2026-0003')                  // the order confirmation
    ->deliveryDate('2026-10-02')
    ->serialisedLine('Router', ['SN-1001', 'SN-1002'], quantity: 2, unit: UnitCode::Unit)
    ->line('Cable', quantity: 5, unit: UnitCode::Metre)
    ->package(new Package('P1', type: 'Box', weightKg: BigDecimal::of('2.5'), dimensions: '30 × 20 × 10 cm', trackingNumber: '00340434161094042557'))
    ->build();
```

`Package` has `id`, `type`, `weightKg` (`BigDecimal`), `dimensions` and `trackingNumber`. The template lists the packages, and the serial numbers below each line.

## Proforma invoices

A proforma invoice announces the amount of a future invoice, e.g. for advance payment or customs. It is not an invoice for tax purposes.

Build it with `Quittio::proforma()` (Laravel) or `DocumentBuilder::proforma()`. It is rendered like an invoice, with prices, totals, payment terms, bank details and QR code. Its title is "Proforma invoice", and the legal notes contain "This proforma invoice is not an invoice for tax purposes." It never carries e-invoice XML.

Do not render a proforma as an invoice. An invoice with the type code (BT-3) 325 (`InvoiceTypeCode::ProformaInvoice`) fails validation with the rule `QUITTIO-PROFORMA` ([Rules and validation](concepts.md#rules-and-validation)), because e-invoice XML with VAT amounts would be booked as an invoice.

## Payment reminders

A payment reminder asks the buyer to pay invoices that are overdue. The level counts the reminders:

- Level 0 (default) is a friendly payment reminder, titled "Payment reminder" (German "Zahlungserinnerung").
- Level 1 and higher are formal reminders, titled "Reminder no. :level": "Reminder no. 1", "Reminder no. 2" … The German titles are "1. Mahnung" (first reminder), "2. Mahnung" (second reminder) …

A negative level throws an `InvalidArgumentException`.

```php
use Quittio\Laravel\Facades\Quittio;

$reminder = Quittio::paymentReminder(1)                  // level 1: first reminder
    ->number('M-2026-0003')
    ->buyer($buyer)
    ->openItem('RE-2026-0042', '2026-08-01', '1190.00', dueDate: '2026-08-15', paid: '190.00')
    ->fee('Reminder costs', '2.50')
    ->interest('1000.00', '10.27', from: '2026-08-16', until: '2026-10-04')   // until is exclusive: the last day with interest is 3 October
    ->deadline('2026-10-18')
    ->build();

$reminder->dunning->total('EUR');            // open items + fees + interest, no VAT
$reminder->dunning->remittanceText();        // "RE-2026-0042" – open invoice numbers for the transfer
```

The template lists the open items (invoice number, date, due date, amount, paid, open), the fees, the interest lines and "Total to pay", then the deadline text ("Please pay the total amount by …"). The payment block uses the open invoice numbers as remittance information (the document number if none is open). The GiroCode, the QR code for the bank transfer ([Payment QR codes](templates.md#payment-qr-codes)), carries the total of the reminder.

Rules:

- Reminders have no lines, allowances or charges; the builder rejects them. The amount to pay is `Dunning::total()`: open items plus fees plus interest.
- Fees and default interest carry no VAT. They are damages, not payment for a supply (section 1.3 (6) of the German VAT application decree, UStAE). The same applies to the flat rate for late payment of section 288 (5) of the German Civil Code (BGB).
- Only actual costs may be claimed as reminder fees; Quittio has no default fee. The documents layer does not track which claims already carried a flat rate.
- `interest()` takes the first day with interest (`from`, e.g. the day after the due date) and the day after the last day with interest (`until`, exclusive). `InterestCharge::lastDay()` is the last day with interest. The default description uses it: "Default interest 10.27% p.a. on 1,000.00 € from … to …".
- Interest is simple interest, counted actual/actual: the days of each calendar year are divided by 365 or 366, so that a full year yields exactly the annual rate. Pass `dayCount: 365` or `dayCount: 360` for a fixed basis. Other values, and a period that ends before it starts, throw an `InvalidArgumentException`. The amount is rounded half up once.

Classes of a reminder:

- `Dunning` (`$document->dunning`): `level`, `openItems`, `deadline`, `fees` (`DunningFee`: `description`, `amount`), `interest`, `openTotal($currency)`, `feeTotal($currency)`, `interestTotal($currency)`, `total($currency)`, `openInvoiceNumbers()` (unsettled items, without duplicates) and `remittanceText(int $maxLength = 140)` (as many complete numbers as fit, comma separated).
- `InterestCharge`: `base`, `ratePercent`, `from`, `until`, `description`, `amount` (a stated amount replaces the calculation), `dayCount`, `days()`, `lastDay()`, `total()`.
- `OpenItem`: `invoiceNumber`, `invoiceDate`, `amount`, `dueDate`, `paid`, `description`, `open()` (amount minus paid; negative for overpaid items and credits) and `isSettled()` (nothing open).

### German default interest

In Germany, a debtor in default owes interest at the statutory rate of section 288 BGB: the base rate of the Bundesbank plus 9 percentage points (businesses) or 5 points (consumers). The German country pack calculates it, with periods split at every change of the base rate. Add its charges to the reminder:

```php
use Brick\Money\Money;
use Quittio\Core\Support\Amount;
use Quittio\Core\Support\Date;
use Quittio\Country\DE\Dunning\DefaultInterest;
use Quittio\Laravel\Facades\Quittio;

$reminder = Quittio::paymentReminder(1)
    ->number('M-2026-0003')
    ->buyer($buyer)
    ->openItem('RE-2026-0042', '2026-06-01', '1000.00', dueDate: '2026-06-15');

// one charge per period with the same base rate; the end date is exclusive
foreach ((new DefaultInterest)->calculate(Money::of('1000.00', 'EUR'), Date::parse('2026-06-16'), Date::parse('2026-10-05'), consumer: false) as $charge) {
    $reminder->interest(Amount::format($charge->base), $charge->ratePercent, $charge->from, $charge->until, $charge->description, $charge->dayCount);
}
```

Pass `$charge->dayCount` along, so that the reminder uses the day count of the country pack (365 in Germany) instead of actual/actual. The descriptions are German by default; any other locale, e.g. `calculate(…, locale: 'en')`, returns English ones.

The base rate table, the flat rate and the conditions of default are described in [Country packs](country-packs.md#dunning-law). The country-neutral contract is `CountryPack::dunningLaw()` (`DunningLaw`: `b2bInterestPoints`, `b2cInterestPoints`, `b2bFlatRate`, `dayCount`, `consumerDefaultAfterDays`, `referenceRateName`).

## Account statements

An account statement lists the invoices of a customer and the resulting balance.

```php
use Quittio\Laravel\Facades\Quittio;

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

The balance is the opening balance plus the open amounts (amount minus paid) of the listed items. The opening balance is the balance at the start of the period and already contains every invoice dated before it. Therefore:

- with `openingBalance()`, only items dated within `statementPeriod()` are accepted;
- without an opening balance, the statement is an open item list and may list older items;
- items dated after the end of the period are always rejected;
- without `statementPeriod()`, the period ends on the document date.

Violations throw an `InvalidArgumentException`, as does a period that ends before it starts.

`AccountStatement` (`$document->statement`) has `period`, `items`, `openingBalance`, `openItems()` (unsettled items) and `balance($currency)`. The template lists all items, the period, the opening balance and the balance; the payment block shows the bank details without QR code.

## From document to invoice

When the customer accepts a quote or the goods are delivered, turn the document into an invoice:

```php
use Quittio\Core\Builder\InvoiceBuilder;

$invoice = InvoiceBuilder::fromDocument($quote)
    ->number('RE-2026-0100')
    ->issueDate('2026-11-16')
    ->deliveryDate('2026-11-15')
    ->build();
```

`fromDocument()` starts an invoice from a quote, order confirmation, delivery note or proforma.

- It takes over the parties, the billable lines (amounts are recalculated), document allowances and charges, payment terms and instructions, delivery, buyer reference, purchase order reference, presentation and locale.
- Number and notes are not taken over. Set the number, and the issue date (otherwise today).
- The number of an order confirmation becomes the sales order reference (BT-14), the number of a delivery note the despatch advice reference (BT-16). Quotes and proformas are not referenced.
- Lines of a delivery note have no prices (net price 0, category O) and need prices and VAT before invoicing.

The result is a normal `InvoiceBuilder`; validation and e-invoice formats apply when it is rendered.

## Rendering

`Quittio::document()` renders a document with more options than `->render()` at the end of the builder chain:

```php
use Quittio\Core\Data\Presentation\Presentation;
use Quittio\Laravel\Facades\Quittio;

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
| `option(string $key, mixed $value)` | Output option of this call, e.g. `option('fold_marks', false)` ([output configuration](formats.md#output-configuration)) |
| `render()` | `RenderedDocument` |

Documents use the invoice templates and the same view model. The node `documentInfo` holds the document specific values ([view model](templates.md#documentinfo)), `document.kind` the type.

The PDF is PDF/A-3b (the archivable PDF variant) when `pdf.pdfa` is set and no watermark is used, and it never contains embedded XML. Documents run no validation and no [mandatory field guard](templates.md#mandatory-field-guard). In Laravel they dispatch `DocumentRendering` and `DocumentRendered` ([Events](laravel.md#events)).

`RenderedDocument` (core) has:

- `data()`, `html()`, `hasPdf()`, `pdf()` (`LogicException` after `htmlOnly()`), `contents()` (PDF, otherwise HTML) and `mimeType()`;
- `filename(?string $extension = null)`;
- `save(string $path)`: a file, or a directory (an existing one or a path ending in `/`) for the generated file name; missing directories are created.

The Laravel class adds `core()`, `download(?string $filename = null)`, `stream(?string $filename = null)` and `save(?string $disk = null, ?string $path = null)` (default disk `quittio.storage.disk`; a path ending in `/` gets the file name appended). It is `Responsable`, so a controller can return it as a download, and `Attachable` for mails ([Laravel integration](laravel.md)).

### File names

The file name follows `pdf.filename` (default `{title}_{number}.{ext}`; `{format}` is the document type, `{date}` the document date). Characters other than letters, digits, `.`, `_` and `-` become `_`. Examples:

| Document | English file name | German file name |
|---|---|---|
| Quote `AN-2026-0007` | `Quote_AN-2026-0007.pdf` | `Angebot_AN-2026-0007.pdf` ("Angebot": quote) |
| Reminder level 1 `M-2026-0003` | `Reminder_no._1_M-2026-0003.pdf` | `1._Mahnung_M-2026-0003.pdf` ("1. Mahnung": first reminder) |

## Builder methods

Parties, lines, notes, discounts and payment data work exactly like in the [invoice builder](building-invoices.md):

- lines by name with named values (`->line('Consulting', netPrice: '120.00', quantity: 10, unit: UnitCode::Hour)`), as `Line::make()`, arrays, models or callables;
- parties as `Party::make()`, models or callables;
- lines without `vat()` get the [default VAT](building-invoices.md#default-vat).

Dates accept `Date`, `DateTimeInterface` or a string; amounts are strings.

| Method | Effect |
|---|---|
| `number(string $number)` | Document number |
| `issueDate($date)` | Document date; default today (in PHP's default time zone, or the one set with `timezone()` through `tap()`) |
| `currency(string $currency)` | Currency; default `EUR` |
| `seller(SellerParty\|PartyBuilder\|ProvidesParty\|callable $seller)`, `buyer(BuyerParty\|PartyBuilder\|ProvidesParty\|callable $buyer)` | Parties ([Seller and buyer](building-invoices.md#seller-and-buyer)) |
| `line(string\|InvoiceLine\|LineBuilder\|ProvidesInvoiceLine\|array\|callable $line, $netPrice = null, $quantity = null, $unit = null, $vat = null, ?string $description = null, $priceInclVat = null, ?DocumentLineOptions $options = null)` | Line ([Lines](building-invoices.md#lines)); the values are only allowed with a name |
| `defaultVat(TaxPreset\|VatCategory $presetOrCategory, …)` | VAT of lines without `vat()` ([Default VAT](building-invoices.md#default-vat)) |
| `lines(iterable $lines)` | Several lines |
| `optionalLine(string\|InvoiceLine\|LineBuilder\|array\|callable $line, …)` | Optional quote line: shown with its amounts, not in the totals. Takes the same named values as `line()` |
| `alternativeLine(string $alternativeTo, string\|InvoiceLine\|LineBuilder\|array\|callable $line, …)` | Alternative to the line with the identifier `$alternativeTo` (BT-126; lines are numbered `1`, `2`, … unless set with `LineBuilder::id()`); shown, not in the totals |
| `serialisedLine(string\|InvoiceLine\|LineBuilder\|array\|callable $line, array $serialNumbers, …)` | Delivery note line with serial numbers |
| `note(string $content, ?NoteSubjectCode $subject = null)` | Note |
| `discountPercent(string $percent, ?string $reason = null)`, `discountAmount(string $amount, ?string $reason = null)` | Document discount, on the lines that count towards the totals |
| `dueInDays(int $days)`, `cashDiscount(int $days, string $percent, ?string $baseAmount = null)` | Payment target and cash discount ([Payment terms](building-invoices.md#payment-terms)) |
| `paymentTerms(PaymentTerms\|string\|callable $terms)` | Payment terms as text or object |
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
| `presentation(Presentation\|array\|callable $presentation)` | Design of this document ([Templates](templates.md#presentation)) |
| `locale(string $locale)` | Locale |
| `custom(string $key, mixed $value)` | Custom value (`meta.custom` in the view model) |
| `tap(callable $callback)` | Access to the underlying `InvoiceBuilder` |
| `build(): DocumentData` | Builds the immutable document |

`DocumentBuilder` is `Macroable`: add own methods with `DocumentBuilder::macro('name', fn () => …)`.

`tap()` reaches every invoice builder method, but the document keeps only number, date, currency, parties, lines, allowances, charges, notes, buyer reference, purchase order reference, delivery, payment terms, payment instructions, presentation and meta. Other values set through `tap()` are dropped.

`build()` throws an `InvalidArgumentException` when a quote, order confirmation, proforma or delivery note has no line, or when a reminder or account statement has lines, allowances or charges.

## DocumentData

`build()` returns `Quittio\Core\Documents\DocumentData` (immutable; change values with `with()`). Its properties: `type`, `number`, `issueDate`, `currency`, `seller`, `buyer`, `lines`, `lineOptions` (`DocumentLineOptions` keyed by line identifier), `allowances`, `charges`, `notes`, `validUntil`, `reference`, `referenceDate`, `buyerReference`, `purchaseOrderReference`, `delivery`, `packages`, `dunning`, `statement`, `paymentTerms`, `paymentInstructions`, `totals`, `vatBreakdown`, `presentation` and `meta`.

| Method | Returns |
|---|---|
| `options(string $lineId)` | `DocumentLineOptions` of a line (`optional`, `alternativeTo`, `serialNumbers`, `isBillable()`) |
| `billableLines()` | Lines that count towards the totals (without optional and alternative lines) |
| `isCalculated()` | Totals are set (or there is nothing to calculate) |
| `toInvoiceDraft(bool $billableOnly = true)` | The document as `InvoiceData` for calculation and rendering; never use it as an invoice |

Documents are calculated by `DocumentCalculator` with the invoice calculator when they are rendered. Every line gets its amounts; the totals and the VAT breakdown come from the billable lines.
