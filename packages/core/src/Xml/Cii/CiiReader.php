<?php

declare(strict_types=1);

namespace Quittio\Core\Xml\Cii;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use DOMDocument;
use DOMElement;
use Quittio\Core\Codes\RawCode;
use Quittio\Core\Data\AllowanceCharge\DocumentAllowance;
use Quittio\Core\Data\AllowanceCharge\DocumentCharge;
use Quittio\Core\Data\AllowanceCharge\LineAllowance;
use Quittio\Core\Data\AllowanceCharge\LineCharge;
use Quittio\Core\Data\Attachments\Attachment;
use Quittio\Core\Data\Delivery\Delivery;
use Quittio\Core\Data\Delivery\Period;
use Quittio\Core\Data\Extensions\ExtendedData;
use Quittio\Core\Data\Extensions\ExtendedParty;
use Quittio\Core\Data\Extensions\Installment;
use Quittio\Core\Data\Extensions\LineExtendedData;
use Quittio\Core\Data\Identifier;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Data\Lines\Classification;
use Quittio\Core\Data\Lines\InvoiceLine;
use Quittio\Core\Data\Lines\Item;
use Quittio\Core\Data\Lines\ItemAttribute;
use Quittio\Core\Data\Lines\LineVat;
use Quittio\Core\Data\Lines\Price;
use Quittio\Core\Data\Meta;
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
use Quittio\Core\Data\Payment\PenaltyTerms;
use Quittio\Core\Data\References\DocumentReference;
use Quittio\Core\Data\References\PrecedingInvoiceReference;
use Quittio\Core\Data\References\ProjectReference;
use Quittio\Core\Data\Totals\Totals;
use Quittio\Core\Data\Totals\VatBreakdown;
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
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Enums\VatExemptionReasonCode;
use Quittio\Core\Exceptions\InvalidArgumentException;
use Quittio\Core\Profiles\Syntax;
use Quittio\Core\Reading\DeclaredExemptionReasons;
use Quittio\Core\Reading\FormatReader;
use Quittio\Core\Reading\ReadResult;
use Quittio\Core\Reading\XPathReader;
use Quittio\Core\Standards\StandardVersion;
use Quittio\Core\Validation\Severity;
use Quittio\Core\Xml\PaymentTermsText;

/**
 * Reads UN/CEFACT Cross Industry Invoices (Factur-X/ZUGFeRD, XRechnung CII) into the data model.
 *
 * Declared totals are kept unchanged; differences to a recalculation are reported
 * by the invoice reader, not silently corrected.
 */
final class CiiReader extends XPathReader implements FormatReader
{
    /** Deepest accepted sub line hierarchy (ParentLineID chains). */
    public const int MAX_SUBLINE_DEPTH = 32;

    public function canRead(DOMDocument $document): bool
    {
        $root = $document->documentElement;

        return $root?->namespaceURI === CiiWriter::NS_RSM && $root->localName === 'CrossIndustryInvoice';
    }

    public function read(DOMDocument $document, string $xml): ReadResult
    {
        $this->init($document, ['rsm' => CiiWriter::NS_RSM, 'ram' => CiiWriter::NS_RAM, 'udt' => CiiWriter::NS_UDT, 'qdt' => CiiWriter::NS_QDT]);
        $root = $document->documentElement ?? throw new InvalidArgumentException('Empty document.');

        $agreement = $this->node($root, 'rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement') ?? $root;
        $deliveryNode = $this->node($root, 'rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeDelivery') ?? $root;
        $settlement = $this->node($root, 'rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement') ?? $root;

        $taxCurrency = $this->value($settlement, 'ram:TaxCurrencyCode');
        $taxTotalNodes = $this->nodes($settlement, 'ram:SpecifiedTradeSettlementHeaderMonetarySummation/ram:TaxTotalAmount');
        $amountCurrency = null;
        foreach ($taxTotalNodes as $taxTotalNode) {
            $id = trim($taxTotalNode->getAttribute('currencyID'));
            if ($id !== '' && $id !== $taxCurrency) {
                $amountCurrency = $id;
                break;
            }
        }
        $this->currency = $this->invoiceCurrency($this->value($settlement, 'ram:InvoiceCurrencyCode'), $amountCurrency);
        $this->checkAmountCurrencies($document, $this->currency, $taxCurrency, $taxTotalNodes);
        $specificationId = $this->value($root, 'rsm:ExchangedDocumentContext/ram:GuidelineSpecifiedDocumentContextParameter/ram:ID');

        $hasLines = $this->nodes($root, 'rsm:SupplyChainTradeTransaction/ram:IncludedSupplyChainTradeLineItem') !== [];
        $declaredTotals = $this->totals($settlement, $taxCurrency, $hasLines);
        $declaredBreakdown = $this->vatBreakdown($settlement);

        [$taxPointDate, $taxPointDateCode] = $this->taxPoint($settlement);
        $paymentTerms = $this->paymentTerms($settlement);
        $extended = $this->extended($agreement, $deliveryNode, $settlement);

        $data = new InvoiceData(
            number: $this->value($root, 'rsm:ExchangedDocument/ram:ID') ?? '',
            issueDate: $this->issueDate($this->ciiDate($root, 'rsm:ExchangedDocument/ram:IssueDateTime/udt:DateTimeString', 'BT-2')),
            typeCode: $this->code($this->value($root, 'rsm:ExchangedDocument/ram:TypeCode'), InvoiceTypeCode::class, 'BT-3') ?? new RawCode(''),
            currency: $this->currency,
            seller: $this->seller($agreement),
            buyer: $this->buyer($agreement),
            lines: $this->lines($root),
            taxCurrency: $taxCurrency,
            taxPointDate: $taxPointDate,
            taxPointDateCode: $taxPointDateCode,
            dueDate: $this->ciiDate($settlement, 'ram:SpecifiedTradePaymentTerms/ram:DueDateDateTime/udt:DateTimeString', 'BT-9'),
            buyerReference: $this->value($agreement, 'ram:BuyerReference'),
            projectReference: $this->project($agreement),
            contractReference: $this->value($agreement, 'ram:ContractReferencedDocument/ram:IssuerAssignedID'),
            purchaseOrderReference: $this->value($agreement, 'ram:BuyerOrderReferencedDocument/ram:IssuerAssignedID'),
            salesOrderReference: $this->value($agreement, 'ram:SellerOrderReferencedDocument/ram:IssuerAssignedID'),
            receivingAdviceReference: $this->value($deliveryNode, 'ram:ReceivingAdviceReferencedDocument/ram:IssuerAssignedID'),
            despatchAdviceReference: $this->value($deliveryNode, 'ram:DespatchAdviceReferencedDocument/ram:IssuerAssignedID'),
            tenderReference: $this->value($agreement, 'ram:AdditionalReferencedDocument[ram:TypeCode="50"]/ram:IssuerAssignedID'),
            invoicedObject: $this->invoicedObject($agreement),
            buyerAccountingReference: $this->value($settlement, 'ram:ReceivableSpecifiedTradeAccountingAccount/ram:ID'),
            paymentTerms: $paymentTerms,
            notes: $this->notes($root),
            businessProcess: $this->value($root, 'rsm:ExchangedDocumentContext/ram:BusinessProcessSpecifiedDocumentContextParameter/ram:ID'),
            precedingInvoices: $this->precedingInvoices($settlement),
            payee: $this->payee($settlement),
            sellerTaxRepresentative: $this->taxRepresentative($agreement),
            delivery: $this->delivery($deliveryNode),
            invoicingPeriod: $this->period($settlement, 'ram:BillingSpecifiedPeriod'),
            paymentInstructions: $this->paymentInstructions($settlement),
            allowances: $this->documentAllowances($settlement),
            charges: $this->documentCharges($settlement),
            totals: $declaredTotals,
            vatBreakdown: $declaredBreakdown,
            attachments: $this->attachments($agreement),
            prepaidAmount: $declaredTotals?->prepaidAmount,
            roundingAmount: $declaredTotals?->roundingAmount,
            extended: $extended,
            meta: $this->meta($settlement, $taxCurrency),
            standardVersion: $specificationId !== null && str_starts_with($specificationId, 'urn:cen.eu:en16931:2026')
                ? StandardVersion::En16931_2026
                : StandardVersion::En16931_2017,
        );
        $data = DeclaredExemptionReasons::apply($data);

        return new ReadResult(
            data: $data,
            syntax: Syntax::Cii->value,
            specificationId: $specificationId,
            profile: null,
            declaredTotals: $declaredTotals,
            declaredVatBreakdown: $declaredBreakdown,
            findings: $this->findings,
            xml: $xml,
        );
    }

    /**
     * @return list<Note>
     */
    private function notes(DOMElement $root): array
    {
        $notes = [];
        foreach ($this->nodes($root, 'rsm:ExchangedDocument/ram:IncludedNote') as $node) {
            $content = $this->value($node, 'ram:Content');
            if ($content !== null) {
                $notes[] = new Note($content, $this->code($this->value($node, 'ram:SubjectCode'), NoteSubjectCode::class, 'BT-21'));
            }
        }

        return $notes;
    }

    private function seller(DOMElement $agreement): SellerParty
    {
        $node = $this->node($agreement, 'ram:SellerTradeParty') ?? $agreement;

        return new SellerParty(
            name: $this->value($node, 'ram:Name') ?? '',
            address: $this->address($node) ?? new Address(''),
            tradingName: $this->value($node, 'ram:SpecifiedLegalOrganization/ram:TradingBusinessName'),
            identifiers: $this->identifiers($node),
            legalRegistration: $this->identifier($node, 'ram:SpecifiedLegalOrganization/ram:ID'),
            vatId: $this->value($node, 'ram:SpecifiedTaxRegistration/ram:ID[@schemeID="VA"]'),
            taxNumber: $this->value($node, 'ram:SpecifiedTaxRegistration/ram:ID[@schemeID="FC"]'),
            additionalLegalInfo: $this->value($node, 'ram:Description'),
            electronicAddress: $this->electronicAddress($node),
            contact: $this->contact($node),
        );
    }

    private function buyer(DOMElement $agreement): BuyerParty
    {
        $node = $this->node($agreement, 'ram:BuyerTradeParty') ?? $agreement;

        return new BuyerParty(
            name: $this->value($node, 'ram:Name') ?? '',
            address: $this->address($node) ?? new Address(''),
            tradingName: $this->value($node, 'ram:SpecifiedLegalOrganization/ram:TradingBusinessName'),
            identifier: $this->identifiers($node)[0] ?? null,
            legalRegistration: $this->identifier($node, 'ram:SpecifiedLegalOrganization/ram:ID'),
            vatId: $this->value($node, 'ram:SpecifiedTaxRegistration/ram:ID[@schemeID="VA"]'),
            electronicAddress: $this->electronicAddress($node),
            contact: $this->contact($node),
        );
    }

    private function payee(DOMElement $settlement): ?PayeeParty
    {
        $node = $this->node($settlement, 'ram:PayeeTradeParty');
        if ($node === null) {
            return null;
        }

        return new PayeeParty(
            name: $this->value($node, 'ram:Name') ?? '',
            identifier: $this->identifiers($node)[0] ?? null,
            legalRegistration: $this->identifier($node, 'ram:SpecifiedLegalOrganization/ram:ID'),
        );
    }

    private function taxRepresentative(DOMElement $agreement): ?TaxRepresentativeParty
    {
        $node = $this->node($agreement, 'ram:SellerTaxRepresentativeTradeParty');
        if ($node === null) {
            return null;
        }

        return new TaxRepresentativeParty(
            name: $this->value($node, 'ram:Name') ?? '',
            vatId: $this->value($node, 'ram:SpecifiedTaxRegistration/ram:ID[@schemeID="VA"]') ?? '',
            address: $this->address($node) ?? new Address(''),
        );
    }

    private function extendedParty(?DOMElement $node): ?ExtendedParty
    {
        if ($node === null) {
            return null;
        }

        return new ExtendedParty(
            name: $this->value($node, 'ram:Name') ?? '',
            address: $this->address($node),
            identifiers: $this->identifiers($node),
            vatId: $this->value($node, 'ram:SpecifiedTaxRegistration/ram:ID[@schemeID="VA"]'),
            contact: $this->contact($node),
        );
    }

    private function address(DOMElement $party): ?Address
    {
        $node = $this->node($party, 'ram:PostalTradeAddress');
        if ($node === null) {
            return null;
        }

        return new Address(
            countryCode: $this->value($node, 'ram:CountryID') ?? '',
            line1: $this->value($node, 'ram:LineOne'),
            line2: $this->value($node, 'ram:LineTwo'),
            line3: $this->value($node, 'ram:LineThree'),
            city: $this->value($node, 'ram:CityName'),
            postcode: $this->value($node, 'ram:PostcodeCode'),
            subdivision: $this->value($node, 'ram:CountrySubDivisionName'),
        );
    }

    private function contact(DOMElement $party): ?Contact
    {
        $node = $this->node($party, 'ram:DefinedTradeContact');
        if ($node === null) {
            return null;
        }

        return new Contact(
            name: $this->value($node, 'ram:PersonName') ?? $this->value($node, 'ram:DepartmentName'),
            phone: $this->value($node, 'ram:TelephoneUniversalCommunication/ram:CompleteNumber'),
            email: $this->value($node, 'ram:EmailURIUniversalCommunication/ram:URIID'),
        );
    }

    private function electronicAddress(DOMElement $party): ?ElectronicAddress
    {
        $value = $this->value($party, 'ram:URIUniversalCommunication/ram:URIID');
        if ($value === null) {
            return null;
        }

        $scheme = $this->attribute($party, 'ram:URIUniversalCommunication/ram:URIID', 'schemeID') ?? '';

        return new ElectronicAddress($value, $this->code($scheme, ElectronicAddressScheme::class, 'BT-34-1') ?? new RawCode(''));
    }

    /**
     * @return list<Identifier>
     */
    private function identifiers(DOMElement $party): array
    {
        $identifiers = [];
        foreach ($this->nodes($party, 'ram:ID') as $node) {
            $identifiers[] = new Identifier(trim($node->textContent));
        }
        foreach ($this->nodes($party, 'ram:GlobalID') as $node) {
            $identifiers[] = new Identifier(
                trim($node->textContent),
                $this->code(self::nonEmpty($node->getAttribute('schemeID')), IdentifierScheme::class, 'BT-29-1'),
            );
        }

        return $identifiers;
    }

    private function identifier(DOMElement $context, string $path): ?Identifier
    {
        $value = $this->value($context, $path);
        if ($value === null) {
            return null;
        }

        return new Identifier($value, $this->code($this->attribute($context, $path, 'schemeID'), IdentifierScheme::class, $path));
    }

    private function project(DOMElement $agreement): ?ProjectReference
    {
        $id = $this->value($agreement, 'ram:SpecifiedProcuringProject/ram:ID');

        return $id === null ? null : new ProjectReference($id, $this->value($agreement, 'ram:SpecifiedProcuringProject/ram:Name'));
    }

    private function invoicedObject(DOMElement $agreement): ?Identifier
    {
        $path = 'ram:AdditionalReferencedDocument[ram:TypeCode="130"]';
        $value = $this->value($agreement, $path.'/ram:IssuerAssignedID');
        if ($value === null) {
            return null;
        }

        $scheme = $this->value($agreement, $path.'/ram:ReferenceTypeCode');

        return new Identifier($value, $scheme === null ? null : new RawCode($scheme));
    }

    /**
     * @return list<Attachment>
     */
    private function attachments(DOMElement $agreement): array
    {
        $attachments = [];
        foreach ($this->nodes($agreement, 'ram:AdditionalReferencedDocument[ram:TypeCode="916"]') as $node) {
            $binary = $this->node($node, 'ram:AttachmentBinaryObject');
            $content = null;
            if ($binary !== null) {
                $decoded = base64_decode(trim($binary->textContent), true);
                if ($decoded === false) {
                    $this->finding('QUITTIO-READER-ATTACHMENT', 'An attached document (BT-125) is not valid Base64 and was skipped.', 'BT-125');
                } else {
                    $content = $decoded;
                }
            }

            $mime = self::nonEmpty($binary?->getAttribute('mimeCode'));
            $rawFilename = self::nonEmpty($binary?->getAttribute('filename'));
            $filename = $this->attachmentFilename($rawFilename);
            $mimeType = $mime === null ? null : MimeType::tryFrom($mime);
            if ($content !== null && ($mimeType === null || $filename === null)) {
                $this->finding('QUITTIO-READER-ATTACHMENT', sprintf('The attached document "%s" has an unsupported MIME code or no usable file name and was skipped.', self::excerpt($rawFilename ?? '?')), $mimeType === null ? 'BT-125-1' : 'BT-125-2');
                $content = null;
            }

            $attachments[] = new Attachment(
                id: $this->value($node, 'ram:IssuerAssignedID') ?? '',
                description: $this->value($node, 'ram:Name'),
                externalUri: $this->value($node, 'ram:URIID'),
                content: $content,
                mimeType: $content === null ? null : $mimeType,
                filename: $content === null ? null : $filename,
            );
        }

        return $attachments;
    }

    private function delivery(DOMElement $node): ?Delivery
    {
        $shipTo = $this->node($node, 'ram:ShipToTradeParty');
        $date = $this->ciiDate($node, 'ram:ActualDeliverySupplyChainEvent/ram:OccurrenceDateTime/udt:DateTimeString', 'BT-72');
        if ($shipTo === null && $date === null) {
            return null;
        }

        return new Delivery(
            recipientName: $shipTo === null ? null : $this->value($shipTo, 'ram:Name'),
            locationId: $shipTo === null ? null : ($this->identifiers($shipTo)[0] ?? null),
            actualDeliveryDate: $date,
            address: $shipTo === null ? null : $this->address($shipTo),
        );
    }

    private function period(DOMElement $context, string $path): ?Period
    {
        $node = $this->node($context, $path);
        if ($node === null) {
            return null;
        }

        $start = $this->ciiDate($node, 'ram:StartDateTime/udt:DateTimeString', 'BT-73');
        $end = $this->ciiDate($node, 'ram:EndDateTime/udt:DateTimeString', 'BT-74');

        return $start === null && $end === null ? null : new Period($start, $end);
    }

    /**
     * @return array{0: ?\Quittio\Core\Support\Date, 1: TaxPointDateCode|RawCode|null}
     */
    private function taxPoint(DOMElement $settlement): array
    {
        $date = null;
        $code = null;
        foreach ($this->nodes($settlement, 'ram:ApplicableTradeTax') as $tax) {
            $date ??= $this->ciiDate($tax, 'ram:TaxPointDate/udt:DateString', 'BT-7');
            $value = $this->value($tax, 'ram:DueDateTypeCode');
            if ($value !== null && $code === null) {
                $code = TaxPointDateCode::fromCii($value);
                if ($code instanceof RawCode) {
                    $this->finding('QUITTIO-READER-UNKNOWN-CODE', sprintf('The VAT point date code "%s" (BT-8) is not part of UNTDID 2475 (5, 29, 72) and was kept as raw value.', self::excerpt($value)), 'BT-8', Severity::Warning);
                }
            }
        }

        return [$date, $code];
    }

    /**
     * BT-20 and the cash discounts. EXTENDED repeats SpecifiedTradePaymentTerms for further
     * discount tiers and for installments; the text and the penalty terms come from the first
     * group, the discounts from all groups.
     */
    private function paymentTerms(DOMElement $settlement): ?PaymentTerms
    {
        $groups = $this->nodes($settlement, 'ram:SpecifiedTradePaymentTerms');
        if ($groups === []) {
            return null;
        }

        $parsed = PaymentTermsText::parse($this->value($groups[0], 'ram:Description'));
        $discounts = array_map(
            fn (array $d): CashDiscount => new CashDiscount($d['days'], BigDecimal::of($d['percent']), $this->money($d['base'], $this->currency, 'BT-20')),
            $parsed['discounts'],
        );

        if ($discounts === []) {
            foreach ($groups as $group) {
                foreach ($this->nodes($group, 'ram:ApplicableTradePaymentDiscountTerms') as $discountNode) {
                    $percent = $this->decimal($this->value($discountNode, 'ram:CalculationPercent'), 'BT-20');
                    if ($percent !== null) {
                        $discounts[] = new CashDiscount(
                            $this->days($discountNode) ?? 0,
                            $percent,
                            $this->money($this->value($discountNode, 'ram:BasisAmount'), $this->currency, 'BT-20'),
                        );
                    }
                }
            }
        }

        $penaltyNode = null;
        foreach ($groups as $group) {
            $penaltyNode ??= $this->node($group, 'ram:ApplicableTradePaymentPenaltyTerms');
        }
        $penalty = $penaltyNode === null ? null : new PenaltyTerms(
            days: $this->days($penaltyNode),
            percent: $this->decimal($this->value($penaltyNode, 'ram:CalculationPercent'), 'BT-20'),
        );

        $terms = new PaymentTerms(text: $parsed['text'], discounts: $discounts, lateFeeTerms: $penalty);

        return $terms->isEmpty() ? null : $terms;
    }

    /**
     * BasisPeriodMeasure in days; weeks (WEE) are converted, other units are not read.
     */
    private function days(DOMElement $terms): ?int
    {
        $value = $this->value($terms, 'ram:BasisPeriodMeasure');
        if ($value === null) {
            return null;
        }
        if (preg_match('/^\d{1,5}$/', $value) !== 1) {
            $this->finding('QUITTIO-READER-NUMBER', sprintf('The number of days "%s" (BT-20) is not a whole number and was not read.', self::excerpt($value)), 'BT-20', Severity::Error);

            return null;
        }
        $unit = $this->attribute($terms, 'ram:BasisPeriodMeasure', 'unitCode') ?? 'DAY';
        if (! in_array($unit, ['DAY', 'WEE'], true)) {
            $this->finding('QUITTIO-READER-UNIT', sprintf('The payment terms period "%s %s" (BT-20) is not given in days or weeks and was not read.', $value, self::excerpt($unit)), 'BT-20');

            return null;
        }

        return (int) $value * ($unit === 'WEE' ? 7 : 1);
    }

    /**
     * EXTENDED: exchange rate behind BT-111 (invoice currency × rate = VAT accounting currency).
     */
    private function meta(DOMElement $settlement, ?string $taxCurrency): Meta
    {
        $node = $this->node($settlement, 'ram:TaxApplicableTradeCurrencyExchange');
        if ($node === null) {
            return new Meta;
        }

        $source = $this->value($node, 'ram:SourceCurrencyCode');
        $target = $this->value($node, 'ram:TargetCurrencyCode');
        if (($source !== null && $source !== $this->currency) || ($target !== null && $target !== $taxCurrency)) {
            $this->finding('QUITTIO-READER-EXCHANGE-RATE', sprintf('The exchange rate converts %s to %s, but the invoice currency is %s and the VAT accounting currency %s (BT-5, BT-6).', self::excerpt($source ?? '?'), self::excerpt($target ?? '?'), $this->currency, $taxCurrency ?? '-'), 'BT-6');
        }

        return new Meta(
            exchangeRate: $this->decimal($this->value($node, 'ram:ConversionRate'), 'BT-6'),
            exchangeRateDate: $this->ciiDate($node, 'ram:ConversionRateDateTime/udt:DateTimeString', 'BT-6'),
        );
    }

    private function paymentInstructions(DOMElement $settlement): ?PaymentInstructions
    {
        $means = $this->nodes($settlement, 'ram:SpecifiedTradeSettlementPaymentMeans');
        if ($means === []) {
            return null;
        }

        $first = $means[0];
        $transfers = [];
        $card = null;
        $debited = null;
        foreach ($means as $node) {
            $account = $this->value($node, 'ram:PayeePartyCreditorFinancialAccount/ram:IBANID')
                ?? $this->value($node, 'ram:PayeePartyCreditorFinancialAccount/ram:ProprietaryID');
            if ($account !== null) {
                $transfers[] = new CreditTransfer(
                    $account,
                    $this->value($node, 'ram:PayeePartyCreditorFinancialAccount/ram:AccountName'),
                    $this->value($node, 'ram:PayeeSpecifiedCreditorFinancialInstitution/ram:BICID'),
                );
            }
            $cardNumber = $this->value($node, 'ram:ApplicableTradeSettlementFinancialCard/ram:ID');
            if ($cardNumber !== null && $card === null) {
                $card = new PaymentCard(substr($cardNumber, -6), $this->value($node, 'ram:ApplicableTradeSettlementFinancialCard/ram:CardholderName'));
            }
            $debited ??= $this->value($node, 'ram:PayerPartyDebtorFinancialAccount/ram:IBANID');
        }

        $mandate = $this->value($settlement, 'ram:SpecifiedTradePaymentTerms/ram:DirectDebitMandateID');
        $creditor = $this->value($settlement, 'ram:CreditorReferenceID');

        return new PaymentInstructions(
            meansCode: $this->code($this->value($first, 'ram:TypeCode'), PaymentMeansCode::class, 'BT-81') ?? new RawCode(''),
            meansText: $this->value($first, 'ram:Information'),
            remittanceInformation: $this->value($settlement, 'ram:PaymentReference'),
            creditTransfers: $transfers,
            card: $card,
            directDebit: $mandate === null && $creditor === null && $debited === null ? null : new DirectDebit($mandate, $creditor, $debited),
        );
    }

    /**
     * @return list<PrecedingInvoiceReference>
     */
    private function precedingInvoices(DOMElement $settlement): array
    {
        $references = [];
        foreach ($this->nodes($settlement, 'ram:InvoiceReferencedDocument') as $node) {
            $number = $this->value($node, 'ram:IssuerAssignedID');
            if ($number !== null) {
                $references[] = new PrecedingInvoiceReference($number, $this->ciiDate($node, 'ram:FormattedIssueDateTime/qdt:DateTimeString', 'BT-26'));
            }
        }

        return $references;
    }

    /**
     * @return list<DocumentAllowance>
     */
    private function documentAllowances(DOMElement $settlement): array
    {
        $result = [];
        foreach ($this->nodes($settlement, 'ram:SpecifiedTradeAllowanceCharge') as $node) {
            if ($this->chargeIndicator($node, 'ram:ChargeIndicator/udt:Indicator') !== false) {
                continue;
            }
            $result[] = new DocumentAllowance(
                amount: $this->money($this->value($node, 'ram:ActualAmount'), $this->currency, 'BT-92'),
                vatCategory: $this->category($this->value($node, 'ram:CategoryTradeTax/ram:CategoryCode'), 'BT-95'),
                vatRate: $this->decimal($this->value($node, 'ram:CategoryTradeTax/ram:RateApplicablePercent'), 'BT-96'),
                baseAmount: $this->money($this->value($node, 'ram:BasisAmount'), $this->currency, 'BT-93'),
                percent: $this->decimal($this->value($node, 'ram:CalculationPercent'), 'BT-94'),
                reason: $this->value($node, 'ram:Reason'),
                reasonCode: $this->code($this->value($node, 'ram:ReasonCode'), AllowanceReasonCode::class, 'BT-98'),
            );
        }

        return $result;
    }

    /**
     * @return list<DocumentCharge>
     */
    private function documentCharges(DOMElement $settlement): array
    {
        $result = [];
        foreach ($this->nodes($settlement, 'ram:SpecifiedTradeAllowanceCharge') as $node) {
            $isCharge = $this->chargeIndicator($node, 'ram:ChargeIndicator/udt:Indicator');
            if ($isCharge === null) {
                // Reported here only, documentAllowances() skips these entries as well.
                $this->invalidChargeIndicator($node, 'ram:ChargeIndicator/udt:Indicator', 'BG-20');
            }
            if ($isCharge !== true) {
                continue;
            }
            $result[] = new DocumentCharge(
                amount: $this->money($this->value($node, 'ram:ActualAmount'), $this->currency, 'BT-99'),
                vatCategory: $this->category($this->value($node, 'ram:CategoryTradeTax/ram:CategoryCode'), 'BT-102'),
                vatRate: $this->decimal($this->value($node, 'ram:CategoryTradeTax/ram:RateApplicablePercent'), 'BT-103'),
                baseAmount: $this->money($this->value($node, 'ram:BasisAmount'), $this->currency, 'BT-100'),
                percent: $this->decimal($this->value($node, 'ram:CalculationPercent'), 'BT-101'),
                reason: $this->value($node, 'ram:Reason'),
                reasonCode: $this->code($this->value($node, 'ram:ReasonCode'), ChargeReasonCode::class, 'BT-105'),
            );
        }

        return $result;
    }

    private function totals(DOMElement $settlement, ?string $taxCurrency, bool $hasLines): ?Totals
    {
        $node = $this->node($settlement, 'ram:SpecifiedTradeSettlementHeaderMonetarySummation');
        if ($node === null) {
            $this->missingTotals($hasLines);

            return null;
        }

        $c = $this->currency;
        // BT-110 is the TaxTotalAmount in the invoice currency only; BT-111 the one in the VAT accounting currency.
        $vatTotal = $this->amountIn($node, 'ram:TaxTotalAmount', $c);
        if ($vatTotal === null && ($vatTotal = $this->amountIn($node, 'ram:TaxTotalAmount', '')) !== null) {
            $this->finding('QUITTIO-READER-CURRENCY', 'The total VAT amount (BT-110) has no currencyID; it was read in the invoice currency.', 'BT-110');
        }
        $vatInTaxCurrency = $taxCurrency !== null && $taxCurrency !== $c
            ? $this->money($this->amountIn($node, 'ram:TaxTotalAmount', $taxCurrency), $taxCurrency, 'BT-111')
            : null;

        $allowanceSum = $this->money($this->value($node, 'ram:AllowanceTotalAmount'), $c, 'BT-107');
        $chargeSum = $this->money($this->value($node, 'ram:ChargeTotalAmount'), $c, 'BT-108');
        $netTotal = $this->requiredTotal($node, 'ram:TaxBasisTotalAmount', 'BT-109', 'BR-13', 'Invoice total amount without VAT');

        return new Totals(
            lineNetSum: $this->lineNetSum($this->money($this->value($node, 'ram:LineTotalAmount'), $c, 'BT-106'), $hasLines, $netTotal, $allowanceSum, $chargeSum),
            netTotal: $netTotal,
            grossTotal: $this->requiredTotal($node, 'ram:GrandTotalAmount', 'BT-112', 'BR-14', 'Invoice total amount with VAT'),
            amountDue: $this->requiredTotal($node, 'ram:DuePayableAmount', 'BT-115', 'BR-15', 'Amount due for payment'),
            allowanceSum: $allowanceSum,
            chargeSum: $chargeSum,
            vatTotal: $this->money($vatTotal, $c, 'BT-110'),
            vatTotalInTaxCurrency: $vatInTaxCurrency,
            prepaidAmount: $this->money($this->value($node, 'ram:TotalPrepaidAmount'), $c, 'BT-113'),
            roundingAmount: $this->money($this->value($node, 'ram:RoundingAmount'), $c, 'BT-114'),
        );
    }

    private function requiredTotal(DOMElement $node, string $path, string $bt, string $rule, string $label): Money
    {
        return $this->money($this->value($node, $path), $this->currency, $bt) ?? $this->missingAmount($bt, $rule, $label);
    }

    /**
     * @return list<VatBreakdown>
     */
    private function vatBreakdown(DOMElement $settlement): array
    {
        $result = [];
        foreach ($this->nodes($settlement, 'ram:ApplicableTradeTax') as $node) {
            $result[] = new VatBreakdown(
                taxableAmount: $this->money($this->value($node, 'ram:BasisAmount'), $this->currency, 'BT-116') ?? $this->missingAmount('BT-116', 'BR-45', 'VAT category taxable amount'),
                taxAmount: $this->money($this->value($node, 'ram:CalculatedAmount'), $this->currency, 'BT-117') ?? $this->missingAmount('BT-117', 'BR-46', 'VAT category tax amount'),
                category: $this->category($this->value($node, 'ram:CategoryCode'), 'BT-118'),
                rate: $this->decimal($this->value($node, 'ram:RateApplicablePercent'), 'BT-119'),
                exemptionReason: $this->value($node, 'ram:ExemptionReason'),
                exemptionReasonCode: $this->code($this->value($node, 'ram:ExemptionReasonCode'), VatExemptionReasonCode::class, 'BT-121'),
            );
        }

        return $result;
    }

    /**
     * Reads all lines and rebuilds the hierarchy of sub lines from ParentLineID.
     *
     * @return list<InvoiceLine>
     */
    private function lines(DOMElement $root): array
    {
        /** @var array<string, array{line: InvoiceLine, parent: ?string}> $byId */
        $byId = [];
        /** @var array<string, array{0: bool, 1: bool}> $quantity */
        $quantity = [];
        $order = [];
        foreach ($this->nodes($root, 'rsm:SupplyChainTradeTransaction/ram:IncludedSupplyChainTradeLineItem') as $index => $node) {
            $line = $this->line($node);
            $key = $line->id !== '' ? $line->id : '#'.$index;
            if (isset($byId[$key])) {
                $this->finding('QUITTIO-READER-DUPLICATE-LINE', sprintf('The line identifier "%s" is used more than once.', $line->id), 'BT-126');
                $key .= '#'.$index;
            }
            $byId[$key] = ['line' => $line, 'parent' => $this->value($node, 'ram:AssociatedDocumentLineDocument/ram:ParentLineID')];
            $quantity[$key] = [
                $this->node($node, 'ram:SpecifiedLineTradeDelivery/ram:BilledQuantity') !== null,
                $this->attribute($node, 'ram:SpecifiedLineTradeDelivery/ram:BilledQuantity', 'unitCode') !== null,
            ];
            $order[] = $key;
        }

        // A parent that does not exist, the line itself or a cycle of lines cannot carry
        // sub lines; such lines are read as top-level lines and reported.
        $parents = [];
        foreach ($order as $key) {
            $parent = $byId[$key]['parent'];
            if ($parent === null) {
                continue;
            }
            if (! isset($byId[$parent])) {
                $this->finding('QUITTIO-READER-PARENT-LINE', sprintf('Line %s refers to the parent line "%s" (ParentLineID), which does not exist; it is read as a top-level line.', $key, self::excerpt($parent)), 'BT-126');

                continue;
            }
            $problem = $this->parentProblem($key, $parent, $byId);
            if ($problem !== null) {
                $this->finding('QUITTIO-READER-PARENT-LINE', sprintf(
                    $problem === 'cycle'
                        ? 'Line %s refers to the parent line "%s" (ParentLineID), which leads back to line %1$s; it is read as a top-level line.'
                        : 'Line %s refers to the parent line "%s" (ParentLineID) in a hierarchy deeper than %d levels; it is read as a top-level line.',
                    $key,
                    self::excerpt($parent),
                    self::MAX_SUBLINE_DEPTH,
                ), 'BT-126', Severity::Error);

                continue;
            }
            $parents[$key] = $parent;
        }

        $children = [];
        foreach ($order as $key) {
            if (isset($parents[$key])) {
                $children[$parents[$key]][] = $key;
            }
            $this->checkQuantity($key, isset($parents[$key]), ...$quantity[$key]);
        }

        $build = function (string $key) use (&$build, $byId, $children): InvoiceLine {
            $line = $byId[$key]['line'];
            $subs = array_map(static fn (string $child): InvoiceLine => $build($child), $children[$key] ?? []);

            return $subs === [] ? $line : $line->with(['subLines' => $subs]);
        };

        $lines = [];
        foreach ($order as $key) {
            if (! isset($parents[$key])) {
                $lines[] = $build($key);
            }
        }

        return $lines;
    }

    /**
     * Follows the parent chain of a line: "cycle" if it leads back to the line itself,
     * "depth" if it is deeper than MAX_SUBLINE_DEPTH, null otherwise.
     *
     * @param  array<string, array{line: InvoiceLine, parent: ?string}>  $byId
     */
    private function parentProblem(string $key, string $parent, array $byId): ?string
    {
        $seen = [$key => true];
        $current = $parent;
        $depth = 0;
        while ($current !== null && isset($byId[$current])) {
            if ($current === $key) {
                return 'cycle';
            }
            if (isset($seen[$current])) {
                // A cycle further up that does not contain this line; its members are reported themselves.
                return null;
            }
            if (++$depth > self::MAX_SUBLINE_DEPTH) {
                return 'depth';
            }
            $seen[$current] = true;
            $current = $byId[$current]['parent'];
        }

        return null;
    }

    /**
     * BT-146 to BT-150. The price base quantity may be given on the net price, on the gross
     * price or on both; a gross price with another base quantity is converted to the base
     * quantity of the net price.
     */
    private function price(DOMElement $agreement, string $lineId): Price
    {
        $net = 'ram:NetPriceProductTradePrice';
        $gross = 'ram:GrossPriceProductTradePrice';
        $netBase = $this->decimal($this->value($agreement, $net.'/ram:BasisQuantity'), 'BT-149');
        $grossBase = $this->decimal($this->value($agreement, $gross.'/ram:BasisQuantity'), 'BT-149');
        $netUnit = $this->attribute($agreement, $net.'/ram:BasisQuantity', 'unitCode');
        $grossUnit = $this->attribute($agreement, $gross.'/ram:BasisQuantity', 'unitCode');
        $grossPrice = $this->decimal($this->value($agreement, $gross.'/ram:ChargeAmount'), 'BT-148');
        $discount = $this->decimal($this->value($agreement, $gross.'/ram:AppliedTradeAllowanceCharge/ram:ActualAmount'), 'BT-147');

        if ($netBase !== null && $grossBase !== null && (! $netBase->isEqualTo($grossBase) || ($netUnit !== null && $grossUnit !== null && $netUnit !== $grossUnit))) {
            $convertible = ! $grossBase->isZero() && ($netUnit === null || $grossUnit === null || $netUnit === $grossUnit);
            if ($convertible) {
                $convert = static fn (?BigDecimal $value): ?BigDecimal => $value?->multipliedBy($netBase)->dividedBy($grossBase, 6, RoundingMode::HalfUp)->strippedOfTrailingZeros();
                $grossPrice = $convert($grossPrice);
                $discount = $convert($discount);
            }
            $this->finding('QUITTIO-READER-BASE-QUANTITY', sprintf(
                $convertible
                    ? 'Line %s states the gross price for %s %s and the net price for %s %s (BT-149); the gross price was converted to the base quantity of the net price.'
                    : 'Line %s states the gross price for %s %s and the net price for %s %s (BT-149); the units differ, so the gross price could not be converted.',
                $lineId,
                $grossBase->toString(),
                $grossUnit ?? '',
                $netBase->toString(),
                $netUnit ?? '',
            ), 'BT-149', $convertible ? Severity::Info : Severity::Warning);
        }

        $baseUnit = $netBase !== null ? $netUnit : $grossUnit;

        return new Price(
            netPrice: $this->decimal($this->value($agreement, $net.'/ram:ChargeAmount'), 'BT-146'),
            discount: $discount,
            grossPrice: $grossPrice,
            baseQuantity: $netBase ?? $grossBase,
            baseQuantityUnit: $this->code($baseUnit, UnitCode::class, 'BT-150'),
        );
    }

    /**
     * BT-129 and BT-130 are mandatory for invoice lines (BR-22, BR-23). Sub lines of the
     * extension may omit the quantity; 0 is used then.
     */
    private function checkQuantity(string $lineId, bool $subLine, bool $hasQuantity, bool $hasUnit): void
    {
        if (! $hasQuantity) {
            $subLine
                ? $this->finding('QUITTIO-READER-SUBLINE-QUANTITY', sprintf('Sub line %s has no quantity (BT-129); 0 is used.', $lineId), 'BT-129', Severity::Info)
                : $this->finding('BR-22', sprintf('Line %s has no invoiced quantity (BT-129); 0 is used.', $lineId), 'BT-129', Severity::Error);

            return;
        }

        if (! $hasUnit) {
            $subLine
                ? $this->finding('QUITTIO-READER-SUBLINE-UNIT', sprintf('Sub line %s has no unit of measure for its quantity (BT-130).', $lineId), 'BT-130', Severity::Info)
                : $this->finding('BR-23', sprintf('Line %s has no unit of measure for the invoiced quantity (BT-130).', $lineId), 'BT-130', Severity::Error);
        }
    }

    private function line(DOMElement $node): InvoiceLine
    {
        $product = $this->node($node, 'ram:SpecifiedTradeProduct') ?? $node;
        $agreement = $this->node($node, 'ram:SpecifiedLineTradeAgreement') ?? $node;
        $delivery = $this->node($node, 'ram:SpecifiedLineTradeDelivery') ?? $node;
        $settlement = $this->node($node, 'ram:SpecifiedLineTradeSettlement') ?? $node;
        $c = $this->currency;

        $allowances = [];
        $charges = [];
        foreach ($this->nodes($settlement, 'ram:SpecifiedTradeAllowanceCharge') as $entry) {
            $isCharge = $this->chargeIndicator($entry, 'ram:ChargeIndicator/udt:Indicator');
            if ($isCharge === null) {
                $this->invalidChargeIndicator($entry, 'ram:ChargeIndicator/udt:Indicator', 'BG-27');

                continue;
            }
            $args = [
                'amount' => $this->money($this->value($entry, 'ram:ActualAmount'), $c, $isCharge ? 'BT-141' : 'BT-136'),
                'baseAmount' => $this->money($this->value($entry, 'ram:BasisAmount'), $c, $isCharge ? 'BT-142' : 'BT-137'),
                'percent' => $this->decimal($this->value($entry, 'ram:CalculationPercent'), $isCharge ? 'BT-143' : 'BT-138'),
                'reason' => $this->value($entry, 'ram:Reason'),
            ];
            $reason = $this->value($entry, 'ram:ReasonCode');
            if ($isCharge) {
                $charges[] = new LineCharge(...$args, reasonCode: $this->code($reason, ChargeReasonCode::class, 'BT-145'));
            } else {
                $allowances[] = new LineAllowance(...$args, reasonCode: $this->code($reason, AllowanceReasonCode::class, 'BT-140'));
            }
        }

        $classifications = [];
        foreach ($this->nodes($product, 'ram:DesignatedProductClassification/ram:ClassCode') as $class) {
            $classifications[] = new Classification(
                trim($class->textContent),
                $this->code($class->getAttribute('listID'), ItemClassificationScheme::class, 'BT-158-1') ?? new RawCode(''),
                self::nonEmpty($class->getAttribute('listVersionID')),
            );
        }

        $attributes = [];
        foreach ($this->nodes($product, 'ram:ApplicableProductCharacteristic') as $characteristic) {
            $attributes[] = new ItemAttribute($this->value($characteristic, 'ram:Description') ?? '', $this->value($characteristic, 'ram:Value') ?? '');
        }

        $objectPath = 'ram:AdditionalReferencedDocument[ram:TypeCode="130"]';
        $objectId = $this->value($settlement, $objectPath.'/ram:IssuerAssignedID');
        $objectScheme = $this->value($settlement, $objectPath.'/ram:ReferenceTypeCode');
        $statusCode = $this->value($node, 'ram:AssociatedDocumentLineDocument/ram:LineStatusReasonCode');

        return new InvoiceLine(
            id: $this->value($node, 'ram:AssociatedDocumentLineDocument/ram:LineID') ?? '',
            quantity: $this->decimal($this->value($delivery, 'ram:BilledQuantity'), 'BT-129') ?? BigDecimal::zero(),
            unit: ($unitCode = $this->attribute($delivery, 'ram:BilledQuantity', 'unitCode')) === null
                ? new RawCode('')
                : ($this->code($unitCode, UnitCode::class, 'BT-130') ?? new RawCode('')),
            price: $this->price($agreement, $this->value($node, 'ram:AssociatedDocumentLineDocument/ram:LineID') ?? ''),
            vat: new LineVat(
                category: $this->category($this->value($settlement, 'ram:ApplicableTradeTax/ram:CategoryCode'), 'BT-151'),
                rate: $this->decimal($this->value($settlement, 'ram:ApplicableTradeTax/ram:RateApplicablePercent'), 'BT-152'),
            ),
            item: new Item(
                name: $this->value($product, 'ram:Name') ?? '',
                description: $this->value($product, 'ram:Description'),
                sellerId: $this->value($product, 'ram:SellerAssignedID'),
                buyerId: $this->value($product, 'ram:BuyerAssignedID'),
                standardId: $this->identifier($product, 'ram:GlobalID'),
                classifications: $classifications,
                originCountry: $this->value($product, 'ram:OriginTradeCountry/ram:ID'),
                attributes: $attributes,
            ),
            note: $this->value($node, 'ram:AssociatedDocumentLineDocument/ram:IncludedNote/ram:Content'),
            objectId: $objectId === null ? null : new Identifier($objectId, $objectScheme === null ? null : new RawCode($objectScheme)),
            netAmount: $this->money($this->value($settlement, 'ram:SpecifiedTradeSettlementLineMonetarySummation/ram:LineTotalAmount'), $c, 'BT-131'),
            orderLineReference: $this->value($agreement, 'ram:BuyerOrderReferencedDocument/ram:LineID'),
            buyerAccountingReference: $this->value($settlement, 'ram:ReceivableSpecifiedTradeAccountingAccount/ram:ID'),
            period: $this->period($settlement, 'ram:BillingSpecifiedPeriod'),
            allowances: $allowances,
            charges: $charges,
            extended: $statusCode === null || in_array($statusCode, ['GROUP', 'DETAIL'], true) ? null : new LineExtendedData(lineStatusReasonCode: $statusCode),
        );
    }

    private function extended(DOMElement $agreement, DOMElement $delivery, DOMElement $settlement): ?ExtendedData
    {
        // Installments are the payment terms groups with a partial payment amount; further
        // groups without one carry additional cash discount tiers.
        $installments = [];
        foreach ($this->nodes($settlement, 'ram:SpecifiedTradePaymentTerms[ram:PartialPaymentAmount]') as $node) {
            $date = $this->ciiDate($node, 'ram:DueDateDateTime/udt:DateTimeString', 'BT-9');
            if ($date === null) {
                $this->finding('QUITTIO-READER-INSTALLMENT', 'A partial payment (EXTENDED) has no due date and was not read.', 'BT-9');

                continue;
            }
            $installments[] = new Installment($date, $this->money($this->value($node, 'ram:PartialPaymentAmount'), $this->currency, 'BT-20'), $this->value($node, 'ram:Description'));
        }

        $references = [];
        foreach ($this->nodes($agreement, 'ram:AdditionalReferencedDocument') as $node) {
            $type = $this->value($node, 'ram:TypeCode');
            if (in_array($type, ['916', '50', '130'], true)) {
                continue;
            }
            $references[] = new DocumentReference(
                id: $this->value($node, 'ram:IssuerAssignedID') ?? '',
                issueDate: $this->ciiDate($node, 'ram:FormattedIssueDateTime/qdt:DateTimeString', 'BT-122'),
                typeCode: $type === null ? null : new RawCode($type),
                description: $this->value($node, 'ram:Name'),
            );
        }

        $extended = new ExtendedData(
            invoicer: $this->extendedParty($this->node($settlement, 'ram:InvoicerTradeParty')),
            invoicee: $this->extendedParty($this->node($settlement, 'ram:InvoiceeTradeParty')),
            ultimateCustomer: $this->extendedParty($this->node($agreement, 'ram:ProductEndUserTradeParty')),
            shipFrom: $this->extendedParty($this->node($delivery, 'ram:ShipFromTradeParty')),
            deliveryTermsCode: $this->value($agreement, 'ram:ApplicableTradeDeliveryTerms/ram:DeliveryTypeCode'),
            additionalReferences: $references,
            installments: $installments,
        );

        return $extended->isEmpty() ? null : $extended;
    }

    private static function nonEmpty(?string $value): ?string
    {
        return $value === null || $value === '' ? null : $value;
    }

    private function category(?string $value, string $bt): VatCategory
    {
        $category = $value === null ? null : VatCategory::tryFrom($value);
        if ($category === null) {
            $this->finding('QUITTIO-READER-VAT-CATEGORY', sprintf('The VAT category code "%s" (%s) is unknown; "S" is assumed.', $value ?? '', $bt), $bt, Severity::Error);

            return VatCategory::StandardRate;
        }

        return $category;
    }
}
