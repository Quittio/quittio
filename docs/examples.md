# Examples

Complete examples for common situations, from a service invoice to an incoming e-invoice. Every example on this page was run against the current version, and the XML it produced passed the official KoSIT validator (EN 16931, XRechnung and Peppol BIS Billing 3.0). The reference for each step is linked below the example.

The examples use the Laravel facade and this seller in `config/quittio.php` ([Seller](configuration.md#seller)):

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

`Quittio::invoice()` starts an invoice with this seller and its bank account, and `->render()` at the end of the chain renders it ([Starting invoices](laravel.md#starting-invoices)); `Quittio::quote()`, `Quittio::paymentReminder()` and the other document methods work the same way. `invoices` and `documents` are filesystem disks of your application. A line is its name with named values (`->line('Hosting', netPrice: '49.00')`), `Line::make()` when it needs further values, or an array; parties are `Party::make()` or your own model ([Lines](building-invoices.md#lines), [Seller and buyer](building-invoices.md#seller-and-buyer)). A line without `quantity()` has the quantity 1, and a line without `vat()` gets 19 %, the standard rate of the German seller ([Default VAT](building-invoices.md#default-vat)); the examples set VAT only where it differs. Without Laravel, the same builder works with the core instance ([Without Laravel](#without-laravel)).

| Example | Shows |
|---|---|
| [Service invoice](#service-invoice) | Hours, service period, cash discount, ZUGFeRD |
| [Different VAT rates and a discount](#different-vat-rates-and-a-discount) | Lines as arrays, 7 % and 19 %, a discount split per VAT rate |
| [Online shop](#online-shop) | Consumer, prices including VAT, voucher, paid at checkout |
| [Small business](#small-business) | Section 19 UStG without VAT, seller without VAT identifier |
| [Public sector](#public-sector) | XRechnung with Leitweg-ID, PDF copy |
| [Advance payment and final invoice](#advance-payment-and-final-invoice) | Advance payment invoice, final invoice with deduction |
| [Cancellation](#cancellation) | Full and partial cancellation with `$result->cancel()` |
| [Self-billing](#self-billing) | "Gutschrift" issued by the buyer |
| [EU business: services](#eu-business-services) | Reverse charge, Peppol BIS for a customer in Belgium |
| [EU business: goods](#eu-business-goods) | Intra-community supply with deliver-to address |
| [Export](#export) | Customer outside the EU, US dollars |
| [Foreign currency with German VAT](#foreign-currency-with-german-vat) | VAT stated in euro |
| [Invoices for orders](#invoices-for-orders) | Eloquent models, storing and mailing |
| [Drafts and previews](#drafts-and-previews) | Drafts without the Managed layer |
| [Testing](#testing) | Fake and validation in Pest |
| [From quote to invoice](#from-quote-to-invoice) | Quote with optional line, invoice from the quote |
| [Payment reminder](#payment-reminder) | Reminder with statutory default interest |
| [Incoming e-invoices](#incoming-e-invoices) | Validate and read an uploaded invoice |
| [Logo, colours and texts](#logo-colours-and-texts) | Design per call |
| [Without Laravel](#without-laravel) | Plain PHP script |

## Invoices in Germany

### Service invoice

A monthly invoice for hours and hosting to a German business. The service period replaces a delivery date, and the cash discount becomes part of the payment terms.

```php
use Quittio\Core\Builder\Party;
use Quittio\Core\Enums\UnitCode;
use Quittio\Laravel\Facades\Quittio;

$result = Quittio::invoice()
    ->number('RE-2026-0101')
    ->issueDate('2026-10-01')
    ->invoicingPeriod('2026-09-01', '2026-09-30')          // service period instead of a delivery date
    ->buyerReference('PO-4711')
    ->buyer(Party::make('Kunde AG')
        ->vatId('DE987654321')
        ->address('Bahnhofstraße 5', '54321', 'Beispielstadt', 'DE')
        ->electronicAddress('ap@kunde.de', 'EM'))
    ->line('Software development', netPrice: '95.00', quantity: '62.5', unit: UnitCode::Hour,
        description: 'Customer portal, sprints 5 and 6')
    ->line('Hosting', netPrice: '49.00', unit: UnitCode::Month)
    ->paymentTerms(fn ($t) => $t->dueInDays(30)->cashDiscount(days: 10, percent: '2.00'))
    ->render();                                          // ZUGFeRD, profile EN 16931

$result->save('invoices', '2026/');                    // 2026/Rechnung_RE-2026-0101.pdf
```

- The automatic choice renders ZUGFeRD in profile EN 16931, a PDF/A-3 with the embedded XML ([Automatic choice](formats.md#automatic-choice)).
- Both lines get 19 % VAT by default; hosting has no quantity, so it is 1 month.
- Totals: 5,986.50 net, 1,137.44 VAT, 7,123.94 due on 31 October 2026 (30 days after the issue date).
- The payment terms in PDF and XML read "Zahlbar innerhalb von 30 Tagen ohne Abzug." and "2 % Skonto bei Zahlung innerhalb von 10 Tagen (bis 11.10.2026)." ([Payment terms](building-invoices.md#payment-terms)).

### Different VAT rates and a discount

Books at 7 %, office supplies at the default 19 % and a discount of 5 % on everything, with the lines as arrays, e.g. rows of your database:

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
        ['name' => 'Handbook of VAT law', 'quantity' => [3, UnitCode::Piece], 'netPrice' => '89.00', 'vat' => VatPreset::Reduced7],
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

- VAT is calculated per VAT rate, never per line, so `discountPercent()` splits the discount of 17.09 into one allowance per rate in proportion to the line sums: 13.35 at 7 % and 3.74 at 19 % ([Discount splitting](building-invoices.md#discount-splitting)).
- For a discount on a single rate, use `allowance(VatCategory::StandardRate, '19', percent: '5', reason: '…')` instead ([Document level allowances and charges](building-invoices.md#document-level-allowances-and-charges)).

### Online shop

Shops show prices including VAT. With `priceInclVat()` the calculator derives the net prices, so that the invoice total is exactly the amount the customer paid:

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
        ->consumer())                                    // B2C: PDF without XML
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

- For consumers the automatic choice renders a PDF without XML.
- 3 × 19.99 + 4.90 − 5.00 = 59.87, of which 9.56 VAT. `prepaid()` records the payment at checkout, so the amount due is 0.00 and the PDF shows no payment QR code.
- In gross price mode every line uses `priceInclVat()`; shipping and vouchers are lines, not charges or allowances ([Gross price mode](building-invoices.md#gross-price-mode)).

### Small business

A small business under section 19 UStG charges no VAT. It says so once, in the configuration; this seller has a tax number but no VAT identifier:

```php
// config/quittio.php of the small business
'seller' => [
    'name' => 'Lisa Klein Grafikdesign',
    'tax_number' => '21/815/08150',
    'identifiers' => [['value' => '21/815/08150', 'scheme' => null]],   // BT-29: needed without a VAT identifier
    'address' => ['line1' => 'Gartenstraße 7', 'postcode' => '12345', 'city' => 'Musterstadt', 'country' => 'DE'],
    'electronic_address' => ['value' => 'hallo@lisaklein.de', 'scheme' => 'EM'],
    'bank_accounts' => [['iban' => 'DE89370400440532013000', 'name' => 'Lisa Klein']],
    'small_business' => true,                                            // or QUITTIO_SELLER_SMALL_BUSINESS=true
],
```

Then every invoice is written as usual, without VAT on the lines:

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
    ->paymentTerms(fn ($t) => $t->dueInDays(14))
    ->render();

$result->data()->totals->amountDue;                    // EUR 760.00, no VAT
```

- `seller.small_business` makes `Quittio::invoice()` flag the invoice as small business invoice (`Meta::FLAG_SMALL_BUSINESS`): lines without `vat()` get `VatPreset::SmallBusiness`, and the German pack rejects VAT on the invoice (`DE-USTG-09`). Only an invoice started with `InvoiceBuilder::make()` and an own seller needs `->flag(Meta::FLAG_SMALL_BUSINESS)`.
- Every line carries the exemption text "Steuerfreie Leistung nach § 19 Abs. 1 UStG (Steuerbefreiung für Kleinunternehmer)." in PDF and XML.
- Invoices of a small business need no service date (section 34a UStDV), but the tax number or VAT identifier ([Small businesses](country-packs.md#small-businesses)).
- Without a VAT identifier, EN 16931 needs a seller identifier (BT-29, `seller.identifiers`) or a register number (BT-30, `seller.legal_registration`), otherwise rendering fails with `BR-CO-26`; here the tax number serves as identifier.

### Public sector

German public authorities receive XRechnung. The Leitweg-ID from the order goes into the buyer reference (BT-10), and the automatic choice then selects XRechnung by itself:

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
        ->electronicAddress('04011000-12345-03', '0204'))
    ->line('Website maintenance', netPrice: '85.00', quantity: 8, unit: UnitCode::Hour)
    ->paymentTerms(fn ($t) => $t->dueInDays(30))
    ->render(['xrechnung-cii', 'pdf']);

$result->save('invoices', '2026/');                    // 2026/Rechnung_RE-2026-0104.xml, the e-invoice
$result->as('pdf')->save('invoices', '2026/');         // 2026/Rechnung_RE-2026-0104.pdf, a readable copy
```

- `Quittio::make($invoice)->render()` alone gives `xrechnung-cii`. `formats()` adds a PDF of the same calculation; the XML is the invoice, the PDF a readable copy.
- XRechnung requires the seller contact with name, phone and email, the electronic addresses of both parties and payment instructions; the seller configuration above provides them ([XRechnung](formats.md#xrechnung)).
- Quittio creates the file. Upload it to the portal the authority names, or send it via Peppol.

### Advance payment and final invoice

An advance payment invoice when the order is placed, and the final invoice after completion:

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
    ->paymentTerms(fn ($t) => $t->dueInDays(14))
    ->render();

// Final invoice: the whole service with its whole VAT, minus the advance payment received
$final = Quittio::invoice()
    ->number('RE-2026-0110')
    ->issueDate('2026-10-14')
    ->deliveryDate('2026-10-10')
    ->buyer($customer)
    ->line('Website relaunch', netPrice: '3000.00', unit: UnitCode::LumpSum)
    ->deductAdvance($advance->data())
    ->paymentTerms(fn ($t) => $t->dueInDays(14))
    ->render();

$final->data()->totals->grossTotal;                    // EUR 3570.00
$final->data()->totals->prepaidAmount;                 // EUR 1190.00
$final->data()->totals->amountDue;                     // EUR 2380.00
```

- The advance payment invoice (type code 386) is titled "Anzahlungsrechnung". It states the planned period of the service; without a date, the German pack warns (`DE-USTG-03`).
- The final invoice states the whole service with its whole VAT. `deductAdvance()` refers to the advance payment invoice, deducts its gross amount and adds the note "Abzüglich Anzahlung gemäß Rechnung RE-2026-0090 vom 01.09.2026: Entgelt 1.000,00 € zzgl. 19 % Umsatzsteuer 190,00 € (§ 14 Abs. 5 Satz 2 UStG)." ([Final invoices](country-packs.md#final-invoices-deductadvance)).
- Call `deductAdvance()` once per advance payment received; it does not check whether the advance invoice was paid.

### Cancellation

An issued invoice is never changed. A cancellation is a new document with its own number that refers to the original. `$result` is the [service invoice](#service-invoice) from above; `cancel()` renders the cancellation in the formats of the invoice:

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

- In Germany a cancellation is a credit note (type code 381) with positive amounts, titled "Stornorechnung". It refers to RE-2026-0101 and carries the note "Stornierung der Rechnung RE-2026-0101 vom 01.10.2026. Grund: Ordered twice".
- The partial cancellation reverses 10 hours: 950.00 net plus 180.50 VAT. `['line' => '1', 'amount' => '100.00']` reduces a line by an amount instead, e.g. after a complaint.
- For an invoice you stored as data, `Quittio::make(Quittio::cancellation($data, 'ST-2026-0007', reason: '…'))->render()` does the same ([Cancellations](laravel.md#cancellations)).
- For several partial cancellations of one invoice, pass the earlier ones as `history:` so that the VAT adds up exactly ([Cumulative VAT rounding](building-invoices.md#cumulative-vat-rounding)). The [Managed layer](managed.md#cancellations-and-corrections) keeps this history itself.

### Self-billing

With self-billing the buyer issues the invoice, e.g. the commission statement for a sales agent. The supplier stays the seller (BG-4) and you are the buyer, so the example sets both parties itself:

```php
use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Builder\Party;
use Quittio\Core\Enums\PaymentMeansCode;
use Quittio\Core\Enums\UnitCode;
use Quittio\Laravel\Facades\Quittio;

$invoice = InvoiceBuilder::make()
    ->selfBilled()                                       // type code 389 and the note "Gutschrift"
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
    ->build();

$result = Quittio::make($invoice)->render();
$result->filename();                                   // Gutschrift_GS-2026-0012.pdf
```

- `selfBilled()` sets type code 389 and the note "Gutschrift"; the PDF is titled "Gutschrift" ([Self-billing](country-packs.md#self-billing-gutschrift)).
- The payment goes to the supplier's account. "Gutschrift" is used only for self-billing; cancellations are titled "Stornorechnung".

## Customers abroad

### EU business: services

IT consulting for a business in Belgium, where the customer owes the VAT (reverse charge). Belgian businesses receive e-invoices through Peppol, so the example writes Peppol BIS Billing 3.0:

```php
use Quittio\Core\Builder\Party;
use Quittio\Core\Enums\UnitCode;
use Quittio\Country\DE\Tax\VatPreset;
use Quittio\Laravel\Facades\Quittio;

$result = Quittio::invoice()
    ->number('RE-2026-0105')
    ->issueDate('2026-10-09')
    ->deliveryDate('2026-09-30')
    ->buyerReference('PO-2026-118')                      // Peppol requires BT-10 or BT-13
    ->locale('en')
    ->seller(fn ($s) => $s->electronicAddress('DE123456789', '9930'))   // Peppol participant ID
    ->buyer(Party::make('Klant BV')
        ->vatId('BE0123456749')
        ->address('Rue de la Loi 16', '1000', 'Brussels', 'BE')
        ->electronicAddress('0123456749', '0208'))
    ->defaultVat(VatPreset::ReverseChargeEu)             // for every line of this invoice
    ->line('IT consulting', netPrice: '110.00', quantity: 16, unit: UnitCode::Hour)
    ->paymentTerms(fn ($t) => $t->dueInDays(14))
    ->render('peppol');

$xml = $result->xml();                                 // hand it to your Peppol access point
```

- `defaultVat()` sets the VAT of every line of this invoice: `VatPreset::ReverseChargeEu`, VAT category AE with 0 % and the text "Reverse charge, the recipient is liable for VAT" (German: "Steuerschuldnerschaft des Leistungsempfängers").
- The German pack requires the VAT identifiers of both parties (`DE-USTG-11`) and warns when the invoice is issued after the 15th of the month following the service (`DE-USTG-15`).
- Peppol needs an electronic address for both parties, here the German VAT number (scheme 9930) and the Belgian enterprise number (scheme 0208), and a buyer reference or purchase order reference ([Peppol BIS Billing 3.0](formats.md#peppol-bis-billing-30)).
- Quittio writes the XML; sending it goes through a Peppol access point. `->locale('en')` makes texts, labels and the file name English: `Invoice_RE-2026-0105.xml`.

### EU business: goods

Goods delivered to a business in another member state are a VAT-exempt intra-community supply:

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
    ->deliverTo('Kunde Handels GmbH, Lager', Address::make('Industriestraße 4', '4020', 'Linz', 'AT'))
    ->line(Line::make('Industrial pump', netPrice: '1450.00', quantity: 2, unit: UnitCode::Piece, vat: VatPreset::IntraCommunitySupply)
        ->sellerId('P-200')                              // further values on the line builder
        ->originCountry('DE'))
    ->render();
```

- `Line::make()` takes the same named values as `line()` and allows further ones, here the item number and the country of origin.
- `VatPreset::IntraCommunitySupply` sets VAT category K with 0 % and the text "Steuerfreie innergemeinschaftliche Lieferung (§ 4 Nr. 1b i. V. m. § 6a UStG)".
- EN 16931 requires the buyer's VAT identifier, the delivery date and the country of the deliver-to address for category K (`BR-IC-02`, `BR-IC-11`, `BR-IC-12`). The German pack warns after the 15th of the following month (`DE-USTG-15`).
- The automatic choice renders ZUGFeRD ([Reverse charge, intra-community supplies and VAT identifiers](country-packs.md#reverse-charge-intra-community-supplies-and-vat-identifiers)).

### Export

A device sold to a customer in the United States, invoiced in US dollars:

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
- The account is given as credit transfer (code 30), because a SEPA credit transfer (58) is for euro payments.

### Foreign currency with German VAT

An invoice in a foreign currency with German VAT also states the VAT in euro:

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

- `taxCurrency()` sets the VAT accounting currency (BT-6) and the exchange rate; the calculator writes the VAT in euro (BT-111): 379.05 × 0.9234 = 350.01.
- Without the rate the validation fails (`BR-53`); without `taxCurrency()` the German pack warns (`DE-USTG-10`) ([Currency and exchange rate](building-invoices.md#currency-and-exchange-rate)).

## Laravel applications

### Invoices for orders

Models provide parties, lines and the invoice through three contracts. With them, `->buyer($this->customer)` takes the customer, `->lines($this->items)` the order items and `Quittio::make($order)` builds the invoice of an order:

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
            ->vat(VatPreset::from($this->tax_preset));   // stored preset key, e.g. "de.reduced_7"
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
            ->paymentTerms(fn ($t) => $t->dueInDays(14))
            ->build();
    }
}
```

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

Drafts need no Managed layer: a draft is invoice data that you keep until you issue it.

```php
use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Builder\Party;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Data\Lines\InvoiceLine;
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
    ->option('watermark', 'ENTWURF')
    ->withoutValidation()
    ->render();

// Change the draft as often as needed
$json = InvoiceBuilder::from(InvoiceData::fromJson($json))
    ->updateLine('1', fn (InvoiceLine $line) => $line->withNetPrice('110.00'))
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

- A watermark renders a plain PDF and never e-invoice XML, so a draft cannot pass as an invoice. The preview runs without validation because a draft has no number yet; return `$preview->stream()` to show it in the browser.
- Issue a draft by setting the number and the dates. After that, never change the invoice; correct it with a [cancellation](#cancellation).
- The [Managed layer](managed.md) adds drafts as database records, gapless numbering, frozen issued data, an archive with hashes, the history of cancellations and an audit log.

### Testing

`Quittio::fake()` records every render call and skips validation, XML and PDF. For the invoice itself, run the real validation with `check()`:

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

- `Order::factory()` is the factory of your application; `InvoiceMail` and the route are from [Invoices for orders](#invoices-for-orders).
- `check()` is never faked: it validates like a render run, including the XML, without rendering a PDF ([Testing](testing.md)).

## Quotes and reminders

### From quote to invoice

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

$quote->render()->save('documents', 'quotes/');        // quotes/Angebot_AN-2026-0031.pdf

// The customer accepts: the invoice takes over parties, lines (without the optional line) and the bank account
$result = Quittio::make(InvoiceBuilder::fromDocument($quote->build())
    ->number('RE-2026-0140')
    ->issueDate('2026-11-03')
    ->deliveryDate('2026-10-28')
    ->paymentTerms(fn ($t) => $t->dueInDays(14)))
    ->render();
```

- The optional line is shown with its amounts but not counted in the totals or taken over by `fromDocument()`. The invoice totals 1,428.00.
- `Quittio::quote()` carries the bank account of the configuration (quotes do not print it), and the invoice takes it over ([From document to invoice](documents.md#from-document-to-invoice)).

### Payment reminder

A first reminder for an overdue invoice, with the statutory default interest:

```php
use Brick\Money\Money;
use Quittio\Core\Builder\Party;
use Quittio\Core\Support\Amount;
use Quittio\Core\Support\Date;
use Quittio\Country\DE\Dunning\DefaultInterest;
use Quittio\Laravel\Facades\Quittio;

$reminder = Quittio::paymentReminder(1)               // 1. Mahnung, with seller and bank account
    ->number('M-2026-0005')
    ->issueDate('2026-10-15')
    ->buyer(Party::make('Kunde AG')->address('Bahnhofstraße 5', '54321', 'Beispielstadt', 'DE'))
    ->openItem('RE-2026-0042', '2026-08-03', '1190.00', dueDate: '2026-08-17')
    ->deadline('2026-10-29');

// Statutory default interest (section 288 BGB), split at every change of the base rate
foreach ((new DefaultInterest)->calculate(Money::of('1190.00', 'EUR'), Date::parse('2026-08-18'), Date::parse('2026-10-15')) as $charge) {
    $reminder->interest(Amount::format($charge->base), $charge->ratePercent, $charge->from, $charge->until, $charge->description);
}

$reminder->render()->save('documents', 'reminders/');  // reminders/1._Mahnung_M-2026-0005.pdf
$reminder->build()->dunning->total('EUR');             // EUR 1209.89
```

- `calculate()` takes the first day with interest and the day after the last one. Here 10.52 % a year (base rate 1.52 % plus 9 points for business debtors) from 18.08. to 14.10.2026 give 19.89, so the reminder asks for 1,209.89. For consumers pass `consumer: true` (5 points).
- Fees and interest carry no VAT. The PDF is saved as `1._Mahnung_M-2026-0005.pdf` ([Payment reminders](documents.md#payment-reminders), [Default interest](country-packs.md#default-interest)).

## Incoming e-invoices

A controller that accepts an uploaded e-invoice, validates it and extracts the values for accounting:

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

- `validateFile()` reads the file and runs all configured validation levels; `read()` returns the data as stated in the document. XML (CII, UBL, XRechnung, Peppol) and PDF with embedded XML are read the same way ([Reading incoming invoices](reading.md)).
- Amounts are never corrected silently: totals that differ from a recalculation are reported as `QUITTIO-DECLARED-TOTALS`.

## Logo, colours and texts

The design of a single invoice:

```php
use Quittio\Core\Builder\Party;
use Quittio\Core\Data\Presentation\Colors;
use Quittio\Core\Data\Presentation\Presentation;
use Quittio\Laravel\Facades\Quittio;

$result = Quittio::invoice()
    ->number('RE-2026-0150')
    ->deliveryDate('2026-10-14')
    ->buyer(Party::make('Kunde AG')->vatId('DE987654321')->address('Bahnhofstraße 5', '54321', 'Beispielstadt', 'DE'))
    ->line('Consulting', netPrice: '120.00', quantity: 4, unit: 'HUR')
    ->presentation(new Presentation(
        logo: storage_path('app/logo.png'),
        colors: new Colors(primary: '#0f766e'),
        introText: 'Thank you for your order. We invoice the following services:',
        closingText: 'Questions about this invoice? Call us at +49 30 1234567.',
        footerColumns: [
            ['Muster GmbH', 'Hauptstraße 1', '12345 Musterstadt'],
            ['Managing director: Max Muster', 'Amtsgericht Musterstadt, HRB 12345'],
            ['Bank: Musterbank', 'IBAN DE02 1203 0000 0000 2020 51'],
        ],
    ))
    ->render();
```

- The same values for all invoices go into the configuration: `pdf.logo`, `pdf.colors.primary`, `pdf.texts.intro`, `pdf.texts.closing` and `pdf.footer_columns` ([Configuration](configuration.md)).
- Logos are PNG, JPEG or SVG; SVG logos in PDF/A need the PHP extension `imagick` ([Logos](templates.md#logos)). The design is never part of the XML.

## Without Laravel

A plain PHP script with `quittio/core` and `quittio/country-de`:

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
    ->paymentTerms(fn ($t) => $t->dueInDays(14))
    ->build();

$result = $quittio->make($invoice)->render();
$path = $result->save(__DIR__.'/invoices/'.$result->filename());   // invoices/Rechnung_RE-2026-0120.pdf

// Read it back, as the recipient would
$read = $quittio->readFile($path);
echo $read->data->totals->amountDue;                   // EUR 571.20
```

`Quittio::create()` takes the configuration as array ([Without Laravel](getting-started.md#without-laravel)); the instance offers `make()`, `cancellation()`, `document()`, `read()` and the other methods of the facade.
