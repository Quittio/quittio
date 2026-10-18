# Building invoices

This page is the reference for creating invoice data with Quittio's invoice builder: every builder method, VAT, payment, the calculation of totals, cancellations and the data model. Use it when you write your first real invoice and whenever you need an option that the tutorial does not show. If you have not rendered an invoice yet, start with [Getting started](getting-started.md); terms such as EN 16931, business terms and profiles are explained in [E-invoicing basics](concepts.md).

The page has three parts:

- **Basics:** the [overview](#overview) with the usual way to write each part of an invoice, the [conventions](#conventions) for amounts, dates and codes, and [creating and building](#creating-and-building) the data.
- **The parts of an invoice**, in the order you usually need them: [number and type](#document-type-and-identification), [dates](#dates), [parties](#parties), [lines](#lines), [VAT](#vat), [payment](#payment), [references](#references), [notes](#notes), [attachments](#supporting-documents), [design and metadata](#presentation-and-metadata) and [your own models](#application-models).
- **Advanced topics:** [foreign currencies](#currency-and-exchange-rate), [allowances and charges](#allowances-and-charges), [sub lines](#sub-lines), [prices including VAT](#gross-price-mode), [changing existing data](#changing-existing-data), [cancellations](#cancellations-and-corrections), [data of profile EXTENDED](#extensions), [macros](#macros), the [calculation](#calculation) and the [data model](#data-model).

## Overview

`Quittio\Core\Builder\InvoiceBuilder` creates an `InvoiceData` object: the invoice as immutable data, following the European standard EN 16931 ([EN 16931 and business terms](concepts.md#en-16931-and-business-terms)). The builder is part of `quittio/core` and works with and without Laravel. Rendering turns the data into PDF and XML ([Formats and profiles](formats.md), [Laravel integration](laravel.md)).

In Laravel, start with `Quittio::invoice()`. It returns the same builder, already filled with the seller, the first bank account and the small business setting of `config/quittio.php`, and the builder can render itself with `->render()` ([Starting invoices](laravel.md#starting-invoices)):

```php
use Quittio\Core\Builder\Party;
use Quittio\Core\Enums\PaymentMeansCode;
use Quittio\Core\Enums\UnitCode;
use Quittio\Country\DE\Tax\VatPreset;
use Quittio\Laravel\Facades\Quittio;

$invoice = Quittio::invoice()                                // seller and bank account from config/quittio.php
    ->number('RE-2026-0042')
    ->issueDate('2026-10-03')
    ->deliveryDate('2026-09-30')                             // date of supply, mandatory in Germany
    ->buyerReference('PO-4711')
    ->buyer(Party::make('Kunde AG')
        ->vatId('DE987654321')
        ->address('Bahnhofstraße 5', '54321', 'Beispielstadt', 'DE')
        ->electronicAddress('ap@kunde.de', 'EM'))
    ->line('Web development', netPrice: '95.00', quantity: 24, unit: UnitCode::Hour)   // default VAT: 19 %
    ->line('Handbook of VAT law', netPrice: '39.00', vat: VatPreset::Reduced)
    ->payment(PaymentMeansCode::SepaCreditTransfer, remittanceInformation: 'RE-2026-0042')   // keeps the configured account
    ->dueInDays(14)->cashDiscount(days: 7, percent: '2.00')
    ->build();                                               // or ->render() for PDF and XML
```

Without Laravel, start with `InvoiceBuilder::make()` and set the seller yourself ([Seller and buyer](#seller-and-buyer)).

Three things to know before you continue:

- `build()` returns the data. In Laravel, `render()` renders it right away instead.
- You never pass totals, VAT amounts or the VAT breakdown. Quittio calculates them when the invoice is rendered ([Calculation](#calculation)).
- The builder only checks that the essential parts are there ([Creating and building](#creating-and-building)). Everything else, such as a missing invoice number, is reported by the [validation](validation.md) when the invoice is rendered.

### The usual forms

Most parts of an invoice can be written in several ways. This documentation uses one usual form for each, the same as [Getting started](getting-started.md#one-way-to-write-it). The other forms exist for special cases; the linked sections describe them.

| You need | Write | Details |
|---|---|---|
| A line with price, quantity, unit, VAT or description | `->line('Hosting', netPrice: '49.00', quantity: 12, unit: UnitCode::Month)`. Without `quantity` it is 1, without `vat` the default VAT applies (in Laravel 19 % for a German seller). | [Lines](#lines), [Default VAT](#default-vat) |
| A line with more than that: item number, line discount, list price, period, sub lines, line group | `->line(Line::make('Pump', netPrice: '1450.00', quantity: 2)->sellerId('P-200'))`. `Line::make()` takes the same named values as `line()` and returns the line builder for the other methods. | [LineBuilder](#linebuilder) |
| Many lines from your data | `->lines($order->items)` with models that implement `ProvidesInvoiceLine`, or `->lines($rows)` with arrays such as `['name' => 'Hosting', 'netPrice' => '49.00']` | [Lines](#lines), [Application models](#application-models) |
| The customer | `->buyer($customer)` with a model that implements `ProvidesParty`, otherwise `->buyer(Party::make('Kunde AG')->address(…))` | [Seller and buyer](#seller-and-buyer) |
| A VAT rate other than the default | `->line('Book', netPrice: '39.00', vat: VatPreset::Reduced)` | [VAT](#vat) |
| Payment terms | `->dueInDays(14)->cashDiscount(days: 7, percent: '2.00')` | [Payment terms](#payment-terms) |
| The result | `->render()` for PDF and XML, `->build()` for the data only, e.g. to store a draft | [Creating and building](#creating-and-building) |

## Conventions

These rules apply to every method on this page.

**Chaining.** Every builder method returns the builder, so calls can be chained. The builders are mutable; the built `InvoiceData` is not ([Immutability](#immutability)).

**Numbers are strings.** Amounts, prices, quantities, percentages and rates are strings (`'95.00'`), integers or `Brick\Math` numbers. Floats are rejected with an `InvalidArgumentException` ("Floats are not accepted …"), with or without `declare(strict_types=1)`, because most decimal values have no exact float representation.

- Methods typed `BigDecimal|int|float|string` accept a float only to reject it. Without `float` in the signature, PHP would turn `19.99` into `19` in files without strict types.
- Parameters typed `string`, such as the amount of `allowance()`, receive a float as its string form.

**Amounts have at most two decimals.** Allowance, charge, prepaid and rounding amounts and the base amounts of cash discounts have at most two decimals, in every currency. More decimals throw an `InvalidArgumentException`. Prices, quantities, percentages and rates keep any precision.

**Dates.** Date parameters accept a `Quittio\Core\Support\Date`, a `DateTimeInterface` or a string: `YYYY-MM-DD`, `YYYYMMDD` or another notation that names a calendar date. Relative expressions such as `tomorrow` or `+14 days` are rejected. A `DateTimeInterface` is converted into a date in the time zone set with `timezone()`, so call `timezone()` first. `invoicingPeriod()` and `LineBuilder::period()` accept `Date` objects and strings only.

**Codes.** Code parameters take the enum case (`UnitCode::Hour`) or the code as string (`'HUR'`). A string that is not a case of the enum is kept as `Quittio\Core\Codes\RawCode`, so every code of the official code lists can be used; the validation checks raw codes against the code lists. Exception: `attachment()` accepts only the MIME types of `MimeType`.

**Calculated values.** Line net amounts (BT-131), the document totals (BG-22), the VAT breakdown (BG-23) and the due date derived from the payment terms are calculated when the invoice is rendered ([Calculation](#calculation)). There is no method to set them.

**Terms used on this page:**

- Numbers such as "buyer reference (BT-10)" or "seller postal address (BG-5)" are the business terms (BT) and business groups (BG) of EN 16931. You never type them in code; they help you match validation messages and the [field reference](field-reference.md) ([EN 16931 and business terms](concepts.md#en-16931-and-business-terms)).
- Codes such as `BR-CO-26`, `BR-DE-1` or `QUITTIO-PROFORMA` name the rules that the validation checks ([Rules and validation](concepts.md#rules-and-validation)).
- ZUGFeRD/Factur-X, XRechnung and Peppol are e-invoice formats ([Formats](concepts.md#formats)). CII and UBL are the two XML syntaxes they use ([Syntaxes: CII and UBL](concepts.md#syntaxes-cii-and-ubl)). A profile, such as EN 16931, EXTENDED or XRechnung, decides which fields and rules an XML file has ([Profiles](concepts.md#profiles)).
- A country pack brings the tax presets, legal rules and texts of one country; `quittio/quittio` includes the German pack ([Country packs](country-packs.md)).
- A **VAT group** is everything with the same VAT category and rate. The **VAT breakdown** (BG-23) has one entry per VAT group, with its taxable amount and its VAT.
- round2(x) is x rounded half up to two decimals.

## Creating and building

The tables of this page group the builder methods by topic and name their business terms. The <a href="api/classes/Quittio-Core-Builder-InvoiceBuilder.html">API reference</a> lists every method with its parameters.

| Method | Description |
|---|---|
| `InvoiceBuilder::make()` | New, empty builder. Defaults: type code 380 (commercial invoice), currency `EUR`, EN 16931:2017. |
| `InvoiceBuilder::from(InvoiceData $data)` | Builder with all values of existing data, e.g. to change a draft, a stored invoice or a read invoice ([Changing existing data](#changing-existing-data)). |
| `Quittio::edit(InvoiceData $data)` | Like `from()`, but declared amounts are compared with the configured calculator and its policy ([Declared amounts](#declared-amounts)). `InvoiceBuilder::fromUsing($data, $calculator)` does the same with a calculator of your own. |
| `InvoiceBuilder::fromDocument(DocumentData $document)` | Invoice from a quote, order confirmation, delivery note or proforma invoice. Parties, billable lines, document allowances and charges and payment data are taken over; number, issue date and notes are not. The number of an order confirmation becomes the sales order reference (BT-14), that of a delivery note the despatch advice reference (BT-16). See [From document to invoice](documents.md#from-document-to-invoice). |
| `build(): InvoiceData` | Builds the data. Requires at least one line. |
| `buildDraft(): InvoiceData` | Builds without requiring lines, e.g. for drafts or as the basis of payment reminders or account statements. Data without lines can be calculated (its line sum is zero), but it is not a valid invoice (BR-16). |

`build()` and `buildDraft()` throw `Quittio\Core\Exceptions\InvalidInvoiceData` (an `InvalidArgumentException`) when an essential part is missing. The message names the business term, e.g. "Missing buyer postal address (BG-8).":

| Missing | Business term |
|---|---|
| Seller, seller name, seller postal address | BG-4, BT-27, BG-5 |
| Buyer, buyer name, buyer postal address | BG-7, BT-44, BG-8 |
| Lines (`build()` only) | BG-25 |
| Item name of a line | BT-153 |
| VAT category of a line, unless the invoice has a [default VAT](#default-vat) | BG-30 |

Everything else is reported by the [validation](validation.md) when the invoice is rendered or checked, e.g. a missing invoice number or a missing buyer reference that XRechnung requires.

Without `issueDate()`, the issue date is today in the builder's time zone (`timezone()`, otherwise PHP's default time zone).

## Document type and identification

Every invoice needs a number. The type code tells the recipient what kind of document it is. Its default, 380, is a normal commercial invoice, so most invoices only call `number()`.

| Method | BT | Description |
|---|---|---|
| `number(string $number)` | BT-1 | Invoice number. The builder does not check it; the validation requires it. The [Managed layer](managed.md) assigns numbers itself. |
| `type(InvoiceTypeCode\|RawCode\|string $type)` | BT-3 | Type code from the code list UNTDID 1001, default `InvoiceTypeCode::CommercialInvoice` (380). See [Type codes](#type-codes). |
| `businessProcess(?string $process)` | BT-23 | Business process identifier. The profiles Peppol and XRechnung write `urn:fdc:peppol.eu:2017:poacc:billing:01:1.0` when it is not set. |
| `standard(StandardVersion $version)` | BT-24 | Version of EN 16931: `StandardVersion::En16931_2017` (default) or `En16931_2026`. Only the profile EN 16931 supports 2026 (`urn:cen.eu:en16931:2026`); other profiles report `QUITTIO-STANDARD-VERSION`. |

The specification identifier (BT-24) itself is not part of the data. It comes from the profile chosen when rendering ([Formats and profiles](formats.md#profiles)).

### Credit notes, corrected invoices and self-billing

Set another type code when the document is not a normal invoice:

```php
use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Enums\InvoiceTypeCode;
use Quittio\Laravel\Facades\Quittio;

// Credit note: positive amounts, type code 381, reference to the invoice
Quittio::invoice()->type(InvoiceTypeCode::CreditNote)->precedingInvoice('RE-2026-0042', '2026-10-03');

// Corrected invoice (384) based on the original
InvoiceBuilder::from($original)
    ->number('RE-2026-0050')
    ->issueDate('2026-10-20')
    ->type(InvoiceTypeCode::CorrectedInvoice)
    ->precedingInvoice($original->number, $original->issueDate)
    ->updateLine('2', netPrice: '80.00');

// Self-billing: the buyer issues the invoice, the supplier stays the seller (BG-4)
Quittio::invoice()->type(InvoiceTypeCode::SelfBilledInvoice);
```

To cancel an issued invoice, build a cancellation with `Quittio::cancellation()` instead ([Cancellations and corrections](#cancellations-and-corrections)).

The German pack adds `selfBilled()` for self-billing. It sets type code 389 and adds the note "Gutschrift" (self-billed invoice) with subject `REG`, which the German rules require ([Self-billing](country-packs.md#self-billing-gutschrift), [German terms](concepts.md#german-terms)).

### Type codes

`InvoiceTypeCode` contains the type codes that EN 16931 allows:

| Case | Code | Note |
|---|---|---|
| `CommercialInvoice` | 380 | Default. |
| `CreditNote` | 381 | Credit note with positive amounts; UBL writes a `CreditNote` document. |
| `CorrectedInvoice` | 384 | Reference the corrected invoice with `precedingInvoice()`. |
| `SelfBilledInvoice` | 389 | Issued by the buyer (self-billing). |
| `SelfBilledCreditNote` | 261 | Self-billed credit note. |
| `SelfBilledDebitNote` | 527 | Self-billed debit note. |
| `PartialInvoice` | 326 | Partial invoice. |
| `PrepaymentInvoice` | 386 | Advance payment invoice. |
| `PartialConstructionInvoice`, `PartialFinalConstructionInvoice`, `FinalConstructionInvoice` | 875, 876, 877 | Construction invoices. |
| `DebitNote` | 383 | Debit note. |
| `ProformaInvoice` | 325 | Rejected in the invoice pipeline (`QUITTIO-PROFORMA`); use `Quittio::proforma()` in Laravel or `DocumentBuilder::proforma()` ([Proforma invoices](documents.md#proforma-invoices)). |

Further cases: `RequestForPayment` (71), `DebitNoteGoodsServices` (80), `CreditNoteGoodsServices` (81), `MeteredServicesInvoice` (82), `CreditNoteFinancialAdjustment` (83), `DebitNoteFinancialAdjustment` (84), `TaxNotification` (102), `InvoicingDataSheet` (130), `DirectPaymentValuation` (202), `ProvisionalPaymentValuation` (203), `PaymentValuation` (204), `InterimApplicationForPayment` (211), `FinalPaymentRequest` (218), `PaymentRequestForCompletedUnits` (219), `ConsolidatedCreditNote` (262), `PriceVariationInvoice` (295), `CreditNoteForPriceVariation` (296), `DelcredereCreditNote` (308), `CommercialInvoiceWithPackingList` (331), `CommissionNote` (382), `ConsolidatedInvoice` (385), `HireInvoice` (387), `TaxInvoice` (388), `DelcredereInvoice` (390), `FactoredInvoice` (393), `LeaseInvoice` (394), `ConsignmentInvoice` (395), `FactoredCreditNote` (396), `OcrPaymentCreditNote` (420), `DebitAdvice` (456), `ReversalOfDebit` (457), `ReversalOfCredit` (458), `ForwardersCreditNote` (532), `ForwardersInvoiceDiscrepancyReport` (553), `InsurersInvoice` (575), `ForwardersInvoice` (623), `PortChargesDocument` (633), `InvoiceInformationForAccounting` (751), `FreightInvoice` (780), `ClaimNotification` (817), `ConsularInvoice` (870), `CustomsInvoice` (935).

`InvoiceTypeCode::isCreditNote()` is true for 81, 83, 261, 262, 296, 308, 381, 396, 420, 458 and 532. These are written as a `CreditNote` document in UBL. `isSelfBilled()` is true for 261, 389 and 527.

The document title in PDF, HTML and file names follows the type code:

- 380, 381, 384, 389, 325, 326, 386, 875, 876 and 877 have their own title.
- Other credit notes use the title of 381, other self-billed documents that of 389.
- Everything else is titled "Invoice" ("Rechnung" on a German invoice).

`Presentation::$title` overrides the title.

## Dates

An invoice has an issue date and usually the date or period of the delivery or service. German invoices must state the date of supply: a delivery date (`deliveryDate()`), an invoicing period (`invoicingPeriod()`) or a period on the lines (rule `DE-USTG-03`).

| Method | BT | Description |
|---|---|---|
| `issueDate(Date\|DateTimeInterface\|string $date)` | BT-2 | Issue date, default today. |
| `deliveryDate(Date\|DateTimeInterface\|string $date)` | BT-72 | Actual delivery or service date. Keeps the deliver-to data. |
| `invoicingPeriod(Date\|string\|null $start, Date\|string\|null $end = null)` | BG-14 (BT-73, BT-74) | Invoicing or service period. One of the two dates may be `null`, not both. |
| `dueDate(Date\|DateTimeInterface\|string $date)` | BT-9 | Due date. Without it, the calculator derives it from `dueInDays()`. Without payment terms, the configured default applies when rendering (`due_days`, Laravel `defaults.due_days`, default 14 there); never for credit notes. |
| `taxPointDate(Date\|DateTimeInterface\|string $date)` | BT-7 | Date when VAT becomes chargeable, if it differs from the issue date. |
| `taxPointDateCode(TaxPointDateCode $code)` | BT-8 | `TaxPointDateCode::IssueDate`, `DeliveryDate` or `PaymentDate` (written as 5, 29, 72 in CII and 3, 35, 432 in UBL). EN 16931 allows BT-7 or BT-8, not both (BR-CO-03). |
| `timezone(string $timezone)` | – | Time zone for `DateTimeInterface` values and the default issue date. |

Lines can carry their own period (`LineBuilder::period()`, BG-26).

## Parties

Every invoice has a seller (your company) and a buyer (your customer). Further parties are optional: a payee, a tax representative of the seller and a deliver-to party.

### Seller and buyer

In Laravel, the seller comes from `config/quittio.php` and you set only the buyer. Without Laravel, set both:

```php
use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Builder\Party;

$builder = InvoiceBuilder::make()
    ->seller(Party::make('Muster GmbH')
        ->vatId('DE123456789')
        ->address('Hauptstraße 1', '12345', 'Musterstadt', 'DE')
        ->contact('Max Muster', '+49 123 456789', 'rechnung@muster.de')
        ->electronicAddress('rechnung@muster.de', 'EM'))
    ->buyer(Party::make('Kunde AG')
        ->vatId('DE987654321')
        ->address('Bahnhofstraße 5', '54321', 'Beispielstadt', 'DE')
        ->electronicAddress('ap@kunde.de', 'EM'));
```

`seller()` and `buyer()` take one of four kinds of argument:

| Argument | Effect |
|---|---|
| `Party::make('Kunde AG')->vatId(…)->address(…)` | A `PartyBuilder` (`Quittio\Core\Builder\Party` starts one). It replaces the party set before. The builder is copied, so it can serve as a template. |
| An application model implementing `ProvidesParty`, e.g. `->buyer($customer)` | The model fills a new `PartyBuilder` ([Application models](#application-models)). It replaces the party set before. |
| `SellerParty`, `BuyerParty` | A finished party, e.g. `Seller::fromConfig()` in Laravel. It replaces the party set before. |
| A callable that receives a `PartyBuilder` | Continues the party set before, also a `SellerParty` or `BuyerParty` (e.g. from `Quittio::invoice()` in Laravel or from `InvoiceBuilder::from()`), so you can change single values. |

A callable is the way to change the configured seller in Laravel, e.g. to add a contact person:

```php
use Quittio\Core\Builder\PartyBuilder;
use Quittio\Laravel\Facades\Quittio;

Quittio::invoice()                                                 // seller from config/quittio.php
    ->seller(fn (PartyBuilder $s) => $s->contact('Erika Muster', '+49 123 4567', 'erika@muster.de'))
    ->buyer($customer);                                            // ProvidesParty
```

**Replacing the configured seller.** `Quittio::invoice()` starts the builder with the defaults of a seller: the seller of the configuration or the current issuer of the Managed layer. It does this with `applyDefaults(callable $apply)`, and the builder remembers what these defaults set. If you then replace the seller with another one, those values are removed again: the bank account, the small business flag and the other defaults of that seller. Values that you set on the builder yourself stay. A callable keeps the seller and its defaults ([Starting invoices](laravel.md#starting-invoices)).

`PartyBuilder` (`Quittio\Core\Builder\PartyBuilder`) has these methods:

| Method | Seller | Buyer | Description |
|---|---|---|---|
| `name(string $name)` | BT-27 | BT-44 | Legal name. Required. |
| `tradingName(?string $tradingName)` | BT-28 | BT-45 | Trading name. |
| `vatId(?string $vatId)` | BT-31 | BT-48 | VAT identifier with country prefix, e.g. `DE123456789`; uppercased, spaces removed. |
| `taxNumber(?string $taxNumber)` | BT-32 | – | Tax registration number (German "Steuernummer", the number issued by the local tax office); ignored for the buyer. |
| `legalRegistration(?string $value, IdentifierScheme\|RawCode\|string\|null $scheme = null)` | BT-30 | BT-47 | Legal registration identifier, e.g. the commercial register number. |
| `additionalLegalInfo(?string $info)` | BT-33 | – | Additional legal information (legal form, share capital …); ignored for the buyer. |
| `identifier(string $value, IdentifierScheme\|RawCode\|string\|null $scheme = null)` | BT-29 | BT-46 | Adds a party identifier, e.g. a GLN (Global Location Number) or the customer number at the buyer. The seller keeps all identifiers, the buyer and the payee only the first. CII writes identifiers without scheme as `ram:ID`, with scheme as `ram:GlobalID`. |
| `address(?string $line1, ?string $postcode, ?string $city, string $countryCode, ?string $line2 = null, ?string $line3 = null, ?string $subdivision = null)` | BG-5 | BG-8 | Postal address. The country code (ISO 3166-1 alpha-2, e.g. `DE`) is uppercased. Required. |
| `postalAddress(Address $address)` | BG-5 | BG-8 | Postal address as object. |
| `electronicAddress(string $value, ElectronicAddressScheme\|RawCode\|string $scheme = ElectronicAddressScheme::Email)` | BT-34 | BT-49 | Electronic address, e.g. an e-mail address, with its scheme (EAS code, e.g. `EM` for e-mail). Required by XRechnung and Peppol. Peppol needs a participant identifier, e.g. `9930` (German VAT number) or `0088` (GLN); `EM` is rejected there. |
| `contact(?string $name = null, ?string $phone = null, ?string $email = null)` | BG-6 | BG-9 | Contact person, phone and e-mail. XRechnung requires all three for the seller. |
| `consumer(bool $consumer = true)` | – | – | Marks the buyer as consumer (B2C). The automatic format choice then renders a PDF without XML, country packs apply their consumer rules, and templates get `buyer.isConsumer`. Not part of the XML; ignored for the seller. |
| `fromModel(ProvidesParty $model)` | | | Lets an application model fill the builder ([Application models](#application-models)). |
| `PartyBuilder::fromSeller(SellerParty $seller)`, `PartyBuilder::fromBuyer(BuyerParty $buyer)`, `PartyBuilder::fromPayee(PayeeParty $payee)` | | | Builder with the values of an existing party. |
| `countryCode(): ?string` | | | Country code of the postal address set so far. |
| `buildSeller(): SellerParty`, `buildBuyer(): BuyerParty`, `buildPayee(): PayeeParty` | | | Build the party. A missing name, or a missing address of seller or buyer, throws `InvalidInvoiceData`. |

Without a VAT identifier, EN 16931 requires a seller identifier (BT-29) or a legal registration identifier (BT-30); otherwise rendering fails with BR-CO-26.

Identifiers and electronic addresses take a scheme code. Use the enum case or pass any other code of the lists as string:

- `IdentifierScheme` (identifier schemes of ISO 6523, the ICD codes) has the cases `Siren` (0002), `SwedishOrganisationNumber` (0007), `Siret` (0009), `Duns` (0060), `Gln` (0088), `DutchKvk` (0106), `Gtin` (0160), `DanishCvr` (0184), `DutchOin` (0190), `NorwegianOrganisationNumber` (0192), `LeitwegId` (0204) and `BelgianEnterpriseNumber` (0208).
- `ElectronicAddressScheme` (EAS, the electronic address schemes) has `Email` (EM), `Siren` (0002), `SwedishOrganisationNumber` (0007), `Siret` (0009), `Duns` (0060), `Gln` (0088), `DutchKvk` (0106), `DanishCvr` (0184), `DutchOin` (0190), `NorwegianOrganisationNumber` (0192), `LeitwegId` (0204), `BelgianEnterpriseNumber` (0208), `AustrianVatNumber` (9914), `AustrianOrganisationNumber` (9915), `GermanVatNumber` (9930) and `FrenchVatNumber` (9957).

### Payee, tax representative and delivery

| Method | BT | Description |
|---|---|---|
| `payee(PayeeParty\|PartyBuilder\|string\|callable $payee, ?string $identifier = null, ?string $legalRegistration = null, IdentifierScheme\|RawCode\|string\|null $identifierScheme = null, IdentifierScheme\|RawCode\|string\|null $legalRegistrationScheme = null)` | BG-10 (BT-59, BT-60, BT-61) | Payee, if different from the seller (e.g. a factoring company). See below. |
| `taxRepresentative(string $name, string $vatId, Address $address)` | BG-11 (BT-62, BT-63, BG-12) | The seller's tax representative. |
| `deliverTo(?string $recipientName, ?Address $address = null, ?Identifier $locationId = null)` | BG-13 (BT-70, BG-15, BT-71) | Deliver-to party, address and location identifier. Replaces these three values (also with `null`) and keeps the delivery date. |
| `delivery(Delivery $delivery)` | BG-13 | Replaces the whole delivery information, including the delivery date. |

`payee()` takes one of these:

- the name, optionally with the identifier, the legal registration identifier and their schemes (e.g. `'0088'` for a GLN);
- a `PayeeParty`;
- a `PartyBuilder`, e.g. `Party::make('Factoring AG')->identifier(…)`;
- a callable that receives a `PartyBuilder` (`name()`, `identifier()`, `legalRegistration()`). Like `seller()` and `buyer()`, it continues an earlier payee.

Identifiers and schemes as arguments are allowed only next to a name; otherwise `payee()` throws an `InvalidArgumentException`.

```php
use Quittio\Core\Data\Identifier;
use Quittio\Core\Data\Party\Address;
use Quittio\Core\Enums\IdentifierScheme;

->deliverTo('Kunde AG, Lager Nord', Address::make('Industriestraße 9', '54322', 'Beispielstadt', 'DE'), Identifier::of('4000001000005', IdentifierScheme::Gln))
->taxRepresentative('Steuer Vertretung GmbH', 'DE111111125', new Address(countryCode: 'DE', line1: 'Ring 1', city: 'Berlin', postcode: '10115'))
```

`Address::make(?string $line1, ?string $postcode, ?string $city, string $countryCode)` creates an address with the most common fields. The constructor takes `countryCode`, `line1`, `line2`, `line3`, `city`, `postcode` and `subdivision`. `Identifier::of(string $value, IdentifierScheme|RawCode|string|null $scheme = null)` creates an identifier.

## Lines

Lines (BG-25) are the goods and services you invoice. Every invoice needs at least one line.

| `InvoiceBuilder` method | Description |
|---|---|
| `line(string\|LineBuilder\|ProvidesInvoiceLine\|array\|callable\|InvoiceLine $line, netPrice, quantity, unit, vat, description, priceInclVat)` | Adds a line (BG-25), in one of the forms below. |
| `lines(iterable $lines)` | Adds several lines. Each entry may be any of the forms, e.g. an array of arrays or an Eloquent collection of order items. |
| `updateLine(string $id, ?callable $change = null, netPrice, quantity, priceInclVat, name, description)` | Changes an existing line ([Changing existing data](#changing-existing-data)). |
| `removeLine(string $id)` | Removes an existing line by its identifier (BT-126) ([Changing existing data](#changing-existing-data)). |

A line can be given in these forms. Use the first one that fits:

| Form | Use it when | Example |
|---|---|---|
| The name with named values | the line needs only price, quantity, unit, VAT or description (the usual case) | `->line('Web development', netPrice: '95.00', quantity: 24, unit: UnitCode::Hour)` |
| `Line::make()` (`Quittio\Core\Builder\Line`) | the line needs more: item number, line allowance or charge, list price, base quantity, period, sub lines, line group. It takes the same named values and returns the `LineBuilder` for the other methods. | `->line(Line::make('Web development', netPrice: '95.00')->sellerId('WD-1')->allowance(percent: '10', reason: 'Framework agreement'))` |
| An application model implementing `ProvidesInvoiceLine` ([Application models](#application-models)) | the lines come from your models | `->lines($order->items)` |
| An array whose keys are methods of the `LineBuilder` | the lines come as data, e.g. database rows or a JSON request | `->lines([['name' => 'Hosting', 'quantity' => [1, UnitCode::Month], 'netPrice' => '49.00']])` |
| A callable that receives a new `LineBuilder` | rarely; the forms above cover the same | `->line(fn (LineBuilder $l) => $l->name('Travel expenses')->netPrice('120.00'))` |
| A finished `InvoiceLine`, taken as it is | you copy a line of existing data | `->line($invoice->lines[0])` |

The named arguments of `line()` and `Line::make()` are:

| Argument | Meaning |
|---|---|
| `netPrice` | The price without VAT (BT-146). |
| `priceInclVat` | The price including VAT ([Gross price mode](#gross-price-mode)). |
| `quantity` | Default 1. |
| `unit` | Default `UnitCode::Unit` (C62). |
| `vat` | A tax preset or a category without rate. Default: the [default VAT](#default-vat) of the invoice. |
| `description` | The item description. |

The named arguments are allowed only together with a name; with another form, `line()` throws an `InvalidArgumentException`. A string is always the name of the line, never a callable. A `LineBuilder` is copied when it is added, so the same builder can serve as a template for further lines.

### Lines as arrays

In an array, each key names a method of the `LineBuilder` and the value is its argument. The keys are `LineBuilder::ARRAY_KEYS`: `id`, `name`, `description`, `note`, `quantity`, `unit`, `netPrice`, `listPrice`, `priceInclVat`, `vat`, `period`, `allowance`, `charge`, `sellerId`, `buyerId`, `standardId`, `classification`, `originCountry`, `attribute`, `orderLineReference`, `buyerAccountingReference`, `objectId`, `group`, `highlight` and `showGrossPrice`.

- A method with several arguments takes an array value, as list or by name: `'quantity' => [4, UnitCode::Hour]`, `'allowance' => ['percent' => '10', 'reason' => 'Early bird']`.
- Null values are skipped, so database rows with empty columns can be passed.
- An unknown key throws an `InvalidArgumentException`.
- `Line::fromArray($array)` and `LineBuilder::fill($array)` do the same for a single builder.

```php
use Quittio\Core\Builder\Line;
use Quittio\Core\Enums\UnitCode;
use Quittio\Country\DE\Tax\VatPreset;

$builder->lines([
    Line::make('Web development', netPrice: '95.00', quantity: 24, unit: UnitCode::Hour),   // in a list: Line::make()
    ['name' => 'Hosting', 'quantity' => [1, UnitCode::Month], 'netPrice' => '49.00'],
    ['name' => 'Book', 'netPrice' => '39.00', 'vat' => VatPreset::Reduced],
]);
```

### Line identifiers

Every line has an identifier (BT-126). Partial cancellations, `updateLine()` and `removeLine()` refer to lines by it.

- A line without `id()` gets its position (1, 2, 3 …). Identifiers that existing lines or other lines' `id()` already use are skipped, so identifiers stay unique after `from()` and `removeLine()`. Example: lines `A`, `B` with `id('1')` and `C` get `2`, `1` and `3`.
- Sub lines get `{parent}.{n}`, e.g. `2.1`.
- An identifier used twice, also by a sub line, makes `build()` throw `InvalidInvoiceData`.
- Data from other sources (JSON, incoming invoices) gets the warning `QUITTIO-LINE-ID-DUPLICATE` instead, because EN 16931 does not require unique identifiers.

### LineBuilder

`Quittio\Core\Builder\LineBuilder` is the builder that `Line::make()` returns:

| Method | BT | Description |
|---|---|---|
| `id(string $id)` | BT-126 | Line identifier; default the position ([Line identifiers](#line-identifiers)). |
| `name(string $name)` | BT-153 | Item name. Required. |
| `description(?string $description)` | BT-154 | Item description. Printed as plain text; with the option `markdown` (Laravel `pdf.markdown`) as Markdown ([Templates](templates.md)). |
| `note(?string $note)` | BT-127 | Line note. |
| `quantity(BigDecimal\|int\|string $quantity, UnitCode\|RawCode\|string\|null $unit = null)` | BT-129, BT-130 | Invoiced quantity and optionally the unit; default 1. Negative quantities for returns and discounts. |
| `unit(UnitCode\|RawCode\|string $unit)` | BT-130 | Unit (UN/ECE Recommendation 20/21), default `UnitCode::Unit` (C62). |
| `netPrice(BigDecimal\|int\|string $price, BigDecimal\|int\|string\|null $baseQuantity = null, UnitCode\|RawCode\|string\|null $baseQuantityUnit = null)` | BT-146, BT-149, BT-150 | Net price per base quantity; the base quantity unit defaults to the line unit. Each call replaces the base quantity. |
| `listPrice(BigDecimal\|int\|string $grossPrice, BigDecimal\|int\|string\|null $discount = null)` | BT-148, BT-147 | List (gross) price before price discount, and the discount per unit. Without `netPrice()` the net price is list price minus discount. |
| `priceInclVat(BigDecimal\|int\|string $price)` | – | Price including VAT ([Gross price mode](#gross-price-mode)); the calculator derives the net price. |
| `vat(TaxPreset\|VatCategory $presetOrCategory, BigDecimal\|int\|string\|null $rate = null, ?string $exemptionReason = null, VatExemptionReasonCode\|RawCode\|string\|null $exemptionReasonCode = null)` | BG-30 (BT-151, BT-152) | VAT treatment: a tax preset of a country pack, or category, rate and exemption reason ([VAT](#vat)). Without it, the line takes the [default VAT](#default-vat) of the invoice. |
| `period(Date\|string\|null $start, Date\|string\|null $end = null)` | BG-26 (BT-134, BT-135) | Line period. |
| `allowance(?string $amount = null, ?string $percent = null, ?string $reason = null, AllowanceReasonCode\|RawCode\|string\|null $reasonCode = null, ?string $baseAmount = null)` | BG-27 | Line allowance: amount or percentage; repeatable ([Line allowances and charges](#line-allowances-and-charges)). |
| `charge(?string $amount = null, ?string $percent = null, ?string $reason = null, ChargeReasonCode\|RawCode\|string\|null $reasonCode = null, ?string $baseAmount = null)` | BG-28 | Line charge: amount or percentage; repeatable. |
| `sellerId(?string $id)` | BT-155 | Seller's item number. |
| `buyerId(?string $id)` | BT-156 | Buyer's item number. |
| `standardId(string $value, IdentifierScheme\|RawCode\|string $scheme = IdentifierScheme::Gtin)` | BT-157 | Standard item identifier, default scheme GTIN (0160, the global trade item number). |
| `classification(string $code, ItemClassificationScheme\|RawCode\|string $scheme, ?string $version = null)` | BT-158 | Item classification with list identifier and version; repeatable. |
| `originCountry(?string $country)` | BT-159 | Country of origin (uppercased). |
| `attribute(string $name, string $value)` | BG-32 (BT-160, BT-161) | Item attribute; repeatable. |
| `orderLineReference(?string $reference)` | BT-132 | Line of the purchase order. |
| `buyerAccountingReference(?string $reference)` | BT-133 | Buyer's accounting reference of the line. |
| `objectId(string $value, string\|RawCode\|null $scheme = null)` | BT-128 | Invoiced object identifier of the line, with scheme. |
| `subLine(string\|LineBuilder\|ProvidesInvoiceLine\|array\|callable $line, netPrice, quantity, unit, vat, description, priceInclVat)` | – | Adds a sub line, in the forms of `line()` except `InvoiceLine` ([Sub lines](#sub-lines)). |
| `reference(DocumentReference $reference)` | – | Line reference to an order, contract, despatch or receiving advice or another document (profile EXTENDED, [Extensions](#extensions)). |
| `extended(LineExtendedData $extended)` | – | Line data of profile EXTENDED ([Extensions](#extensions)). |
| `group(string $groupId)` | – | Assigns the line to a line group of the PDF (`InvoiceBuilder::group()`, [Presentation and metadata](#presentation-and-metadata)). |
| `highlight(bool $highlight = true)` | – | Highlights the line in the PDF (the default templates shade it and mark it in the secondary colour). |
| `showGrossPrice(?bool $show = true)` | – | Prints the price including VAT below the net unit price of this line (`false`: not), whatever the presentation's `showGrossPrices` says; `null` follows the presentation. Only for lines with `priceInclVat()`. |
| `fromModel(ProvidesInvoiceLine $model)` | – | Lets an application model fill the builder. |
| `fill(array $values)` | – | Calls the methods named by the keys of the array ([Lines as arrays](#lines-as-arrays)). |
| `build(string $currency, string $defaultId, ?string $locale = null, ?LineBuilder $vatDefault = null): InvoiceLine` | – | Builds the line; called by `InvoiceBuilder`, which passes the [default VAT](#default-vat) as `$vatDefault`. Use it to create an `InvoiceLine` on its own, e.g. `(new LineBuilder)->name('…')->…->build('EUR', '1')`. |

`UnitCode` has the cases `Unit` (C62), `Piece` (H87), `Set` (SET), `Pair` (PR), `Dozen` (DZN), `LumpSum` (LS), `Second` (SEC), `Minute` (MIN), `Hour` (HUR), `Day` (DAY), `Week` (WEE), `Month` (MON), `Quarter` (QAN), `Year` (ANN), `Gram` (GRM), `Kilogram` (KGM), `Tonne` (TNE), `Millimetre` (MMT), `Centimetre` (CMT), `Metre` (MTR), `Kilometre` (KMT), `SquareMetre` (MTK), `CubicMetre` (MTQ), `Millilitre` (MLT), `Litre` (LTR), `KilowattHour` (KWH), `Kilowatt` (KWT), `Percent` (P1), `ServiceUnit` (E48), `Package` (XPK), `Box` (XBX) and `Pallet` (XPX). Every other code of the list can be passed as string.

`ItemClassificationScheme` (code list UNTDID 7143) has `HarmonisedSystem` (HS), `Unspsc` (TST), `CustomsArticleNumber` (CV), `NationalProductGroup` (GN), `Isbn` (IB), `ProductServiceId` (MP) and `MutuallyDefined` (ZZZ).

### Prices

Most lines need only `netPrice`. A price per base quantity (e.g. per 1000 pieces) or a list price with a discount needs `Line::make()`:

```php
use Quittio\Core\Builder\Line;
use Quittio\Core\Enums\UnitCode;
use Quittio\Country\DE\Tax\VatPreset;

->line(Line::make('Paper A4', quantity: 5000, unit: UnitCode::Piece, vat: VatPreset::Standard)
    ->netPrice('12.50', baseQuantity: 1000))                 // 12.50 per 1000 sheets: 62.50
->line(Line::make('Monitor', quantity: 2, vat: VatPreset::Standard)
    ->listPrice('299.00', discount: '30.00'))                // net price 269.00
```

The line amount before allowances and charges is round2(quantity × net price / base quantity). Prices must not be negative (BR-27); returns and discounts use a negative quantity.

## VAT

Every line has a VAT category (BT-151) and, except for category O (not subject to VAT), a rate (BT-152). Usually you do not set them on every line. Lines without `vat()` take the [default VAT](#default-vat) of the invoice; in Laravel that is 19 % for a German seller.

Set the VAT of a line when it differs from the default. Use a tax preset of a country pack or the category:

```php
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Enums\VatExemptionReasonCode;
use Quittio\Country\DE\Tax\VatPreset;

->vat(VatPreset::Standard)                                         // preset of the German pack
->vat(VatCategory::StandardRate, '19')
->vat(VatCategory::ReverseCharge, '0', 'Reverse charge', VatExemptionReasonCode::ReverseCharge)
->vat(VatCategory::Exempt, '0', 'Exempt according to Art. 132 VAT Directive', 'VATEX-EU-132')
->vat(VatCategory::NotSubjectToVat)                                  // no rate
```

`vat()` is a method of the `LineBuilder`; `line()` and `Line::make()` take a preset or a category without rate as named argument `vat:`. A tax preset is a ready-made VAT treatment of a country pack: category, rate and, for exemptions, the reason text and code, e.g. `VatPreset::Reduced` for the German 7 % rate ([Tax presets](#tax-presets)). Reverse charge (category AE) means that the buyer, not the seller, owes the VAT.

With a preset, category, rate, exemption reason and code come from the preset, and the other parameters are ignored. The preset key is kept on the line (`LineVat::$presetKey`). The exemption text of a preset is resolved in the locale of the invoice (`locale()`) when the line is built; the German presets use German without a locale.

### Default VAT

Lines and sub lines without `vat()` take the default VAT of the invoice when it is built. The default is, in this order:

1. `defaultVat()` of the builder, with the arguments of `LineBuilder::vat()`, e.g. `->defaultVat(VatPreset::Reduced)` for a bookshop;
2. otherwise the application default, set once with `InvoiceBuilder::useDefaultVat(callable|TaxPreset|null $default)`. It is a preset, or a callable that receives the seller's country code (BT-40) and whether the invoice is flagged as small business invoice (`Meta::FLAG_SMALL_BUSINESS`), and returns a preset or `null`.

`Quittio\Core\Tax\CountryDefaultVat` is such a callable: `new CountryDefaultVat(CountryRegistry $countries, ?TaxPreset $preset = null)`.

- For normal invoices it returns `$preset`, otherwise the standard preset of the seller's country pack (`CountryDefaults::$vatPreset`, Germany `de.standard`).
- For small business invoices it returns the small business preset of the pack (`CountryDefaults::$smallBusinessVatPreset`, Germany `de.small_business`), otherwise `$preset`. A small business ("Kleinunternehmer" in Germany) charges no VAT ([German terms](concepts.md#german-terms)).

The Laravel integration installs it with the preset of `quittio.defaults.vat` ([Configuration](configuration.md#defaults)). So in Laravel, lines without `vat()` get 19 % for a German seller. Without Laravel, install it yourself:

```php
use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Enums\UnitCode;
use Quittio\Core\Tax\CountryDefaultVat;
use Quittio\Country\DE\Tax\VatPreset;

// Without Laravel, once at start-up
InvoiceBuilder::useDefaultVat(new CountryDefaultVat($quittio->countries()));   // or a preset: VatPreset::Standard

$invoice = InvoiceBuilder::make()
    // ...
    ->line('Web development', netPrice: '95.00', quantity: 24, unit: UnitCode::Hour)   // 19 %
    ->line('Book', netPrice: '39.00', vat: VatPreset::Reduced)                         // 7 %
    ->build();
```

The default only fills in what a line leaves open: the built data and the XML carry the VAT of every line, as EN 16931 requires. Without a default, a line without VAT makes `build()` fail (`InvalidInvoiceData`, BG-30). `useDefaultVat(null)` removes the application default. Documents (`DocumentBuilder`) use the same defaults and have `defaultVat()` as well.

### Categories

| `VatCategory` | Code | Rate | VAT | Exemption reason (BT-120/BT-121) | Further requirements |
|---|---|---|---|---|---|
| `StandardRate` | S | greater than 0 (BR-S-05) | rate × taxable amount | not allowed (BR-S-10) | Seller VAT identifier, tax number or tax representative (BR-S-02). One VAT breakdown per rate. |
| `ZeroRated` | Z | 0 (BR-Z-05) | 0 | not allowed (BR-Z-10) | As S (BR-Z-02). |
| `Exempt` | E | 0 (BR-E-05) | 0 | required (BR-E-10) | As S (BR-E-02). |
| `ReverseCharge` | AE | 0 (BR-AE-05) | 0 | required (BR-AE-10), e.g. `VATEX-EU-AE` | As S, and the buyer VAT identifier or legal registration identifier (BR-AE-02). |
| `IntraCommunitySupply` | K | 0 (BR-IC-05) | 0 | required (BR-IC-10), e.g. `VATEX-EU-IC` | Seller VAT identifier or tax representative, and buyer VAT identifier (BR-IC-02); delivery date or invoicing period (BR-IC-11); deliver-to country (BR-IC-12). |
| `Export` | G | 0 (BR-G-05) | 0 | required (BR-G-10), e.g. `VATEX-EU-G` | Seller VAT identifier or tax representative (BR-G-02). |
| `NotSubjectToVat` | O | none (BR-O-05) | 0 | required (BR-O-10), e.g. `VATEX-EU-O` | No VAT identifier of seller, tax representative or buyer (BR-O-02); no other category in the invoice (BR-O-11). |
| `CanaryIslands` | L | 0 or greater (BR-AF-05) | rate × taxable amount (BR-AF-09) | not allowed (BR-AF-10) | IGIC (Canary Islands). As S (BR-AF-02). One VAT breakdown per rate. The official CII validation rejects the rate 0, UBL accepts it (warning `QUITTIO-IGIC-ZERO-RATE-CII`). |
| `CeutaMelilla` | M | 0 or greater (BR-AG-05) | rate × taxable amount (BR-AG-09) | not allowed (BR-AG-10) | IPSI (Ceuta and Melilla). As S (BR-AG-02). One VAT breakdown per rate. |
| `TransferredVatItaly` | B | as given | rate × taxable amount | – | Split payment (Italy): only in a domestic Italian invoice, every country code (seller, buyer, tax representative, deliver-to, item origin) is `IT` (BR-B-01); no category S in the same invoice (BR-B-02). |

- Categories Z, E, AE, K, G and O allow exactly one VAT breakdown each (BR-Z-01 …); S, L and M one per rate.
- The calculator calculates VAT 0 for Z, E, AE, K, G and O, whatever rate is given. It writes the rate 0 to the VAT breakdown of a group whose lines have no rate (category O: no rate). The validation still requires the rate 0 on the lines (BR-Z-05 …).
- The rules are part of the [validation](validation.md) (level L3). `VatCategory::requiresRate()`, `isZeroTax()` and `requiresExemptionReason()` expose the same properties.

`VatExemptionReasonCode` (code list VATEX) has `ReverseCharge` (VATEX-EU-AE), `IntraCommunitySupply` (VATEX-EU-IC), `Export` (VATEX-EU-G), `NotSubjectToVat` (VATEX-EU-O), `TravelAgents` (VATEX-EU-D), `SecondHandGoods` (VATEX-EU-F), `WorksOfArt` (VATEX-EU-I), `CollectorsItems` (VATEX-EU-J), `Article79c` (VATEX-EU-79-C), `Article132` (VATEX-EU-132), `Article143` (VATEX-EU-143), `Article148` (VATEX-EU-148), `Article151` (VATEX-EU-151) and `Article309` (VATEX-EU-309). Other codes of the list can be passed as strings.

### Exemption reasons in the VAT breakdown

You give the exemption reason per line, but the XML carries it per VAT breakdown entry (one per category and rate). The calculator therefore collects the reasons of the lines of each group, for the categories that require one (E, AE, K, G, O):

- Different texts are joined with "; " in the order of the lines, so that no legally required note is lost.
- A code is kept when all lines of the group have the same code. Otherwise it is left out, and the validation reports `QUITTIO-EXEMPTION-CONFLICT`.
- The exemption reason of a document level allowance or charge is used only if no line of its group provides one, e.g. for an exempt charge on an otherwise standard rated invoice.

### Tax presets

A tax preset implements `Quittio\Core\Tax\TaxPreset` (`key()`, `category()`, `rate()`, `exemptionReasonCode()`, `exemptionReason(?string $locale = null)`). Country packs provide their presets as `TaxPresetCollection` (`$pack->taxPresets()`, with `get(string $key)`, `has()`, `add()`, `count()` and iteration), so a stored preset key can be resolved again:

```php
$preset = $quittio->countries()->get('DE')?->taxPresets()->get('de.standard');
```

The German presets (`VatPreset::Standard`, `Reduced`, `SmallBusiness`, `ReverseChargeEu`, `VatPreset::exempt('§ 4 Nr. 14 UStG')` …) are listed in [Tax presets](country-packs.md#tax-presets); [Presets of your pack](country-packs.md#presets-of-your-pack) shows how a pack provides its own.

Create your own presets with `CustomTaxPreset::make(string $key, VatCategory $category, BigDecimal|int|string|null $rate = null, VatExemptionReasonCode|RawCode|string|null $code = null, array|string|null $reason = null)`. Give the reason as text, or as an array of texts per locale (fallback `en`):

```php
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Tax\CustomTaxPreset;

$exempt = CustomTaxPreset::make('acme.exempt_132', VatCategory::Exempt, '0', 'VATEX-EU-132', [
    'de' => 'Steuerfrei nach Art. 132 MwStSystRL',
    'en' => 'Exempt according to Art. 132 VAT Directive',
]);

->line('Course', netPrice: '300.00', vat: $exempt)
```

## Payment

Payment data tells the buyer how and when to pay. It has three parts: the payment instructions (payment means and bank account), the payment terms (due date and cash discount) and amounts already paid. In Laravel, `Quittio::invoice()` already adds a SEPA credit transfer to the first bank account with an IBAN in `config/quittio.php`, so most invoices only add payment terms.

### Payment instructions

| Method | BT | Description |
|---|---|---|
| `payment(PaymentMeansCode\|RawCode\|string $means, ?string $iban = null, ?string $bic = null, ?string $accountName = null, ?string $remittanceInformation = null, ?string $meansText = null)` | BG-16 (BT-81, BT-82, BT-83), BG-17 | Payment means, optionally with one account (IBAN uppercased, spaces removed), the remittance information (the payment reference the buyer should state) and a text for the means. |
| `creditTransfer(string $accountId, ?string $bic = null, ?string $accountName = null)` | BG-17 (BT-84, BT-86, BT-85) | Adds a further account. CII writes an IBAN as `IBANID` and any other account identifier as `ProprietaryID`. Without payment instructions, it starts them with `SepaCreditTransfer` (58). |
| `paymentCard(string $lastDigits, ?string $holderName = null)` | BG-18 (BT-87, BT-88) | Card data: only the last 4 to 6 digits (more throw an `InvalidArgumentException`). Without payment instructions, it starts them with `BankCard` (48). |
| `directDebit(?string $mandateReference, ?string $creditorId = null, ?string $debitedIban = null)` | BG-19 (BT-89, BT-90, BT-91) | SEPA direct debit: mandate reference, creditor identifier, debited account. Without payment instructions, it starts them with `SepaDirectDebit` (59). |

A second call of `payment()` replaces the payment instructions:

- An account given in the call replaces all accounts.
- Without an account, existing accounts are kept for the credit transfer codes 30, 42 and 58 (e.g. the seller's default account set by `Quittio::invoice()`), card data for 48, 54 and 55, and direct debit data for 49 and 59. Everything else is dropped.
- The remittance information is kept unless a new one is given.

`creditTransfer()`, `paymentCard()` and `directDebit()` add to existing instructions without changing the means code, so call `payment()` with the means first.

```php
use Quittio\Core\Enums\PaymentMeansCode;

// SEPA credit transfer with two accounts
->payment(PaymentMeansCode::SepaCreditTransfer, iban: 'DE02 1203 0000 0000 2020 51', bic: 'BYLADEM1001', accountName: 'Muster GmbH', remittanceInformation: 'RE-2026-0042')
->creditTransfer('AT61 1904 3002 3457 3201', 'BKAUATWW')

// SEPA direct debit (replaces the seller's default account set by Quittio::invoice())
->payment(PaymentMeansCode::SepaDirectDebit, remittanceInformation: 'RE-2026-0042')
->directDebit('MANDATE-2026-17', creditorId: 'DE98ZZZ09999999999', debitedIban: 'DE02120300000000202051')

// Paid by credit card
->payment(PaymentMeansCode::CreditCard, meansText: 'Paid online')
->paymentCard('4242', 'Max Muster')
```

The payment means codes come from the code list UNTDID 4461:

| `PaymentMeansCode` | Code | Data |
|---|---|---|
| `NotDefined` | 1 | – |
| `Cash` | 10 | – |
| `Cheque` | 20 | – |
| `CreditTransfer` | 30 | account required (BR-61) |
| `DebitTransfer` | 31 | – |
| `PaymentToBankAccount` | 42 | account |
| `BankCard` | 48 | card |
| `DirectDebit` | 49 | direct debit |
| `CreditCard` | 54 | card |
| `DebitCard` | 55 | card |
| `StandingAgreement` | 57 | – |
| `SepaCreditTransfer` | 58 | account required (BR-61) |
| `SepaDirectDebit` | 59 | direct debit; XRechnung requires creditor identifier and debited account (BR-DE-30, BR-DE-31) |
| `OnlinePaymentService` | 68 | – |
| `Clearing` | 97 | – |
| `MutuallyDefined` | ZZZ | – |

XRechnung requires payment instructions (BR-DE-1). Profile EXTENDED can carry further payment instructions (`ExtendedData::$additionalPaymentInstructions`, [Extensions](#extensions)).

### Payment terms

Set the payment terms (BT-20) with these methods. Each continues the terms set before, also those of `InvoiceBuilder::from()` or of the seller's defaults:

| Method | Description |
|---|---|
| `dueInDays(int $days)` | Payment target: the calculator sets the due date (BT-9) to issue date + days, unless `dueDate()` is set. |
| `cashDiscount(int $days, string $percent, ?string $baseAmount = null)` | Cash discount ("Skonto") for payment within `$days` days after the issue date, optionally on a base amount. Repeatable for several tiers. The percentage is rounded to two decimals. |
| `lateFee(?int $days = null, ?string $percent = null, ?string $text = null)` | Late payment terms. |
| `paymentTerms(PaymentTerms\|string\|callable $terms)` | A text or a `PaymentTerms` object replaces the earlier terms, e.g. to start over. A callable receives the `PaymentTermsBuilder` (`text()` and the three methods above) and continues them. |

```php
->dueInDays(30)
->cashDiscount(days: 10, percent: '3.00')
->cashDiscount(days: 20, percent: '2.00', baseAmount: '1000.00')
```

`InvoiceBuilder::from($draft)->cashDiscount(days: 7, percent: '2.00')` adds a cash discount to the terms of the draft.

A cash discount is an agreed reduction of the price, so it must be part of the XML, not only of the PDF. Quittio therefore writes the terms into the text BT-20, generated in the language of the rendering run:

- Without `text()`, `dueInDays()` adds "Payable within 30 days without deduction." (German: "Zahlbar innerhalb von 30 Tagen ohne Abzug.").
- Each cash discount adds a line such as "3.00 % cash discount for payment within 10 days (until 2026-10-13)." (German: "3 % Skonto bei Zahlung innerhalb von 10 Tagen (bis 13.10.2026)."). The late fee text follows.
- XRechnung uses its structured notation instead (BR-DE-18): the texts first, then one line `#SKONTO#TAGE=10#PROZENT=3.00#` per cash discount (German for cash discount, days and percent), each closed by a line break. A base amount adds `BASISBETRAG=1000.00#`.
- Profile EXTENDED additionally writes the cash discounts (one payment terms group per tier) and the late fee days and percentage as structured CII elements.

### Prepaid amounts and rounding

| Method | BT | Description |
|---|---|---|
| `prepaid(BigDecimal\|int\|string $amount)` | BT-113 | Amount already paid, e.g. received advance payments; replaces an earlier value. |
| `addPrepaid(BigDecimal\|int\|string $amount)` | BT-113 | Adds to the amount already paid, e.g. one of several advance payments. |
| `rounding(BigDecimal\|int\|string $amount)` | BT-114 | Rounding amount, e.g. cash rounding to 0.05. Never use it to fix wrong totals. |

The amount due is BT-115 = gross total − BT-113 + BT-114. For final invoices, the German pack offers `deductAdvance($advanceInvoice)`: it references the advance invoice and adds its gross amount to BT-113 ([Builder macros](country-packs.md#builder-macros)).

## References

References link the invoice to other documents, e.g. the buyer's purchase order or a contract. All of them are optional in EN 16931. The one you need most often is the buyer reference (BT-10): XRechnung requires it, and for German public buyers it holds the Leitweg-ID, the routing ID of the authority ([German terms](concepts.md#german-terms)).

| Method | BT | Description |
|---|---|---|
| `buyerReference(?string $reference)` | BT-10 | Buyer reference; required by XRechnung, for German public buyers the Leitweg-ID. |
| `projectReference(string $id, ?string $name = null)` | BT-11 | Project reference. CII requires a name and writes the identifier when none is given. |
| `contractReference(?string $reference)` | BT-12 | Contract reference. |
| `purchaseOrderReference(?string $reference)` | BT-13 | Purchase order reference of the buyer. |
| `salesOrderReference(?string $reference)` | BT-14 | Sales order reference of the seller. |
| `receivingAdviceReference(?string $reference)` | BT-15 | Receiving advice reference. |
| `despatchAdviceReference(?string $reference)` | BT-16 | Despatch advice (delivery note) reference. |
| `tenderReference(?string $reference)` | BT-17 | Tender or lot reference. |
| `invoicedObject(string $value, string\|RawCode\|null $scheme = null)` | BT-18 | Invoiced object identifier (e.g. a meter or subscription), with the scheme from the code list UNTDID 1153, written as `ReferenceTypeCode` (CII) or `schemeID` (UBL). |
| `buyerAccountingReference(?string $reference)` | BT-19 | Buyer's accounting reference (cost centre). |
| `precedingInvoice(string $number, Date\|DateTimeInterface\|string\|null $issueDate = null)` | BG-3 (BT-25, BT-26) | Reference to a preceding invoice; repeatable. CII carries only the first reference, UBL all of them. |

Lines have their own references (`orderLineReference()`, `buyerAccountingReference()`, `objectId()`, see [LineBuilder](#linebuilder)).

## Notes

`note(string $content, NoteSubjectCode|RawCode|string|null $subject = null)` adds an invoice note (BG-1): a free text (BT-22) with an optional subject code (BT-21). Call it several times for several notes. UBL writes a note with subject code as `#CODE#text`.

| `NoteSubjectCode` | Code |
|---|---|
| `GeneralInformation` | AAI |
| `PriceConditions` | AAK |
| `LegalInformation` | ABL |
| `Introduction` | ACY |
| `PaymentDetail` | PMD |
| `PaymentInformation` | PMT |
| `RegulatoryInformation` | REG |
| `SupplierRemarks` | SUR |
| `TaxDeclaration` | TXD |

Cancellations take over the notes with subject `REG` and `TXD` of the original ([Cancellations and corrections](#cancellations-and-corrections)).

## Supporting documents

`attachment(string $id, ?string $content = null, MimeType|string|null $mimeType = null, ?string $filename = null, ?string $description = null, ?string $externalUri = null)` adds a supporting document (BG-24), e.g. a timesheet. It is repeatable. A supporting document is either embedded or a link:

- Embedded: `$content` are the raw bytes; the XML carries them base64 encoded. An embedded document requires MIME type and file name (`InvalidArgumentException` otherwise).
- Link: `$externalUri`.

```php
use Quittio\Core\Enums\MimeType;

->attachment('Timesheet-09', file_get_contents($path), MimeType::Pdf, 'timesheet-2026-09.pdf', 'Timesheet September')
->attachment('Contract', externalUri: 'https://example.com/contracts/4711.pdf', description: 'Framework contract')
```

- `MimeType` has `Pdf` (application/pdf), `Png` (image/png), `Jpeg` (image/jpeg), `Csv` (text/csv), `Xlsx` (application/vnd.openxmlformats-officedocument.spreadsheetml.sheet) and `Ods` (application/vnd.oasis.opendocument.spreadsheet), the MIME codes EN 16931 allows. `Xml` (application/xml) is allowed only in the profile `xrechnung-extension` (BR-DEX-01); other profiles report it with `BR-CL-24`. Another string fails with a `ValueError`.
- Supporting documents are part of the XML from profile EN 16931 upwards, not in the profiles MINIMUM, BASIC WL and BASIC. They are not separate files in the PDF ([Formats and profiles](formats.md#embedded-files)).
- XRechnung requires unique file names (BR-DE-22).

## Presentation and metadata

These methods set the design of the PDF and HTML, the language of the invoice and data for templates and country packs.

| Method | Description |
|---|---|
| `presentation(Presentation\|array\|callable $presentation)` | Design of this invoice in PDF and HTML: logo, colours, font, texts, footer, line groups, gross prices, payment QR code, watermark, paper, labels, title … A `Presentation` replaces it, an array changes single properties (`['introText' => '…']`), a callable receives the current `Presentation` (or an empty one) and returns the new one. See [Templates and design](templates.md). |
| `group(string $id, string $title, bool $showSubtotal = true)` | Defines a line group of the PDF; lines join it with `LineBuilder::group($id)`. |
| `locale(string $locale)` | Language of the invoice: labels, generated texts, exemption texts of presets. Rendering uses the locale of the call, then this one, then the configured locale, then the country pack default. |
| `timezone(string $timezone)` | Time zone for dates (see [Dates](#dates)). |
| `custom(string $key, mixed $value)` | Free data for templates (view model key `meta.custom`). |
| `source(string $source)` | Origin of the data, e.g. `shop` (view model key `meta.source`). |
| `flag(string $flag, bool $enabled = true)` | Sets or removes a flag that country packs evaluate, e.g. `Meta::FLAG_SMALL_BUSINESS` (`small_business`) or the German `Quittio\Country\DE\Support\Flags::SMALL_AMOUNT` (`de.small_amount`, see [Meta flags](country-packs.md#meta-flags)). |

```php
use Quittio\Core\Builder\Line;
use Quittio\Country\DE\Tax\VatPreset;

->presentation(['introText' => 'Thank you for your order.', 'showGrossPrices' => true])
->group('hardware', 'Hardware')
->line(Line::make('Notebook', netPrice: '1200.00', vat: VatPreset::Standard)->group('hardware'))   // group(): Line::make()
```

Quittio sets some flags itself; do not set them: `declared_amounts`, `declared_amounts_recalculated`, `cancellation_vat_aligned` and those with the prefixes `amounts_fingerprint:` and `declared_vat_in_tax_currency:` ([Declared amounts](#declared-amounts)). They are the stored format of the declared amounts: Quittio reads them in any order and writes them after the other flags in a fixed order, the fingerprint last.

## Application models

Your own models can fill the builder, so a customer becomes the buyer and order items become lines. Implement one of three contracts in `Quittio\Core\Contracts`:

| Contract | Method | Used by |
|---|---|---|
| `ProvidesParty` | `fillParty(PartyBuilder $party): void` | `InvoiceBuilder::buyer($model)` and `seller($model)`, `PartyBuilder::fromModel($model)`, e.g. a customer or user as buyer |
| `ProvidesInvoiceLine` | `fillLine(LineBuilder $line): void` | `InvoiceBuilder::line($model)`, `lines($models)`, `LineBuilder::fromModel($model)` |
| `ProvidesInvoiceData` | `toInvoiceData(): InvoiceData` | `Quittio::make($model)` (core and Laravel), `Invoice::draftFor($model)` and `HasInvoices::draftInvoice()` in the [Managed layer](managed.md) |

```php
use Quittio\Core\Builder\LineBuilder;
use Quittio\Core\Contracts\ProvidesInvoiceData;
use Quittio\Core\Contracts\ProvidesInvoiceLine;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Enums\UnitCode;
use Quittio\Country\DE\Tax\VatPreset;
use Quittio\Laravel\Facades\Quittio;

class OrderItem extends Model implements ProvidesInvoiceLine
{
    public function fillLine(LineBuilder $line): void
    {
        $line->id((string) $this->position)
            ->name($this->product_name)
            ->sellerId($this->sku)
            ->quantity((string) $this->quantity, UnitCode::Piece)
            ->netPrice($this->unit_price)                       // decimal string from the database
            ->vat(VatPreset::from($this->tax_preset));          // stored preset key, e.g. "de.standard"
    }
}

class Order extends Model implements ProvidesInvoiceData
{
    public function toInvoiceData(): InvoiceData
    {
        return Quittio::invoice()
            ->number($this->invoice_number)
            ->buyer($this->customer)                            // ProvidesParty
            ->lines($this->items)
            ->build();
    }
}
```

`fillParty()` and `fillLine()` may set any value. `buyer($model)`, `seller($model)`, `line($model)` and `lines($models)` pass them a fresh builder. `PartyBuilder::fromModel()` and `LineBuilder::fromModel()` pass the builder they are called on, e.g. the builder of the party set before inside a `buyer()` callable. The invoice keeps a copy, so changing the model later does not change invoices that were already built. Store amounts as decimal strings (database `decimal` columns), not as floats.

## Currency and exchange rate

Invoices are in euros by default. Set another invoice currency with `currency()`; all amounts of the invoice are then in that currency. If the VAT must also be stated in another currency, the VAT accounting currency (BT-6), set it with `taxCurrency()` together with the exchange rate.

| Method | BT | Description |
|---|---|---|
| `currency(string $currency)` | BT-5 | Invoice currency (ISO 4217, uppercased), default `EUR`. All amounts of the invoice are in this currency. |
| `taxCurrency(string $currency, BigDecimal\|int\|string\|null $exchangeRate = null, Date\|DateTimeInterface\|string\|null $rateDate = null)` | BT-6 | VAT accounting currency; with a rate, also sets `exchangeRate()`. |
| `exchangeRate(BigDecimal\|int\|string $rate, Date\|DateTimeInterface\|string\|null $date = null)` | – | Units of the VAT accounting currency per unit of the invoice currency. |

```php
->currency('USD')
->taxCurrency('EUR', exchangeRate: '0.9234', rateDate: '2026-10-02')
```

The calculator then sets the VAT total in accounting currency: BT-111 = round2(BT-110 × rate), where BT-110 is the VAT total in the invoice currency.

- Without a rate, BT-111 stays empty (except for a VAT total of zero) and the validation fails with BR-53.
- BT-6 is written only if it differs from BT-5.
- The rate itself appears in the XML only in profile EXTENDED (CII `TaxApplicableTradeCurrencyExchange`).

Data read from XML has no exchange rate; see [Declared amounts](#declared-amounts) for how `from()` handles it.

## Allowances and charges

An allowance reduces an amount, a charge increases it, e.g. a discount or a shipping fee. Both exist on two levels:

- **Line allowances and charges** (BG-27, BG-28) change the net amount of one line. They need `Line::make()`.
- **Document level allowances and charges** (BG-20, BG-21) belong to one VAT group of the whole invoice. For a discount on the whole invoice, `discountPercent()` and `discountAmount()` split it over the VAT groups for you.

Each one has an amount, or a percentage of a base amount. EN 16931 requires a reason text or a reason code ([Reason codes](#reason-codes)). Invoices with prices including VAT cannot have allowances or charges ([Gross price mode](#gross-price-mode)).

### Line allowances and charges

A line allowance or charge has an amount, or a percentage of a base amount. Without a base amount, the base is the line amount before allowances and charges (quantity × price); the calculator writes it to BT-137/BT-142.

```php
use Quittio\Core\Builder\Line;
use Quittio\Country\DE\Tax\VatPreset;

// Line allowances and charges need Line::make()
->line(Line::make('Seminar', netPrice: '400.00', quantity: 3, vat: VatPreset::Standard)
    ->allowance(percent: '10', reason: 'Early bird')       // 120.00 of 1200.00
    ->charge('25.00', reason: 'Materials', reasonCode: 'ABK'))
```

### Document level allowances and charges

| Method | Description |
|---|---|
| `allowance(VatCategory $category, BigDecimal\|int\|string\|null $rate, ?string $amount = null, ?string $percent = null, ?string $reason = null, AllowanceReasonCode\|RawCode\|string\|null $reasonCode = null, ?string $baseAmount = null, ?string $exemptionReason = null, VatExemptionReasonCode\|RawCode\|string\|null $exemptionReasonCode = null)` | Document level allowance (BG-20: BT-92 to BT-98) for one VAT category and rate. |
| `charge(VatCategory $category, BigDecimal\|int\|string\|null $rate, ?string $amount = null, ?string $percent = null, ?string $reason = null, ChargeReasonCode\|RawCode\|string\|null $reasonCode = null, ?string $baseAmount = null, ?string $exemptionReason = null, VatExemptionReasonCode\|RawCode\|string\|null $exemptionReasonCode = null)` | Document level charge (BG-21: BT-99 to BT-105). |
| `discountPercent(BigDecimal\|int\|string $percent, ?string $reason = null, AllowanceReasonCode\|RawCode\|string\|null $reasonCode = AllowanceReasonCode::Discount)` | Percentage discount on all lines, split into one allowance per VAT group. |
| `discountAmount(BigDecimal\|int\|string $amount, ?string $reason = null, AllowanceReasonCode\|RawCode\|string\|null $reasonCode = AllowanceReasonCode::Discount)` | Absolute discount, distributed over the VAT groups in proportion to their line sums. |

A document level allowance or charge belongs to exactly one VAT group, given by category and rate. Without a base amount, the base is the sum of the line net amounts of its VAT group; the calculator writes it to BT-93/BT-100. Its exemption reason is used for the VAT breakdown as described in [Exemption reasons in the VAT breakdown](#exemption-reasons-in-the-vat-breakdown).

```php
use Quittio\Core\Enums\ChargeReasonCode;
use Quittio\Core\Enums\VatCategory;

->charge(VatCategory::StandardRate, '19', amount: '9.90', reason: 'Shipping', reasonCode: ChargeReasonCode::Freight)
->allowance(VatCategory::StandardRate, '19', percent: '5', reason: 'Loyalty discount')
->discountPercent('3', 'Framework agreement')                     // one allowance per VAT group
```

`discountPercent()` and `discountAmount()` split the discount when the invoice is built ([Discount splitting](#discount-splitting)):

- Each VAT group gets one allowance, in proportion to the signed line sum of the group. A group with a negative sum (only returns) receives a negative part.
- Rounding differences are distributed with the largest remainder method, so the parts add up exactly to the discount.
- A part keeps percentage and base amount only if it equals round2(base × percentage / 100). Otherwise only the amount is written, because validators check that formula (e.g. PEPPOL-EN16931-R040).
- A discount of 0 adds nothing. A discount on lines that add up to zero throws a `CalculationException`.

### Reason codes

Use these codes for the `reasonCode` of allowances and charges on both levels. Other codes of the lists can be passed as strings.

- `AllowanceReasonCode` (code list UNTDID 5189): `BonusForWorksAheadOfSchedule` (41), `OtherBonus` (42), `ManufacturersConsumerDiscount` (60), `DueToMilitaryStatus` (62), `DueToWorkAccident` (63), `SpecialAgreement` (64), `ProductionErrorDiscount` (65), `NewOutletDiscount` (66), `SampleDiscount` (67), `EndOfRangeDiscount` (68), `IncotermDiscount` (70), `PointOfSalesThresholdAllowance` (71), `MaterialSurchargeDeduction` (88), `Discount` (95), `SpecialRebate` (100), `FixedLongTerm` (102), `Temporary` (103), `Standard` (104), `YearlyTurnover` (105).
- `ChargeReasonCode` (code list UNTDID 7161): `Advertising` (AA), `Telecommunication` (AAA), `Miscellaneous` (ABK), `AdditionalPackaging` (ABL), `OtherServices` (ADR), `PickUp` (ADT), `Delivery` (DL), `Freight` (FC), `Financing` (FI), `Labelling` (LA), `Packing` (PC), `SpecialHandling` (SH).

## Sub lines

Sub lines divide a line into parts, e.g. a workstation that consists of a computer and a monitor. The parent line needs `Line::make()`. `subLine()` takes the same forms as `line()`, usually the name with named values:

```php
use Quittio\Core\Builder\Line;
use Quittio\Country\DE\Tax\VatPreset;

->line(Line::make('Workstation', vat: VatPreset::Standard)
    ->subLine('Computer', netPrice: '900.00', vat: VatPreset::Standard)
    ->subLine('Monitor', netPrice: '250.00', quantity: 2, vat: VatPreset::Standard))
```

- A parent line without quantity has the quantity 1. Without its own price, it takes the sum of its sub lines as price.
- Sub lines exist only in two profiles: EXTENDED (CII, with parent line identifier and line status `GROUP`/`DETAIL`) and the XRechnung extension (UBL only, `->format('xrechnung-ubl')->profile('xrechnung-extension')`).
- The automatic format choice upgrades ZUGFeRD and CII to EXTENDED ([Automatic choice](formats.md#automatic-choice)). Other profiles reject sub lines (`QUITTIO-PROFILE-SUBLINES`).

## Gross price mode

Shops and B2C invoices often work with prices including VAT. Give every line a `priceInclVat` instead of a `netPrice`. The calculator then derives the net prices so that the customer pays exactly the gross amount ([Gross price conversion](#gross-price-conversion)):

```php
use Quittio\Country\DE\Tax\VatPreset;
use Quittio\Laravel\Facades\Quittio;

Quittio::invoice()
    // ...
    ->line('T-shirt', priceInclVat: '19.99', quantity: 3, vat: VatPreset::Standard)
    ->line('Shipping', priceInclVat: '4.90', vat: VatPreset::Standard)
    ->line('Voucher', priceInclVat: '5.00', quantity: -1, vat: VatPreset::Standard)
    ->build();
```

Restrictions:

- All lines of the invoice use `priceInclVat()`.
- Gross price lines have no line allowances, charges or sub lines.
- The invoice has no document level allowances or charges. Model shipping and discounts as lines, a discount with a negative quantity.

Violations throw a `CalculationException` when the invoice is calculated.

To change a price later, `InvoiceLine::withPriceInclVat()` changes a gross price line and `withNetPrice()` a net price line. Each throws an `InvalidArgumentException` for a line of the other mode.

## Changing existing data

`InvoiceBuilder::from(InvoiceData $data)` takes over every value of the data, including lines, document allowances and charges, payment data, presentation and metadata. Drafts, stored invoices and read invoices can then be changed with the normal builder methods. Existing lines are changed with `updateLine()` and removed with `removeLine()`:

```php
use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Builder\PartyBuilder;
use Quittio\Core\Data\Lines\InvoiceLine;
use Quittio\Country\DE\Tax\VatPreset;

$changed = InvoiceBuilder::from($draft)
    ->issueDate('2026-10-20')
    ->buyer(fn (PartyBuilder $b) => $b->contact('Lena Kunde', null, 'lena@kunde.de'))   // changes the existing buyer
    ->updateLine('2', netPrice: '80.00', description: 'Price adjusted')
    ->updateLine('4', fn (InvoiceLine $line) => $line->with(['note' => 'Delivered in two parts']))
    ->removeLine('3')
    ->line('Travel expenses', netPrice: '120.00', vat: VatPreset::Standard)
    ->build();
```

`updateLine(string $id, ?callable $change = null, netPrice, quantity, priceInclVat, name, description)` changes a line in one of two ways: with the named values (`->updateLine('2', netPrice: '80.00', quantity: 3)`), or with a callable that receives the `InvoiceLine` and returns the changed one. `removeLine(string $id)` removes a line by its identifier (BT-126, [Line identifiers](#line-identifiers)).

Both work only on lines that already exist as `InvoiceLine`: the lines of data passed to `InvoiceBuilder::from()` and lines added as `InvoiceLine`. Lines added in the other forms are built only in `build()`, so they cannot be changed or removed by identifier. An unknown identifier throws an `InvalidArgumentException`.

What happens to the amounts:

- Totals and the VAT breakdown are not taken over (except [declared amounts](#declared-amounts)); the result is calculated again when rendered.
- Percentage document allowances and charges whose base is the line sum of their VAT group follow the lines when lines are added, changed or removed. So do discounts that `discountPercent()` or `discountAmount()` split over the VAT groups. They are recalculated or split again: a VAT group without lines keeps no allowance of 0.00, and a new VAT group gets its part.
- `updateLine()` resets the line net amount and recalculates percentage line allowances and charges from the new line amount. A base amount that equalled the previous line amount follows the new one.
- `InvoiceLine::withNetPrice()` drops list price and discount. It throws for a line with a price including VAT, and `withPriceInclVat()` throws for a line with a net price (the gross price mode applies to all lines).
- `dueInDays()`, `cashDiscount()` and `lateFee()` continue the existing payment terms; a text passed to `paymentTerms()` replaces them. Seller and buyer callables continue the existing parties.

## Cancellations and corrections

A cancellation ("Stornorechnung", cancellation invoice) is a new invoice with its own number. It reverses an issued invoice and refers to it (BG-3); the original is never changed. Build it with `cancellation()` of the `Quittio` instance or the Laravel facade. It returns an `InvoiceBuilder`:

```php
use Quittio\Laravel\Facades\Quittio;

$builder = $quittio->cancellation($original, 'ST-2026-0001', reason: 'Charged twice');   // core instance
$builder = Quittio::cancellation($original, 'ST-2026-0001', reason: 'Charged twice');    // Laravel facade

$result = $quittio->make($builder)->render();
```

In Laravel, a rendered invoice can also be cancelled with `$result->cancel(…)` ([Getting started](getting-started.md#cancellation)).

`cancellation(InvoiceData $original, string $number, ?string $reason = null, ?string $strategy = null, ?Date $issueDate = null, ?array $lines = null, ?CancellationHistory $history = null, ?string $title = null): InvoiceBuilder`

| Parameter | Description |
|---|---|
| `$original` | The issued invoice, calculated (e.g. `RenderedInvoice::data()`, a stored snapshot or read data). A cancellation reverses its amounts exactly, also amounts another system rounded ([declared amounts](#declared-amounts)). |
| `$number` | Number of the cancellation. |
| `$reason` | Appended to the cancellation note ("Reason: …"). |
| `$strategy` | `credit_note` or `negative_invoice` (`CorrectionPolicy::CANCELLATION_CREDIT_NOTE`, `CANCELLATION_NEGATIVE_INVOICE`, see [Sign strategies](#sign-strategies)). Default: the configured `corrections.cancellation_strategy`, then the country pack (Germany: `credit_note`). Another value throws an `InvalidArgumentException`. |
| `$issueDate` | A `Date` (`Date::parse('2026-10-20')`), default today in the time zone of the original. |
| `$lines` | Partial cancellation ([below](#partial-cancellations)); `null` cancels the whole invoice. |
| `$history` | Earlier partial cancellations of the original, for the cumulative VAT rounding ([below](#cumulative-vat-rounding)). |
| `$title` | Document title in PDF, HTML and file name (`Presentation::$title`), e.g. "Stornorechnung". Default: the title `cancellation` of the country pack's correction policy (`cancellation_self_billed` for self-billed originals); without one, the label of the type code. |

The leading country pack of the original ([Country packs](country-packs.md)) provides the correction policy and the locale of the texts: the locale of the original, then the configured locale, then the pack default.

Without a `Quittio` instance, `Cancellation::of(InvoiceData $original, string $number, string $strategy = 'credit_note', ?string $reason = null, ?Date $issueDate = null, ?string $locale = null, ?string $title = null, ?array $lines = null, ?CorrectionPolicy $policy = null, ?CancellationHistory $history = null)` (`Quittio\Core\Corrections\Cancellation`) does the same. Note the different parameter order, and that without `$title` or `$policy` the document title comes from the type code.

The cancellation is a copy of the original with these changes:

| Field | Value |
|---|---|
| Number, issue date | `$number`, `$issueDate` |
| Type code | by strategy, see [Sign strategies](#sign-strategies) |
| Preceding invoice (BG-3) | the original's number and issue date (replaces earlier references) |
| Notes (BG-1) | "Stornierung der Rechnung RE-2026-0042 vom 03.10.2026." ("Cancellation of invoice … of …"; for self-billed originals "Stornierung der Gutschrift …", "Cancellation of the self-billed invoice …") with subject `AAI`, plus the reason. The original's notes with subject `REG` and `TXD` are kept, others dropped. |
| Payment terms, due date | "Der Betrag wird mit offenen Forderungen verrechnet oder erstattet." ("The amount will be offset against open items or refunded."); no due date |
| Lines, allowances, charges | all lines with document allowances and charges, or the selected lines |
| Prepaid amount BT-113, rounding amount BT-114, third party payments | taken over by a full cancellation, so that the amounts due match; not by a partial one |
| Title in PDF and file name | from the correction policy (Germany: "Stornorechnung", cancellation invoice; for self-billed originals "Storno-Gutschrift", cancellation of the self-billed invoice) |

Everything else stays as in the original: parties, references, payment instructions, delivery, currency, attachments and presentation. The result is an `InvoiceBuilder`, so you can change it before rendering, e.g. `->note('…')` or `->payment(PaymentMeansCode::SepaCreditTransfer, iban: '…')` for the refund account. Changing lines or allowances recalculates the amounts.

### Sign strategies

A cancellation reverses the amounts in one of two ways: as a credit note with positive amounts (`credit_note`) or as an invoice with negative amounts (`negative_invoice`).

| Original | `credit_note` | `negative_invoice` |
|---|---|---|
| Invoice (380, 384, 326 …) | 381, positive amounts | 380, negative quantities and amounts |
| Credit note (`isCreditNote()`) | 380, positive amounts | 380, positive amounts |
| Self-billed invoice (389) or debit note (527) | 261, positive amounts | 389, negative quantities and amounts |
| Self-billed credit note (261) | 389, positive amounts | 389, positive amounts |

- A credit note is reversed by an invoice with the same positive amounts in both strategies; a second credit note would credit twice.
- With `negative_invoice`, the quantities, line net amounts, line and document allowances and charges (amounts and base amounts), BT-113, BT-114 and third party payments are negated. Prices stay positive (BR-27).
- Self-billed documents stay self-billed.
- Both strategies are permitted in Germany. The German pack titles the document "Stornorechnung", never "Gutschrift" (in German a self-billed invoice), except for self-billing.
- UBL writes a cancellation with `credit_note` as `CreditNote`. The XRechnung extension has no UBL credit note: use `negative_invoice` there.

### Partial cancellations

Pass `lines:` to cancel only some lines, quantities or amounts:

```php
$builder = $quittio->cancellation($original, 'ST-2026-0002', reason: 'Partial return', lines: [
    ['line' => '3'],                         // the whole line
    ['line' => '1', 'quantity' => '1'],      // 1 of 3
    ['line' => '2', 'amount' => '50.00'],    // an amount, e.g. a price reduction
]);
```

- Each entry names a line identifier (BT-126) once, with a `quantity`, an `amount` or neither (the whole line). Quantity and amount together throw an `InvalidArgumentException`, as do an unknown line or an empty list.
- Quantities and amounts are magnitudes: a negative line (discount, return) keeps its sign and is reversed with it.
- A quantity must be greater than 0 and at most the invoiced quantity. Line allowances and charges are scaled in proportion. Lines with sub lines can only be cancelled as a whole.
- An amount becomes one unit (C62) at that net price, without allowances and charges. It must be greater than 0 and at most the line net amount, so the original must be calculated. For lines with a price including VAT, the amount includes VAT and is compared with the gross line amount.
- Whole lines keep the original's declared net amounts; quantities and amounts are recalculated.
- Invoices with document level allowances or charges cannot be cancelled partially, because these cannot be assigned to single lines reliably. Cancel fully and issue a new invoice.
- Each part is rounded on its own: three cancellations of one unit of a line of 29.00 net for 3 units give 9.67 each. Cancel the remainder of a line as an amount (`['line' => '1', 'amount' => '9.66']`), so that the parts add up exactly to the line. The Managed layer tracks this per line ([Managed layer](managed.md#cancellations-and-corrections)).

### Cumulative VAT rounding

VAT is rounded per document and VAT group. If each partial cancellation is rounded on its own, the parts together can differ from the original by a cent per part. Example: three parts of 9.67, 9.67 and 9.66 net at 19 % carry 3 × 1.84 = 5.52 VAT, the original 5.51.

With a `CancellationHistory` of the earlier partial cancellations, the VAT is rounded cumulatively per VAT group:

- S = taxable amount of the earlier cancellations + taxable amount of this one,
- target = round2(S × rate / 100), or the original's VAT BT-117 when this cancellation closes the group (all its lines are cancelled completely afterwards),
- VAT of this cancellation = target − VAT of the earlier cancellations.

The three parts then carry 1.84, 1.83 and 1.84 = 5.51.

- Every part differs by at most 0.01 from round2(own taxable amount × rate) and keeps the sign of its taxable amount.
- The validation reports the difference as information `QUITTIO-CANCELLATION-VAT-ALIGNED`, and the cancellation note explains the rounding: "Rounding adjustment: the VAT of this partial cancellation is set so that all partial cancellations of invoice RE-… together correct its VAT exactly." (German: "Rundungsausgleich: Die Umsatzsteuer dieses Teilstornos ist so bemessen, dass alle Teilstornos zur Rechnung RE-… zusammen deren Umsatzsteuer exakt berichtigen.").
- For an original whose VAT another system rounded (BT-117 ≠ round2(BT-116 × rate)), the target uses its effective rate BT-117 / BT-116, which spreads the difference over the parts. This does not apply to groups with positive and negative lines.
- In gross price mode, the taxable amounts follow the same way from the gross amounts cancelled so far, and the rounding amounts (BT-114) of all parts add up to the original's.
- BT-111 in accounting currency is aligned the same way at the original's exchange rate.
- Without a history, or for an original whose amounts are stale, the VAT is rounded per document.

The Managed layer keeps the history itself. Without it, build the history from the cancellations as issued:

```php
use Quittio\Core\Corrections\CancellationHistory;

// $original: 3 × 10.00 with a line allowance of 1.00 at 19 %, 29.00 net, 5.51 VAT
$history = new CancellationHistory;                         // no earlier partial cancellation

$first = $quittio->make($quittio->cancellation($original, 'ST-1', lines: [['line' => '1', 'quantity' => '1']], history: $history))->render();
$history = $history->withCancellation($original, $first->data());

$second = $quittio->make($quittio->cancellation($original, 'ST-2', lines: [['line' => '1', 'quantity' => '1']], history: $history))->render();
$history = $history->withCancellation($original, $second->data());

// The last part cancels what is left of line 1 and closes the VAT group S|19.00
$history = new CancellationHistory($history->groups, closes: ['S|19.00']);
$third = $quittio->make($quittio->cancellation($original, 'ST-3', lines: [['line' => '1', 'amount' => '9.66']], history: $history))->render();
```

| `CancellationHistory` | Description |
|---|---|
| `new CancellationHistory(array $groups = [], array $closes = [])` | `$groups`: per VAT group key (`DeclaredAmounts::breakdownKey()`, e.g. `S\|19.00`, `S\|7.00`, `E\|0.00`, `O\|`) the sums of the earlier cancellations as `BigDecimal`: `taxable` (BT-116), `tax` (BT-117), `taxInTaxCurrency` (share of BT-111, null if unknown) and in gross price mode `gross` (null or missing if unknown), in the original's direction. `$closes`: keys of the VAT groups whose lines are all cancelled completely once this cancellation is issued, including groups closed earlier. |
| `withCancellation(InvoiceData $original, InvoiceData $cancellation, array $closes = []): self` | The history after an issued (calculated) cancellation; `$closes` for the next one. |
| `CancellationHistory::entries(InvoiceData $original, InvoiceData $cancellation)` | The VAT groups of an issued cancellation in the original's direction. |
| `taxable(string $key)`, `tax(string $key)`, `gross(string $key)`, `closes(string $key)`, `taxInTaxCurrency()` | Values of the history. |
| `align(InvoiceData $original, InvoiceData $cancellation)` | The aligned VAT breakdown and BT-111 of a calculated partial cancellation (used by `cancellation()`). |
| `assertFits(InvoiceData $original, InvoiceData $cancellation, array $aligned)` | Throws an `InvalidArgumentException` for a history that does not fit the original: unknown VAT groups, more taxable amount than the original's lines, or a VAT more than 0.01 off or of the wrong sign. A history built with `withCancellation()` from the issued cancellations always fits. |
| `matches(InvoiceData $original, InvoiceData $cancellation): bool` | Whether an issued cancellation carries the VAT this history gives it; false e.g. after another partial cancellation was issued meanwhile. |

Amounts in the history are signed in the original's direction: a credit note that cancels an invoice and an invoice that cancels a credit note count with their amounts, a negative invoice with its amounts negated.

### Correction policy

A country pack defines its defaults for cancellations and corrections in `Quittio\Core\Countries\CorrectionPolicy`:

- `new CorrectionPolicy(string $cancellationStrategy = 'credit_note', string $correctionStrategy = 'cancel_and_reissue', array $titles = [])`, with the constants `CANCELLATION_CREDIT_NOTE`, `CANCELLATION_NEGATIVE_INVOICE`, `CORRECTION_CANCEL_AND_REISSUE` and `CORRECTION_CORRECTED_INVOICE`.
- `$titles` maps a document kind to titles per locale (`['cancellation' => ['de' => 'Stornorechnung', 'en' => 'Cancellation invoice']]`). `title(string $kind, string $locale = 'en')` and `cancellationTitle(bool $selfBilled, string $locale = 'en')` read them; self-billed originals use `cancellation_self_billed` if defined.
- The correction strategy is used by the Managed layer. In the core, correct an invoice by cancelling it and issuing a new one (`InvoiceBuilder::from($original)->number(…)`), or with a corrected invoice (type code 384, [Credit notes, corrected invoices and self-billing](#credit-notes-corrected-invoices-and-self-billing)).

## Extensions

Profile EXTENDED of ZUGFeRD/Factur-X carries more data than EN 16931, e.g. further parties, delivery terms or instalments ([Profiles](concepts.md#profiles)). Add it with `extended(ExtendedData $extended)` and, for lines, `LineBuilder::extended(LineExtendedData $extended)`.

- The data is written to CII in profile EXTENDED only.
- When you use it, the automatic choice switches ZUGFeRD and CII to EXTENDED. Other profiles report `QUITTIO-PROFILE-EXTENDED-ONLY`.

| `ExtendedData` (`Quittio\Core\Data\Extensions`) | Written as |
|---|---|
| `invoicer`, `invoicee` (`ExtendedParty`) | `InvoicerTradeParty`, `InvoiceeTradeParty` |
| `ultimateCustomer` (`ExtendedParty`) | `ProductEndUserTradeParty` |
| `shipFrom` (`ExtendedParty`) | `ShipFromTradeParty` |
| `deliveryTermsCode` (string, e.g. an Incoterms code of the international delivery terms such as `FCA`) | `ApplicableTradeDeliveryTerms/DeliveryTypeCode` |
| `additionalReferences` (list of `DocumentReference`) | `AdditionalReferencedDocument` with type code (default 916), name and date |
| `installments` (list of `Installment(Date $dueDate, ?Money $amount = null, ?string $description = null)`) | one payment terms group per instalment with due date and partial amount |
| `additionalPaymentInstructions` (list of `PaymentInstructions`) | further payment means |

| `LineExtendedData` | Written as |
|---|---|
| `lineStatusReasonCode` (`LineExtendedData::STATUS_DETAIL`, `STATUS_GROUP`, `STATUS_INFORMATION`) | Line status; default `GROUP` for lines with sub lines and `DETAIL` for sub lines |
| `shipTo` (`ExtendedParty`) | Ship-to party of the line |
| `deliveryDate` (`Date`) | Delivery date of the line |
| `includedProducts` (list of `IncludedProduct(string $name, ?BigDecimal $quantity = null, UnitCode\|RawCode\|null $unit = null, ?string $description = null, ?string $sellerId = null)`) | Included products |

`ExtendedParty(string $name, ?Address $address = null, array $identifiers = [], ?string $vatId = null, ?Contact $contact = null)` describes a further party.

A line `DocumentReference(string $id, string $kind = DocumentReference::TYPE_OTHER, ?string $lineId = null, ?Date $issueDate = null, ?RawCode $typeCode = null, ?string $description = null)` is added with `LineBuilder::reference()`. It is written by its kind: `TYPE_ORDER`, `TYPE_CONTRACT`, `TYPE_DESPATCH_ADVICE`, `TYPE_RECEIVING_ADVICE` or `TYPE_OTHER`.

```php
use Quittio\Core\Data\Extensions\ExtendedData;
use Quittio\Core\Data\Extensions\ExtendedParty;
use Quittio\Core\Data\Extensions\Installment;
use Quittio\Core\Data\Party\Address;
use Quittio\Core\Support\Amount;
use Quittio\Core\Support\Date;

->extended(new ExtendedData(
    ultimateCustomer: new ExtendedParty('Endkunde GmbH', Address::make('Weg 2', '12345', 'Musterstadt', 'DE')),
    deliveryTermsCode: 'FCA',
    installments: [
        new Installment(Date::parse('2026-11-01'), Amount::of('500.00', 'EUR')),
        new Installment(Date::parse('2026-12-01'), Amount::of('690.00', 'EUR')),
    ],
))
```

### XRechnung extension

The XRechnung extension is a profile of XRechnung with additional fields, such as sub lines and third party payments. `xrechnungExtension(XRechnungExtensionData $extension)` adds third party payments (BG-DEX-09): `new XRechnungExtensionData([new ThirdPartyPayment(string $type, Money $amount, string $description)])`.

- Third party payments are added to the amount due (BR-DEX-09).
- They can only be transmitted in an XRechnung UBL invoice with profile `xrechnung-extension` (UBL `cac:PrepaidPayment`). CII, credit notes and other profiles fail with `QUITTIO-THIRD-PARTY-PAYMENTS`.

## Macros

`InvoiceBuilder` and `LineBuilder` are macroable, so you can add your own methods:

- `InvoiceBuilder::macro(string $name, callable $macro)` adds a method; `hasMacro(string $name)` and `flushMacros()` inspect and remove them.
- The closure is bound to the builder, so `$this` and the builder's private properties are available.
- `InvoiceBuilder` can also be extended: `make()`, `from()` and `fromDocument()` return `static`.

Country packs add their helpers this way, e.g. `smallAmount()`, `selfBilled()` and `deductAdvance()` of the German pack ([Builder macros](country-packs.md#builder-macros)).

```php
use Quittio\Core\Builder\InvoiceBuilder;

InvoiceBuilder::macro('forProject', function (string $id, string $name): InvoiceBuilder {
    return $this->projectReference($id, $name)->note('Project: '.$name);
});

$builder->forProject('P-17', 'Customer portal');
```

## Calculation

`Quittio\Core\Calculation\En16931Calculator` calculates line net amounts, document allowances and charges, the VAT breakdown and the totals. Every render, check and prepare run calls it, so data from the builder never needs to be calculated by hand. To calculate without rendering, call it directly or use `prepare()` ([Formats and profiles](formats.md#rendering-in-the-core)):

```php
use Quittio\Core\Calculation\En16931Calculator;

$calculated = (new En16931Calculator)->calculate($invoice);
$calculated->isCalculated();                       // true
$calculated->totals->amountDue;                    // Brick\Money\Money
$calculated->vatBreakdown[0]->taxAmount;
```

Amounts are rounded half up to two decimals, only at the points listed below. VAT is calculated per VAT group (category and rate), never per line (BR-CO-17). Calculating calculated data again gives the same result.

### Line amounts

| Step | Formula |
|---|---|
| Net price BT-146 | as given; otherwise list price BT-148 − price discount BT-147 |
| Line amount before allowances and charges B | round2(quantity BT-129 × net price BT-146 / base quantity BT-149), the base quantity only if it is set and not 0 or 1 |
| Line allowance BT-136 | as given; otherwise round2((base BT-137, default B) × percentage BT-138 / 100) |
| Line charge BT-141 | as given; otherwise round2((base BT-142, default B) × percentage BT-143 / 100) |
| Line net amount BT-131 | B − Σ line allowances + Σ line charges |

A line with sub lines and without its own price takes the sum of the net amounts of its sub lines as line amount. The price is derived from it with up to ten decimals.

`LineCalculator` (`calculate(InvoiceLine $line, string $currency)`) does this per line. Its static helpers are:

- `baseAmount($quantity, $netPrice, $baseQuantity)`: B;
- `exactAmount(InvoiceLine $line)`: the unrounded line amount that PEPPOL-EN16931-R120 checks;
- `priceFor($amount, $quantity, $baseQuantity)`: the shortest net price, 2 to 10 decimals, for which the line formula yields an amount.

### Document totals and VAT breakdown

| Step | Formula |
|---|---|
| Document allowance BT-92 / charge BT-99 | as given; otherwise round2((base BT-93/BT-100, default the line net sum of its VAT group) × percentage / 100) |
| Taxable amount BT-116 per VAT group | Σ line net amounts − Σ allowances + Σ charges of the group |
| VAT amount BT-117 per VAT group | round2(BT-116 × rate / 100); 0 for categories Z, E, AE, K, G and O |
| Sum of line net amounts BT-106 | Σ BT-131 |
| Sum of allowances BT-107, charges BT-108 | Σ document allowances, Σ document charges (empty if there are none) |
| Total without VAT BT-109 | BT-106 − BT-107 + BT-108 |
| VAT total BT-110 | Σ BT-117 |
| VAT total in accounting currency BT-111 | round2(BT-110 × exchange rate), only if BT-6 differs from BT-5 |
| Total with VAT BT-112 | BT-109 + BT-110 |
| Amount due BT-115 | BT-112 − BT-113 + BT-114, plus third party payments of the XRechnung extension |
| Due date BT-9 | issue date + `dueInDays()` if no due date is set |

The VAT groups appear in the breakdown in the order of their first line. A document allowance or charge for a group without lines adds its own group. A rounding amount of 0 is left out.

### Gross price conversion

`GrossPriceConverter` applies when a line has a price including VAT (`priceInclVat()`, [Gross price mode](#gross-price-mode)). Per VAT group:

1. G = Σ g_i with g_i = round2(quantity × price including VAT).
2. N = round2(G / (1 + rate / 100)), the taxable amount.
3. T = round2(N × rate / 100), the VAT (BR-CO-17).
4. Every line gets n_i = round2(g_i / (1 + rate / 100)); the cents N − Σ n_i go to the lines with the largest rounding remainders. A line keeps the sign of its gross amount (returns and vouchers stay negative). A cent never moves a line to zero or across zero while another line can take it. Net prices are derived with the precision needed for the line formula to yield n_i.
5. G − (N + T) of all groups becomes the rounding amount BT-114, so that the amount due equals the gross amount the customer saw.

Example: 3 × 9.99 including 19 % gives G = 29.97, N = 25.18, T = 4.78, net price 8.393, BT-114 = 0.01 and an amount due of 29.97. `GrossPriceConverter::netOf(BigDecimal $gross, BigDecimal $rate)` is step 2.

### Discount splitting

`AllowanceSplitter::splitAllowance(array $lines, string $currency, ?BigDecimal $percent, ?Money $amount, ?string $reason = null, AllowanceReasonCode|RawCode|null $reasonCode = null): list<DocumentAllowance>` implements `discountPercent()` and `discountAmount()`. The discount (the amount, or round2(line sum × percentage / 100)) is distributed over the VAT groups in proportion to their signed line sums with `LargestRemainder::distribute(BigDecimal $total, array $weights, int $scale = 2)`.

`LargestRemainder::distribute()` floors every part and gives the remaining cents to the parts with the largest remainders (ties in the order of the weights). It returns parts that add up exactly to the total. Weights that are all zero share equally; weights that add up to zero throw a `CalculationException`.

Example: a discount of 10.00 on 100.00 at 19 % and 50.00 at 7 % becomes 6.67 and 3.33.

### Declared amounts

Data that Quittio did not calculate itself can carry amounts that another system rounded differently, e.g. line net amounts rounded per line or a VAT amount within the tolerance of BR-CO-17. Recalculating such data would change amounts that were already issued, and a cancellation would no longer reverse the original exactly. Quittio therefore keeps these declared amounts in two cases:

- `InvoiceBuilder::from($data)` with data whose line net amounts or VAT breakdown the recalculation does not reproduce exactly (e.g. an invoice read from XML), as long as no amount-relevant input changes: lines, document allowances and charges, discounts and the currency. Changing a line, adding one or calling `allowance()` recalculates everything.
- Cancellations of such data ([Cancellations and corrections](#cancellations-and-corrections)).

The builder then sets `Meta::FLAG_DECLARED_AMOUNTS` with a fingerprint of the amount inputs (`DeclaredAmounts::fingerprint()`, an xxh128 hash over identifiers, quantities, prices, VAT groups, allowances, charges, net amounts, VAT breakdown and currency). The calculator keeps a declared amount within these tolerances:

| Declared amount | Kept if |
|---|---|
| Line net amount BT-131 | within 0.02 of the unrounded line formula (`DeclaredAmounts::LINE_TOLERANCE`, as PEPPOL-EN16931-R120 tolerates it); a parent line may also state the sum of its sub lines |
| VAT amount BT-117 | less than one currency unit from the recalculation (BR-CO-17); 0 for categories without VAT |
| VAT total in accounting currency BT-111 | within 0.01 (`DeclaredAmounts::TAX_CURRENCY_TOLERANCE`) |

- Amounts beyond these tolerances are recalculated, and the data gets `Meta::FLAG_DECLARED_AMOUNTS_RECALCULATED`. The validation reports it as `QUITTIO-DECLARED-AMOUNTS` (the document may not reverse the original exactly).
- Data whose amount inputs no longer match the fingerprint, e.g. changed with `with()` or edited in stored JSON, is recalculated completely.
- Data calculated by Quittio never needs declared amounts. The calculator also writes a fingerprint to every result, so `from()`, cancellations and the calculator never keep amounts that were changed afterwards.

The calculation policy decides what happens with declared amounts (`calculation.declared_amounts` in Laravel, `calculation.declared_amounts` in `Quittio::create()`, or `new En16931Calculator(policy: new CalculationPolicy(DeclaredAmountsMode::Recalculate))`):

| Mode | Declared amounts |
|---|---|
| `keep` (default) | kept within the tolerances above |
| `recalculate` | always recalculated, reported as `QUITTIO-DECLARED-AMOUNTS`; the aligned VAT of partial cancellations is kept |
| `reject` | kept within the tolerances, otherwise a `CalculationException` |

The policy belongs to the run, not to the data: the same stored invoice gives other amounts under another policy. `InvoiceBuilder::from()` compares the declared amounts with the default calculator; `Quittio::edit()` and cancellations use the calculator of the instance.

The helpers are public: `DeclaredAmounts::needed(InvoiceData $data)` (the recalculation does not reproduce the declared amounts), `isStale()` (changed after the amounts were calculated or declared), `isTrusted()` (flagged and fingerprint matches), `hasAmounts()` and `breakdownKey(VatCategory $category, ?BigDecimal $rate)` (VAT group key, e.g. `S|19.00`).

Data read from XML has no exchange rate. `InvoiceData::withImpliedExchangeRate()` returns a copy with the rate BT-111 / BT-110 (ten decimals). `from()` takes it over, so that the recalculation reproduces BT-111. It drops the rate once amounts or the VAT accounting currency change, because the rate is only as precise as the declared amounts. Set the rate with `exchangeRate()` then, otherwise the validation fails with BR-53.

## Data model

`Quittio\Core\Data\InvoiceData` is the root of the model. It is a superset of EN 16931 (versions 2017 and 2026) with the extensions of profile EXTENDED and the XRechnung extension. All data classes are `final readonly` with promoted constructor properties. The [field reference](field-reference.md) lists every field with business term, cardinality, profile level and XML paths.

| Property | Type | BT | Set with |
|---|---|---|---|
| `number` | `string` | BT-1 | `number()` |
| `issueDate` | `Date` | BT-2 | `issueDate()` |
| `typeCode` | `InvoiceTypeCode\|RawCode` | BT-3 | `type()` |
| `currency` | `string` | BT-5 | `currency()` |
| `seller` | `SellerParty` | BG-4 | `seller()` |
| `buyer` | `BuyerParty` | BG-7 | `buyer()` |
| `lines` | `list<InvoiceLine>` | BG-25 | `line()`, `lines()` |
| `taxCurrency` | `?string` | BT-6 | `taxCurrency()` |
| `taxPointDate`, `taxPointDateCode` | `?Date`, `TaxPointDateCode\|RawCode\|null` | BT-7, BT-8 | `taxPointDate()`, `taxPointDateCode()` |
| `dueDate` | `?Date` | BT-9 | `dueDate()`, calculator |
| `buyerReference`, `projectReference`, `contractReference`, `purchaseOrderReference`, `salesOrderReference`, `receivingAdviceReference`, `despatchAdviceReference`, `tenderReference`, `invoicedObject`, `buyerAccountingReference` | `?string`, `?ProjectReference`, `?Identifier` | BT-10 to BT-19 | [References](#references) |
| `paymentTerms` | `?PaymentTerms` | BT-20 | `paymentTerms()` |
| `notes` | `list<Note>` | BG-1 | `note()` |
| `businessProcess` | `?string` | BT-23 | `businessProcess()` |
| `precedingInvoices` | `list<PrecedingInvoiceReference>` | BG-3 | `precedingInvoice()` |
| `payee` | `?PayeeParty` | BG-10 | `payee()` |
| `sellerTaxRepresentative` | `?TaxRepresentativeParty` | BG-11 | `taxRepresentative()` |
| `delivery` | `?Delivery` | BG-13 | `deliveryDate()`, `deliverTo()`, `delivery()` |
| `invoicingPeriod` | `?Period` | BG-14 | `invoicingPeriod()` |
| `paymentInstructions` | `?PaymentInstructions` | BG-16 | `payment()`, `creditTransfer()`, `paymentCard()`, `directDebit()` |
| `allowances`, `charges` | `list<DocumentAllowance>`, `list<DocumentCharge>` | BG-20, BG-21 | `allowance()`, `charge()`, `discountPercent()`, `discountAmount()` |
| `totals` | `?Totals` | BG-22 | calculator |
| `vatBreakdown` | `list<VatBreakdown>` | BG-23 | calculator |
| `attachments` | `list<Attachment>` | BG-24 | `attachment()` |
| `prepaidAmount`, `roundingAmount` | `?Money` | input for BT-113, BT-114 | `prepaid()`, `addPrepaid()`, `rounding()` |
| `extended` | `?ExtendedData` | profile EXTENDED | `extended()` |
| `xrechnungExtension` | `?XRechnungExtensionData` | XRechnung extension | `xrechnungExtension()` |
| `presentation` | `?Presentation` | PDF and HTML only | `presentation()`, `group()` |
| `meta` | `Meta` | internal | `locale()`, `timezone()`, `custom()`, `source()`, `flag()`, `exchangeRate()` |
| `standardVersion` | `StandardVersion` | internal | `standard()` |

The parts live in sub namespaces of `Quittio\Core\Data`:

| Namespace | Classes |
|---|---|
| `Party` | `SellerParty`, `BuyerParty`, `PayeeParty`, `TaxRepresentativeParty`, `Address`, `Contact`, `ElectronicAddress` |
| `Lines` | `InvoiceLine`, `Item`, `Price`, `LineVat`, `Classification`, `ItemAttribute` |
| `AllowanceCharge` | `DocumentAllowance`, `DocumentCharge` (base `DocumentAllowanceCharge`), `LineAllowance`, `LineCharge` (base `LineAllowanceCharge`) |
| `Payment` | `PaymentInstructions`, `CreditTransfer`, `PaymentCard`, `DirectDebit`, `PaymentTerms`, `CashDiscount`, `PenaltyTerms` |
| `Delivery` | `Delivery`, `Period` |
| `References` | `PrecedingInvoiceReference`, `ProjectReference`, `DocumentReference` |
| `Totals` | `Totals`, `VatBreakdown` |
| `Attachments` | `Attachment` |
| `Extensions` | `ExtendedData`, `ExtendedParty`, `Installment`, `IncludedProduct`, `LineExtendedData`, `XRechnungExtensionData`, `ThirdPartyPayment` |
| `Presentation` | `Presentation`, `Colors`, `LineGroup`, `LinePresentation` |
| (root) | `InvoiceData`, `Identifier`, `Note`, `Meta` |

`Totals` has `lineNetSum` (BT-106), `allowanceSum` (BT-107), `chargeSum` (BT-108), `netTotal` (BT-109), `vatTotal` (BT-110), `vatTotalInTaxCurrency` (BT-111), `grossTotal` (BT-112), `prepaidAmount` (BT-113), `roundingAmount` (BT-114) and `amountDue` (BT-115), all `Brick\Money\Money`. A `VatBreakdown` has `taxableAmount` (BT-116), `taxAmount` (BT-117), `category` (BT-118), `rate` (BT-119), `exemptionReason` (BT-120) and `exemptionReasonCode` (BT-121).

Types: amounts are `Money` with two decimals; prices, quantities and rates are `Brick\Math\BigDecimal`; dates are `Quittio\Core\Support\Date` (`toIsoString()`, `format()`, `addDays()`, `toCarbon()`, `isBefore()` …).

### Immutability

Data objects never change. `with(array $changes)` returns a copy with the named properties replaced; an unknown property throws an `InvalidArgumentException`:

```php
$copy = $invoice->with(['buyerReference' => '04011000-12345-03']);
$copy = $invoice->withNumber('RE-2026-0043')->withIssueDate(Date::parse('2026-10-05'));
$line = $invoice->lines[0]->with(['note' => 'Delivered in two parts']);
```

`with()` does not recalculate anything and checks nothing. For changes of amounts, prefer `InvoiceBuilder::from()`, so that allowances and discounts follow the lines ([Changing existing data](#changing-existing-data)). Changing amount inputs of calculated data with `with()` makes its amounts stale: the next calculation recalculates them ([Declared amounts](#declared-amounts)).

Helpers of `InvoiceData`: `isCalculated()` (totals present), `typeCodeValue()` (BT-3 as string), `isCreditNote()`, `allLines()` (all lines including sub lines, depth first), `withImpliedExchangeRate()` and `InvoiceData::factory()` for test data ([Testing](testing.md)).

Helpers of the parts: `InvoiceLine::withNetPrice()`, `withPriceInclVat()`, `hasSubLines()`, `Price::isGrossMode()`, `LineVat::groupKey()`, `DocumentAllowanceCharge::isCharge()` and `reasonCodeValue()`, `PaymentInstructions::primaryCreditTransfer()`, `CreditTransfer::isIban()`, `PaymentTerms::isEmpty()`, `Period::isValid()`, `Attachment::size()`, `Identifier::schemeValue()`, `Meta::hasFlag()`, `Presentation::isExplicit()`.

### Arrays and JSON

`InvoiceData::toArray()`, `toJson(int $flags = 0)`, `InvoiceData::fromArray(array $data)` and `InvoiceData::fromJson(string $json)` convert the model losslessly, including totals, presentation and metadata:

```php
$json = $invoice->toJson(JSON_PRETTY_PRINT);
$same = InvoiceData::fromJson($json);
```

```json
{
    "number": "RE-1",
    "issueDate": "2026-10-03",
    "typeCode": "380",
    "currency": "EUR",
    "seller": {
        "name": "Muster GmbH",
        "address": { "countryCode": "DE", "line1": "Hauptstraße 1", "city": "Musterstadt", "postcode": "12345" },
        "vatId": "DE123456789"
    },
    "buyer": {
        "name": "Kunde AG",
        "address": { "countryCode": "DE", "line1": "Bahnhofstraße 5", "city": "Beispielstadt", "postcode": "54321" }
    },
    "lines": [
        {
            "id": "1",
            "quantity": "2",
            "unit": "HUR",
            "price": { "netPrice": "95.00" },
            "vat": { "category": "S", "rate": "19" },
            "item": { "name": "Beratung" }
        }
    ]
}
```

- Keys are the property names. Values equal to the constructor default are left out and restored on the way back.
- Amounts are strings with two decimals and without currency: `fromArray()` uses the invoice currency (BT-5), for BT-111 the VAT accounting currency (BT-6). Decimals are strings, dates `YYYY-MM-DD`, codes their value. The content of attachments is base64 encoded.
- `fromArray()` rejects unknown fields, missing required values, floats and values of the wrong type with a `Quittio\Core\Exceptions\HydrationException` that names the path, e.g. `lines[0].price.netPrice: Floats are not accepted. Pass monetary values and quantities as strings.` A code that is not a case of its enum becomes a `RawCode` where the property allows one.
- `toJson()` always uses `JSON_THROW_ON_ERROR`, `JSON_UNESCAPED_SLASHES` and `JSON_UNESCAPED_UNICODE`; pass further flags such as `JSON_PRETTY_PRINT`.

The JSON Schema (draft 2020-12, `$id` `https://quittio.dev/schema/invoice.schema.json`) is shipped in `packages/core/resources/schema/invoice.schema.json` (`JsonSchemaGenerator::defaultPath()`). With the optional package `opis/json-schema`, `Quittio\Core\Serialization\JsonSchemaValidator` checks input before hydration:

- `JsonSchemaValidator::available()` tells whether the package is installed.
- `(new JsonSchemaValidator)->validate(string $json)` returns up to 20 errors as `path: message`, or an empty list if the input is valid. Without the package it throws a `RuntimeException`.

### Snapshots

Stored invoices (the Managed layer, queued render jobs) use snapshots. A snapshot is `toSnapshot()`: `toArray()` plus the schema version `_version` (`InvoiceData::SNAPSHOT_VERSION`, currently 1).

`InvoiceData::fromSnapshot(array $snapshot, ?SnapshotUpcasterChain $chain = null)` brings a snapshot of an older version to the current model before hydrating it; a snapshot without `_version` counts as version 1. Snapshots of issued invoices are never rewritten: upcasters run in memory when a snapshot is read.

```php
use Quittio\Core\Serialization\Snapshots\SnapshotUpcaster;
use Quittio\Core\Serialization\Snapshots\SnapshotUpcasterChain;

final class SnapshotV1ToV2 implements SnapshotUpcaster
{
    public function from(): int
    {
        return 1;                                   // reads version 1, produces version 2
    }

    public function upcast(array $snapshot): array
    {
        // rename or convert the fields that changed between the versions
        return $snapshot;
    }
}

SnapshotUpcasterChain::useDefault(new SnapshotUpcasterChain([new SnapshotV1ToV2]));
```

- `SnapshotUpcasterChain` (`register(SnapshotUpcaster $upcaster)`, `upcast(array $snapshot, int $from, int $to = InvoiceData::SNAPSHOT_VERSION)`) applies one upcaster per version step.
- `SnapshotUpcasterChain::default()` is the shared chain of `fromSnapshot()`; `useDefault(?SnapshotUpcasterChain $chain)` replaces it.
- A snapshot of a newer version than the installed package, an invalid version or a missing upcaster throw a `SnapshotVersionException`.
- The current model is version 1, so no upcaster is needed yet; upcasters matter once a release changes the model. In Laravel, list the upcaster classes in `snapshots.upcasters` ([Configuration](configuration.md)).
