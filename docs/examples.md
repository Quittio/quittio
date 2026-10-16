# Examples

This page shows complete examples for common situations, from a first service invoice to reading an e-invoice you received. Find the situation closest to yours, copy the code and adapt it. If you have not rendered an invoice yet, start with [Getting started](getting-started.md). Terms such as ZUGFeRD, XRechnung or profile are explained in [E-invoicing basics](concepts.md).

Every example runs in Quittio's test suite against the current version. The XML the examples produce passed the official validator of KoSIT, the German agency that publishes XRechnung. It checks the rules of EN 16931, XRechnung and Peppol BIS Billing 3.0 ([Rules and validation](concepts.md#rules-and-validation)). Each example starts with the situation, then shows the code, then explains the important lines with links to the reference.

| Example | Situation and what it shows |
|---|---|
| [Service invoice](#service-invoice) | Hours and hosting for a German business: service period, cash discount, ZUGFeRD |
| [Different VAT rates and a discount](#different-vat-rates-and-a-discount) | Goods at 7 % and 19 %: lines as arrays, a discount split per VAT rate |
| [Online shop](#online-shop) | A consumer who paid at checkout: prices including VAT, a voucher |
| [Small business](#small-business) | A seller who charges no VAT (section 19 UStG, the German VAT Act) and has no VAT identification number |
| [Public sector](#public-sector) | A German public authority: XRechnung with Leitweg-ID (the buyer's routing ID), PDF copy |
| [Advance payment and final invoice](#advance-payment-and-final-invoice) | A project paid in two parts: advance payment invoice, final invoice with deduction |
| [Cancellation](#cancellation) | An issued invoice that was wrong: full and partial cancellation with `$result->cancel()` |
| [Self-billing](#self-billing) | You write the invoice for your supplier: "Gutschrift" (self-billed invoice) |
| [EU business: services](#eu-business-services) | A service for a business in Belgium: reverse charge (the customer owes the VAT), Peppol BIS |
| [EU business: goods](#eu-business-goods) | Goods for a business in Austria: intra-community supply, deliver-to address |
| [Export](#export) | A customer outside the EU, invoiced in US dollars |
| [Foreign currency with German VAT](#foreign-currency-with-german-vat) | A German customer invoiced in US dollars: VAT stated in euro |
| [Invoices for orders](#invoices-for-orders) | Orders in your Laravel application: Eloquent models, storing and mailing |
| [Drafts and previews](#drafts-and-previews) | Invoices prepared over time: drafts and previews without the Managed layer |
| [Testing](#testing) | Feature tests for the order flow: fake and validation in Pest |
| [From quote to invoice](#from-quote-to-invoice) | A quote with an optional line, then the invoice from the quote |
| [Payment reminder](#payment-reminder) | An overdue invoice: reminder with statutory default interest |
| [Incoming e-invoices](#incoming-e-invoices) | An e-invoice from a supplier: validate and read an upload |
| [Logo, colours and texts](#logo-colours-and-texts) | The design of a single invoice |
| [Without Laravel](#without-laravel) | A plain PHP script |

All examples use the Laravel facade and this seller in `config/quittio.php` ([Seller](configuration.md#seller)):

```php
// config/quittio.php
'seller' => [
    'name' => 'Muster GmbH',
    'vat_id' => 'DE123456789',
    'address' => ['line1' => 'Hauptstraße 1', 'postcode' => '12345', 'city' => 'Musterstadt', 'country' => 'DE'],
    'contact' => ['name' => 'Max Muster', 'phone' => '+49 30 1234567', 'email' => 'rechnung@muster.de'],
    'electronic_address' => ['value' => 'rechnung@muster.de', 'scheme' => 'EM'],
    'bank_accounts' => [['iban' => 'DE02120300000000202051', 'bic' => 'BYLADEM1001', 'name' => 'Muster GmbH']],
],
```

How the examples are written:

- `Quittio::invoice()` starts an invoice with this seller and its bank account. `->render()` at the end of the chain calculates, validates and renders it ([Starting invoices](laravel.md#starting-invoices)). `Quittio::quote()`, `Quittio::paymentReminder()` and the other document methods work the same way.
- The examples follow [one way to write it](getting-started.md#one-way-to-write-it). A line is its name with named values: `->line('Hosting', netPrice: '49.00', quantity: 12, unit: UnitCode::Month)`. Without `quantity` the quantity is 1. Without `vat` the line gets 19 %, the standard rate for a German seller ([Default VAT](building-invoices.md#default-vat)), so the examples set VAT only where it differs.
- `Line::make('…', netPrice: …)->…` appears only where a line needs more than these values. The example says why.
- Lines from your data appear as arrays or models, the customer as `Party::make()` or as your model, payment terms as `->dueInDays()` and `->cashDiscount()`.
- Amounts are strings such as `'49.00'`, never floats.
- `invoices` and `documents` are filesystem disks of your application (`config/filesystems.php`).
- The seller is German, so the documents are in German. A comment such as `// 2026/Rechnung_RE-2026-0101.pdf` shows the German file name ("Rechnung" means invoice). `->locale('en')` switches texts and file names to English, see [EU business: services](#eu-business-services).
- Without Laravel, the same builder works with the core instance ([Without Laravel](#without-laravel)).

## Invoices in Germany

These examples invoice customers in Germany. Read the first one even if your case differs: it explains what all examples have in common, and the [Cancellation](#cancellation) and [Incoming e-invoices](#incoming-e-invoices) examples reuse it.

### Service invoice

You bill a German business customer for a month of work: hours of software development and a monthly hosting fee. The example shows a service period, payment terms with a cash discount and the format Quittio chooses for German businesses.

```php
use Quittio\Core\Builder\Party;
use Quittio\Core\Enums\UnitCode;
use Quittio\Laravel\Facades\Quittio;

$result = Quittio::invoice()
    ->number('RE-2026-0101')
    ->issueDate('2026-10-01')
    ->invoicingPeriod('2026-09-01', '2026-09-30')          // service period instead of a delivery date
    ->buyerReference('PO-4711')                          // the reference your customer asked for (BT-10)
    ->buyer(Party::make('Kunde AG')
        ->vatId('DE987654321')
        ->address('Bahnhofstraße 5', '54321', 'Beispielstadt', 'DE')
        ->electronicAddress('ap@kunde.de', 'EM'))
    ->line('Software development', netPrice: '95.00', quantity: '62.5', unit: UnitCode::Hour,
        description: 'Customer portal, sprints 5 and 6')
    ->line('Hosting', netPrice: '49.00', unit: UnitCode::Month)
    ->dueInDays(30)->cashDiscount(days: 10, percent: '2.00')
    ->render();                                          // ZUGFeRD, profile EN 16931

$result->save('invoices', '2026/');                    // 2026/Rechnung_RE-2026-0101.pdf
```

- Without a format, Quittio chooses one from the invoice ([Automatic choice](formats.md#automatic-choice)). For a German business it renders ZUGFeRD: a PDF/A-3 file (a PDF for archiving) with the e-invoice XML embedded. The XML follows the profile EN 16931 ([Formats](concepts.md#formats), [Profiles](concepts.md#profiles)).
- German invoices must state when the service was provided. `invoicingPeriod()` states the service period (September) instead of a single delivery date.
- Both lines get 19 % VAT by default. Hosting has no quantity, so it is 1 month.
- Totals: 5,986.50 net, 1,137.44 VAT, 7,123.94 due on 31 October 2026 (30 days after the issue date).
- `dueInDays()` and `cashDiscount()` become the payment terms. PDF and XML state them in German: "Zahlbar innerhalb von 30 Tagen ohne Abzug." (payable within 30 days without deduction) and "2 % Skonto bei Zahlung innerhalb von 10 Tagen (bis 11.10.2026)." (2 % cash discount for payment within 10 days, until 11 October 2026). See [Payment terms](building-invoices.md#payment-terms).
- `save()` stores the file on the disk `invoices` below `2026/` and returns its path. The file name starts with the German title "Rechnung" (invoice).

### Different VAT rates and a discount

You sell goods with different VAT rates: books at the reduced rate of 7 % and office supplies at the standard rate of 19 %. You grant a discount of 5 % on everything. The example passes the lines as arrays, as they might come from your database, and prints the VAT breakdown:

```php
use Quittio\Core\Builder\Party;
use Quittio\Core\Enums\UnitCode;
use Quittio\Country\DE\Tax\VatPreset;
use Quittio\Laravel\Facades\Quittio;

$result = Quittio::invoice()
    ->number('RE-2026-0102')
    ->issueDate('2026-10-05')
    ->deliveryDate('2026-10-02')
    ->buyer(Party::make('Kanzlei Recht & Partner')
        ->vatId('DE987654321')
        ->address('Marktplatz 2', '54321', 'Beispielstadt', 'DE'))
    ->lines([
        ['name' => 'Handbook of VAT law', 'quantity' => [3, UnitCode::Piece], 'netPrice' => '89.00', 'vat' => VatPreset::Reduced],
        ['name' => 'Desk organiser', 'quantity' => [3, UnitCode::Piece], 'netPrice' => '24.90'],
    ])
    ->discountPercent('5', 'Customer discount')          // one allowance per VAT rate
    ->render();

foreach ($result->data()->vatBreakdown as $row) {
    echo $row->rate.' %: '.$row->taxableAmount.' net, '.$row->taxAmount." VAT\n";
}
```

```
7 %: EUR 253.65 net, EUR 17.76 VAT
19 %: EUR 70.96 net, EUR 13.48 VAT
```

- Each array key names a method of the line builder, and the value holds its arguments: `'quantity' => [3, UnitCode::Piece]` means 3 pieces ([Lines](building-invoices.md#lines)).
- `VatPreset::Reduced` is a tax preset of the German country pack: the reduced rate of 7 % ([Tax presets](country-packs.md#tax-presets)). The desk organiser has no `vat`, so it gets 19 %.
- VAT is calculated per VAT rate, never per line. So `discountPercent()` splits the discount of 17.09 into one allowance per rate, in proportion to the line sums: 13.35 at 7 % and 3.74 at 19 % ([Discount splitting](building-invoices.md#discount-splitting)).
- For a discount on a single rate, use `allowance(VatCategory::StandardRate, '19', percent: '5', reason: '…')` instead ([Document level allowances and charges](building-invoices.md#document-level-allowances-and-charges)).

### Online shop

You sell to consumers in an online shop. Your prices include VAT, and the customer pays at checkout, here with PayPal. The example shows prices including VAT, shipping, a voucher and an invoice that is already paid.

```php
use Quittio\Core\Builder\Party;
use Quittio\Core\Enums\PaymentMeansCode;
use Quittio\Laravel\Facades\Quittio;

$result = Quittio::invoice()
    ->number('RE-2026-0103')
    ->issueDate('2026-10-06')
    ->deliveryDate('2026-10-06')
    ->buyer(Party::make('Erika Mustermann')
        ->address('Lindenweg 3', '10115', 'Berlin', 'DE')
        ->consumer())                                    // a consumer (B2C): PDF without XML
    ->line('T-shirt "Quittio"', priceInclVat: '19.99', quantity: 3)
    ->line('Shipping', priceInclVat: '4.90')
    ->line('Voucher WELCOME5', priceInclVat: '5.00', quantity: -1)
    ->payment(PaymentMeansCode::OnlinePaymentService, meansText: 'PayPal')
    ->prepaid('59.87')                                   // paid at checkout
    ->render();

$result->format();                                     // "pdf"
$result->data()->totals->grossTotal;                   // EUR 59.87
$result->data()->totals->amountDue;                    // EUR 0.00
```

- `consumer()` marks the buyer as a consumer. Invoices to consumers need no e-invoice, so the automatic choice renders a PDF without XML ([Which format do I need?](concepts.md#which-format-do-i-need)).
- `priceInclVat()` takes the price including VAT. The calculator derives the net prices, so that the invoice total is exactly the amount the customer paid: 3 × 19.99 + 4.90 − 5.00 = 59.87, of which 9.56 VAT.
- In this gross price mode every line uses `priceInclVat()`. Shipping and vouchers are lines (the voucher with quantity −1), not charges or allowances ([Gross price mode](building-invoices.md#gross-price-mode)).
- `payment()` states how the customer paid. `prepaid()` records the payment at checkout, so the amount due is 0.00 and the PDF shows no payment QR code.

### Small business

You run a small business ("Kleinunternehmer") under section 19 UStG, the German VAT Act, and charge no VAT. You state this once, in the configuration. The seller in this example has a tax number but no VAT identification number:

```php
// config/quittio.php of the small business
'seller' => [
    'name' => 'Lisa Klein Grafikdesign',
    'tax_number' => '21/815/08150',
    'identifiers' => [['value' => '21/815/08150', 'scheme' => null]],   // seller identifier (BT-29): needed without a VAT identifier
    'address' => ['line1' => 'Gartenstraße 7', 'postcode' => '12345', 'city' => 'Musterstadt', 'country' => 'DE'],
    'electronic_address' => ['value' => 'hallo@lisaklein.de', 'scheme' => 'EM'],
    'bank_accounts' => [['iban' => 'DE89370400440532013000', 'name' => 'Lisa Klein']],
    'small_business' => true,                                            // or QUITTIO_SELLER_SMALL_BUSINESS=true
],
```

Then you write every invoice as usual, without VAT on the lines:

```php
use Quittio\Core\Builder\Party;
use Quittio\Core\Enums\UnitCode;
use Quittio\Laravel\Facades\Quittio;

$result = Quittio::invoice()
    ->number('2026-017')
    ->issueDate('2026-10-07')
    ->buyer(Party::make('Café Sonnenschein GmbH')->address('Am Markt 3', '12345', 'Musterstadt', 'DE'))
    ->line('Logo design', netPrice: '650.00', unit: UnitCode::LumpSum)
    ->line('Business card layout', netPrice: '55.00', quantity: 2, unit: UnitCode::Hour)
    ->dueInDays(14)
    ->render();

$result->data()->totals->amountDue;                    // EUR 760.00, no VAT
```

- `seller.small_business` makes `Quittio::invoice()` flag the invoice as a small business invoice (`Meta::FLAG_SMALL_BUSINESS`). Lines without `vat()` get `VatPreset::SmallBusiness`, and the German country pack rejects VAT on the invoice (rule `DE-USTG-09`).
- Only an invoice with another seller than the configured one needs `->flag(Meta::FLAG_SMALL_BUSINESS)`.
- Every line carries the exemption text "Steuerfreie Leistung nach § 19 Abs. 1 UStG (Steuerbefreiung für Kleinunternehmer)." (tax-exempt supply under section 19 (1) UStG, exemption for small businesses) in PDF and XML.
- Invoices of a small business need no service date (section 34a UStDV, the German VAT implementing ordinance). They need the tax number or the VAT identification number ([Small businesses](country-packs.md#small-businesses)).
- Without a VAT identification number, EN 16931 needs a seller identifier (BT-29, `seller.identifiers`) or a trade register number (BT-30, `seller.legal_registration`). Otherwise rendering fails with the rule `BR-CO-26` ([Rules and validation](concepts.md#rules-and-validation)). Here the tax number serves as the seller identifier.

### Public sector

You invoice a German public authority, here a city administration. German public buyers require XRechnung, an e-invoice that is an XML file only. With the order they give you a Leitweg-ID, the routing ID of the buyer ([German terms](concepts.md#german-terms)). The example shows where the Leitweg-ID goes and how to add a readable PDF copy.

```php
use Quittio\Core\Builder\Party;
use Quittio\Core\Enums\UnitCode;
use Quittio\Laravel\Facades\Quittio;

$result = Quittio::invoice()
    ->number('RE-2026-0104')
    ->issueDate('2026-10-08')
    ->deliveryDate('2026-10-02')
    ->buyerReference('04011000-12345-03')                // Leitweg-ID from the order
    ->buyer(Party::make('Stadt Beispielstadt, Hauptamt')
        ->address('Rathausplatz 1', '54321', 'Beispielstadt', 'DE')
        ->electronicAddress('04011000-12345-03', '0204'))  // scheme 0204: Leitweg-ID
    ->line('Website maintenance', netPrice: '85.00', quantity: 8, unit: UnitCode::Hour)
    ->dueInDays(30)
    ->render(['xrechnung-cii', 'pdf']);                  // XRechnung and a PDF copy

$result->save('invoices', '2026/');                    // 2026/Rechnung_RE-2026-0104.xml, the e-invoice
$result->as('pdf')->save('invoices', '2026/');         // 2026/Rechnung_RE-2026-0104.pdf, a readable copy
```

- The Leitweg-ID goes into the buyer reference (BT-10). With a Leitweg-ID there, the automatic choice selects XRechnung in the CII syntax (`xrechnung-cii`) by itself ([Syntaxes](concepts.md#syntaxes-cii-and-ubl)). The buyer's electronic address is the Leitweg-ID as well, with the scheme `0204`.
- `render()` without a format would render `xrechnung-cii` alone. The list `['xrechnung-cii', 'pdf']` renders both formats from the same calculation, like `->formats([...])`. The first format is the main result, and `as('pdf')` returns the PDF. The XML is the invoice, the PDF only a readable copy.
- XRechnung requires the seller contact with name, phone and email, the electronic addresses of both parties and payment instructions. The seller configuration at the top of this page provides them ([XRechnung](formats.md#xrechnung)).
- Quittio creates the file but does not send it. Upload it to the portal the authority names, or send it via Peppol.

### Advance payment and final invoice

You agree on a project with an advance payment: you invoice part of the price when the order is placed and the rest after completion. The example shows the advance payment invoice and the final invoice that deducts it.

```php
use Quittio\Core\Builder\Party;
use Quittio\Core\Enums\InvoiceTypeCode;
use Quittio\Core\Enums\UnitCode;
use Quittio\Laravel\Facades\Quittio;

$customer = Party::make('Kunde AG')
    ->vatId('DE987654321')
    ->address('Bahnhofstraße 5', '54321', 'Beispielstadt', 'DE');

// Advance payment invoice when the order is placed
$advance = Quittio::invoice()
    ->type(InvoiceTypeCode::PrepaymentInvoice)
    ->number('RE-2026-0090')
    ->issueDate('2026-09-01')
    ->invoicingPeriod('2026-09-01', '2026-10-31')        // planned period of the service
    ->buyer($customer)
    ->line('Website relaunch, advance payment', netPrice: '1000.00', unit: UnitCode::LumpSum)
    ->dueInDays(14)
    ->render();

// Final invoice: the whole service with its whole VAT, minus the advance payment received
$final = Quittio::invoice()
    ->number('RE-2026-0110')
    ->issueDate('2026-10-14')
    ->deliveryDate('2026-10-10')
    ->buyer($customer)
    ->line('Website relaunch', netPrice: '3000.00', unit: UnitCode::LumpSum)
    ->de()->deductAdvance($advance->data())
    ->dueInDays(14)
    ->render();

$final->data()->totals->grossTotal;                    // EUR 3570.00
$final->data()->totals->prepaidAmount;                 // EUR 1190.00
$final->data()->totals->amountDue;                     // EUR 2380.00
```

- `type(InvoiceTypeCode::PrepaymentInvoice)` sets the invoice type code 386 (BT-3, the kind of invoice). The PDF is titled "Anzahlungsrechnung" (advance payment invoice).
- The advance payment invoice states the planned period of the service. Without a date, the German country pack warns (`DE-USTG-03`).
- The final invoice states the whole service with its whole VAT. `deductAdvance()` refers to the advance payment invoice and deducts its gross amount ([Final invoices](country-packs.md#final-invoices-deductadvance)).
- It also adds the note "Abzüglich Anzahlung gemäß Rechnung RE-2026-0090 vom 01.09.2026: Entgelt 1.000,00 € zzgl. 19 % Umsatzsteuer 190,00 € (§ 14 Abs. 5 Satz 2 UStG)." (less the advance payment of invoice RE-2026-0090 of 1 September 2026: net amount 1,000.00 € plus 19 % VAT 190.00 €, section 14 (5) sentence 2 UStG).
- Call `deductAdvance()` once per advance payment received. It does not check whether the advance invoice was paid.

### Cancellation

An issued invoice is never changed. If it is wrong, you issue a cancellation: a new document with its own number that refers to the original. The example cancels the [service invoice](#service-invoice) from above, first completely and then in part. `$result` is that invoice; `cancel()` renders the cancellation in the formats of the invoice.

```php
// Cancel the whole invoice
$cancellation = $result->cancel('ST-2026-0007', reason: 'Ordered twice');
$cancellation->filename();                             // Stornorechnung_ST-2026-0007.pdf

// Or cancel only part of it: 10 of the 62.5 hours of line 1
$partial = $result->cancel('ST-2026-0008', reason: 'Hours booked twice', lines: [
    ['line' => '1', 'quantity' => '10'],
]);
$partial->data()->totals->grossTotal;                  // EUR 1130.50
```

- In Germany a cancellation is a credit note (type code 381) with positive amounts. It is titled "Stornorechnung" (cancellation invoice).
- It refers to RE-2026-0101 and carries the note "Stornierung der Rechnung RE-2026-0101 vom 01.10.2026. Grund: Ordered twice" (cancellation of invoice RE-2026-0101 of 1 October 2026, reason: …).
- The partial cancellation reverses 10 hours: 950.00 net plus 180.50 VAT. `['line' => '1', 'amount' => '100.00']` reduces a line by an amount instead, e.g. after a complaint.
- For an invoice you stored as data, `Quittio::make(Quittio::cancellation($data, 'ST-2026-0007', reason: '…'))->render()` does the same ([Cancellations](laravel.md#cancellations)).
- For several partial cancellations of one invoice, pass the earlier ones as `history:`, so that the VAT adds up exactly ([Cumulative VAT rounding](building-invoices.md#cumulative-vat-rounding)). The [Managed layer](managed.md#cancellations-and-corrections), the optional package `quittio/managed`, keeps this history itself.

### Self-billing

With self-billing, the buyer writes the invoice instead of the seller, e.g. the commission statement for your sales agent. In Germany this document is called "Gutschrift" (self-billed invoice); it is not a refund. The example shows who is seller and who is buyer, and where the payment goes.

```php
use Quittio\Core\Builder\Party;
use Quittio\Core\Enums\PaymentMeansCode;
use Quittio\Core\Enums\UnitCode;
use Quittio\Laravel\Facades\Quittio;

$result = Quittio::invoice()
    ->de()->selfBilled()                                       // type code 389 and the note "Gutschrift"
    ->number('GS-2026-0012')
    ->issueDate('2026-10-05')
    ->invoicingPeriod('2026-09-01', '2026-09-30')
    ->seller(Party::make('Jonas Schreiber Handelsvertretung')   // the supplier: your sales agent
        ->vatId('DE111111125')
        ->address('Lindenallee 12', '80331', 'München', 'DE'))
    ->buyer(Party::make('Muster GmbH')                   // you: you issue the document
        ->vatId('DE123456789')
        ->address('Hauptstraße 1', '12345', 'Musterstadt', 'DE'))
    ->line('Commission September 2026', netPrice: '840.00', unit: UnitCode::LumpSum)
    ->payment(PaymentMeansCode::SepaCreditTransfer, iban: 'DE89370400440532013000', accountName: 'Jonas Schreiber')
    ->render();

$result->filename();                                   // Gutschrift_GS-2026-0012.pdf
```

- The supplier stays the seller (BG-4) and you are the buyer, even though you issue the document.
- `->seller()` replaces the seller of the configuration, and with it the configured bank account and small business setting. `payment()` sends the payment to the supplier's account.
- `selfBilled()` sets type code 389 and the note "Gutschrift". The PDF is titled "Gutschrift" ([Self-billing](country-packs.md#self-billing-gutschrift)).
- "Gutschrift" is used only for self-billing. Cancellations are titled "Stornorechnung" (cancellation invoice).

## Customers abroad

For customers in other countries, the VAT treatment and the format the customer can receive decide how the invoice looks.

### EU business: services

You provide a service to a business in another EU country, here IT consulting for a customer in Belgium. For such services the customer owes the VAT (reverse charge), so your invoice shows 0 % VAT. Belgian businesses receive e-invoices through Peppol, a network for delivering e-invoices, so the example writes Peppol BIS Billing 3.0 ([Formats](concepts.md#formats)).

```php
use Quittio\Core\Builder\Party;
use Quittio\Core\Enums\UnitCode;
use Quittio\Country\DE\Tax\VatPreset;
use Quittio\Laravel\Facades\Quittio;

$result = Quittio::invoice()
    ->number('RE-2026-0105')
    ->issueDate('2026-10-09')
    ->deliveryDate('2026-09-30')
    ->buyerReference('PO-2026-118')                      // Peppol requires a buyer reference (BT-10) or an order reference (BT-13)
    ->locale('en')                                       // English texts, labels and file name
    ->seller(fn ($s) => $s->electronicAddress('DE123456789', '9930'))   // Peppol participant ID
    ->buyer(Party::make('Klant BV')
        ->vatId('BE0123456749')
        ->address('Rue de la Loi 16', '1000', 'Brussels', 'BE')
        ->electronicAddress('0123456749', '0208'))
    ->defaultVat(VatPreset::ReverseChargeEu)             // for every line of this invoice
    ->line('IT consulting', netPrice: '110.00', quantity: 16, unit: UnitCode::Hour)
    ->dueInDays(14)
    ->render('peppol');

$xml = $result->xml();                                 // hand it to your Peppol access point
```

- `defaultVat()` sets the VAT of every line of this invoice. `VatPreset::ReverseChargeEu` is VAT category AE with 0 % and the text "Reverse charge, the recipient is liable for VAT" (in German: "Steuerschuldnerschaft des Leistungsempfängers").
- The German country pack requires the VAT identification numbers of both parties (`DE-USTG-11`). It warns when the invoice is issued after the 15th of the month following the service (`DE-USTG-15`).
- Peppol needs an electronic address (participant ID) for both parties and a buyer reference or purchase order reference ([Peppol BIS Billing 3.0](formats.md#peppol-bis-billing-30)). Here these are the German VAT number (scheme 9930) and the Belgian enterprise number (scheme 0208).
- `->seller(fn ($s) => …)` adds the Peppol address to the configured seller. A `Party` instead of the closure would replace the seller.
- `->locale('en')` makes texts, labels and the file name English: `Invoice_RE-2026-0105.xml`.
- Quittio writes the XML but does not send it. Sending goes through a Peppol access point, a provider connected to the network.

### EU business: goods

You deliver goods to a business in another EU country, here two pumps to a customer in Austria. This is an intra-community supply, which is exempt from German VAT. The example also shows a deliver-to address that differs from the buyer's address, and a line with an item number and a country of origin.

```php
use Quittio\Core\Builder\Line;
use Quittio\Core\Builder\Party;
use Quittio\Core\Data\Party\Address;
use Quittio\Core\Enums\UnitCode;
use Quittio\Country\DE\Tax\VatPreset;
use Quittio\Laravel\Facades\Quittio;

$result = Quittio::invoice()
    ->number('RE-2026-0106')
    ->issueDate('2026-10-09')
    ->deliveryDate('2026-10-08')
    ->buyer(Party::make('Kunde Handels GmbH')
        ->vatId('ATU12345675')
        ->address('Mariahilfer Straße 1', '1060', 'Wien', 'AT'))
    ->deliverTo('Kunde Handels GmbH, Lager', Address::make('Industriestraße 4', '4020', 'Linz', 'AT'))   // the customer's warehouse ("Lager")
    // Item number and country of origin go beyond the named values: Line::make()
    ->line(Line::make('Industrial pump', netPrice: '1450.00', quantity: 2, unit: UnitCode::Piece, vat: VatPreset::IntraCommunitySupply)
        ->sellerId('P-200')
        ->originCountry('DE'))
    ->render();
```

- The line needs an item number and a country of origin, so it uses `Line::make()`. It takes the same named values as `line()` and allows the other methods of the line builder.
- `VatPreset::IntraCommunitySupply` sets VAT category K with 0 % and the text "Steuerfreie innergemeinschaftliche Lieferung (§ 4 Nr. 1b i. V. m. § 6a UStG)" (tax-exempt intra-community supply under section 4 no. 1b in conjunction with section 6a UStG).
- For category K, EN 16931 requires the buyer's VAT identification number, the delivery date and the country of the deliver-to address (rules `BR-IC-02`, `BR-IC-11`, `BR-IC-12`). The German country pack warns after the 15th of the following month (`DE-USTG-15`).
- The automatic choice renders ZUGFeRD ([Reverse charge, intra-community supplies and VAT identifiers](country-packs.md#reverse-charge-intra-community-supplies-and-vat-identifiers)).

### Export

You sell to a customer outside the EU, here a measuring device for a customer in the United States, invoiced in US dollars. Exports are exempt from German VAT.

```php
use Quittio\Core\Builder\Party;
use Quittio\Core\Enums\PaymentMeansCode;
use Quittio\Core\Enums\UnitCode;
use Quittio\Country\DE\Tax\VatPreset;
use Quittio\Laravel\Facades\Quittio;

$result = Quittio::invoice()
    ->number('RE-2026-0107')
    ->issueDate('2026-10-12')
    ->deliveryDate('2026-10-12')
    ->currency('USD')
    ->buyer(Party::make('Customer Inc.')->address('1 Main Street', '10001', 'New York', 'US'))
    ->line('Measuring device', netPrice: '2400.00', unit: UnitCode::Piece, vat: VatPreset::Export)
    ->payment(PaymentMeansCode::CreditTransfer, iban: 'DE02120300000000202051', bic: 'BYLADEM1001')
    ->locale('en')
    ->render();
```

- `VatPreset::Export` sets VAT category G with 0 % and, in English, the text "VAT-exempt export supply (section 4 no. 1a in conjunction with section 6 UStG)".
- For a buyer outside the EU the automatic choice renders a PDF. Pass `->format('zugferd')` if the customer wants an e-invoice.
- The account is given as credit transfer (payment means code 30), because a SEPA credit transfer (code 58) is for payments in euro.

### Foreign currency with German VAT

You invoice a German customer in a foreign currency, here US dollars, with German VAT. Such an invoice also states the VAT in euro. The example sets the exchange rate and reads both VAT totals.

```php
use Quittio\Core\Builder\Party;
use Quittio\Core\Enums\PaymentMeansCode;
use Quittio\Laravel\Facades\Quittio;

$result = Quittio::invoice()
    ->number('RE-2026-0108')
    ->issueDate('2026-10-12')
    ->deliveryDate('2026-10-12')
    ->currency('USD')
    ->taxCurrency('EUR', exchangeRate: '0.9234', rateDate: '2026-10-12')
    ->buyer(Party::make('Kunde AG')
        ->vatId('DE987654321')
        ->address('Bahnhofstraße 5', '54321', 'Beispielstadt', 'DE'))
    ->line('Software licence', netPrice: '399.00', quantity: 5)
    ->payment(PaymentMeansCode::CreditTransfer, iban: 'DE02120300000000202051', bic: 'BYLADEM1001')
    ->render();

$result->data()->totals->vatTotal;                     // USD 379.05
$result->data()->totals->vatTotalInTaxCurrency;        // EUR 350.01
```

- `taxCurrency()` sets the VAT accounting currency (BT-6) and the exchange rate. The calculator writes the VAT in euro (BT-111): 379.05 × 0.9234 = 350.01.
- Without the rate the validation fails (`BR-53`). Without `taxCurrency()` the German country pack warns (`DE-USTG-10`). See [Currency and exchange rate](building-invoices.md#currency-and-exchange-rate).

## Laravel applications

These examples connect Quittio to your application: models, stored drafts and tests.

### Invoices for orders

Your application stores customers, orders and order items as Eloquent models. Instead of copying their fields into the builder for every invoice, the models provide the data themselves. The example shows the three models and the code that issues, stores and mails the invoice when an order ships.

```php
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Quittio\Core\Builder\LineBuilder;
use Quittio\Core\Builder\PartyBuilder;
use Quittio\Core\Contracts\ProvidesInvoiceData;
use Quittio\Core\Contracts\ProvidesInvoiceLine;
use Quittio\Core\Contracts\ProvidesParty;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Enums\UnitCode;
use Quittio\Country\DE\Tax\VatPreset;
use Quittio\Laravel\Facades\Quittio;

class Customer extends Model implements ProvidesParty
{
    public function fillParty(PartyBuilder $party): void
    {
        $party->name($this->company)
            ->vatId($this->vat_id)
            ->address($this->street, $this->postcode, $this->city, $this->country)
            ->electronicAddress($this->email, 'EM');
    }
}

class OrderItem extends Model implements ProvidesInvoiceLine
{
    protected function casts(): array
    {
        return ['unit_price' => 'decimal:2'];            // a string such as "24.90", never a float
    }

    public function fillLine(LineBuilder $line): void
    {
        $line->name($this->product_name)
            ->sellerId($this->sku)
            ->quantity($this->quantity, UnitCode::Piece)
            ->netPrice($this->unit_price)
            ->vat(VatPreset::from($this->tax_preset));   // stored preset key, e.g. "de.reduced"
    }
}

class Order extends Model implements ProvidesInvoiceData
{
    protected function casts(): array
    {
        return ['invoiced_at' => 'date', 'shipped_at' => 'date'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function toInvoiceData(): InvoiceData
    {
        return Quittio::invoice()
            ->number($this->invoice_number)
            ->issueDate($this->invoiced_at)
            ->deliveryDate($this->shipped_at)
            ->purchaseOrderReference($this->customer_reference)
            ->buyer($this->customer)
            ->lines($this->items)
            ->dueInDays(14)
            ->build();
    }
}
```

The three contracts of `Quittio\Core\Contracts` connect the models to the builder ([Application models](building-invoices.md#application-models)):

- `Customer` implements `ProvidesParty`: `fillParty()` fills the buyer, so `->buyer($this->customer)` takes the customer.
- `OrderItem` implements `ProvidesInvoiceLine`: `fillLine()` fills one line, so `->lines($this->items)` takes the order items.
- `Order` implements `ProvidesInvoiceData`: `toInvoiceData()` builds the invoice, so `Quittio::make($order)` renders the invoice of an order.

When the order ships, issue the invoice once and keep the file:

```php
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Quittio\Laravel\Facades\Quittio;

// When the order ships: issue the invoice once, store it and send it
$result = Quittio::make($order)->render();
$order->invoice_path = $result->save('invoices', 'orders/');
$order->save();
Mail::to($order->customer->email)->send(new InvoiceMail($result));

// Later downloads serve the stored file, so the invoice never changes
return Storage::disk('invoices')->download($order->invoice_path);
```

- `InvoiceMail` is the mailable from [Mail attachments](laravel.md#mail-attachments); the rendered invoice is its attachment.
- Rendering again later would build the invoice from the current model data. Serving the stored file keeps the issued invoice unchanged when, for example, the customer's address changes.
- The invoice number comes from your number range. The [Managed layer](managed.md) assigns gapless numbers, stores the issued data and archives the files.
- To render in the background: `Quittio::make($order)->queue()->save('invoices', 'orders/')` ([Queueing](laravel.md#queueing)).

### Drafts and previews

Your users prepare an invoice over some time and want to see it before it is sent. Keep it as a draft: invoice data without a number that you store until you issue it. The example stores a draft as JSON, renders a preview, changes the draft and issues it. This needs no Managed layer.

```php
use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Builder\Party;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Enums\UnitCode;
use Quittio\Laravel\Facades\Quittio;

// Create a draft and keep it as JSON, e.g. in a column of your model
$draft = Quittio::invoice()
    ->buyer(Party::make('Kunde AG')->vatId('DE987654321')->address('Bahnhofstraße 5', '54321', 'Beispielstadt', 'DE'))
    ->line('Consulting', netPrice: '120.00', quantity: 10, unit: UnitCode::Hour)
    ->build();
$json = $draft->toJson();

// Preview: a PDF with watermark, without e-invoice XML; a draft has no number yet
$preview = Quittio::make(InvoiceData::fromJson($json))
    ->format('pdf')
    ->option('watermark', 'ENTWURF')                     // "Entwurf" means draft
    ->withoutValidation()
    ->render();

// Change the draft as often as needed
$json = InvoiceBuilder::from(InvoiceData::fromJson($json))
    ->updateLine('1', netPrice: '110.00')
    ->build()
    ->toJson();

// Issue it: number and dates, then render and store the final invoice
$invoice = InvoiceBuilder::from(InvoiceData::fromJson($json))
    ->number('RE-2026-0115')                             // the next number of your number range
    ->issueDate(today())
    ->deliveryDate('2026-10-13')
    ->build();

$result = Quittio::make($invoice)->render();
$result->save('invoices', '2026/');
```

- A watermark renders a plain PDF and never e-invoice XML, so a draft cannot pass as an invoice ([Drafts and watermarks](formats.md#drafts-and-watermarks)).
- The preview runs without validation because a draft has no number yet. Return `$preview->stream()` to show it in the browser.
- Issue a draft by setting the number and the dates. After that, never change the invoice; correct it with a [cancellation](#cancellation).
- The [Managed layer](managed.md) adds drafts as database records, gapless numbering, frozen issued data, an archive with hashes, the history of cancellations and an audit log.

### Testing

You want feature tests for the order flow from [Invoices for orders](#invoices-for-orders). The first test checks the flow with the fake: `Quittio::fake()` records every render call and skips validation, XML and PDF, so the test is fast. The second test runs the real validation on the invoice of an order with `check()`:

```php
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Quittio\Laravel\Facades\Quittio;
use Quittio\Laravel\Rendering\RenderedInvoice;

it('sends the invoice when an order ships', function () {
    Quittio::fake();                                     // no PDF, no XML, no validation: fast
    Mail::fake();
    Storage::fake('invoices');

    $order = Order::factory()->create(['invoice_number' => 'RE-2026-0130']);

    $this->post("/orders/{$order->id}/ship")->assertOk();

    Quittio::assertRendered(fn (RenderedInvoice $r) => $r->data()->number === 'RE-2026-0130'
        && $r->format() === 'zugferd');
    Mail::assertSent(InvoiceMail::class);
});

it('builds a valid e-invoice for an order', function () {
    $order = Order::factory()->create();

    $report = Quittio::make($order)->check();            // the real validation, without rendering

    expect($report->isValid())->toBeTrue()
        ->and($report->warnings())->toBe([]);
});
```

- `Order::factory()` is the model factory of your application. `InvoiceMail` and the route are from [Invoices for orders](#invoices-for-orders).
- `assertRendered()` passes if at least one rendered invoice matches the callback ([Fake](testing.md#fake)).
- `check()` is never faked. It validates like a render run, including the XML, without rendering a PDF ([Testing](testing.md)).

## Quotes and reminders

Besides invoices, Quittio renders other business documents such as quotes and payment reminders ([Quotes and other documents](documents.md)).

### From quote to invoice

You send the customer a quote first and invoice when they accept it. The example shows a quote with an optional line and an invoice that takes over the accepted quote.

```php
use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Builder\Party;
use Quittio\Core\Enums\UnitCode;
use Quittio\Laravel\Facades\Quittio;

$quote = Quittio::quote()
    ->number('AN-2026-0031')
    ->issueDate('2026-10-01')
    ->validUntil('2026-10-31')
    ->buyer(Party::make('Kunde AG')->vatId('DE987654321')->address('Bahnhofstraße 5', '54321', 'Beispielstadt', 'DE'))
    ->line('Workshop "E-invoicing"', netPrice: '1200.00', unit: UnitCode::Day)
    ->optionalLine('Follow-up session', netPrice: '120.00', quantity: 4, unit: UnitCode::Hour);

$quote->render()->save('documents', 'quotes/');        // quotes/Angebot_AN-2026-0031.pdf ("Angebot": quote)

// The customer accepts: the invoice takes over parties, lines (without the optional line) and the bank account
$result = Quittio::make(InvoiceBuilder::fromDocument($quote->build())
    ->number('RE-2026-0140')
    ->issueDate('2026-11-03')
    ->deliveryDate('2026-10-28')
    ->dueInDays(14))
    ->render();
```

- The optional line is shown with its amounts. It is not counted in the totals and not taken over by `fromDocument()`. The invoice totals 1,428.00.
- `Quittio::quote()` carries the bank account of the configuration, although quotes do not print it. The invoice takes it over ([From document to invoice](documents.md#from-document-to-invoice)).

### Payment reminder

A customer has not paid an invoice by its due date. The example sends the first reminder ("1. Mahnung") and adds the statutory default interest for a business customer.

```php
use Brick\Money\Money;
use Quittio\Core\Builder\Party;
use Quittio\Core\Support\Amount;
use Quittio\Core\Support\Date;
use Quittio\Country\DE\Dunning\DefaultInterest;
use Quittio\Laravel\Facades\Quittio;

$reminder = Quittio::paymentReminder(1)               // first reminder ("1. Mahnung"), with seller and bank account
    ->number('M-2026-0005')
    ->issueDate('2026-10-15')
    ->buyer(Party::make('Kunde AG')->address('Bahnhofstraße 5', '54321', 'Beispielstadt', 'DE'))
    ->openItem('RE-2026-0042', '2026-08-03', '1190.00', dueDate: '2026-08-17')
    ->deadline('2026-10-29');

// Statutory default interest (section 288 BGB, the German Civil Code), split at every change of the base rate
foreach ((new DefaultInterest)->calculate(Money::of('1190.00', 'EUR'), Date::parse('2026-08-18'), Date::parse('2026-10-15')) as $charge) {
    $reminder->interest(Amount::format($charge->base), $charge->ratePercent, $charge->from, $charge->until, $charge->description, $charge->dayCount);
}

$reminder->render()->save('documents', 'reminders/');  // reminders/1._Mahnung_M-2026-0005.pdf
$reminder->build()->dunning->total('EUR');             // EUR 1209.89
```

- `paymentReminder(1)` is the first formal reminder. Level 0 is a friendly payment reminder ("Zahlungserinnerung"), level 2 the second reminder, and so on ([Payment reminders](documents.md#payment-reminders)).
- `openItem()` lists the unpaid invoice with its number, date, amount and due date. `deadline()` is the date by which the customer should pay.
- `calculate()` takes the first day with interest and the day after the last one. Here the rate is 10.52 % a year: the base rate of 1.52 % plus 9 points for business debtors. From 18 August to 14 October 2026 this gives 19.89, so the reminder asks for 1,209.89. For consumers pass `consumer: true` (5 points). See [Default interest](country-packs.md#default-interest).
- Fees and interest carry no VAT.
- The PDF is saved as `1._Mahnung_M-2026-0005.pdf`, after its German title "1. Mahnung" (first reminder).

## Incoming e-invoices

Your suppliers send you e-invoices, and you want to check and book them. The example is controller code that accepts an uploaded e-invoice, validates it and extracts the values for your accounting.

```php
use Quittio\Laravel\Facades\Quittio;

$content = $request->file('invoice')->get();          // uploaded PDF or XML

$report = Quittio::validateFile($content);
if (! $report->isValid()) {
    return back()->withErrors(array_map(fn ($m) => "[{$m->code}] {$m->message}", $report->errors()));
}

$read = Quittio::read($content);
$invoice = $read->data;

$booking = [
    'supplier' => $invoice->seller->name,
    'supplier_vat_id' => $invoice->seller->vatId,
    'number' => $invoice->number,
    'issue_date' => $invoice->issueDate->toIsoString(),
    'due_date' => $invoice->dueDate?->toIsoString(),
    'amount_due' => (string) $invoice->totals?->amountDue->getAmount(),
    'currency' => $invoice->currency,
    'iban' => $invoice->paymentInstructions?->primaryCreditTransfer()?->accountId,
    'profile' => $read->profile,                           // e.g. "en16931" or "xrechnung"
];
```

For the ZUGFeRD invoice of the first example, `$booking` is:

```json
{"supplier":"Muster GmbH","supplier_vat_id":"DE123456789","number":"RE-2026-0101","issue_date":"2026-10-01","due_date":"2026-10-31","amount_due":"7123.94","currency":"EUR","iban":"DE02120300000000202051","profile":"en16931"}
```

- `validateFile()` reads the file and runs all configured validation levels ([Validation](validation.md)). Each error has a rule code and a message, which the example passes back to the form.
- `read()` returns the data as stated in the document. It reads XML in both syntaxes (CII and UBL, including XRechnung and Peppol) and PDFs with embedded XML the same way ([Syntaxes](concepts.md#syntaxes-cii-and-ubl), [Reading incoming invoices](reading.md)).
- Amounts are never corrected silently: totals that differ from a recalculation are reported as `QUITTIO-DECLARED-TOTALS`.

## Logo, colours and texts

You usually set your logo, colours and texts once in the configuration. The example shows how to set them for a single invoice.

```php
use Quittio\Core\Builder\Party;
use Quittio\Core\Data\Presentation\Colors;
use Quittio\Core\Data\Presentation\Presentation;
use Quittio\Core\Enums\UnitCode;
use Quittio\Laravel\Facades\Quittio;

$result = Quittio::invoice()
    ->number('RE-2026-0150')
    ->deliveryDate('2026-10-14')
    ->buyer(Party::make('Kunde AG')->vatId('DE987654321')->address('Bahnhofstraße 5', '54321', 'Beispielstadt', 'DE'))
    ->line('Consulting', netPrice: '120.00', quantity: 4, unit: UnitCode::Hour)
    ->presentation(new Presentation(
        logo: storage_path('app/logo.png'),
        colors: new Colors(primary: '#0f766e'),
        introText: 'Thank you for your order. We invoice the following services:',
        closingText: 'Questions about this invoice? Call us at +49 30 1234567.',
        footerColumns: [
            ['Muster GmbH', 'Hauptstraße 1', '12345 Musterstadt'],
            ['Managing director: Max Muster', 'Amtsgericht Musterstadt, HRB 12345'],   // register court and register number
            ['Bank: Musterbank', 'IBAN DE02 1203 0000 0000 2020 51'],
        ],
    ))
    ->render();
```

- The same values for all invoices go into the configuration: `pdf.logo`, `pdf.colors.primary`, `pdf.texts.intro`, `pdf.texts.closing` and `pdf.footer_columns` ([Configuration](configuration.md)).
- Logos are PNG, JPEG or SVG. SVG logos in PDF/A need the PHP extension `imagick` ([Logos](templates.md#logos)).
- The design is never part of the XML.

## Without Laravel

You use Quittio in a PHP application without Laravel. The example is a complete script with the packages `quittio/core` and `quittio/country-de` (the German country pack). It renders an invoice, saves it and reads it back.

```php
<?php
require __DIR__.'/vendor/autoload.php';

use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Builder\Party;
use Quittio\Core\Enums\PaymentMeansCode;
use Quittio\Core\Enums\UnitCode;
use Quittio\Core\Quittio;
use Quittio\Core\Tax\CountryDefaultVat;
use Quittio\Country\DE\CountryDE;

$quittio = Quittio::create([], [new CountryDE]);
InvoiceBuilder::useDefaultVat(new CountryDefaultVat($quittio->countries()));   // lines without vat(): 19 %

$invoice = InvoiceBuilder::make()
    ->number('RE-2026-0120')
    ->issueDate('2026-10-14')
    ->deliveryDate('2026-10-14')
    ->seller(Party::make('Muster GmbH')->vatId('DE123456789')->address('Hauptstraße 1', '12345', 'Musterstadt', 'DE'))
    ->buyer(Party::make('Kunde AG')->vatId('DE987654321')->address('Bahnhofstraße 5', '54321', 'Beispielstadt', 'DE'))
    ->line('Consulting', netPrice: '120.00', quantity: 4, unit: UnitCode::Hour)
    ->payment(PaymentMeansCode::SepaCreditTransfer, iban: 'DE02120300000000202051')
    ->dueInDays(14)
    ->build();

$result = $quittio->make($invoice)->render();
$path = $result->save(__DIR__.'/invoices/'.$result->filename());   // invoices/Rechnung_RE-2026-0120.pdf

// Read it back, as the recipient would
$read = $quittio->readFile($path);
echo $read->data->totals->amountDue;                   // EUR 571.20
```

- Without Laravel there is no configuration file and no facade. `InvoiceBuilder::make()` starts an empty invoice, so the seller and the bank account are set in code.
- `useDefaultVat()` gives lines without `vat()` the default VAT of the seller's country pack (19 % in Germany), as the Laravel integration does. Without a default, such lines make `build()` fail.
- `Quittio::create()` takes the configuration as an array ([Without Laravel](getting-started.md#without-laravel)). The instance offers `make()`, `cancellation()`, `document()`, `read()` and the other methods of the facade.
