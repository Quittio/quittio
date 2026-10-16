<?php

declare(strict_types=1);

namespace Quittio\Core\Xml\Ubl;

use Brick\Math\BigDecimal;
use DOMDocument;
use DOMElement;
use Quittio\Core\Codes\RawCode;
use Quittio\Core\Data\AllowanceCharge\LineAllowance;
use Quittio\Core\Data\AllowanceCharge\LineCharge;
use Quittio\Core\Data\Attachments\Attachment;
use Quittio\Core\Data\Delivery\Delivery;
use Quittio\Core\Data\Delivery\Period;
use Quittio\Core\Data\Extensions\ThirdPartyPayment;
use Quittio\Core\Data\Extensions\XRechnungExtensionData;
use Quittio\Core\Data\Identifier;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Data\Lines\Classification;
use Quittio\Core\Data\Lines\InvoiceLine;
use Quittio\Core\Data\Lines\Item;
use Quittio\Core\Data\Lines\ItemAttribute;
use Quittio\Core\Data\Lines\LineVat;
use Quittio\Core\Data\Lines\Price;
use Quittio\Core\Data\Note;
use Quittio\Core\Data\Party\Address;
use Quittio\Core\Data\Party\BuyerParty;
use Quittio\Core\Data\Party\Contact;
use Quittio\Core\Data\Party\ElectronicAddress;
use Quittio\Core\Data\Party\PayeeParty;
use Quittio\Core\Data\Party\SellerParty;
use Quittio\Core\Data\Party\TaxRepresentativeParty;
use Quittio\Core\Data\Payment\CashDiscount;
use Quittio\Core\Data\Payment\CreditTransfer;
use Quittio\Core\Data\Payment\DirectDebit;
use Quittio\Core\Data\Payment\PaymentCard;
use Quittio\Core\Data\Payment\PaymentInstructions;
use Quittio\Core\Data\Payment\PaymentTerms;
use Quittio\Core\Data\References\PrecedingInvoiceReference;
use Quittio\Core\Data\References\ProjectReference;
use Quittio\Core\Data\Totals\Totals;
use Quittio\Core\Enums\AllowanceReasonCode;
use Quittio\Core\Enums\ChargeReasonCode;
use Quittio\Core\Enums\ElectronicAddressScheme;
use Quittio\Core\Enums\IdentifierScheme;
use Quittio\Core\Enums\InvoiceTypeCode;
use Quittio\Core\Enums\ItemClassificationScheme;
use Quittio\Core\Enums\MimeType;
use Quittio\Core\Enums\NoteSubjectCode;
use Quittio\Core\Enums\PaymentMeansCode;
use Quittio\Core\Enums\TaxPointDateCode;
use Quittio\Core\Enums\UnitCode;
use Quittio\Core\Exceptions\InvalidArgumentException;
use Quittio\Core\Profiles\Syntax;
use Quittio\Core\Reading\FormatReader;
use Quittio\Core\Reading\ReadResult;
use Quittio\Core\Reading\XPathReader;
use Quittio\Core\Standards\StandardVersion;
use Quittio\Core\Validation\Severity;
use Quittio\Core\Xml\PaymentTermsText;

/**
 * Reads UBL 2.1 Invoice and CreditNote documents (EN 16931, XRechnung UBL, Peppol BIS) into the data model.
 */
final class UblReader extends XPathReader implements FormatReader
{
    /** Element paths of a document level allowance or charge (BG-20, BG-21), see XPathReader::documentAllowances(). */
    private const array ALLOWANCE_CHARGE = [
        'node' => 'cac:AllowanceCharge',
        'indicator' => 'cbc:ChargeIndicator',
        'amount' => 'cbc:Amount',
        'category' => 'cac:TaxCategory/cbc:ID',
        'rate' => 'cac:TaxCategory/cbc:Percent',
        'base' => 'cbc:BaseAmount',
        'percent' => 'cbc:MultiplierFactorNumeric',
        'reason' => 'cbc:AllowanceChargeReason',
        'reasonCode' => 'cbc:AllowanceChargeReasonCode',
    ];

    private const array TOTALS = [
        'node' => 'cac:LegalMonetaryTotal',
        // The VAT totals are siblings of the monetary totals.
        'vat' => '../cac:TaxTotal/cbc:TaxAmount',
        'lineNet' => 'cbc:LineExtensionAmount',
        'allowance' => 'cbc:AllowanceTotalAmount',
        'charge' => 'cbc:ChargeTotalAmount',
        'net' => 'cbc:TaxExclusiveAmount',
        'gross' => 'cbc:TaxInclusiveAmount',
        'due' => 'cbc:PayableAmount',
        'prepaid' => 'cbc:PrepaidAmount',
        'rounding' => 'cbc:PayableRoundingAmount',
    ];

    private const array VAT_BREAKDOWN = [
        'node' => 'cac:TaxTotal/cac:TaxSubtotal',
        'taxable' => 'cbc:TaxableAmount',
        'tax' => 'cbc:TaxAmount',
        'category' => 'cac:TaxCategory/cbc:ID',
        'rate' => 'cac:TaxCategory/cbc:Percent',
        'reason' => 'cac:TaxCategory/cbc:TaxExemptionReason',
        'reasonCode' => 'cac:TaxCategory/cbc:TaxExemptionReasonCode',
    ];

    private bool $creditNote = false;

    public function canRead(DOMDocument $document): bool
    {
        $root = $document->documentElement;

        return ($root?->namespaceURI === UblWriter::NS_INVOICE && $root->localName === 'Invoice')
            || ($root?->namespaceURI === UblWriter::NS_CREDIT_NOTE && $root->localName === 'CreditNote');
    }

    public function read(DOMDocument $document, string $xml): ReadResult
    {
        $root = $document->documentElement ?? throw new InvalidArgumentException('Empty document.');
        $this->creditNote = $root->localName === 'CreditNote';
        $this->init($document, [
            'doc' => $this->creditNote ? UblWriter::NS_CREDIT_NOTE : UblWriter::NS_INVOICE,
            'cac' => UblWriter::NS_CAC,
            'cbc' => UblWriter::NS_CBC,
        ]);

        $taxCurrency = $this->value($root, 'cbc:TaxCurrencyCode');
        $this->currency = $this->invoiceCurrency(
            $this->value($root, 'cbc:DocumentCurrencyCode'),
            $this->attribute($root, 'cac:LegalMonetaryTotal/cbc:PayableAmount', 'currencyID'),
        );
        $this->checkAmountCurrencies($document, $this->currency, $taxCurrency, $this->nodes($root, 'cac:TaxTotal/cbc:TaxAmount'));
        $specificationId = $this->value($root, 'cbc:CustomizationID');
        $lineElement = $this->creditNote ? 'cac:CreditNoteLine' : 'cac:InvoiceLine';
        $totals = $this->totals($root, self::TOTALS, $taxCurrency, $this->nodes($root, $lineElement) !== []);
        $breakdown = $this->vatBreakdown($root, self::VAT_BREAKDOWN);
        $project = $this->value($root, 'cac:ProjectReference/cbc:ID')
            ?? $this->value($root, 'cac:AdditionalDocumentReference[cbc:DocumentTypeCode="50"]/cbc:ID');
        $dueDate = $this->value($root, 'cbc:DueDate') ?? $this->value($root, 'cac:PaymentMeans/cbc:PaymentDueDate');
        $periodCode = $this->value($root, 'cac:InvoicePeriod/cbc:DescriptionCode');

        $data = new InvoiceData(
            number: $this->value($root, 'cbc:ID') ?? '',
            issueDate: $this->issueDate($this->date($this->value($root, 'cbc:IssueDate'), 'BT-2')),
            typeCode: $this->code($this->value($root, $this->creditNote ? 'cbc:CreditNoteTypeCode' : 'cbc:InvoiceTypeCode'), InvoiceTypeCode::class, 'BT-3') ?? new RawCode(''),
            currency: $this->currency,
            seller: $this->seller($root),
            buyer: $this->buyer($root),
            lines: array_map(fn (DOMElement $n): InvoiceLine => $this->line($n, false), $this->nodes($root, $lineElement)),
            taxCurrency: $taxCurrency,
            taxPointDate: $this->date($this->value($root, 'cbc:TaxPointDate'), 'BT-7'),
            taxPointDateCode: $this->taxPointDateCode($periodCode),
            dueDate: $this->date($dueDate, 'BT-9'),
            buyerReference: $this->value($root, 'cbc:BuyerReference'),
            projectReference: $project === null ? null : new ProjectReference($project),
            contractReference: $this->value($root, 'cac:ContractDocumentReference/cbc:ID'),
            purchaseOrderReference: ($order = $this->value($root, 'cac:OrderReference/cbc:ID')) === 'NA' ? null : $order,
            salesOrderReference: $this->value($root, 'cac:OrderReference/cbc:SalesOrderID'),
            receivingAdviceReference: $this->value($root, 'cac:ReceiptDocumentReference/cbc:ID'),
            despatchAdviceReference: $this->value($root, 'cac:DespatchDocumentReference/cbc:ID'),
            tenderReference: $this->value($root, 'cac:OriginatorDocumentReference/cbc:ID'),
            invoicedObject: $this->identifier($root, 'cac:AdditionalDocumentReference[cbc:DocumentTypeCode="130"]/cbc:ID'),
            buyerAccountingReference: $this->value($root, 'cbc:AccountingCost'),
            paymentTerms: $this->paymentTerms($root),
            notes: $this->notes($root),
            businessProcess: $this->value($root, 'cbc:ProfileID'),
            precedingInvoices: $this->precedingInvoices($root),
            payee: $this->payee($root),
            sellerTaxRepresentative: $this->taxRepresentative($root),
            delivery: $this->delivery($root),
            invoicingPeriod: $this->period($root, 'cac:InvoicePeriod'),
            paymentInstructions: $this->paymentInstructions($root),
            allowances: $this->documentAllowances($root, self::ALLOWANCE_CHARGE),
            charges: $this->documentCharges($root, self::ALLOWANCE_CHARGE),
            totals: $totals,
            vatBreakdown: $breakdown,
            attachments: $this->attachments($root),
            prepaidAmount: $totals?->prepaidAmount,
            roundingAmount: $totals?->roundingAmount,
            xrechnungExtension: $this->xrechnungExtension($root),
            standardVersion: StandardVersion::fromSpecificationId($specificationId),
        );

        return $this->result($data, Syntax::Ubl, $specificationId, $totals, $breakdown, $xml);
    }

    /**
     * Notes may carry the subject code as prefix "#CODE#text".
     *
     * @return list<Note>
     */
    private function notes(DOMElement $root): array
    {
        $notes = [];
        foreach ($this->nodes($root, 'cbc:Note') as $node) {
            $text = trim($node->textContent);
            if ($text === '') {
                continue;
            }
            $code = null;
            if (preg_match('/^#([A-Z]{3})#(.*)$/s', $text, $m) === 1) {
                $code = $this->code($m[1], NoteSubjectCode::class, 'BT-21');
                $text = $m[2];
            }
            $notes[] = new Note($text, $code);
        }

        return $notes;
    }

    private function seller(DOMElement $root): SellerParty
    {
        $party = $this->node($root, 'cac:AccountingSupplierParty/cac:Party') ?? $root;
        $identifiers = [];
        foreach ($this->nodes($party, 'cac:PartyIdentification/cbc:ID') as $node) {
            if ($node->getAttribute('schemeID') === 'SEPA') {
                continue;
            }
            $identifiers[] = new Identifier(trim($node->textContent), $this->scheme($node->getAttribute('schemeID')));
        }

        return new SellerParty(
            name: $this->value($party, 'cac:PartyLegalEntity/cbc:RegistrationName') ?? '',
            address: $this->address($party, 'cac:PostalAddress') ?? new Address(''),
            tradingName: $this->value($party, 'cac:PartyName/cbc:Name'),
            identifiers: $identifiers,
            legalRegistration: $this->identifier($party, 'cac:PartyLegalEntity/cbc:CompanyID'),
            vatId: $this->value($party, 'cac:PartyTaxScheme[cac:TaxScheme/cbc:ID="VAT"]/cbc:CompanyID'),
            taxNumber: $this->value($party, 'cac:PartyTaxScheme[cac:TaxScheme/cbc:ID!="VAT"]/cbc:CompanyID'),
            additionalLegalInfo: $this->value($party, 'cac:PartyLegalEntity/cbc:CompanyLegalForm'),
            electronicAddress: $this->endpoint($party),
            contact: $this->contact($party),
        );
    }

    private function buyer(DOMElement $root): BuyerParty
    {
        $party = $this->node($root, 'cac:AccountingCustomerParty/cac:Party') ?? $root;

        return new BuyerParty(
            name: $this->value($party, 'cac:PartyLegalEntity/cbc:RegistrationName') ?? '',
            address: $this->address($party, 'cac:PostalAddress') ?? new Address(''),
            tradingName: $this->value($party, 'cac:PartyName/cbc:Name'),
            identifier: $this->identifier($party, 'cac:PartyIdentification/cbc:ID'),
            legalRegistration: $this->identifier($party, 'cac:PartyLegalEntity/cbc:CompanyID'),
            vatId: $this->value($party, 'cac:PartyTaxScheme/cbc:CompanyID'),
            electronicAddress: $this->endpoint($party),
            contact: $this->contact($party),
        );
    }

    private function payee(DOMElement $root): ?PayeeParty
    {
        $party = $this->node($root, 'cac:PayeeParty');
        if ($party === null) {
            return null;
        }

        return new PayeeParty(
            name: $this->value($party, 'cac:PartyName/cbc:Name') ?? '',
            identifier: $this->identifier($party, 'cac:PartyIdentification/cbc:ID'),
            legalRegistration: $this->identifier($party, 'cac:PartyLegalEntity/cbc:CompanyID'),
        );
    }

    private function taxRepresentative(DOMElement $root): ?TaxRepresentativeParty
    {
        $party = $this->node($root, 'cac:TaxRepresentativeParty');
        if ($party === null) {
            return null;
        }

        return new TaxRepresentativeParty(
            name: $this->value($party, 'cac:PartyName/cbc:Name') ?? '',
            vatId: $this->value($party, 'cac:PartyTaxScheme/cbc:CompanyID') ?? '',
            address: $this->address($party, 'cac:PostalAddress') ?? new Address(''),
        );
    }

    private function address(DOMElement $context, string $path): ?Address
    {
        $node = $this->node($context, $path);
        if ($node === null) {
            return null;
        }

        return new Address(
            countryCode: $this->value($node, 'cac:Country/cbc:IdentificationCode') ?? '',
            line1: $this->value($node, 'cbc:StreetName'),
            line2: $this->value($node, 'cbc:AdditionalStreetName'),
            line3: $this->value($node, 'cac:AddressLine/cbc:Line'),
            city: $this->value($node, 'cbc:CityName'),
            postcode: $this->value($node, 'cbc:PostalZone'),
            subdivision: $this->value($node, 'cbc:CountrySubentity'),
        );
    }

    private function contact(DOMElement $party): ?Contact
    {
        $node = $this->node($party, 'cac:Contact');
        if ($node === null) {
            return null;
        }

        return new Contact(
            name: $this->value($node, 'cbc:Name'),
            phone: $this->value($node, 'cbc:Telephone'),
            email: $this->value($node, 'cbc:ElectronicMail'),
        );
    }

    private function endpoint(DOMElement $party): ?ElectronicAddress
    {
        $value = $this->value($party, 'cbc:EndpointID');
        if ($value === null) {
            return null;
        }

        return new ElectronicAddress($value, $this->code($this->attribute($party, 'cbc:EndpointID', 'schemeID') ?? '', ElectronicAddressScheme::class, 'BT-34-1') ?? new RawCode(''));
    }

    private function identifier(DOMElement $context, string $path): ?Identifier
    {
        $value = $this->value($context, $path);
        if ($value === null) {
            return null;
        }

        return new Identifier($value, $this->scheme($this->attribute($context, $path, 'schemeID')));
    }

    private function scheme(?string $value): IdentifierScheme|RawCode|null
    {
        return $value === null || $value === '' ? null : $this->code($value, IdentifierScheme::class, 'scheme');
    }

    /**
     * @return list<PrecedingInvoiceReference>
     */
    private function precedingInvoices(DOMElement $root): array
    {
        $result = [];
        foreach ($this->nodes($root, 'cac:BillingReference/cac:InvoiceDocumentReference') as $node) {
            $number = $this->value($node, 'cbc:ID');
            if ($number !== null) {
                $result[] = new PrecedingInvoiceReference($number, $this->date($this->value($node, 'cbc:IssueDate'), 'BT-26'));
            }
        }

        return $result;
    }

    /**
     * @return list<Attachment>
     */
    private function attachments(DOMElement $root): array
    {
        $result = [];
        foreach ($this->nodes($root, 'cac:AdditionalDocumentReference') as $node) {
            if (in_array($this->value($node, 'cbc:DocumentTypeCode'), ['130', '50'], true)) {
                continue;
            }
            $binary = $this->node($node, 'cac:Attachment/cbc:EmbeddedDocumentBinaryObject');
            $content = null;
            $mimeType = null;
            $filename = null;
            if ($binary !== null) {
                $decoded = base64_decode(trim($binary->textContent), true);
                $mimeType = MimeType::tryFrom($binary->getAttribute('mimeCode'));
                $filename = $this->attachmentFilename($binary->getAttribute('filename'));
                if ($decoded === false || $mimeType === null || $filename === null) {
                    $this->finding('QUITTIO-READER-ATTACHMENT', sprintf('The attached document "%s" (BT-125) is not valid Base64, has an unsupported MIME code or no usable file name and was skipped.', self::excerpt($binary->getAttribute('filename'))), 'BT-125');
                    $mimeType = null;
                    $filename = null;
                } else {
                    $content = $decoded;
                }
            }

            $result[] = new Attachment(
                id: $this->value($node, 'cbc:ID') ?? '',
                description: $this->value($node, 'cbc:DocumentDescription'),
                externalUri: $this->value($node, 'cac:Attachment/cac:ExternalReference/cbc:URI'),
                content: $content,
                mimeType: $mimeType,
                filename: $filename,
            );
        }

        return $result;
    }

    private function delivery(DOMElement $root): ?Delivery
    {
        $node = $this->node($root, 'cac:Delivery');
        if ($node === null) {
            return null;
        }

        return new Delivery(
            recipientName: $this->value($node, 'cac:DeliveryParty/cac:PartyName/cbc:Name'),
            locationId: $this->identifier($node, 'cac:DeliveryLocation/cbc:ID'),
            actualDeliveryDate: $this->date($this->value($node, 'cbc:ActualDeliveryDate'), 'BT-72'),
            address: $this->address($node, 'cac:DeliveryLocation/cac:Address'),
        );
    }

    private function period(DOMElement $context, string $path): ?Period
    {
        $node = $this->node($context, $path);
        if ($node === null) {
            return null;
        }

        $start = $this->date($this->value($node, 'cbc:StartDate'), 'BT-73');
        $end = $this->date($this->value($node, 'cbc:EndDate'), 'BT-74');

        return $start === null && $end === null ? null : new Period($start, $end);
    }

    private function paymentTerms(DOMElement $root): ?PaymentTerms
    {
        $parsed = PaymentTermsText::parse($this->value($root, 'cac:PaymentTerms/cbc:Note'));
        $terms = new PaymentTerms(
            text: $parsed['text'],
            discounts: array_map(
                fn (array $d): CashDiscount => new CashDiscount($d['days'], BigDecimal::of($d['percent']), $this->money($d['base'], $this->currency, 'BT-20')),
                $parsed['discounts'],
            ),
        );

        return $terms->isEmpty() ? null : $terms;
    }

    private function paymentInstructions(DOMElement $root): ?PaymentInstructions
    {
        $means = $this->nodes($root, 'cac:PaymentMeans');
        if ($means === []) {
            return null;
        }

        $first = $means[0];
        $transfers = [];
        $card = null;
        $debit = null;
        foreach ($means as $node) {
            $account = $this->value($node, 'cac:PayeeFinancialAccount/cbc:ID');
            if ($account !== null) {
                $transfers[] = new CreditTransfer($account, $this->value($node, 'cac:PayeeFinancialAccount/cbc:Name'), $this->value($node, 'cac:PayeeFinancialAccount/cac:FinancialInstitutionBranch/cbc:ID'));
            }
            $cardNumber = $this->value($node, 'cac:CardAccount/cbc:PrimaryAccountNumberID');
            if ($cardNumber !== null && $card === null) {
                $card = new PaymentCard(substr($cardNumber, -6), $this->value($node, 'cac:CardAccount/cbc:HolderName'));
            }
            $mandate = $this->node($node, 'cac:PaymentMandate');
            if ($mandate !== null && $debit === null) {
                $debit = [$this->value($mandate, 'cbc:ID'), $this->value($mandate, 'cac:PayerFinancialAccount/cbc:ID')];
            }
        }

        $creditorId = $this->value($root, 'cac:AccountingSupplierParty/cac:Party/cac:PartyIdentification/cbc:ID[@schemeID="SEPA"]');

        return new PaymentInstructions(
            meansCode: $this->code($this->value($first, 'cbc:PaymentMeansCode'), PaymentMeansCode::class, 'BT-81') ?? new RawCode(''),
            meansText: $this->attribute($first, 'cbc:PaymentMeansCode', 'name'),
            remittanceInformation: $this->value($first, 'cbc:PaymentID'),
            creditTransfers: $transfers,
            card: $card,
            directDebit: $debit === null && $creditorId === null ? null : new DirectDebit($debit[0] ?? null, $creditorId, $debit[1] ?? null),
        );
    }

    private function taxPointDateCode(?string $value): TaxPointDateCode|RawCode|null
    {
        if ($value === null) {
            return null;
        }

        $code = TaxPointDateCode::fromUbl($value);
        if ($code instanceof RawCode) {
            $this->finding('QUITTIO-READER-UNKNOWN-CODE', sprintf('The VAT point date code "%s" (BT-8) is not part of UNTDID 2005 (3, 35, 432) and was kept as raw value.', self::excerpt($value)), 'BT-8', Severity::Warning);
        }

        return $code;
    }

    private function line(DOMElement $node, bool $subLine): InvoiceLine
    {
        $c = $this->currency;
        $quantityElement = $this->creditNote ? 'cbc:CreditedQuantity' : 'cbc:InvoicedQuantity';
        $lineId = $this->value($node, 'cbc:ID') ?? '';
        $unitCode = $this->attribute($node, $quantityElement, 'unitCode');
        if ($this->node($node, $quantityElement) === null) {
            $subLine
                ? $this->finding('QUITTIO-READER-SUBLINE-QUANTITY', sprintf('Sub line %s has no quantity (BT-129); 0 is used.', $lineId), 'BT-129', Severity::Info)
                : $this->finding('BR-22', sprintf('Line %s has no invoiced quantity (BT-129); 0 is used.', $lineId), 'BT-129', Severity::Error);
        } elseif ($unitCode === null) {
            $subLine
                ? $this->finding('QUITTIO-READER-SUBLINE-UNIT', sprintf('Sub line %s has no unit of measure for its quantity (BT-130).', $lineId), 'BT-130', Severity::Info)
                : $this->finding('BR-23', sprintf('Line %s has no unit of measure for the invoiced quantity (BT-130).', $lineId), 'BT-130', Severity::Error);
        }
        $item = $this->node($node, 'cac:Item') ?? $node;
        $price = $this->node($node, 'cac:Price') ?? $node;

        $allowances = [];
        $charges = [];
        foreach ($this->nodes($node, 'cac:AllowanceCharge') as $entry) {
            $isCharge = $this->chargeIndicator($entry, 'cbc:ChargeIndicator');
            if ($isCharge === null) {
                $this->invalidChargeIndicator($entry, 'cbc:ChargeIndicator', 'BG-27');

                continue;
            }
            $args = [
                'amount' => $this->money($this->value($entry, 'cbc:Amount'), $c, $isCharge ? 'BT-141' : 'BT-136'),
                'baseAmount' => $this->money($this->value($entry, 'cbc:BaseAmount'), $c, $isCharge ? 'BT-142' : 'BT-137'),
                'percent' => $this->decimal($this->value($entry, 'cbc:MultiplierFactorNumeric'), $isCharge ? 'BT-143' : 'BT-138'),
                'reason' => $this->value($entry, 'cbc:AllowanceChargeReason'),
            ];
            $reason = $this->value($entry, 'cbc:AllowanceChargeReasonCode');
            if ($isCharge) {
                $charges[] = new LineCharge(...$args, reasonCode: $this->code($reason, ChargeReasonCode::class, 'BT-145'));
            } else {
                $allowances[] = new LineAllowance(...$args, reasonCode: $this->code($reason, AllowanceReasonCode::class, 'BT-140'));
            }
        }

        $classifications = [];
        foreach ($this->nodes($item, 'cac:CommodityClassification/cbc:ItemClassificationCode') as $class) {
            $classifications[] = new Classification(
                trim($class->textContent),
                $this->code($class->getAttribute('listID'), ItemClassificationScheme::class, 'BT-158-1') ?? new RawCode(''),
                $class->getAttribute('listVersionID') === '' ? null : $class->getAttribute('listVersionID'),
            );
        }
        $attributes = [];
        foreach ($this->nodes($item, 'cac:AdditionalItemProperty') as $property) {
            $attributes[] = new ItemAttribute($this->value($property, 'cbc:Name') ?? '', $this->value($property, 'cbc:Value') ?? '');
        }

        $subElement = $this->creditNote ? 'cac:SubCreditNoteLine' : 'cac:SubInvoiceLine';

        return new InvoiceLine(
            id: $lineId,
            quantity: $this->decimal($this->value($node, $quantityElement), 'BT-129') ?? BigDecimal::zero(),
            unit: $unitCode === null ? new RawCode('') : ($this->code($unitCode, UnitCode::class, 'BT-130') ?? new RawCode('')),
            price: new Price(
                netPrice: $this->decimal($this->value($price, 'cbc:PriceAmount'), 'BT-146'),
                discount: $this->decimal($this->value($price, 'cac:AllowanceCharge/cbc:Amount'), 'BT-147'),
                grossPrice: $this->decimal($this->value($price, 'cac:AllowanceCharge/cbc:BaseAmount'), 'BT-148'),
                baseQuantity: $this->decimal($this->value($price, 'cbc:BaseQuantity'), 'BT-149'),
                baseQuantityUnit: $this->code($this->attribute($price, 'cbc:BaseQuantity', 'unitCode'), UnitCode::class, 'BT-150'),
            ),
            vat: new LineVat(
                category: $this->category($this->value($item, 'cac:ClassifiedTaxCategory/cbc:ID'), 'BT-151'),
                rate: $this->decimal($this->value($item, 'cac:ClassifiedTaxCategory/cbc:Percent'), 'BT-152'),
            ),
            item: new Item(
                name: $this->value($item, 'cbc:Name') ?? '',
                description: $this->value($item, 'cbc:Description'),
                sellerId: $this->value($item, 'cac:SellersItemIdentification/cbc:ID'),
                buyerId: $this->value($item, 'cac:BuyersItemIdentification/cbc:ID'),
                standardId: $this->identifier($item, 'cac:StandardItemIdentification/cbc:ID'),
                classifications: $classifications,
                originCountry: $this->value($item, 'cac:OriginCountry/cbc:IdentificationCode'),
                attributes: $attributes,
            ),
            note: $this->value($node, 'cbc:Note'),
            objectId: $this->identifier($node, 'cac:DocumentReference[cbc:DocumentTypeCode="130"]/cbc:ID'),
            netAmount: $this->money($this->value($node, 'cbc:LineExtensionAmount'), $c, 'BT-131'),
            orderLineReference: $this->value($node, 'cac:OrderLineReference/cbc:LineID'),
            buyerAccountingReference: $this->value($node, 'cbc:AccountingCost'),
            period: $this->period($node, 'cac:InvoicePeriod'),
            allowances: $allowances,
            charges: $charges,
            subLines: array_map(fn (DOMElement $sub): InvoiceLine => $this->line($sub, true), $this->nodes($node, $subElement)),
        );
    }

    private function xrechnungExtension(DOMElement $root): ?XRechnungExtensionData
    {
        $payments = [];
        foreach ($this->nodes($root, 'cac:PrepaidPayment') as $node) {
            $amount = $this->money($this->value($node, 'cbc:PaidAmount'), $this->currency, 'BG-DEX-09');
            if ($amount !== null) {
                $payments[] = new ThirdPartyPayment($this->value($node, 'cbc:ID') ?? '', $amount, $this->value($node, 'cbc:InstructionID') ?? '');
            }
        }

        return $payments === [] ? null : new XRechnungExtensionData($payments);
    }
}
