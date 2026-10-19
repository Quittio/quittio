<?php

declare(strict_types=1);

namespace Quittio\Country\AT\EbInterface;

use Brick\Math\BigDecimal;
use Brick\Money\Money;
use DOMDocument;
use DOMElement;
use Quittio\Core\Codes\RawCode;
use Quittio\Core\Data\AllowanceCharge\DocumentAllowance;
use Quittio\Core\Data\AllowanceCharge\DocumentCharge;
use Quittio\Core\Data\AllowanceCharge\LineAllowance;
use Quittio\Core\Data\AllowanceCharge\LineCharge;
use Quittio\Core\Data\Delivery\Delivery;
use Quittio\Core\Data\Delivery\Period;
use Quittio\Core\Data\Identifier;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Data\Lines\InvoiceLine;
use Quittio\Core\Data\Lines\Item;
use Quittio\Core\Data\Lines\LineVat;
use Quittio\Core\Data\Lines\Price;
use Quittio\Core\Data\Note;
use Quittio\Core\Data\Party\Address;
use Quittio\Core\Data\Party\BuyerParty;
use Quittio\Core\Data\Party\Contact;
use Quittio\Core\Data\Party\SellerParty;
use Quittio\Core\Data\Payment\CashDiscount;
use Quittio\Core\Data\Payment\CreditTransfer;
use Quittio\Core\Data\Payment\DirectDebit;
use Quittio\Core\Data\Payment\PaymentCard;
use Quittio\Core\Data\Payment\PaymentInstructions;
use Quittio\Core\Data\Payment\PaymentTerms;
use Quittio\Core\Data\References\PrecedingInvoiceReference;
use Quittio\Core\Data\Totals\Totals;
use Quittio\Core\Data\Totals\VatBreakdown;
use Quittio\Core\Enums\InvoiceTypeCode;
use Quittio\Core\Enums\PaymentMeansCode;
use Quittio\Core\Enums\UnitCode;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Exceptions\InvalidArgumentException;
use Quittio\Core\Reading\DeclaredExemptionReasons;
use Quittio\Core\Reading\ReadResult;
use Quittio\Core\Reading\XPathReader;
use Quittio\Core\Support\Amount;

/**
 * One read of EbInterfaceReader: holds the XPath, the currency and the findings of the document.
 *
 * @internal
 */
final class EbInterfaceDocumentReader extends XPathReader
{
    /** ebInterface document types and the invoice type codes (UNTDID 1001) they are read as. */
    private const array TYPE_CODES = [
        'Invoice' => InvoiceTypeCode::CommercialInvoice,
        'CreditMemo' => InvoiceTypeCode::CreditNote,
        'SubsequentCredit' => InvoiceTypeCode::CreditNote,
        'InvoiceForAdvancePayment' => InvoiceTypeCode::PrepaymentInvoice,
        'InvoiceForPartialDelivery' => InvoiceTypeCode::PartialInvoice,
        'SelfBilling' => InvoiceTypeCode::SelfBilledInvoice,
        'FinalSettlement' => InvoiceTypeCode::FinalPaymentRequest,
        'SubsequentDebit' => InvoiceTypeCode::DebitNote,
    ];

    public function read(DOMDocument $document, string $xml): ReadResult
    {
        $root = $document->documentElement ?? throw new InvalidArgumentException('Empty document.');
        $this->init($document, ['e' => (string) $root->namespaceURI]);
        $this->currency = $root->getAttribute('InvoiceCurrency') !== '' ? strtoupper(trim($root->getAttribute('InvoiceCurrency'))) : 'EUR';

        $lines = array_map(fn (DOMElement $n): InvoiceLine => $this->line($n), $this->nodes($root, 'e:Details/e:ItemList/e:ListLineItem'));
        $allowances = array_map(fn (DOMElement $n): DocumentAllowance => new DocumentAllowance(...$this->documentAllowanceCharge($n)), $this->nodes($root, 'e:ReductionAndSurchargeDetails/e:Reduction'));
        $charges = array_map(fn (DOMElement $n): DocumentCharge => new DocumentCharge(...$this->documentAllowanceCharge($n)), $this->nodes($root, 'e:ReductionAndSurchargeDetails/e:Surcharge'));
        $breakdown = $this->breakdown($root);
        $totals = $this->totalsOf($root, $lines, $allowances, $charges, $breakdown);
        $delivery = $this->node($root, 'e:Delivery');
        $orderId = $this->value($root, 'e:InvoiceRecipient/e:OrderReference/e:OrderID');
        $type = $root->getAttribute('DocumentType');
        $language = $root->getAttribute('Language');

        $data = new InvoiceData(
            number: $this->value($root, 'e:InvoiceNumber') ?? '',
            issueDate: $this->issueDate($this->date($this->value($root, 'e:InvoiceDate'), 'BT-2')),
            typeCode: self::TYPE_CODES[$type] ?? InvoiceTypeCode::CommercialInvoice,
            currency: $this->currency,
            seller: $this->seller($root),
            buyer: $this->buyer($root),
            lines: $lines,
            dueDate: $this->date($this->value($root, 'e:PaymentConditions/e:DueDate'), 'BT-9'),
            purchaseOrderReference: $orderId,
            buyerAccountingReference: $this->value($root, 'e:InvoiceRecipient/e:AccountingArea'),
            paymentTerms: $this->paymentTerms($root),
            notes: $this->notes($root),
            precedingInvoices: $this->precedingInvoices($root),
            delivery: $this->delivery($delivery),
            invoicingPeriod: $delivery === null ? null : $this->period($delivery),
            paymentInstructions: $this->paymentInstructions($root),
            allowances: $allowances,
            charges: $charges,
            totals: $totals,
            vatBreakdown: $breakdown,
            prepaidAmount: $totals->prepaidAmount,
            roundingAmount: $totals->roundingAmount,
        );
        if ($language !== '') {
            $data = $data->with(['meta' => $data->meta->with(['locale' => strtolower($language)])]);
        }

        return new ReadResult(
            data: DeclaredExemptionReasons::apply($data),
            syntax: EbInterfaceReader::SYNTAX,
            specificationId: null,
            profile: null,
            declaredTotals: $totals,
            declaredVatBreakdown: $breakdown,
            findings: $this->findings,
            xml: $xml,
        );
    }

    private function seller(DOMElement $root): SellerParty
    {
        $biller = $this->node($root, 'e:Biller');
        $billerId = $biller === null ? null : $this->value($biller, 'e:InvoiceRecipientsBillerID');

        return new SellerParty(
            name: $biller === null ? '' : ($this->value($biller, 'e:Address/e:Name') ?? ''),
            address: $this->address($biller === null ? null : $this->node($biller, 'e:Address')),
            identifiers: $billerId === null ? [] : [new Identifier($billerId)],
            vatId: $biller === null ? null : $this->vatId($biller),
            contact: $biller === null ? null : $this->contact($biller),
        );
    }

    private function buyer(DOMElement $root): BuyerParty
    {
        $recipient = $this->node($root, 'e:InvoiceRecipient');
        $recipientId = $recipient === null ? null : $this->value($recipient, 'e:BillersInvoiceRecipientID');

        return new BuyerParty(
            name: $recipient === null ? '' : ($this->value($recipient, 'e:Address/e:Name') ?? ''),
            address: $this->address($recipient === null ? null : $this->node($recipient, 'e:Address')),
            identifier: $recipientId === null ? null : new Identifier($recipientId),
            vatId: $recipient === null ? null : $this->vatId($recipient),
            contact: $recipient === null ? null : $this->contact($recipient),
        );
    }

    /**
     * The VAT identifier of a party; "00000000" and "ATU00000000" stand for none.
     */
    private function vatId(DOMElement $party): ?string
    {
        $vatId = $this->value($party, 'e:VATIdentificationNumber');

        return $vatId === null || in_array($vatId, ['00000000', EbInterfaceWriter::UNDEFINED_VAT_ID], true) ? null : $vatId;
    }

    private function address(?DOMElement $node): Address
    {
        if ($node === null) {
            return new Address(countryCode: '');
        }
        $zip = $this->value($node, 'e:ZIP');
        $town = $this->value($node, 'e:Town');

        return new Address(
            countryCode: strtoupper($this->attribute($node, 'e:Country', 'CountryCode') ?? ''),
            line1: $this->value($node, 'e:Street') ?? $this->value($node, 'e:POBox'),
            city: $town === '-' ? null : $town,
            postcode: $zip === '-' ? null : $zip,
        );
    }

    /**
     * The contact of a party; without a Contact element the phone and email of its address.
     */
    private function contact(DOMElement $party): ?Contact
    {
        $name = $this->value($party, 'e:Contact/e:Name');
        $phone = $this->value($party, 'e:Contact/e:Phone') ?? $this->value($party, 'e:Address/e:Phone');
        $email = $this->value($party, 'e:Contact/e:Email') ?? $this->value($party, 'e:Address/e:Email');

        return $name === null && $phone === null && $email === null ? null : new Contact($name, $phone, $email);
    }

    private function line(DOMElement $node): InvoiceLine
    {
        $descriptions = array_values(array_filter(array_map(static fn (DOMElement $d): string => trim($d->textContent), $this->nodes($node, 'e:Description')), static fn (string $d): bool => $d !== ''));
        $gtin = $this->value($node, 'e:ArticleNumber[@ArticleNumberType="GTIN"]');
        $taxItem = $this->node($node, 'e:TaxItem');
        $netAmount = $this->money($this->value($node, 'e:LineItemAmount'), $this->currency, 'BT-131');

        return new InvoiceLine(
            id: $this->value($node, 'e:PositionNumber') ?? '',
            quantity: $this->decimal($this->value($node, 'e:Quantity'), 'BT-129') ?? BigDecimal::zero(),
            unit: $this->code($this->attribute($node, 'e:Quantity', 'Unit'), UnitCode::class, 'BT-130') ?? new RawCode('C62'),
            price: new Price(
                netPrice: $this->decimal($this->value($node, 'e:UnitPrice'), 'BT-146'),
                baseQuantity: $this->decimal($this->attribute($node, 'e:UnitPrice', 'BaseQuantity'), 'BT-149'),
            ),
            vat: new LineVat(
                category: $this->category($taxItem === null ? null : $this->attribute($taxItem, 'e:TaxPercent', 'TaxCategoryCode'), 'BT-151'),
                rate: $taxItem === null ? null : $this->decimal($this->value($taxItem, 'e:TaxPercent'), 'BT-152'),
                exemptionReason: $taxItem === null ? null : $this->value($taxItem, 'e:Comment'),
            ),
            item: new Item(
                name: $descriptions[0] ?? '',
                description: isset($descriptions[1]) ? implode("\n", array_slice($descriptions, 1)) : null,
                sellerId: $this->value($node, 'e:ArticleNumber[@ArticleNumberType="BillersArticleNumber"]'),
                buyerId: $this->value($node, 'e:ArticleNumber[@ArticleNumberType="InvoiceRecipientsArticleNumber"]'),
                standardId: $gtin === null ? null : new Identifier($gtin, new RawCode('0160')),
            ),
            netAmount: $netAmount,
            orderLineReference: $this->value($node, 'e:InvoiceRecipientsOrderReference/e:OrderPositionNumber'),
            period: ($delivery = $this->node($node, 'e:Delivery')) === null ? null : $this->period($delivery),
            allowances: array_map(fn (DOMElement $n): LineAllowance => new LineAllowance(...$this->lineAllowanceCharge($n)), $this->nodes($node, 'e:ReductionAndSurchargeListLineItemDetails/e:ReductionListLineItem')),
            charges: array_map(fn (DOMElement $n): LineCharge => new LineCharge(...$this->lineAllowanceCharge($n)), $this->nodes($node, 'e:ReductionAndSurchargeListLineItemDetails/e:SurchargeListLineItem')),
        );
    }

    /**
     * @return array{amount: ?Money, baseAmount: ?Money, percent: ?BigDecimal, reason: ?string}
     */
    private function lineAllowanceCharge(DOMElement $node): array
    {
        return [
            'amount' => $this->money($this->value($node, 'e:Amount'), $this->currency, 'BT-136'),
            'baseAmount' => $this->money($this->value($node, 'e:BaseAmount'), $this->currency, 'BT-137'),
            'percent' => $this->decimal($this->value($node, 'e:Percentage'), 'BT-138'),
            'reason' => $this->value($node, 'e:Comment'),
        ];
    }

    /**
     * @return array{amount: ?Money, vatCategory: VatCategory, vatRate: ?BigDecimal, baseAmount: ?Money, percent: ?BigDecimal, reason: ?string, exemptionReason: ?string}
     */
    private function documentAllowanceCharge(DOMElement $node): array
    {
        return [
            'amount' => $this->money($this->value($node, 'e:Amount'), $this->currency, 'BT-92'),
            'vatCategory' => $this->category($this->attribute($node, 'e:TaxItem/e:TaxPercent', 'TaxCategoryCode'), 'BT-95'),
            'vatRate' => $this->decimal($this->value($node, 'e:TaxItem/e:TaxPercent'), 'BT-96'),
            'baseAmount' => $this->money($this->value($node, 'e:BaseAmount'), $this->currency, 'BT-93'),
            'percent' => $this->decimal($this->value($node, 'e:Percentage'), 'BT-94'),
            'reason' => $this->value($node, 'e:Comment'),
            'exemptionReason' => $this->value($node, 'e:TaxItem/e:Comment'),
        ];
    }

    /**
     * @return list<VatBreakdown>
     */
    private function breakdown(DOMElement $root): array
    {
        $rows = [];
        foreach ($this->nodes($root, 'e:Tax/e:TaxItem') as $node) {
            $rows[] = new VatBreakdown(
                taxableAmount: $this->money($this->value($node, 'e:TaxableAmount'), $this->currency, 'BT-116') ?? $this->missingAmount('BT-116', 'BR-45', 'VAT category taxable amount'),
                taxAmount: $this->money($this->value($node, 'e:TaxAmount'), $this->currency, 'BT-117') ?? Amount::zero($this->currency),
                category: $this->category($this->attribute($node, 'e:TaxPercent', 'TaxCategoryCode'), 'BT-118'),
                rate: $this->decimal($this->value($node, 'e:TaxPercent'), 'BT-119'),
                exemptionReason: $this->value($node, 'e:Comment'),
            );
        }

        return $rows;
    }

    /**
     * The totals of EN 16931 from the ebInterface amounts: sums of the lines, reductions,
     * surcharges and tax items, the declared gross, prepaid, rounding and payable amounts.
     *
     * @param  list<InvoiceLine>  $lines
     * @param  list<DocumentAllowance>  $allowances
     * @param  list<DocumentCharge>  $charges
     * @param  list<VatBreakdown>  $breakdown
     */
    private function totalsOf(DOMElement $root, array $lines, array $allowances, array $charges, array $breakdown): Totals
    {
        $lineNet = $this->sum(array_map(static fn (InvoiceLine $l): ?Money => $l->netAmount, $lines));
        $allowanceSum = $this->sum(array_map(static fn (DocumentAllowance $a): ?Money => $a->amount, $allowances));
        $chargeSum = $this->sum(array_map(static fn (DocumentCharge $c): ?Money => $c->amount, $charges));

        return new Totals(
            lineNetSum: $lineNet,
            netTotal: $lineNet->minus($allowanceSum)->plus($chargeSum),
            grossTotal: $this->money($this->value($root, 'e:TotalGrossAmount'), $this->currency, 'BT-112') ?? $this->missingAmount('BT-112', 'BR-14', 'Invoice total amount with VAT'),
            amountDue: $this->money($this->value($root, 'e:PayableAmount'), $this->currency, 'BT-115') ?? $this->missingAmount('BT-115', 'BR-15', 'Amount due for payment'),
            allowanceSum: $allowances === [] ? null : $allowanceSum,
            chargeSum: $charges === [] ? null : $chargeSum,
            vatTotal: $this->sum(array_map(static fn (VatBreakdown $row): Money => $row->taxAmount, $breakdown)),
            prepaidAmount: $this->money($this->value($root, 'e:PrepaidAmount'), $this->currency, 'BT-113'),
            roundingAmount: $this->money($this->value($root, 'e:RoundingAmount'), $this->currency, 'BT-114'),
        );
    }

    /**
     * @param  list<Money|null>  $amounts
     */
    private function sum(array $amounts): Money
    {
        $total = Amount::zero($this->currency);
        foreach ($amounts as $amount) {
            $total = $amount === null ? $total : $total->plus($amount);
        }

        return $total;
    }

    private function delivery(?DOMElement $node): ?Delivery
    {
        $date = $node === null ? null : $this->date($this->value($node, 'e:Date'), 'BT-72');
        $address = $node === null ? null : $this->node($node, 'e:Address');
        if ($date === null && $address === null) {
            return null;
        }

        return new Delivery(
            recipientName: $address === null ? null : $this->value($address, 'e:Name'),
            actualDeliveryDate: $date,
            address: $address === null ? null : $this->address($address),
        );
    }

    private function period(DOMElement $node): ?Period
    {
        $from = $this->date($this->value($node, 'e:Period/e:FromDate'), 'BT-73');
        $to = $this->date($this->value($node, 'e:Period/e:ToDate'), 'BT-74');

        return $from === null && $to === null ? null : new Period($from, $to);
    }

    /**
     * @return list<PrecedingInvoiceReference>
     */
    private function precedingInvoices(DOMElement $root): array
    {
        $references = [];
        foreach ($this->nodes($root, 'e:CancelledOriginalDocument | e:RelatedDocument') as $node) {
            $number = $this->value($node, 'e:InvoiceNumber');
            if ($number !== null) {
                $references[] = new PrecedingInvoiceReference($number, $this->date($this->value($node, 'e:InvoiceDate'), 'BT-26'));
            }
        }

        return $references;
    }

    /**
     * @return list<Note>
     */
    private function notes(DOMElement $root): array
    {
        $comment = $this->value($root, 'e:Comment');
        if ($comment === null) {
            return [];
        }

        $parts = preg_split('/\R{2,}/', $comment);

        return array_values(array_map(static fn (string $text): Note => new Note(trim($text)), array_filter($parts === false ? [$comment] : $parts, static fn (string $text): bool => trim($text) !== '')));
    }

    private function paymentInstructions(DOMElement $root): ?PaymentInstructions
    {
        $method = $this->node($root, 'e:PaymentMethod');
        if ($method === null) {
            return null;
        }
        $comment = $this->value($method, 'e:Comment');
        if (($debit = $this->node($method, 'e:SEPADirectDebit')) !== null) {
            return new PaymentInstructions(
                meansCode: PaymentMeansCode::SepaDirectDebit,
                meansText: $comment,
                directDebit: new DirectDebit($this->value($debit, 'e:MandateReference'), $this->value($debit, 'e:CreditorID'), $this->value($debit, 'e:IBAN')),
            );
        }
        if (($card = $this->node($method, 'e:PaymentCard')) !== null) {
            return new PaymentInstructions(
                meansCode: PaymentMeansCode::BankCard,
                meansText: $comment,
                card: new PaymentCard($this->value($card, 'e:PrimaryAccountNumber') ?? '', $this->value($card, 'e:CardHolderName')),
            );
        }
        if (($transfer = $this->node($method, 'e:UniversalBankTransaction')) !== null) {
            $accounts = [];
            foreach ($this->nodes($transfer, 'e:BeneficiaryAccount') as $account) {
                $id = $this->value($account, 'e:IBAN') ?? $this->value($account, 'e:BankAccountNr');
                if ($id !== null) {
                    $accounts[] = new CreditTransfer($id, $this->value($account, 'e:BankAccountOwner'), $this->value($account, 'e:BIC'));
                }
            }

            return new PaymentInstructions(
                meansCode: PaymentMeansCode::SepaCreditTransfer,
                meansText: $comment,
                remittanceInformation: $this->value($transfer, 'e:PaymentReference'),
                creditTransfers: $accounts,
            );
        }

        return new PaymentInstructions(meansCode: PaymentMeansCode::MutuallyDefined, meansText: $comment);
    }

    private function paymentTerms(DOMElement $root): ?PaymentTerms
    {
        $conditions = $this->node($root, 'e:PaymentConditions');
        if ($conditions === null) {
            return null;
        }
        $issueDate = $this->date($this->value($root, 'e:InvoiceDate'), 'BT-2');
        $discounts = [];
        foreach ($this->nodes($conditions, 'e:Discount') as $node) {
            $date = $this->date($this->value($node, 'e:PaymentDate'), 'BT-20');
            $percent = $this->decimal($this->value($node, 'e:Percentage'), 'BT-20');
            if ($date !== null && $percent !== null && $issueDate !== null) {
                $discounts[] = new CashDiscount($issueDate->diffInDays($date), $percent, $this->money($this->value($node, 'e:BaseAmount'), $this->currency, 'BT-20'));
            }
        }
        $text = $this->value($conditions, 'e:Comment');

        return $text === null && $discounts === [] ? null : new PaymentTerms(text: $text, discounts: $discounts);
    }
}
