# Building invoices

`Quittio\Core\Builder\InvoiceBuilder` creates the immutable `InvoiceData`, the semantic invoice model of EN 16931. It is part of `quittio/core` and works with and without Laravel. This page is the reference for everything that creates or changes invoice data: every builder method, VAT, the calculation, cancellations in the core and the data model. In Laravel, start with `Quittio::invoice()`: the same builder with the seller of the configuration, which renders itself with `->render()` ([Starting invoices](laravel.md#starting-invoices)). The tutorial is [Getting started](getting-started.md); rendering the data is described in [Formats and profiles](formats.md) and [Laravel integration](laravel.md).

```php
use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Builder\Party;
use Quittio\Core\Builder\PaymentTermsBuilder;
use Quittio\Core\Enums\PaymentMeansCode;
use Quittio\Core\Enums\UnitCode;
use Quittio\Country\DE\Tax\VatPreset;

$invoice = InvoiceBuilder::make()
    ->number('RE-2026-0042')
    ->issueDate('2026-10-03')
    ->deliveryDate('2026-09-30')
    ->buyerReference('PO-4711')
    ->seller(Party::make('Muster GmbH')
        ->vatId('DE123456789')
        ->address('Hauptstraße 1', '12345', 'Musterstadt', 'DE')
        ->contact('Max Muster', '+49 123 456789', 'rechnung@muster.de')
        ->electronicAddress('rechnung@muster.de', 'EM'))
    ->buyer(Party::make('Kunde AG')
        ->vatId('DE987654321')
        ->address('Bahnhofstraße 5', '54321', 'Beispielstadt', 'DE')
        ->electronicAddress('ap@kunde.de', 'EM'))
    ->line('Web development', netPrice: '95.00', quantity: 24, unit: UnitCode::Hour, vat: VatPreset::Standard19)
    ->payment(PaymentMeansCode::SepaCreditTransfer, iban: 'DE02120300000000202051', remittanceInformation: 'RE-2026-0042')
    ->paymentTerms(fn (PaymentTermsBuilder $t) => $t->dueInDays(14)->cashDiscount(days: 7, percent: '2.00'))
    ->build();
```

## Conventions

- Every builder method returns the builder, so calls can be chained. The builders are mutable; the built `InvoiceData` is not.
- Amounts, prices, quantities, percentages and rates are strings (`'95.00'`), integers or `Brick\Math` numbers. Floats are rejected with an `InvalidArgumentException` ("Floats are not accepted …"), with or without `declare(strict_types=1)`: most decimal values have no exact float representation. The methods typed `BigDecimal|int|float|string` accept the float only to reject it; without `float` in the signature PHP would turn `19.99` into `19` in files without strict types. Parameters typed `string` (e.g. `prepaid()`, `allowance()` amounts) receive a float as its string form.
- Amounts (allowances, charges, prepaid and rounding amounts, cash discount base amounts) have at most two decimals, in every currency, otherwise `InvalidArgumentException`. Prices, quantities, percentages and rates keep any precision.
- Dates accept a `Quittio\Core\Support\Date`, a `DateTimeInterface` or a string: `YYYY-MM-DD`, `YYYYMMDD` or another notation that names a calendar date. Relative expressions such as `tomorrow` or `+14 days` are rejected. A `DateTimeInterface` is converted into a date in the time zone set with `timezone()`, so call `timezone()` first. `invoicingPeriod()` and `LineBuilder::period()` accept `Date` objects and strings only.
- Code parameters take the enum case (`UnitCode::Hour`) or the code as string (`'HUR'`). A string that is not a case of the enum is kept as `Quittio\Core\Codes\RawCode`, so every code of the official code lists can be used; the validation checks raw codes against the code lists. Exception: `attachment()` accepts only the MIME types of `MimeType`.
- Line net amounts (BT-131), totals (BG-22), the VAT breakdown (BG-23) and the due date derived from the payment terms are calculated when the invoice is rendered ([Calculation](#calculation)). There is no method to set them.

## Creating and building

| Method | Description |
|---|---|
| `InvoiceBuilder::make()` | New builder. Defaults: type code 380, currency `EUR`, EN 16931:2017. |
| `InvoiceBuilder::from(InvoiceData $data)` | Builder with all values of existing data, e.g. to change a draft, a stored invoice or a read invoice ([Changing existing data](#changing-existing-data)). |
| `InvoiceBuilder::fromDocument(DocumentData $document)` | Invoice from a quote, order confirmation, delivery note or proforma: parties, billable lines, document allowances and charges and payment data are taken over, number, issue date and notes are not. The number of an order confirmation becomes the sales order reference (BT-14), that of a delivery note the despatch advice reference (BT-16). See [From document to invoice](documents.md#from-document-to-invoice). |
| `build(): InvoiceData` | Builds the data; requires at least one line. |
| `buildDraft(): InvoiceData` | Builds without requiring lines, e.g. for drafts or as the basis of payment reminders or account statements. Data without lines can be calculated (line sum zero), but is not a valid invoice (BR-16). |

`build()` and `buildDraft()` throw `Quittio\Core\Exceptions\InvalidInvoiceData` (an `InvalidArgumentException`) when an essential part is missing; the message names the business term, e.g. "Missing buyer postal address (BG-8).":

| Missing | Business term |
|---|---|
| Seller, seller name, seller postal address | BG-4, BT-27, BG-5 |
| Buyer, buyer name, buyer postal address | BG-7, BT-44, BG-8 |
| Lines (`build()` only) | BG-25 |
| Item name of a line | BT-153 |
| VAT category of a line, unless the invoice has a [default VAT](#default-vat) | BG-30 |

Everything else, e.g. a missing invoice number or a buyer reference that XRechnung requires, is reported by the [validation](validation.md) when the invoice is rendered or checked. Without `issueDate()` the issue date is today in the builder's time zone (`timezone()`, otherwise PHP's default time zone).

### Macros

`InvoiceBuilder` and `LineBuilder` are macroable: `InvoiceBuilder::macro(string $name, callable $macro)` adds a method, `hasMacro(string $name)` and `flushMacros()` inspect and remove them. The closure is bound to the builder, so `$this` and the builder's private properties are available. `InvoiceBuilder` can also be extended: `make()`, `from()` and `fromDocument()` return `static`. Country packs add their helpers this way, e.g. `smallAmount()`, `selfBilled()` and `deductAdvance()` of the German pack ([Builder macros](country-packs.md#builder-macros)).

```php
InvoiceBuilder::macro('forProject', function (string $id, string $name): InvoiceBuilder {
    return $this->projectReference($id, $name)->note('Project: '.$name);
});

$builder->forProject('P-17', 'Customer portal');
```

## Document type and identification

| Method | BT | Description |
|---|---|---|
| `number(string $number)` | BT-1 | Invoice number. The builder does not check it; the validation requires it. The Managed layer assigns numbers itself. |
| `type(InvoiceTypeCode\|RawCode\|string $type)` | BT-3 | Type code (UNTDID 1001), default `InvoiceTypeCode::CommercialInvoice` (380). |
| `businessProcess(?string $process)` | BT-23 | Business process identifier. The profiles Peppol and XRechnung write `urn:fdc:peppol.eu:2017:poacc:billing:01:1.0` when it is not set. |
| `standard(StandardVersion $version)` | BT-24 | `StandardVersion::En16931_2017` (default) or `En16931_2026`. Only the profile EN 16931 supports 2026 (`urn:cen.eu:en16931:2026`); other profiles report `QUITTIO-STANDARD-VERSION`. |

The specification identifier (BT-24) itself is not part of the data; it comes from the profile chosen when rendering ([Formats and profiles](formats.md#profiles)).

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
| `ProformaInvoice` | 325 | Rejected in the invoice pipeline (`QUITTIO-PROFORMA`); use `DocumentBuilder::proforma()` ([Proforma invoices](documents.md#proforma-invoices)). |

Further cases: `RequestForPayment` (71), `DebitNoteGoodsServices` (80), `CreditNoteGoodsServices` (81), `MeteredServicesInvoice` (82), `CreditNoteFinancialAdjustment` (83), `DebitNoteFinancialAdjustment` (84), `TaxNotification` (102), `InvoicingDataSheet` (130), `DirectPaymentValuation` (202), `ProvisionalPaymentValuation` (203), `PaymentValuation` (204), `InterimApplicationForPayment` (211), `FinalPaymentRequest` (218), `PaymentRequestForCompletedUnits` (219), `ConsolidatedCreditNote` (262), `PriceVariationInvoice` (295), `CreditNoteForPriceVariation` (296), `DelcredereCreditNote` (308), `CommercialInvoiceWithPackingList` (331), `CommissionNote` (382), `ConsolidatedInvoice` (385), `HireInvoice` (387), `TaxInvoice` (388), `DelcredereInvoice` (390), `FactoredInvoice` (393), `LeaseInvoice` (394), `ConsignmentInvoice` (395), `FactoredCreditNote` (396), `OcrPaymentCreditNote` (420), `DebitAdvice` (456), `ReversalOfDebit` (457), `ReversalOfCredit` (458), `ForwardersCreditNote` (532), `ForwardersInvoiceDiscrepancyReport` (553), `InsurersInvoice` (575), `ForwardersInvoice` (623), `PortChargesDocument` (633), `InvoiceInformationForAccounting` (751), `FreightInvoice` (780), `ClaimNotification` (817), `ConsularInvoice` (870), `CustomsInvoice` (935).

`InvoiceTypeCode::isCreditNote()` is true for 81, 83, 261, 262, 296, 308, 381, 396, 420, 458 and 532: these are written as UBL `CreditNote`. `isSelfBilled()` is true for 261, 389 and 527. The document title in PDF, HTML and file names follows the type code: 380, 381, 384, 389, 325, 326, 386, 875, 876 and 877 have their own title, other credit notes use the title of 381, other self-billed documents that of 389, everything else "Invoice" ("Rechnung"); `Presentation::$title` overrides it.

### Credit notes, corrected invoices and self-billing

```php
use Quittio\Core\Data\Lines\InvoiceLine;
use Quittio\Core\Enums\InvoiceTypeCode;

// Credit note: positive amounts, type code 381, reference to the invoice
InvoiceBuilder::make()->type(InvoiceTypeCode::CreditNote)->precedingInvoice('RE-2026-0042', '2026-10-03');

// Corrected invoice (384) based on the original
InvoiceBuilder::from($original)
    ->number('RE-2026-0050')
    ->issueDate('2026-10-20')
    ->type(InvoiceTypeCode::CorrectedInvoice)
    ->precedingInvoice($original->number, $original->issueDate)
    ->updateLine('2', fn (InvoiceLine $l) => $l->withNetPrice('80.00'));

// Self-billing: the buyer issues the invoice, the supplier stays the seller (BG-4)
InvoiceBuilder::make()->type(InvoiceTypeCode::SelfBilledInvoice);
```

A cancellation of an issued invoice is built with `Quittio::cancellation()` ([Cancellations and corrections](#cancellations-and-corrections)). The German pack adds `selfBilled()`, which sets type code 389 and adds the note "Gutschrift" (subject `REG`) that the German rules require ([Self-billing](country-packs.md#self-billing-gutschrift)).

## Dates

| Method | BT | Description |
|---|---|---|
| `issueDate(Date\|DateTimeInterface\|string $date)` | BT-2 | Issue date, default today. |
| `dueDate(Date\|DateTimeInterface\|string $date)` | BT-9 | Due date. Without it the calculator derives it from `paymentTerms()->dueInDays()`; without payment terms the configured default applies when rendering (`due_days`, Laravel `defaults.due_days`, default 14 there; not for credit notes). |
| `deliveryDate(Date\|DateTimeInterface\|string $date)` | BT-72 | Actual delivery or service date. Keeps the deliver-to data. |
| `invoicingPeriod(Date\|string\|null $start, Date\|string\|null $end = null)` | BG-14 (BT-73, BT-74) | Invoicing or service period; one of the two dates may be `null`, not both. |
| `taxPointDate(Date\|DateTimeInterface\|string $date)` | BT-7 | Date when VAT becomes chargeable, if it differs from the issue date. |
| `taxPointDateCode(TaxPointDateCode $code)` | BT-8 | `TaxPointDateCode::IssueDate`, `DeliveryDate` or `PaymentDate` (written as 5, 29, 72 in CII and 3, 35, 432 in UBL). EN 16931 allows BT-7 or BT-8, not both (BR-CO-03). |
| `timezone(string $timezone)` | – | Time zone for `DateTimeInterface` values and the default issue date. |

Lines can carry their own period (`LineBuilder::period()`, BG-26).

## Currency and exchange rate

| Method | BT | Description |
|---|---|---|
| `currency(string $currency)` | BT-5 | Invoice currency (ISO 4217, uppercased), default `EUR`. All amounts of the invoice are in this currency. |
| `taxCurrency(string $currency, BigDecimal\|int\|string\|null $exchangeRate = null, Date\|DateTimeInterface\|string\|null $rateDate = null)` | BT-6 | VAT accounting currency; with a rate, also sets `exchangeRate()`. |
| `exchangeRate(BigDecimal\|int\|string $rate, Date\|DateTimeInterface\|string\|null $date = null)` | – | Units of the VAT accounting currency per unit of the invoice currency. |

The calculator sets the VAT total in accounting currency BT-111 = round2(BT-110 × rate). Without a rate BT-111 stays empty (except for a VAT total of zero) and the validation fails with BR-53. BT-6 is written only if it differs from BT-5; the rate itself appears in the XML only in profile EXTENDED (CII `TaxApplicableTradeCurrencyExchange`).

```php
->currency('USD')
->taxCurrency('EUR', exchangeRate: '0.9234', rateDate: '2026-10-02')
```

## References

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
| `invoicedObject(string $value, string\|RawCode\|null $scheme = null)` | BT-18 | Invoiced object identifier (e.g. a meter or subscription), with the scheme (UNTDID 1153) as `ReferenceTypeCode`/`schemeID`. |
| `buyerAccountingReference(?string $reference)` | BT-19 | Buyer's accounting reference (cost centre). |
| `precedingInvoice(string $number, Date\|DateTimeInterface\|string\|null $issueDate = null)` | BG-3 (BT-25, BT-26) | Reference to a preceding invoice; repeatable. CII carries only the first reference, UBL all of them. |

Lines have their own references (`orderLineReference()`, `buyerAccountingReference()`, `objectId()`, see [Lines](#lines)).

## Notes

`note(string $content, NoteSubjectCode|RawCode|string|null $subject = null)` adds an invoice note (BG-1: text BT-22, subject code BT-21); it can be called several times. UBL writes a note with subject code as `#CODE#text`.

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

Cancellations take over the notes with subject `REG` and `TXD` of the original.

## Parties

### Seller and buyer

`seller()` and `buyer()` take one of four kinds of party:

| Argument | Effect |
|---|---|
| `Party::make('Kunde AG')->vatId(…)->address(…)` | A `PartyBuilder` (`Quittio\Core\Builder\Party`); it replaces the party set before. The builder is copied, so it can serve as template. |
| An application model implementing `ProvidesParty`, e.g. `->buyer($customer)` | The model fills a new `PartyBuilder` ([Application models](#application-models)); it replaces the party set before. |
| `SellerParty`, `BuyerParty` | A finished party, e.g. `Seller::fromConfig()` in Laravel; it replaces the party set before. |
| A callable that receives a `PartyBuilder` | Continues the party set before, also a `SellerParty` or `BuyerParty` (e.g. from `Quittio::invoice()` in Laravel or from `InvoiceBuilder::from()`), so single values can be changed. |

```php
use Quittio\Core\Builder\Party;

Quittio::invoice()                                                 // seller from config/quittio.php
    ->seller(fn (PartyBuilder $s) => $s->contact('Erika Muster', '+49 123 4567', 'erika@muster.de'))
    ->buyer($customer);                                            // ProvidesParty

InvoiceBuilder::make()
    ->buyer(Party::make('Kunde AG')->vatId('DE987654321')->address('Bahnhofstraße 5', '54321', 'Beispielstadt', 'DE'));
```

`PartyBuilder` (`Quittio\Core\Builder\PartyBuilder`):

| Method | Seller | Buyer | Description |
|---|---|---|---|
| `name(string $name)` | BT-27 | BT-44 | Legal name. Required. |
| `tradingName(?string $tradingName)` | BT-28 | BT-45 | Trading name. |
| `vatId(?string $vatId)` | BT-31 | BT-48 | VAT identifier with country prefix; uppercased, spaces removed. |
| `taxNumber(?string $taxNumber)` | BT-32 | – | Tax registration number (German "Steuernummer"); ignored for the buyer. |
| `legalRegistration(?string $value, IdentifierScheme\|RawCode\|string\|null $scheme = null)` | BT-30 | BT-47 | Legal registration identifier, e.g. the commercial register number. |
| `additionalLegalInfo(?string $info)` | BT-33 | – | Additional legal information (legal form, share capital …); ignored for the buyer. |
| `identifier(string $value, IdentifierScheme\|RawCode\|string\|null $scheme = null)` | BT-29 | BT-46 | Adds a party identifier, e.g. a GLN or the customer number at the buyer. The seller keeps all, the buyer only the first. CII writes identifiers without scheme as `ram:ID`, with scheme as `ram:GlobalID`. |
| `address(?string $line1, ?string $postcode, ?string $city, string $countryCode, ?string $line2 = null, ?string $line3 = null, ?string $subdivision = null)` | BG-5 | BG-8 | Postal address; the country code (ISO 3166-1 alpha-2) is uppercased. Required. |
| `postalAddress(Address $address)` | BG-5 | BG-8 | Postal address as object. |
| `electronicAddress(string $value, ElectronicAddressScheme\|RawCode\|string $scheme = ElectronicAddressScheme::Email)` | BT-34 | BT-49 | Electronic address with scheme (EAS); required by XRechnung and Peppol. |
| `contact(?string $name = null, ?string $phone = null, ?string $email = null)` | BG-6 | BG-9 | Contact person, phone and email. XRechnung requires all three for the seller. |
| `consumer(bool $consumer = true)` | – | – | Marks the buyer as consumer (B2C): the automatic format choice renders a PDF without XML, country packs apply their consumer rules, templates get `buyer.isConsumer`. Not part of the XML; ignored for the seller. |
| `fromModel(ProvidesParty $model)` | | | Lets an application model fill the builder ([Application models](#application-models)). |
| `PartyBuilder::fromSeller(SellerParty $seller)`, `PartyBuilder::fromBuyer(BuyerParty $buyer)` | | | Builder with the values of an existing party. |
| `buildSeller(): SellerParty`, `buildBuyer(): BuyerParty` | | | Build the party; missing name or address throw `InvalidInvoiceData`. |

Without a VAT identifier, EN 16931 requires a seller identifier (BT-29) or a legal registration identifier (BT-30), otherwise rendering fails with BR-CO-26.

`IdentifierScheme` (ISO 6523 ICD) has the cases `Siren` (0002), `SwedishOrganisationNumber` (0007), `Siret` (0009), `Duns` (0060), `Gln` (0088), `DutchKvk` (0106), `Gtin` (0160), `DanishCvr` (0184), `DutchOin` (0190), `NorwegianOrganisationNumber` (0192), `LeitwegId` (0204) and `BelgianEnterpriseNumber` (0208). `ElectronicAddressScheme` (EAS) has `Email` (EM), `Siren` (0002), `SwedishOrganisationNumber` (0007), `Siret` (0009), `Duns` (0060), `Gln` (0088), `DutchKvk` (0106), `DanishCvr` (0184), `DutchOin` (0190), `NorwegianOrganisationNumber` (0192), `LeitwegId` (0204), `BelgianEnterpriseNumber` (0208), `AustrianVatNumber` (9914), `AustrianOrganisationNumber` (9915), `GermanVatNumber` (9930) and `FrenchVatNumber` (9957). Other codes of the lists can be passed as strings.

### Payee, tax representative and delivery

| Method | BT | Description |
|---|---|---|
| `payee(PayeeParty\|string\|callable $payee, ?string $identifier = null, ?string $legalRegistration = null, IdentifierScheme\|RawCode\|string\|null $identifierScheme = null, IdentifierScheme\|RawCode\|string\|null $legalRegistrationScheme = null)` | BG-10 (BT-59, BT-60, BT-61) | Payee, if different from the seller (e.g. a factoring company): a `PayeeParty`, the name with identifier, legal registration identifier and their schemes (e.g. `'0088'` for a GLN), or a callable that receives a `PartyBuilder` (`name()`, `identifier()`, `legalRegistration()`) and, like `seller()` and `buyer()`, continues an earlier payee. Identifiers and schemes next to a `PayeeParty` or a callable throw an `InvalidArgumentException`. |
| `taxRepresentative(string $name, string $vatId, Address $address)` | BG-11 (BT-62, BT-63, BG-12) | Seller's tax representative. |
| `deliverTo(?string $recipientName, ?Address $address = null, ?Identifier $locationId = null)` | BG-13 (BT-70, BG-15, BT-71) | Deliver-to party, address and location identifier. Replaces these three values (also with `null`) and keeps the delivery date. |
| `delivery(Delivery $delivery)` | BG-13 | Replaces the whole delivery information, including the delivery date. |

```php
use Quittio\Core\Data\Identifier;
use Quittio\Core\Data\Party\Address;
use Quittio\Core\Enums\IdentifierScheme;

->deliverTo('Kunde AG, Lager Nord', Address::make('Industriestraße 9', '54322', 'Beispielstadt', 'DE'), Identifier::of('4000001000005', IdentifierScheme::Gln))
->taxRepresentative('Steuer Vertretung GmbH', 'DE111111125', new Address(countryCode: 'DE', line1: 'Ring 1', city: 'Berlin', postcode: '10115'))
```

`Address::make(?string $line1, ?string $postcode, ?string $city, string $countryCode)` creates an address with the most common fields; the constructor takes `countryCode`, `line1`, `line2`, `line3`, `city`, `postcode` and `subdivision`. `Identifier::of(string $value, IdentifierScheme|RawCode|string|null $scheme = null)` creates an identifier.

## Lines

| `InvoiceBuilder` method | Description |
|---|---|
| `line(InvoiceLine\|LineBuilder\|ProvidesInvoiceLine\|array\|callable $line)` | Adds a line (BG-25), in one of the forms below. |
| `lines(iterable $lines)` | Adds several lines; each entry may be any of the forms, e.g. an array of arrays or an Eloquent collection of order items. |
| `updateLine(string $id, callable $change)` | Changes an existing line: the callable receives the `InvoiceLine` and returns the changed one. The line net amount is reset, and percentage allowances and charges of the line are recalculated from the new line amount. |
| `removeLine(string $id)` | Removes an existing line by its identifier (BT-126). |

A line can be given as:

| Form | Example |
|---|---|
| The name, with the common values as named arguments | `->line('Web development', netPrice: '95.00', quantity: 24, unit: UnitCode::Hour)` |
| `Line::make()` (`Quittio\Core\Builder\Line`), a `LineBuilder` with the same arguments, for everything else | `->line(Line::make('Web development', netPrice: '95.00')->sellerId('WD-1')->allowance(percent: '10', reason: 'Framework agreement'))` |
| An array whose keys are methods of the `LineBuilder` | `->line(['name' => 'Hosting', 'quantity' => [1, UnitCode::Month], 'netPrice' => '49.00'])` |
| An application model implementing `ProvidesInvoiceLine` ([Application models](#application-models)) | `->lines($order->items)` |
| A callable that receives a new `LineBuilder` | `->line(fn (LineBuilder $l) => $l->name('Travel expenses')->netPrice('120.00'))` |
| A finished `InvoiceLine`, taken as it is | `->line($invoice->lines[0])` |

The named arguments of `line()` and `Line::make()` are `netPrice` (BT-146, the price without VAT), `priceInclVat` (the price including VAT, [gross price mode](#gross-price-mode)), `quantity` (default 1), `unit`, `vat` (a preset or a category without rate; default the [default VAT](#default-vat)) and `description`; they are allowed only together with a name. A string is always the name of the line, never a callable.

A `LineBuilder` is copied when it is added, so the same builder can serve as template for further lines. In an array, each key names a method (`LineBuilder::ARRAY_KEYS`: `id`, `name`, `description`, `note`, `quantity`, `unit`, `netPrice`, `listPrice`, `priceInclVat`, `vat`, `period`, `allowance`, `charge`, `sellerId`, `buyerId`, `standardId`, `classification`, `originCountry`, `attribute`, `orderLineReference`, `buyerAccountingReference`, `objectId`, `group`, `highlight`, `showGrossPrice`) and the value is its argument; an array value holds the arguments as list or by name (`'quantity' => [4, UnitCode::Hour]`, `'allowance' => ['percent' => '10', 'reason' => 'Early bird']`). Null values are skipped, so database rows with empty columns can be passed; an unknown key throws an `InvalidArgumentException`. `Line::fromArray($array)` and `LineBuilder::fill($array)` do the same for a builder.

```php
use Quittio\Core\Builder\Line;

$builder->lines([
    Line::make('Web development', netPrice: '95.00', quantity: 24, unit: UnitCode::Hour),
    ['name' => 'Hosting', 'quantity' => [1, UnitCode::Month], 'netPrice' => '49.00'],
    ['name' => 'Book', 'netPrice' => '39.00', 'vat' => VatPreset::Reduced7],
]);
```

Without `quantity()` a line has the quantity 1; without `vat()` it takes the [default VAT](#default-vat) of the invoice.

`updateLine()` and `removeLine()` work on lines that already exist as `InvoiceLine`: lines of data passed to `InvoiceBuilder::from()` and lines added as `InvoiceLine`. Lines added in the other forms are built only in `build()`, so they cannot be changed or removed by identifier. An unknown identifier throws an `InvalidArgumentException`.

```php
use Quittio\Core\Data\Lines\InvoiceLine;

$changed = InvoiceBuilder::from($draft)
    ->updateLine('2', fn (InvoiceLine $line) => $line->withNetPrice('80.00')->with(['note' => 'Price adjusted']))
    ->removeLine('3')
    ->line('Travel expenses', netPrice: '120.00', vat: VatPreset::Standard19)
    ->build();
```

Line identifiers: a line without `id()` gets its position (1, 2, 3 …), skipping identifiers that existing lines or other lines' `id()` already use, so identifiers stay unique after `from()` and `removeLine()` (lines `A`, `B` with `id('1')`, `C` get `2`, `1`, `3`). Sub lines get `{parent}.{n}` (e.g. `2.1`). An identifier used twice, also by a sub line, makes `build()` throw `InvalidInvoiceData`; partial cancellations refer to lines by identifier. Data from other sources (JSON, incoming invoices) gets the warning `QUITTIO-LINE-ID-DUPLICATE` instead, because EN 16931 does not require unique identifiers.

### LineBuilder

`Quittio\Core\Builder\LineBuilder`:

| Method | BT | Description |
|---|---|---|
| `id(string $id)` | BT-126 | Line identifier; default the position. |
| `name(string $name)` | BT-153 | Item name. Required. |
| `description(?string $description)` | BT-154 | Item description. Printed as plain text; with the option `markdown` (Laravel `pdf.markdown`) as Markdown ([Templates](templates.md)). |
| `note(?string $note)` | BT-127 | Line note. |
| `quantity(BigDecimal\|int\|string $quantity, UnitCode\|RawCode\|string\|null $unit = null)` | BT-129, BT-130 | Invoiced quantity and optionally the unit; default 1. Negative quantities for returns and discounts. |
| `unit(UnitCode\|RawCode\|string $unit)` | BT-130 | Unit (UN/ECE Rec. 20/21), default `UnitCode::Unit` (C62). |
| `netPrice(BigDecimal\|int\|string $price, BigDecimal\|int\|string\|null $baseQuantity = null, UnitCode\|RawCode\|string\|null $baseQuantityUnit = null)` | BT-146, BT-149, BT-150 | Net price per base quantity; the base quantity unit defaults to the line unit. Each call replaces the base quantity. |
| `listPrice(BigDecimal\|int\|string $grossPrice, BigDecimal\|int\|string\|null $discount = null)` | BT-148, BT-147 | List (gross) price before price discount and the discount per unit. Without `netPrice()` the net price is list price minus discount. |
| `priceInclVat(BigDecimal\|int\|string $price)` | – | Price including VAT ([gross price mode](#gross-price-mode)); the calculator derives the net price. |
| `vat(TaxPreset\|VatCategory $presetOrCategory, BigDecimal\|int\|string\|null $rate = null, ?string $exemptionReason = null, VatExemptionReasonCode\|RawCode\|string\|null $exemptionReasonCode = null)` | BG-30 (BT-151, BT-152) | VAT treatment: a tax preset of a country pack, or category, rate and exemption reason ([VAT](#vat)). Without it, the line takes the [default VAT](#default-vat) of the invoice. |
| `period(Date\|string\|null $start, Date\|string\|null $end = null)` | BG-26 (BT-134, BT-135) | Line period. |
| `allowance(?string $amount = null, ?string $percent = null, ?string $reason = null, AllowanceReasonCode\|RawCode\|string\|null $reasonCode = null, ?string $baseAmount = null)` | BG-27 | Line allowance: amount or percentage; repeatable. |
| `charge(?string $amount = null, ?string $percent = null, ?string $reason = null, ChargeReasonCode\|RawCode\|string\|null $reasonCode = null, ?string $baseAmount = null)` | BG-28 | Line charge: amount or percentage; repeatable. |
| `sellerId(?string $id)` | BT-155 | Seller's item number. |
| `buyerId(?string $id)` | BT-156 | Buyer's item number. |
| `standardId(string $value, IdentifierScheme\|RawCode\|string $scheme = IdentifierScheme::Gtin)` | BT-157 | Standard item identifier, default scheme GTIN (0160). |
| `classification(string $code, ItemClassificationScheme\|RawCode\|string $scheme, ?string $version = null)` | BT-158 | Item classification with list identifier and version; repeatable. |
| `originCountry(?string $country)` | BT-159 | Country of origin (uppercased). |
| `attribute(string $name, string $value)` | BG-32 (BT-160, BT-161) | Item attribute; repeatable. |
| `orderLineReference(?string $reference)` | BT-132 | Line of the purchase order. |
| `buyerAccountingReference(?string $reference)` | BT-133 | Buyer's accounting reference of the line. |
| `objectId(string $value, string\|RawCode\|null $scheme = null)` | BT-128 | Invoiced object identifier of the line, with scheme. |
| `subLine(callable $callback)` | – | Adds a sub line; the callable receives a new `LineBuilder` ([Sub lines](#sub-lines)). |
| `reference(DocumentReference $reference)` | – | Line reference to an order, contract, despatch or receiving advice or another document (profile EXTENDED). |
| `extended(LineExtendedData $extended)` | – | Line data of profile EXTENDED ([Extensions](#extensions)). |
| `group(string $groupId)` | – | Assigns the line to a line group of the PDF (`InvoiceBuilder::group()`). |
| `highlight(bool $highlight = true)` | – | Highlights the line in the PDF (the default templates shade it and mark it in the secondary colour). |
| `showGrossPrice(?bool $show = true)` | – | Prints the price including VAT below the net unit price of this line (`false`: not), whatever the presentation's `showGrossPrices` says; `null` follows the presentation. Only for lines with `priceInclVat()`. |
| `fromModel(ProvidesInvoiceLine $model)` | – | Lets an application model fill the builder. |
| `fill(array $values)` | – | Calls the methods named by the keys of the array ([Lines](#lines)). |
| `build(string $currency, string $defaultId, ?string $locale = null): InvoiceLine` | – | Builds the line; called by `InvoiceBuilder`. Use it to create an `InvoiceLine` on its own, e.g. `(new LineBuilder)->name('…')->…->build('EUR', '1')`. |

`UnitCode` has the cases `Unit` (C62), `Piece` (H87), `Set` (SET), `Pair` (PR), `Dozen` (DZN), `LumpSum` (LS), `Second` (SEC), `Minute` (MIN), `Hour` (HUR), `Day` (DAY), `Week` (WEE), `Month` (MON), `Quarter` (QAN), `Year` (ANN), `Gram` (GRM), `Kilogram` (KGM), `Tonne` (TNE), `Millimetre` (MMT), `Centimetre` (CMT), `Metre` (MTR), `Kilometre` (KMT), `SquareMetre` (MTK), `CubicMetre` (MTQ), `Millilitre` (MLT), `Litre` (LTR), `KilowattHour` (KWH), `Kilowatt` (KWT), `Percent` (P1), `ServiceUnit` (E48), `Package` (XPK), `Box` (XBX) and `Pallet` (XPX); every other code of the list can be passed as string. `ItemClassificationScheme` (UNTDID 7143) has `HarmonisedSystem` (HS), `Unspsc` (TST), `CustomsArticleNumber` (CV), `NationalProductGroup` (GN), `Isbn` (IB), `ProductServiceId` (MP) and `MutuallyDefined` (ZZZ).

### Prices

```php
->line(Line::make('Paper A4')->quantity(5000, 'H87')
    ->netPrice('12.50', baseQuantity: 1000)                  // 12.50 per 1000 sheets: 62.50
    ->vat(VatPreset::Standard19))
->line(Line::make('Monitor')->quantity(2)
    ->listPrice('299.00', discount: '30.00')                 // net price 269.00
    ->vat(VatPreset::Standard19))
```

The line amount before allowances and charges is round2(quantity × net price / base quantity). Prices must not be negative (BR-27); returns and discounts use a negative quantity.

### Line allowances and charges

A line allowance or charge has an amount, or a percentage of a base amount. Without a base amount the base is the line amount before allowances and charges (quantity × price); the calculator writes it to BT-137/BT-142. EN 16931 requires a reason text or a reason code (`AllowanceReasonCode` and `ChargeReasonCode`, see [Document level allowances and charges](#document-level-allowances-and-charges)).

```php
->line(Line::make('Seminar')->quantity(3)->netPrice('400.00')->vat(VatPreset::Standard19)
    ->allowance(percent: '10', reason: 'Early bird')       // 120.00 of 1200.00
    ->charge('25.00', reason: 'Materials', reasonCode: 'ABK'))
```

### Gross price mode

Shops and B2C invoices often work with prices including VAT. With `priceInclVat()` on every line, the calculator derives net prices so that the customer pays exactly the gross amount ([Gross price conversion](#gross-price-conversion)):

```php
InvoiceBuilder::make()
    // ...
    ->line('T-shirt', priceInclVat: '19.99', quantity: 3, vat: VatPreset::Standard19)
    ->line('Shipping', priceInclVat: '4.90', vat: VatPreset::Standard19)
    ->line('Voucher', priceInclVat: '5.00', quantity: -1, vat: VatPreset::Standard19)
    ->build();
```

Restrictions: all lines of the invoice use `priceInclVat()`; gross price lines have no line allowances, charges or sub lines; the invoice has no document level allowances or charges (model shipping and discounts as lines, a discount with a negative quantity). Violations throw a `CalculationException` when the invoice is calculated. To change a price later, `InvoiceLine::withPriceInclVat()` changes a gross price line and `withNetPrice()` a net price line; each throws an `InvalidArgumentException` for a line of the other mode.

### Sub lines

```php
->line(Line::make('Workstation')->vat(VatPreset::Standard19)
    ->subLine('Computer', netPrice: '900.00', vat: VatPreset::Standard19)
    ->subLine('Monitor', netPrice: '250.00', quantity: 2, vat: VatPreset::Standard19))
```

A parent line without quantity has the quantity 1; without own price it takes the sum of its sub lines as price. Sub lines exist only in the profiles EXTENDED (CII, with parent line identifier and line status `GROUP`/`DETAIL`) and XRechnung extension (UBL only, `->format('xrechnung-ubl')->profile('xrechnung-extension')`). The automatic choice upgrades ZUGFeRD and CII to EXTENDED ([Formats and profiles](formats.md#automatic-choice)); other profiles reject sub lines (`QUITTIO-PROFILE-SUBLINES`).

## VAT

Every line has a VAT category (BT-151) and, except for category O, a rate (BT-152). Set them with a tax preset of a country pack or with the category:

```php
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Enums\VatExemptionReasonCode;

->vat(VatPreset::Standard19)                                         // preset of the German pack
->vat(VatCategory::StandardRate, '19')
->vat(VatCategory::ReverseCharge, '0', 'Reverse charge', VatExemptionReasonCode::ReverseCharge)
->vat(VatCategory::Exempt, '0', 'Exempt according to Art. 132 VAT Directive', 'VATEX-EU-132')
->vat(VatCategory::NotSubjectToVat)                                  // no rate
```

With a preset, category, rate, exemption reason and code come from the preset and the other parameters are ignored; the preset key is kept on the line (`LineVat::$presetKey`). The exemption text of a preset is resolved in the locale of the invoice (`locale()`) when the line is built; the German presets use German without a locale.

### Default VAT

Lines (and sub lines) without `vat()` take the default VAT of the invoice when it is built:

1. `defaultVat()` of the builder, with the arguments of `LineBuilder::vat()`, e.g. `->defaultVat(VatPreset::Reduced7)` for a bookshop;
2. otherwise the application default set with `InvoiceBuilder::useDefaultVat(callable|TaxPreset|null $default)`: a preset, or a callable that receives the seller's country code (BT-40) and whether the invoice is flagged as small business invoice (`Meta::FLAG_SMALL_BUSINESS`) and returns a preset or `null`.

`Quittio\Core\Tax\CountryDefaultVat` is such a callable: `new CountryDefaultVat(CountryRegistry $countries, ?TaxPreset $preset = null)` returns `$preset`, otherwise the standard preset of the seller's country pack (`CountryDefaults::$vatPreset`, Germany `de.standard_19`); for small business invoices it returns the small business preset of the pack (`CountryDefaults::$smallBusinessVatPreset`, Germany `de.small_business`), otherwise `$preset`. The Laravel integration installs it with the preset of `quittio.defaults.vat` ([Configuration](configuration.md#defaults)), so in Laravel lines without `vat()` get 19 % for a German seller.

```php
use Quittio\Core\Tax\CountryDefaultVat;

// Without Laravel, once at start-up
InvoiceBuilder::useDefaultVat(new CountryDefaultVat($quittio->countries()));   // or a preset: VatPreset::Standard19

$invoice = InvoiceBuilder::make()
    // ...
    ->line('Web development', netPrice: '95.00', quantity: 24, unit: UnitCode::Hour)   // 19 %
    ->line('Book', netPrice: '39.00', vat: VatPreset::Reduced7)                         // 7 %
    ->build();
```

The default only fills in what a line leaves open: the built data and the XML carry the VAT of every line, as EN 16931 requires. Without a default, a line without VAT makes `build()` fail (`InvalidInvoiceData`, BG-30); `useDefaultVat(null)` removes the application default. Documents (`DocumentBuilder`) use the same defaults and have `defaultVat()` as well.

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
| `CanaryIslands` | L | as given | rate × taxable amount | – | – |
| `CeutaMelilla` | M | as given | rate × taxable amount | – | – |
| `TransferredVatItaly` | B | as given | rate × taxable amount | – | – |

Categories Z, E, AE, K, G and O allow exactly one VAT breakdown each (BR-Z-01 …). The calculator calculates VAT 0 for Z, E, AE, K, G and O whatever rate is given, and writes the rate 0 to the VAT breakdown of a group whose lines have no rate (category O: no rate); the validation still requires the rate 0 on the lines (BR-Z-05 …). The rules are part of the [validation](validation.md) (level L3); `VatCategory::requiresRate()`, `isZeroTax()` and `requiresExemptionReason()` expose the same properties.

`VatExemptionReasonCode` (VATEX) has `ReverseCharge` (VATEX-EU-AE), `IntraCommunitySupply` (VATEX-EU-IC), `Export` (VATEX-EU-G), `NotSubjectToVat` (VATEX-EU-O), `TravelAgents` (VATEX-EU-D), `SecondHandGoods` (VATEX-EU-F), `WorksOfArt` (VATEX-EU-I), `CollectorsItems` (VATEX-EU-J), `Article79c` (VATEX-EU-79-C), `Article132` (VATEX-EU-132), `Article143` (VATEX-EU-143), `Article148` (VATEX-EU-148), `Article151` (VATEX-EU-151) and `Article309` (VATEX-EU-309); other codes of the list as strings.

### Exemption reasons in the VAT breakdown

The exemption reason is given per line but written per VAT breakdown (one per category and rate). The calculator collects the reasons of the lines of each group for the categories that require one (E, AE, K, G, O): different texts are joined with "; " in the order of the lines, so that no legally required note is lost; a code is kept when all lines of the group have the same code, otherwise it is left out and the validation reports `QUITTIO-EXEMPTION-CONFLICT`. The exemption reason of a document level allowance or charge is used only if no line of its group provides one, e.g. for an exempt charge on an otherwise standard rated invoice.

### Tax presets

A tax preset implements `Quittio\Core\Tax\TaxPreset` (`key()`, `category()`, `rate()`, `exemptionReasonCode()`, `exemptionReason(?string $locale = null)`). Country packs provide their presets as `TaxPresetCollection` (`$pack->taxPresets()`, with `get(string $key)`, `has()`, `add()`, `count()` and iteration), so a stored preset key can be resolved again:

```php
$preset = $quittio->countries()->get('DE')?->taxPresets()->get('de.standard_19');
```

The German presets (`VatPreset::Standard19`, `Reduced7`, `SmallBusiness`, `ReverseChargeEu`, `VatPreset::exempt('§ 4 Nr. 14 UStG')` …) are listed in [Tax presets](country-packs.md#tax-presets); [Presets of your pack](country-packs.md#presets-of-your-pack) shows how a pack provides its own. Own presets are created with `CustomTaxPreset::make(string $key, VatCategory $category, BigDecimal|int|string|null $rate = null, VatExemptionReasonCode|RawCode|string|null $code = null, array|string|null $reason = null)`, the reason as text or as array of texts per locale (fallback `en`):

```php
use Quittio\Core\Tax\CustomTaxPreset;

$exempt = CustomTaxPreset::make('acme.exempt_132', VatCategory::Exempt, '0', 'VATEX-EU-132', [
    'de' => 'Steuerfrei nach Art. 132 MwStSystRL',
    'en' => 'Exempt according to Art. 132 VAT Directive',
]);

->line('Course', netPrice: '300.00', vat: $exempt)
```

## Document level allowances and charges

| Method | Description |
|---|---|
| `allowance(VatCategory $category, BigDecimal\|int\|string\|null $rate, ?string $amount = null, ?string $percent = null, ?string $reason = null, AllowanceReasonCode\|RawCode\|string\|null $reasonCode = null, ?string $baseAmount = null, ?string $exemptionReason = null, VatExemptionReasonCode\|RawCode\|string\|null $exemptionReasonCode = null)` | Document level allowance (BG-20: BT-92 to BT-98) for one VAT category and rate. |
| `charge(VatCategory $category, BigDecimal\|int\|string\|null $rate, ?string $amount = null, ?string $percent = null, ?string $reason = null, ChargeReasonCode\|RawCode\|string\|null $reasonCode = null, ?string $baseAmount = null, ?string $exemptionReason = null, VatExemptionReasonCode\|RawCode\|string\|null $exemptionReasonCode = null)` | Document level charge (BG-21: BT-99 to BT-105). |
| `discountPercent(string $percent, ?string $reason = null, AllowanceReasonCode\|RawCode\|string\|null $reasonCode = AllowanceReasonCode::Discount)` | Percentage discount on all lines, split into one allowance per VAT group. |
| `discountAmount(string $amount, ?string $reason = null, AllowanceReasonCode\|RawCode\|string\|null $reasonCode = AllowanceReasonCode::Discount)` | Absolute discount, distributed over the VAT groups in proportion to their line sums. |

An allowance or charge has an amount, or a percentage of a base amount; without base amount the base is the sum of the line net amounts of its VAT group (category and rate), which the calculator writes to BT-93/BT-100. A document allowance or charge belongs to exactly one VAT group; the exemption reason is used for the VAT breakdown as described [above](#exemption-reasons-in-the-vat-breakdown). EN 16931 requires a reason text or code.

```php
use Quittio\Core\Enums\ChargeReasonCode;

->charge(VatCategory::StandardRate, '19', amount: '9.90', reason: 'Shipping', reasonCode: ChargeReasonCode::Freight)
->allowance(VatCategory::StandardRate, '19', percent: '5', reason: 'Loyalty discount')
->discountPercent('3', 'Framework agreement')                     // one allowance per VAT group
```

`discountPercent()` and `discountAmount()` split the discount when the invoice is built: one allowance per VAT group, in proportion to the signed line sum of the group; a group with a negative sum (only returns) receives a negative part, rounding differences are distributed with the largest remainder method, so the parts add up exactly to the discount ([Calculation](#discount-splitting)). Percentage and base amount are kept on a part only if the part equals round2(base × percentage / 100), otherwise only the amount is written (validators check that formula, e.g. PEPPOL-EN16931-R040). A discount of 0 adds nothing; a discount on lines that add up to zero throws a `CalculationException`.

`AllowanceReasonCode` (UNTDID 5189): `BonusForWorksAheadOfSchedule` (41), `OtherBonus` (42), `ManufacturersConsumerDiscount` (60), `DueToMilitaryStatus` (62), `DueToWorkAccident` (63), `SpecialAgreement` (64), `ProductionErrorDiscount` (65), `NewOutletDiscount` (66), `SampleDiscount` (67), `EndOfRangeDiscount` (68), `IncotermDiscount` (70), `PointOfSalesThresholdAllowance` (71), `MaterialSurchargeDeduction` (88), `Discount` (95), `SpecialRebate` (100), `FixedLongTerm` (102), `Temporary` (103), `Standard` (104), `YearlyTurnover` (105). `ChargeReasonCode` (UNTDID 7161): `Advertising` (AA), `Telecommunication` (AAA), `Miscellaneous` (ABK), `AdditionalPackaging` (ABL), `OtherServices` (ADR), `PickUp` (ADT), `Delivery` (DL), `Freight` (FC), `Financing` (FI), `Labelling` (LA), `Packing` (PC), `SpecialHandling` (SH). Other codes of the lists as strings.

## Payment

### Payment instructions

| Method | BT | Description |
|---|---|---|
| `payment(PaymentMeansCode\|RawCode\|string $means, ?string $iban = null, ?string $bic = null, ?string $accountName = null, ?string $remittanceInformation = null, ?string $meansText = null)` | BG-16 (BT-81, BT-82, BT-83), BG-17 | Payment means, optionally with one account (IBAN uppercased, spaces removed), the remittance information (payment reference) and a text for the means. |
| `creditTransfer(string $accountId, ?string $bic = null, ?string $accountName = null)` | BG-17 (BT-84, BT-86, BT-85) | Adds a further account. An IBAN is written as `IBANID` in CII, any other account identifier as `ProprietaryID`. Without payment instructions it starts them with `SepaCreditTransfer` (58). |
| `paymentCard(string $lastDigits, ?string $holderName = null)` | BG-18 (BT-87, BT-88) | Card data: only the last 4 to 6 digits (more throw an `InvalidArgumentException`). Without payment instructions it starts them with `BankCard` (48). |
| `directDebit(?string $mandateReference, ?string $creditorId = null, ?string $debitedIban = null)` | BG-19 (BT-89, BT-90, BT-91) | SEPA direct debit: mandate reference, creditor identifier, debited account. Without payment instructions it starts them with `SepaDirectDebit` (59). |

A second call of `payment()` replaces the payment instructions: an account given there replaces all accounts; without one, existing accounts are kept for the credit transfer codes 30, 42 and 58 (e.g. the seller's default account set by `Quittio::invoice()`), card data for 48, 54 and 55, direct debit data for 49 and 59, everything else is dropped. The remittance information is kept unless a new one is given. `creditTransfer()`, `paymentCard()` and `directDebit()` add to existing instructions without changing the means code, so call `payment()` with the means first.

```php
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

XRechnung requires payment instructions (BR-DE-1). Profile EXTENDED can carry further payment instructions (`ExtendedData::$additionalPaymentInstructions`).

### Payment terms

`paymentTerms(PaymentTerms|string|callable $terms)` sets the payment terms (BT-20): a text, a `PaymentTerms` object or a callable that receives a `PaymentTermsBuilder`. A text or a `PaymentTerms` object replaces earlier terms; a callable continues the earlier terms (of a callable, a text, a `PaymentTerms` object or `InvoiceBuilder::from()`), like `seller()` and `buyer()`: `InvoiceBuilder::from($draft)->paymentTerms(fn ($t) => $t->cashDiscount(7, '2.00'))` adds a cash discount to the existing terms. To start over, pass a text or `new PaymentTerms(...)`.

| `PaymentTermsBuilder` method | Description |
|---|---|
| `text(?string $text)` | Free text of the payment terms. |
| `dueInDays(int $days)` | Payment target: the calculator sets the due date (BT-9) to issue date + days unless `dueDate()` is set. |
| `cashDiscount(int $days, string $percent, ?string $baseAmount = null)` | Cash discount ("Skonto") for payment within `$days` days after the issue date, optionally on a base amount; repeatable. The percentage is rounded to two decimals. |
| `lateFee(?int $days = null, ?string $percent = null, ?string $text = null)` | Late payment terms. |

```php
->paymentTerms(fn (PaymentTermsBuilder $t) => $t
    ->dueInDays(30)
    ->cashDiscount(days: 10, percent: '3.00')
    ->cashDiscount(days: 20, percent: '2.00', baseAmount: '1000.00'))
```

Cash discounts are an agreed reduction of the consideration and must be part of the XML, not only of the PDF, so they are written into the text BT-20, generated in the locale of the rendering run:

- Without `text()`, `dueInDays()` adds "Payable within 30 days without deduction." ("Zahlbar innerhalb von 30 Tagen ohne Abzug.").
- Each cash discount adds a line such as "3.00 % cash discount for payment within 10 days (until 2026-10-13)." (German: "3 % Skonto bei Zahlung innerhalb von 10 Tagen (bis 13.10.2026)."), the late fee text follows.
- XRechnung uses its structured notation instead: the texts first, then one line `#SKONTO#TAGE=10#PROZENT=3.00#` (with `BASISBETRAG=1000.00#` for a base amount) per cash discount, each closed by a line break (BR-DE-18).
- Profile EXTENDED additionally writes the cash discounts (one payment terms group per tier) and the late fee days and percentage as structured CII elements.

### Prepaid amounts and rounding

| Method | BT | Description |
|---|---|---|
| `prepaid(string $amount)` | BT-113 | Amount already paid, e.g. received advance payments; replaces an earlier value. |
| `addPrepaid(string $amount)` | BT-113 | Adds to the amount already paid, e.g. one of several advance payments. |
| `rounding(string $amount)` | BT-114 | Rounding amount, e.g. cash rounding to 0.05. Never use it to fix wrong totals. |

The amount due is BT-115 = gross total − BT-113 + BT-114. For final invoices the German pack offers `deductAdvance($advanceInvoice)`, which references the advance invoice and adds its gross amount to BT-113 ([Builder macros](country-packs.md#builder-macros)).

## Supporting documents

`attachment(string $id, ?string $content = null, MimeType|string|null $mimeType = null, ?string $filename = null, ?string $description = null, ?string $externalUri = null)` adds a supporting document (BG-24): either embedded (`$content` are the raw bytes, base64 encoded in the XML) or as link (`$externalUri`). An embedded document requires MIME type and file name (`InvalidArgumentException` otherwise); repeatable.

```php
use Quittio\Core\Enums\MimeType;

->attachment('Timesheet-09', file_get_contents($path), MimeType::Pdf, 'timesheet-2026-09.pdf', 'Timesheet September')
->attachment('Contract', externalUri: 'https://example.com/contracts/4711.pdf', description: 'Framework contract')
```

`MimeType` has `Pdf` (application/pdf), `Png` (image/png), `Jpeg` (image/jpeg), `Csv` (text/csv), `Xlsx` (application/vnd.openxmlformats-officedocument.spreadsheetml.sheet) and `Ods` (application/vnd.oasis.opendocument.spreadsheet), the MIME codes EN 16931 allows; another string fails with a `ValueError`. Supporting documents are part of the XML from profile EN 16931 upwards (not in MINIMUM, BASIC WL and BASIC); they are not separate files in the PDF ([Formats and profiles](formats.md#embedded-files)). XRechnung requires unique file names (BR-DE-22).

## Extensions

`extended(ExtendedData $extended)` and `LineBuilder::extended(LineExtendedData $extended)` add data of the Factur-X/ZUGFeRD profile EXTENDED. They are written to CII in profile EXTENDED only; the automatic choice switches ZUGFeRD and CII to EXTENDED when they are used, other profiles report `QUITTIO-PROFILE-EXTENDED-ONLY`.

| `ExtendedData` (`Quittio\Core\Data\Extensions`) | Written as |
|---|---|
| `invoicer`, `invoicee` (`ExtendedParty`) | `InvoicerTradeParty`, `InvoiceeTradeParty` |
| `ultimateCustomer` (`ExtendedParty`) | `ProductEndUserTradeParty` |
| `shipFrom` (`ExtendedParty`) | `ShipFromTradeParty` |
| `deliveryTermsCode` (string, e.g. Incoterms) | `ApplicableTradeDeliveryTerms/DeliveryTypeCode` |
| `additionalReferences` (list of `DocumentReference`) | `AdditionalReferencedDocument` with type code (default 916), name and date |
| `installments` (list of `Installment(Date $dueDate, ?Money $amount = null, ?string $description = null)`) | one payment terms group per instalment with due date and partial amount |
| `additionalPaymentInstructions` (list of `PaymentInstructions`) | further payment means |

| `LineExtendedData` | Written as |
|---|---|
| `lineStatusReasonCode` (`LineExtendedData::STATUS_DETAIL`, `STATUS_GROUP`, `STATUS_INFORMATION`) | Line status; default `GROUP` for lines with sub lines and `DETAIL` for sub lines |
| `shipTo` (`ExtendedParty`) | Ship-to party of the line |
| `deliveryDate` (`Date`) | Delivery date of the line |
| `includedProducts` (list of `IncludedProduct(string $name, ?BigDecimal $quantity = null, UnitCode\|RawCode\|null $unit = null, ?string $description = null, ?string $sellerId = null)`) | Included products |

`ExtendedParty(string $name, ?Address $address = null, array $identifiers = [], ?string $vatId = null, ?Contact $contact = null)`. A line `DocumentReference(string $id, string $kind = DocumentReference::TYPE_OTHER, ?string $lineId = null, ?Date $issueDate = null, ?RawCode $typeCode = null, ?string $description = null)` (added with `LineBuilder::reference()`) is written by kind: `TYPE_ORDER`, `TYPE_CONTRACT`, `TYPE_DESPATCH_ADVICE`, `TYPE_RECEIVING_ADVICE` or `TYPE_OTHER`.

```php
use Quittio\Core\Data\Extensions\ExtendedData;
use Quittio\Core\Data\Extensions\ExtendedParty;
use Quittio\Core\Data\Extensions\Installment;
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

`xrechnungExtension(XRechnungExtensionData $extension)` adds data of the XRechnung extension: third party payments (BG-DEX-09), `new XRechnungExtensionData([new ThirdPartyPayment(string $type, Money $amount, string $description)])`. They are added to the amount due (BR-DEX-09) and can only be transmitted in an XRechnung UBL invoice with profile `xrechnung-extension` (UBL `cac:PrepaidPayment`); CII, credit notes and other profiles fail with `QUITTIO-THIRD-PARTY-PAYMENTS`.

## Presentation and metadata

| Method | Description |
|---|---|
| `presentation(Presentation\|callable $presentation)` | Design of this invoice in PDF and HTML (logo, colours, font, texts, footer, line groups, gross prices, payment QR code, watermark, paper, labels, title …). A callable receives the current `Presentation` (or an empty one) and returns the new one. Not part of the XML. See [Templates and design](templates.md). |
| `group(string $id, string $title, bool $showSubtotal = true)` | Defines a line group of the PDF; lines join it with `LineBuilder::group($id)`. |
| `locale(string $locale)` | Language of the invoice (labels, generated texts, exemption texts of presets). Rendering uses the locale of the call, then this one, then the configured locale, then the country pack default. |
| `timezone(string $timezone)` | Time zone for dates (see [Dates](#dates)). |
| `custom(string $key, mixed $value)` | Free data for templates (view model key `meta.custom`). |
| `source(string $source)` | Origin of the data, e.g. `shop` (view model key `meta.source`). |
| `flag(string $flag, bool $enabled = true)` | Sets or removes a flag that country packs evaluate, e.g. `Meta::FLAG_SMALL_BUSINESS` (`small_business`) or the German `Quittio\Country\DE\Support\Flags::SMALL_AMOUNT` (`de.small_amount`, see [Meta flags](country-packs.md#meta-flags)). |

```php
use Quittio\Core\Data\Presentation\Presentation;

->presentation(fn (Presentation $p) => $p->with(['introText' => 'Thank you for your order.', 'showGrossPrices' => true]))
->group('hardware', 'Hardware')
->line(Line::make('Notebook')->quantity(1)->netPrice('1200.00')->vat(VatPreset::Standard19)->group('hardware'))
```

The flags `declared_amounts`, `declared_amounts_recalculated`, `cancellation_vat_aligned` and those with the prefixes `amounts_fingerprint:` and `declared_vat_in_tax_currency:` are set by Quittio itself ([Declared amounts](#declared-amounts)); do not set them.

## Application models

Application models can provide invoice data through three contracts in `Quittio\Core\Contracts`:

| Contract | Method | Used by |
|---|---|---|
| `ProvidesParty` | `fillParty(PartyBuilder $party): void` | `InvoiceBuilder::buyer($model)` and `seller($model)`, `PartyBuilder::fromModel($model)`, e.g. a customer or user as buyer |
| `ProvidesInvoiceLine` | `fillLine(LineBuilder $line): void` | `InvoiceBuilder::line($model)`, `lines($models)`, `LineBuilder::fromModel($model)` |
| `ProvidesInvoiceData` | `toInvoiceData(): InvoiceData` | `Quittio::make($model)` (core and Laravel), `Invoice::draftFor($model)` and `HasInvoices::draftInvoice()` in the [Managed layer](managed.md) |

```php
use Quittio\Core\Contracts\ProvidesInvoiceData;
use Quittio\Core\Contracts\ProvidesInvoiceLine;

class OrderItem extends Model implements ProvidesInvoiceLine
{
    public function fillLine(LineBuilder $line): void
    {
        $line->id((string) $this->position)
            ->name($this->product_name)
            ->sellerId($this->sku)
            ->quantity((string) $this->quantity, UnitCode::Piece)
            ->netPrice($this->unit_price)                       // decimal string from the database
            ->vat(VatPreset::from($this->tax_preset));          // stored preset key, e.g. "de.standard_19"
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

`fillParty()` and `fillLine()` receive a fresh builder (or, for parties, the builder of the party set before) and may set any value; the invoice keeps a copy, so changing the model later does not change invoices that were already built. Store amounts as decimal strings (database `decimal` columns), not as floats.

## Calculation

`Quittio\Core\Calculation\En16931Calculator` calculates line net amounts, document allowances and charges, the VAT breakdown and the totals. Every render, check and prepare run calls it, so data from the builder never needs to be calculated by hand. To calculate without rendering, call it directly or use `prepare()` ([Formats and profiles](formats.md#rendering-in-the-core)):

```php
use Quittio\Core\Calculation\En16931Calculator;

$calculated = (new En16931Calculator)->calculate($invoice);
$calculated->isCalculated();                       // true
$calculated->totals->amountDue;                    // Brick\Money\Money
$calculated->vatBreakdown[0]->taxAmount;
```

Amounts are rounded half up to two decimals, at the points listed below only; VAT is calculated per VAT group (category and rate), never per line (BR-CO-17). Calculating calculated data again gives the same result.

### Line amounts

| Step | Formula |
|---|---|
| Net price BT-146 | as given; otherwise list price BT-148 − price discount BT-147 |
| Line amount before allowances and charges B | round2(quantity BT-129 × net price BT-146 / base quantity BT-149), the base quantity only if it is set and not 0 or 1 |
| Line allowance BT-136 | as given; otherwise round2((base BT-137, default B) × percentage BT-138 / 100) |
| Line charge BT-141 | as given; otherwise round2((base BT-142, default B) × percentage BT-143 / 100) |
| Line net amount BT-131 | B − Σ line allowances + Σ line charges |

A line with sub lines and without own price takes the sum of the net amounts of its sub lines as line amount (the price is derived from it with up to ten decimals). `LineCalculator` (`calculate(InvoiceLine $line, string $currency)`) does this per line; its static helpers are `baseAmount($quantity, $netPrice, $baseQuantity)` (B), `exactAmount(InvoiceLine $line)` (the unrounded line amount that PEPPOL-EN16931-R120 checks) and `priceFor($amount, $quantity, $baseQuantity)` (the shortest net price, 2 to 10 decimals, for which the line formula yields an amount).

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

The VAT groups appear in the breakdown in the order of their first line; a document allowance or charge for a group without lines adds its own group. A rounding amount of 0 is left out.

### Gross price conversion

`GrossPriceConverter` applies when a line has a price including VAT (`priceInclVat()`). Per VAT group:

1. G = Σ g_i with g_i = round2(quantity × price including VAT).
2. N = round2(G / (1 + rate / 100)), the taxable amount.
3. T = round2(N × rate / 100), the VAT (BR-CO-17).
4. Every line gets n_i = round2(g_i / (1 + rate / 100)); the cents N − Σ n_i go to the lines with the largest rounding remainders. A line keeps the sign of its gross amount (returns and vouchers stay negative), a cent never moves a line to zero or across zero while another line can take it. Net prices are derived with the precision needed for the line formula to yield n_i.
5. G − (N + T) of all groups becomes the rounding amount BT-114, so that the amount due equals the gross amount the customer saw.

Example: 3 × 9.99 including 19 % gives G = 29.97, N = 25.18, T = 4.78, net price 8.393, BT-114 = 0.01 and an amount due of 29.97. `GrossPriceConverter::netOf(BigDecimal $gross, BigDecimal $rate)` is step 2.

### Discount splitting

`AllowanceSplitter::splitAllowance(array $lines, string $currency, ?BigDecimal $percent, ?Money $amount, ?string $reason = null, AllowanceReasonCode|RawCode|null $reasonCode = null): list<DocumentAllowance>` implements `discountPercent()` and `discountAmount()`: the discount (the amount, or round2(line sum × percentage / 100)) is distributed over the VAT groups in proportion to their signed line sums with `LargestRemainder::distribute(BigDecimal $total, array $weights, int $scale = 2)`. That method floors every part, gives the remaining cents to the parts with the largest remainders (ties in the order of the weights) and returns parts that add up exactly to the total; weights that are all zero share equally, weights that add up to zero throw a `CalculationException`. A discount of 10.00 on 100.00 at 19 % and 50.00 at 7 % becomes 6.67 and 3.33.

### Declared amounts

Data that Quittio did not calculate itself can carry amounts that another system rounded differently, e.g. line net amounts rounded per line or a VAT amount within the tolerance of BR-CO-17. Recalculating such data would change amounts that were already issued, and a cancellation would no longer reverse the original exactly. Quittio therefore keeps declared amounts in two cases:

- `InvoiceBuilder::from($data)` with data that carries line net amounts or a VAT breakdown that the recalculation does not reproduce exactly (e.g. an invoice read from XML), as long as no amount-relevant input changes: lines, document allowances and charges, discounts and the currency. Changing a line, adding one or calling `allowance()` recalculates everything.
- Cancellations of such data ([below](#cancellations-and-corrections)).

The builder then sets `Meta::FLAG_DECLARED_AMOUNTS` with a fingerprint of the amount inputs (`DeclaredAmounts::fingerprint()`, an xxh128 hash over identifiers, quantities, prices, VAT groups, allowances, charges, net amounts, VAT breakdown and currency). The calculator keeps a declared line net amount BT-131 within 0.02 of the unrounded line formula (`DeclaredAmounts::LINE_TOLERANCE`, as PEPPOL-EN16931-R120 tolerates it; a parent line may also state the sum of its sub lines), a declared VAT amount BT-117 less than one currency unit from the recalculation (BR-CO-17; 0 for categories without VAT) and a declared BT-111 within 0.01 (`DeclaredAmounts::TAX_CURRENCY_TOLERANCE`). Amounts beyond these tolerances are recalculated and the data gets `Meta::FLAG_DECLARED_AMOUNTS_RECALCULATED`, which the validation reports as `QUITTIO-DECLARED-AMOUNTS` (the document may not reverse the original exactly). Data whose amount inputs no longer match the fingerprint, e.g. changed with `with()` or edited in stored JSON, is recalculated completely. Data calculated by Quittio never needs declared amounts. The calculator also writes a fingerprint to every result, so `from()`, cancellations and the calculator never keep amounts that were changed afterwards.

The helpers are public: `DeclaredAmounts::needed(InvoiceData $data)` (the recalculation does not reproduce the declared amounts), `isStale()` (changed after the amounts were calculated or declared), `isTrusted()` (flagged and fingerprint matches), `hasAmounts()` and `breakdownKey(VatCategory $category, ?BigDecimal $rate)` (VAT group key, e.g. `S|19.00`).

Data read from XML has no exchange rate. `InvoiceData::withImpliedExchangeRate()` returns a copy with the rate BT-111 / BT-110 (ten decimals); `from()` takes it over so that the recalculation reproduces BT-111, and drops it once amounts or the VAT accounting currency change, because it is only as precise as the declared amounts. Set the rate with `exchangeRate()` then, otherwise the validation fails with BR-53.

## Changing existing data

`InvoiceBuilder::from(InvoiceData $data)` takes over every value of the data, including lines, document allowances and charges, payment data, presentation and metadata, so drafts, stored invoices and read invoices can be changed with the normal builder methods:

```php
$draft = InvoiceBuilder::from($stored)
    ->issueDate('2026-10-20')
    ->buyer(fn (PartyBuilder $b) => $b->contact('Lena Kunde', null, 'lena@kunde.de'))
    ->updateLine('1', fn (InvoiceLine $l) => $l->withNetPrice('90.00'))
    ->build();
```

- Totals and the VAT breakdown are not taken over (except [declared amounts](#declared-amounts)); the result is calculated again when rendered.
- Percentage document allowances and charges whose base is the line sum of their VAT group, and discounts that `discountPercent()` or `discountAmount()` split over the VAT groups, follow the lines when lines are added, changed or removed: they are recalculated or split again (a VAT group without lines keeps no allowance of 0.00, a new VAT group gets its part).
- `updateLine()` resets the line net amount and recalculates percentage line allowances and charges; a base amount that equalled the previous line amount follows the new one.
- `InvoiceLine::withNetPrice()` drops list price and discount; it throws for a line with a price including VAT, `withPriceInclVat()` throws for a line with a net price (the gross price mode applies to all lines).
- `paymentTerms()` with a callable starts new payment terms; seller and buyer callables continue the existing parties.

## Cancellations and corrections

A cancellation is a new invoice with its own number that reverses an issued invoice and refers to it (BG-3); the original is never changed. The core builds it with `Quittio::cancellation()`, which returns an `InvoiceBuilder`:

```php
use Quittio\Core\Support\Date;

$builder = $quittio->cancellation($original, 'ST-2026-0001', reason: 'Charged twice');   // core instance
$builder = Quittio::cancellation($original, 'ST-2026-0001', reason: 'Charged twice');    // Laravel facade

$result = $quittio->make($builder)->render();
```

`cancellation(InvoiceData $original, string $number, ?string $reason = null, ?string $strategy = null, ?Date $issueDate = null, ?array $lines = null, ?CancellationHistory $history = null, ?string $title = null): InvoiceBuilder`

| Parameter | Description |
|---|---|
| `$original` | The issued invoice, calculated (e.g. `RenderedInvoice::data()`, a stored snapshot or read data). A cancellation reverses its amounts exactly, also amounts another system rounded ([declared amounts](#declared-amounts)). |
| `$number` | Number of the cancellation. |
| `$reason` | Appended to the cancellation note ("Reason: …"). |
| `$strategy` | `credit_note` or `negative_invoice` (`CorrectionPolicy::CANCELLATION_CREDIT_NOTE`, `CANCELLATION_NEGATIVE_INVOICE`); default the configured `corrections.cancellation_strategy`, then the country pack (Germany: `credit_note`). Another value throws an `InvalidArgumentException`. |
| `$issueDate` | A `Date` (`Date::parse('2026-10-20')`), default today in the time zone of the original. |
| `$lines` | Partial cancellation ([below](#partial-cancellations)); `null` cancels the whole invoice. |
| `$history` | Earlier partial cancellations of the original, for the cumulative VAT rounding ([below](#cumulative-vat-rounding)). |
| `$title` | Document title in PDF, HTML and file name (`Presentation::$title`), e.g. "Stornorechnung"; default the title `cancellation` of the country pack's correction policy (`cancellation_self_billed` for self-billed originals), without one the label of the type code. |

The leading country pack of the original provides the correction policy and the locale of the texts (locale of the original, then the configured locale, then the pack default). Without a `Quittio` instance, `Cancellation::of(InvoiceData $original, string $number, string $strategy = 'credit_note', ?string $reason = null, ?Date $issueDate = null, ?string $locale = null, ?string $title = null, ?array $lines = null, ?CorrectionPolicy $policy = null, ?CancellationHistory $history = null)` (`Quittio\Core\Corrections\Cancellation`) does the same; note the different parameter order, and that without `$title` or `$policy` the document title comes from the type code.

The cancellation is a copy of the original with these changes:

| Field | Value |
|---|---|
| Number, issue date | `$number`, `$issueDate` |
| Type code | by strategy, see below |
| Preceding invoice (BG-3) | the original's number and issue date (replaces earlier references) |
| Notes (BG-1) | "Stornierung der Rechnung RE-2026-0042 vom 03.10.2026." ("Cancellation of invoice … of …", self-billed: "Stornierung der Gutschrift …") with subject `AAI`, plus the reason; the original's notes with subject `REG` and `TXD` are kept, others dropped |
| Payment terms, due date | "Der Betrag wird mit offenen Forderungen verrechnet oder erstattet." ("The amount will be offset against open items or refunded."); no due date |
| Lines, allowances, charges | all lines with document allowances and charges, or the selected lines |
| Prepaid amount BT-113, rounding amount BT-114, third party payments | taken over by a full cancellation, so that the amounts due match; not by a partial one |
| Title in PDF and file name | from the correction policy (Germany: "Stornorechnung", for self-billed originals "Storno-Gutschrift") |

Everything else (parties, references, payment instructions, delivery, currency, attachments, presentation) stays as in the original. The result is an `InvoiceBuilder`, so further changes are possible before rendering, e.g. `->note('…')` or `->payment(PaymentMeansCode::SepaCreditTransfer, iban: '…')` for the refund account; changing lines or allowances recalculates the amounts.

### Sign strategies

| Original | `credit_note` | `negative_invoice` |
|---|---|---|
| Invoice (380, 384, 326 …) | 381, positive amounts | 380, negative quantities and amounts |
| Credit note (`isCreditNote()`) | 380, positive amounts | 380, positive amounts |
| Self-billed invoice (389) or debit note (527) | 261, positive amounts | 389, negative quantities and amounts |
| Self-billed credit note (261) | 389, positive amounts | 389, positive amounts |

A credit note is reversed by an invoice with the same positive amounts in both strategies; a second credit note would credit twice. With `negative_invoice` the quantities, line net amounts, line and document allowances and charges (amounts and base amounts), BT-113, BT-114 and third party payments are negated; prices stay positive (BR-27). Self-billed documents stay self-billed. Both strategies are permitted in Germany; the German pack titles the document "Stornorechnung", never "Gutschrift" (except for self-billing). UBL writes a cancellation with `credit_note` as `CreditNote`. The XRechnung extension has no UBL credit note: use `negative_invoice` there.

### Partial cancellations

```php
$builder = $quittio->cancellation($original, 'ST-2026-0002', reason: 'Partial return', lines: [
    ['line' => '3'],                         // the whole line
    ['line' => '1', 'quantity' => '1'],      // 1 of 3
    ['line' => '2', 'amount' => '50.00'],    // an amount, e.g. a price reduction
]);
```

- Each entry names a line identifier (BT-126) once, with a `quantity`, an `amount` or neither (the whole line); quantity and amount together throw an `InvalidArgumentException`, as do an unknown line or an empty list.
- Quantities and amounts are magnitudes: a negative line (discount, return) keeps its sign and is reversed with it. A quantity must be greater than 0 and at most the invoiced quantity; line allowances and charges are scaled in proportion. Lines with sub lines can only be cancelled as a whole.
- An amount becomes one unit (C62) at that net price, without allowances and charges; it must be greater than 0 and at most the line net amount, so the original must be calculated. For lines with a price including VAT the amount includes VAT and is compared with the gross line amount.
- Whole lines keep the original's declared net amounts; quantities and amounts are recalculated.
- Invoices with document level allowances or charges cannot be cancelled partially (they cannot be assigned to single lines reliably); cancel fully and issue a new invoice.
- Each part is rounded on its own: three cancellations of one unit of a line of 29.00 net for 3 units give 9.67 each. Cancel the remainder of a line as an amount (`['line' => '1', 'amount' => '9.66']`), so that the parts add up exactly to the line. The Managed layer tracks this per line ([Managed layer](managed.md#cancellations-and-corrections)).

### Cumulative VAT rounding

VAT is rounded per document and VAT group. Rounded per partial cancellation on its own, the parts together can differ from the original by a cent per part: three parts of 9.67, 9.67 and 9.66 net at 19 % carry 3 × 1.84 = 5.52 VAT, the original 5.51. With a `CancellationHistory` of the earlier partial cancellations, the VAT is rounded cumulatively per VAT group:

- S = taxable amount of the earlier cancellations + taxable amount of this one,
- target = round2(S × rate / 100), or the original's VAT BT-117 when this cancellation closes the group (all its lines are cancelled completely afterwards),
- VAT of this cancellation = target − VAT of the earlier cancellations.

The three parts then carry 1.84, 1.83 and 1.84 = 5.51. Every part differs by at most 0.01 from round2(own taxable amount × rate) and keeps the sign of its taxable amount; the validation reports the difference as information `QUITTIO-CANCELLATION-VAT-ALIGNED`, and the cancellation note says "The VAT is aligned with the amounts already cancelled.". For an original whose VAT another system rounded (BT-117 ≠ round2(BT-116 × rate)) the target uses its effective rate BT-117 / BT-116, which spreads the difference over the parts (not for groups with positive and negative lines). In gross price mode the taxable amounts follow the same way from the gross amounts cancelled so far, and the rounding amounts (BT-114) of all parts add up to the original's. BT-111 in accounting currency is aligned the same way at the original's exchange rate. Without a history, or for an original whose amounts are stale, the VAT is rounded per document.

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

A country pack defines its defaults in `Quittio\Core\Countries\CorrectionPolicy`: `new CorrectionPolicy(string $cancellationStrategy = 'credit_note', string $correctionStrategy = 'cancel_and_reissue', array $titles = [])` with the constants `CANCELLATION_CREDIT_NOTE`, `CANCELLATION_NEGATIVE_INVOICE`, `CORRECTION_CANCEL_AND_REISSUE` and `CORRECTION_CORRECTED_INVOICE`. `$titles` maps a document kind to titles per locale (`['cancellation' => ['de' => 'Stornorechnung', 'en' => 'Cancellation invoice']]`); `title(string $kind, string $locale = 'en')` and `cancellationTitle(bool $selfBilled, string $locale = 'en')` read them (self-billed originals use `cancellation_self_billed` if defined). The correction strategy is used by the Managed layer; in the core, correct an invoice by cancelling it and issuing a new one (`InvoiceBuilder::from($original)->number(…)`), or with a corrected invoice (type code 384, [above](#credit-notes-corrected-invoices-and-self-billing)).

## Data model

`Quittio\Core\Data\InvoiceData` is the root of the model, a superset of EN 16931 (versions 2017 and 2026) with the extensions of profile EXTENDED and the XRechnung extension. All data classes are `final readonly` with promoted constructor properties; the [field reference](field-reference.md) lists every field with business term, cardinality, profile level and XML paths.

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

`Totals` has `lineNetSum` (BT-106), `allowanceSum` (BT-107), `chargeSum` (BT-108), `netTotal` (BT-109), `vatTotal` (BT-110), `vatTotalInTaxCurrency` (BT-111), `grossTotal` (BT-112), `prepaidAmount` (BT-113), `roundingAmount` (BT-114) and `amountDue` (BT-115), all `Brick\Money\Money`. A `VatBreakdown` has `taxableAmount` (BT-116), `taxAmount` (BT-117), `category` (BT-118), `rate` (BT-119), `exemptionReason` (BT-120) and `exemptionReasonCode` (BT-121). Amounts are `Money` with two decimals, prices, quantities and rates `Brick\Math\BigDecimal`, dates `Quittio\Core\Support\Date` (`toIsoString()`, `format()`, `addDays()`, `toCarbon()`, `isBefore()` …).

### Immutability

Data objects never change. `with(array $changes)` returns a copy with the named properties replaced (an unknown property throws an `InvalidArgumentException`):

```php
$copy = $invoice->with(['buyerReference' => '04011000-12345-03']);
$copy = $invoice->withNumber('RE-2026-0043')->withIssueDate(Date::parse('2026-10-05'));
$line = $invoice->lines[0]->with(['note' => 'Delivered in two parts']);
```

`with()` does not recalculate anything and checks nothing; prefer `InvoiceBuilder::from()` for changes of amounts, so that allowances and discounts follow the lines. Changing amount inputs of calculated data with `with()` makes its amounts stale: the next calculation recalculates them ([Declared amounts](#declared-amounts)).

Helpers of `InvoiceData`: `isCalculated()` (totals present), `typeCodeValue()` (BT-3 as string), `isCreditNote()`, `allLines()` (all lines including sub lines, depth first), `withImpliedExchangeRate()` and `InvoiceData::factory()` for test data ([Testing](testing.md)). Of the parts: `InvoiceLine::withNetPrice()`, `withPriceInclVat()`, `hasSubLines()`, `Price::isGrossMode()`, `LineVat::groupKey()`, `DocumentAllowanceCharge::isCharge()` and `reasonCodeValue()`, `PaymentInstructions::primaryCreditTransfer()`, `CreditTransfer::isIban()`, `PaymentTerms::isEmpty()`, `Period::isValid()`, `Attachment::size()`, `Identifier::schemeValue()`, `Meta::hasFlag()`, `Presentation::isExplicit()`.

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

- Keys are the property names; values equal to the constructor default are left out and restored on the way back.
- Amounts are strings with two decimals and without currency: `fromArray()` uses the invoice currency (BT-5), for BT-111 the VAT accounting currency (BT-6). Decimals are strings, dates `YYYY-MM-DD`, codes their value; the content of attachments is base64 encoded.
- `fromArray()` rejects unknown fields, missing required values, floats and values of the wrong type with a `Quittio\Core\Exceptions\HydrationException` that names the path (e.g. `lines[0].price.netPrice: Floats are not accepted. Pass monetary values and quantities as strings.`). A code that is not a case of its enum becomes a `RawCode` where the property allows one.
- `toJson()` always uses `JSON_THROW_ON_ERROR`, `JSON_UNESCAPED_SLASHES` and `JSON_UNESCAPED_UNICODE`; pass further flags such as `JSON_PRETTY_PRINT`.

The JSON Schema (draft 2020-12, `$id` `https://quittio.dev/schema/invoice.schema.json`) is shipped in `packages/core/resources/schema/invoice.schema.json` (`JsonSchemaGenerator::defaultPath()`). With the optional package `opis/json-schema`, `Quittio\Core\Serialization\JsonSchemaValidator` checks input before hydration: `JsonSchemaValidator::available()` tells whether the package is installed, `(new JsonSchemaValidator)->validate(string $json)` returns up to 20 errors as `path: message` (an empty list if valid) and throws a `RuntimeException` without the package.

### Snapshots

Stored invoices (the Managed layer, queued render jobs) use snapshots: `toSnapshot()` is `toArray()` plus the schema version `_version` (`InvoiceData::SNAPSHOT_VERSION`, currently 1). `InvoiceData::fromSnapshot(array $snapshot, ?SnapshotUpcasterChain $chain = null)` brings a snapshot of an older version to the current model before hydrating it; a snapshot without `_version` counts as version 1. Snapshots of issued invoices are never rewritten; upcasters run in memory when a snapshot is read.

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

`SnapshotUpcasterChain` (`register(SnapshotUpcaster $upcaster)`, `upcast(array $snapshot, int $from, int $to = InvoiceData::SNAPSHOT_VERSION)`) applies one upcaster per version step; `SnapshotUpcasterChain::default()` is the shared chain of `fromSnapshot()`, `useDefault(?SnapshotUpcasterChain $chain)` replaces it. A snapshot of a newer version than the installed package, an invalid version or a missing upcaster throw a `SnapshotVersionException`. The current model is version 1, so no upcaster is needed yet; upcasters matter once a release changes the model. In Laravel, list the upcaster classes in `snapshots.upcasters` ([Configuration](configuration.md)).
