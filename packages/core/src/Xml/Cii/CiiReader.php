<?php

declare(strict_types=1);

namespace Quittio\Core\Xml\Cii;

use Brick\Math\BigDecimal;
use DOMDocument;
use DOMElement;
use InvalidArgumentException;
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
use Quittio\Core\Profiles\Syntax;
use Quittio\Core\Reading\FormatReader;
use Quittio\Core\Reading\ReadResult;
use Quittio\Core\Reading\XPathReader;
use Quittio\Core\Standards\StandardVersion;
use Quittio\Core\Support\Amount;
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
    private string $currency = 'EUR';

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

        $this->currency = $this->value($settlement, 'ram:InvoiceCurrencyCode') ?? 'EUR';
        $taxCurrency = $this->value($settlement, 'ram:TaxCurrencyCode');
        $specificationId = $this->value($root, 'rsm:ExchangedDocumentContext/ram:GuidelineSpecifiedDocumentContextParameter/ram:ID');

        $declaredTotals = $this->totals($settlement, $taxCurrency);
        $declaredBreakdown = $this->vatBreakdown($settlement);

        [$taxPointDate, $taxPointDateCode] = $this->taxPoint($settlement);
        $paymentTerms = $this->paymentTerms($settlement);
        $extended = $this->extended($agreement, $deliveryNode, $settlement);

        $data = new InvoiceData(
            number: $this->value($root, 'rsm:ExchangedDocument/ram:ID') ?? '',
            issueDate: $this->date($this->value($root, 'rsm:ExchangedDocument/ram:IssueDateTime/udt:DateTimeString'), 'BT-2') ?? \Quittio\Core\Support\Date::today(),
            typeCode: $this->code($this->value($root, 'rsm:ExchangedDocument/ram:TypeCode'), InvoiceTypeCode::class, 'BT-3') ?? new RawCode(''),
            currency: $this->currency,
            seller: $this->seller($agreement),
            buyer: $this->buyer($agreement),
            lines: $this->lines($root),
            taxCurrency: $taxCurrency,
            taxPointDate: $taxPointDate,
            taxPointDateCode: $taxPointDateCode,
            dueDate: $this->date($this->value($settlement, 'ram:SpecifiedTradePaymentTerms/ram:DueDateDateTime/udt:DateTimeString'), 'BT-9'),
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
            standardVersion: $specificationId !== null && str_starts_with($specificationId, 'urn:cen.eu:en16931:2026')
                ? StandardVersion::En16931_2026
                : StandardVersion::En16931_2017,
        );

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
            $filename = self::nonEmpty($binary?->getAttribute('filename'));
            $mimeType = $mime === null ? null : MimeType::tryFrom($mime);
            if ($content !== null && ($mimeType === null || $filename === null)) {
                $this->finding('QUITTIO-READER-ATTACHMENT', sprintf('The attached document "%s" has an unsupported MIME code or no file name and was skipped.', $filename ?? '?'), 'BT-125-1');
                $content = null;
            }

            $attachments[] = new Attachment(
                id: $this->value($node, 'ram:IssuerAssignedID') ?? '',
                description: $this->value($node, 'ram:Name'),
                externalUri: $this->value($node, 'ram:URIID'),
                content: $content,
                mimeType: $content === null ? null : $mimeType,
                filename: $content === null ? null : basename((string) $filename),
            );
        }

        return $attachments;
    }

    private function delivery(DOMElement $node): ?Delivery
    {
        $shipTo = $this->node($node, 'ram:ShipToTradeParty');
        $date = $this->date($this->value($node, 'ram:ActualDeliverySupplyChainEvent/ram:OccurrenceDateTime/udt:DateTimeString'), 'BT-72');
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

        $start = $this->date($this->value($node, 'ram:StartDateTime/udt:DateTimeString'), 'BT-73');
        $end = $this->date($this->value($node, 'ram:EndDateTime/udt:DateTimeString'), 'BT-74');

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
            $date ??= $this->date($this->value($tax, 'ram:TaxPointDate/udt:DateString'), 'BT-7');
            $value = $this->value($tax, 'ram:DueDateTypeCode');
            if ($value !== null && $code === null) {
                $code = TaxPointDateCode::fromCii($value);
            }
        }

        return [$date, $code];
    }

    private function paymentTerms(DOMElement $settlement): ?PaymentTerms
    {
        $node = $this->node($settlement, 'ram:SpecifiedTradePaymentTerms');
        if ($node === null) {
            return null;
        }

        $parsed = PaymentTermsText::parse($this->value($node, 'ram:Description'));
        $discounts = array_map(
            fn (array $d): CashDiscount => new CashDiscount($d['days'], BigDecimal::of($d['percent']), $d['base'] === null ? null : Amount::of($d['base'], $this->currency, round: true)),
            $parsed['discounts'],
        );

        $discountNode = $this->node($node, 'ram:ApplicableTradePaymentDiscountTerms');
        if ($discounts === [] && $discountNode !== null) {
            $percent = $this->decimal($this->value($discountNode, 'ram:CalculationPercent'), 'BT-20');
            if ($percent !== null) {
                $discounts[] = new CashDiscount(
                    (int) ($this->value($discountNode, 'ram:BasisPeriodMeasure') ?? 0),
                    $percent,
                    $this->money($this->value($discountNode, 'ram:BasisAmount'), $this->currency, 'BT-20'),
                );
            }
        }

        $penaltyNode = $this->node($node, 'ram:ApplicableTradePaymentPenaltyTerms');
        $penalty = $penaltyNode === null ? null : new PenaltyTerms(
            days: ($days = $this->value($penaltyNode, 'ram:BasisPeriodMeasure')) === null ? null : (int) $days,
            percent: $this->decimal($this->value($penaltyNode, 'ram:CalculationPercent'), 'BT-20'),
        );

        $terms = new PaymentTerms(text: $parsed['text'], discounts: $discounts, lateFeeTerms: $penalty);

        return $terms->isEmpty() ? null : $terms;
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
                $references[] = new PrecedingInvoiceReference($number, $this->date($this->value($node, 'ram:FormattedIssueDateTime/qdt:DateTimeString'), 'BT-26'));
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
        foreach ($this->nodes($settlement, 'ram:SpecifiedTradeAllowanceCharge[ram:ChargeIndicator/udt:Indicator="false"]') as $node) {
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
        foreach ($this->nodes($settlement, 'ram:SpecifiedTradeAllowanceCharge[ram:ChargeIndicator/udt:Indicator="true"]') as $node) {
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

    private function totals(DOMElement $settlement, ?string $taxCurrency): ?Totals
    {
        $node = $this->node($settlement, 'ram:SpecifiedTradeSettlementHeaderMonetarySummation');
        if ($node === null) {
            return null;
        }

        $c = $this->currency;
        $vatTotal = $this->value($node, 'ram:TaxTotalAmount[@currencyID="'.$c.'"]') ?? $this->value($node, 'ram:TaxTotalAmount[not(@currencyID)]');
        $vatInTaxCurrency = $taxCurrency !== null && $taxCurrency !== $c
            ? $this->money($this->value($node, 'ram:TaxTotalAmount[@currencyID="'.$taxCurrency.'"]'), $taxCurrency, 'BT-111')
            : null;

        return new Totals(
            lineNetSum: $this->money($this->value($node, 'ram:LineTotalAmount'), $c, 'BT-106') ?? Amount::zero($c),
            netTotal: $this->money($this->value($node, 'ram:TaxBasisTotalAmount'), $c, 'BT-109') ?? Amount::zero($c),
            grossTotal: $this->money($this->value($node, 'ram:GrandTotalAmount'), $c, 'BT-112') ?? Amount::zero($c),
            amountDue: $this->money($this->value($node, 'ram:DuePayableAmount'), $c, 'BT-115') ?? Amount::zero($c),
            allowanceSum: $this->money($this->value($node, 'ram:AllowanceTotalAmount'), $c, 'BT-107'),
            chargeSum: $this->money($this->value($node, 'ram:ChargeTotalAmount'), $c, 'BT-108'),
            vatTotal: $this->money($vatTotal, $c, 'BT-110'),
            vatTotalInTaxCurrency: $vatInTaxCurrency,
            prepaidAmount: $this->money($this->value($node, 'ram:TotalPrepaidAmount'), $c, 'BT-113'),
            roundingAmount: $this->money($this->value($node, 'ram:RoundingAmount'), $c, 'BT-114'),
        );
    }

    /**
     * @return list<VatBreakdown>
     */
    private function vatBreakdown(DOMElement $settlement): array
    {
        $result = [];
        foreach ($this->nodes($settlement, 'ram:ApplicableTradeTax') as $node) {
            $result[] = new VatBreakdown(
                taxableAmount: $this->money($this->value($node, 'ram:BasisAmount'), $this->currency, 'BT-116') ?? Amount::zero($this->currency),
                taxAmount: $this->money($this->value($node, 'ram:CalculatedAmount'), $this->currency, 'BT-117') ?? Amount::zero($this->currency),
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
        $order = [];
        foreach ($this->nodes($root, 'rsm:SupplyChainTradeTransaction/ram:IncludedSupplyChainTradeLineItem') as $index => $node) {
            $line = $this->line($node);
            $key = $line->id !== '' ? $line->id : '#'.$index;
            if (isset($byId[$key])) {
                $this->finding('QUITTIO-READER-DUPLICATE-LINE', sprintf('The line identifier "%s" is used more than once.', $line->id), 'BT-126');
                $key .= '#'.$index;
            }
            $byId[$key] = ['line' => $line, 'parent' => $this->value($node, 'ram:AssociatedDocumentLineDocument/ram:ParentLineID')];
            $order[] = $key;
        }

        $children = [];
        foreach ($order as $key) {
            $parent = $byId[$key]['parent'];
            if ($parent !== null && isset($byId[$parent])) {
                $children[$parent][] = $key;
            }
        }

        $build = function (string $key) use (&$build, $byId, $children): InvoiceLine {
            $line = $byId[$key]['line'];
            $subs = array_map(static fn (string $child): InvoiceLine => $build($child), $children[$key] ?? []);

            return $subs === [] ? $line : $line->with(['subLines' => $subs]);
        };

        $lines = [];
        foreach ($order as $key) {
            $parent = $byId[$key]['parent'];
            if ($parent === null || ! isset($byId[$parent])) {
                $lines[] = $build($key);
            }
        }

        return $lines;
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
            $isCharge = $this->value($entry, 'ram:ChargeIndicator/udt:Indicator') === 'true';
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
            unit: $this->code($this->attribute($delivery, 'ram:BilledQuantity', 'unitCode') ?? '', UnitCode::class, 'BT-130') ?? new RawCode(''),
            price: new Price(
                netPrice: $this->decimal($this->value($agreement, 'ram:NetPriceProductTradePrice/ram:ChargeAmount'), 'BT-146'),
                discount: $this->decimal($this->value($agreement, 'ram:GrossPriceProductTradePrice/ram:AppliedTradeAllowanceCharge/ram:ActualAmount'), 'BT-147'),
                grossPrice: $this->decimal($this->value($agreement, 'ram:GrossPriceProductTradePrice/ram:ChargeAmount'), 'BT-148'),
                baseQuantity: $this->decimal($this->value($agreement, 'ram:NetPriceProductTradePrice/ram:BasisQuantity'), 'BT-149'),
                baseQuantityUnit: $this->code($this->attribute($agreement, 'ram:NetPriceProductTradePrice/ram:BasisQuantity', 'unitCode'), UnitCode::class, 'BT-150'),
            ),
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
        $installments = [];
        $terms = $this->nodes($settlement, 'ram:SpecifiedTradePaymentTerms');
        if (count($terms) > 1) {
            foreach ($terms as $node) {
                $date = $this->date($this->value($node, 'ram:DueDateDateTime/udt:DateTimeString'), 'BT-9');
                if ($date !== null) {
                    $installments[] = new Installment($date, $this->money($this->value($node, 'ram:PartialPaymentAmount'), $this->currency, 'BT-20'), $this->value($node, 'ram:Description'));
                }
            }
        }

        $references = [];
        foreach ($this->nodes($agreement, 'ram:AdditionalReferencedDocument') as $node) {
            $type = $this->value($node, 'ram:TypeCode');
            if (in_array($type, ['916', '50', '130'], true)) {
                continue;
            }
            $references[] = new DocumentReference(
                id: $this->value($node, 'ram:IssuerAssignedID') ?? '',
                issueDate: $this->date($this->value($node, 'ram:FormattedIssueDateTime/qdt:DateTimeString'), 'BT-122'),
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
