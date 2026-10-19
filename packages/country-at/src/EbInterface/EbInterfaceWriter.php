<?php

declare(strict_types=1);

namespace Quittio\Country\AT\EbInterface;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use DOMDocument;
use Quittio\Core\Data\AllowanceCharge\DocumentAllowanceCharge;
use Quittio\Core\Data\AllowanceCharge\LineAllowanceCharge;
use Quittio\Core\Data\Delivery\Period;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Data\Lines\InvoiceLine;
use Quittio\Core\Data\Party\Address;
use Quittio\Core\Data\Party\Contact;
use Quittio\Core\Enums\InvoiceTypeCode;
use Quittio\Core\Enums\PaymentMeansCode;
use Quittio\Core\Exceptions\InvalidArgumentException;
use Quittio\Core\Support\Decimal;
use Quittio\Core\Xml\XmlBuilder;

/**
 * Writes a calculated invoice as ebInterface 6.1, the Austrian XML invoice format of AUSTRIAPRO
 * that e-Rechnung.gv.at accepts besides UBL.
 *
 * The mapping follows the official mapping of AUSTRIAPRO between UBL and ebInterface: the purchase
 * order reference (BT-13) is the order reference of the invoice recipient, a missing VAT
 * identifier is written as "ATU00000000", credit notes are a "CreditMemo" with positive amounts.
 * Fields without a place in ebInterface (e.g. the buyer reference BT-10, the payee BG-10) are
 * not written.
 */
final class EbInterfaceWriter
{
    public const string NAMESPACE = 'http://www.ebinterface.at/schema/6p1/';

    /** VAT identifier of a party without one, as the AUSTRIAPRO mapping writes it. */
    public const string UNDEFINED_VAT_ID = 'ATU00000000';

    public const string GENERATING_SYSTEM = 'Quittio';

    /**
     * @throws InvalidArgumentException for an invoice that is not calculated
     */
    public function write(InvoiceData $invoice): string
    {
        $totals = $invoice->totals ?? throw new InvalidArgumentException('ebInterface needs a calculated invoice (totals).');

        $xml = new XmlBuilder;
        $xml->root('Invoice', [], self::NAMESPACE);
        $root = $this->rootAttributes($invoice);

        $xml->element('InvoiceNumber', $invoice->number)
            ->element('InvoiceDate', $invoice->issueDate->toIsoString());
        foreach ($invoice->precedingInvoices as $reference) {
            $xml->group('RelatedDocument', fn (XmlBuilder $x) => $x
                ->element('InvoiceNumber', $reference->number)
                ->element('InvoiceDate', $reference->issueDate?->toIsoString())
                ->element('DocumentType', 'Invoice'));
        }
        $this->delivery($xml, $invoice);
        $xml->required('Biller', function (XmlBuilder $x) use ($invoice): void {
            $seller = $invoice->seller;
            $x->element('VATIdentificationNumber', $seller->vatId ?? ($invoice->sellerTaxRepresentative === null ? null : $invoice->sellerTaxRepresentative->vatId) ?? self::UNDEFINED_VAT_ID);
            $this->address($x, $seller->name, $seller->address, $seller->contact);
            $this->contact($x, $seller->contact);
            $x->element('InvoiceRecipientsBillerID', ($seller->identifiers[0] ?? null)?->value);
        });
        $xml->required('InvoiceRecipient', function (XmlBuilder $x) use ($invoice): void {
            $buyer = $invoice->buyer;
            $x->element('VATIdentificationNumber', $buyer->vatId ?? self::UNDEFINED_VAT_ID);
            $x->group('OrderReference', fn (XmlBuilder $o) => $o->element('OrderID', self::orderId($invoice)));
            $this->address($x, $buyer->name, $buyer->address, $buyer->contact);
            $this->contact($x, $buyer->contact);
            $x->element('BillersInvoiceRecipientID', $buyer->identifier?->value);
            $x->element('AccountingArea', $invoice->buyerAccountingReference === null ? null : mb_substr($invoice->buyerAccountingReference, 0, 20));
        });
        $xml->required('Details', function (XmlBuilder $x) use ($invoice): void {
            $x->element('HeaderDescription', $invoice->presentation?->introText);
            $x->required('ItemList', function (XmlBuilder $list) use ($invoice): void {
                foreach ($invoice->lines as $index => $line) {
                    $this->line($list, $invoice, $line, $index + 1);
                }
            });
            $x->element('FooterDescription', $invoice->presentation?->closingText);
        });
        $xml->group('ReductionAndSurchargeDetails', function (XmlBuilder $x) use ($invoice): void {
            foreach ($invoice->allowances as $allowance) {
                $this->documentAllowanceCharge($x, 'Reduction', $allowance);
            }
            foreach ($invoice->charges as $charge) {
                $this->documentAllowanceCharge($x, 'Surcharge', $charge);
            }
        });
        $xml->required('Tax', function (XmlBuilder $x) use ($invoice): void {
            foreach ($invoice->vatBreakdown as $row) {
                $x->group('TaxItem', fn (XmlBuilder $t) => $t
                    ->element('TaxableAmount', self::amount($row->taxableAmount))
                    ->element('TaxPercent', self::rate($row->rate), ['TaxCategoryCode' => $row->category->value])
                    ->element('TaxAmount', self::amount($row->taxAmount))
                    ->element('Comment', $row->exemptionReason));
            }
        });
        $xml->element('TotalGrossAmount', self::amount($totals->grossTotal))
            ->element('PrepaidAmount', $totals->prepaidAmount === null ? null : self::amount($totals->prepaidAmount))
            ->element('RoundingAmount', $totals->roundingAmount === null ? null : self::amount($totals->roundingAmount))
            ->element('PayableAmount', self::amount($totals->amountDue));
        $this->paymentMethod($xml, $invoice);
        $this->paymentConditions($xml, $invoice);
        $comments = array_map(static fn ($note): string => $note->content, $invoice->notes);
        $xml->element('Comment', $comments === [] ? null : implode("\n\n", $comments));

        return $this->withRootAttributes($xml->xml(), $root);
    }

    /**
     * The ebInterface document type of an invoice type code (UNTDID 1001).
     */
    public static function documentType(InvoiceData $invoice): string
    {
        return match ($invoice->typeCode) {
            InvoiceTypeCode::CreditNote, InvoiceTypeCode::SelfBilledCreditNote => 'CreditMemo',
            InvoiceTypeCode::PrepaymentInvoice => 'InvoiceForAdvancePayment',
            InvoiceTypeCode::PartialInvoice => 'InvoiceForPartialDelivery',
            InvoiceTypeCode::SelfBilledInvoice => 'SelfBilling',
            InvoiceTypeCode::FinalPaymentRequest => 'FinalSettlement',
            InvoiceTypeCode::DebitNote => 'SubsequentDebit',
            default => 'Invoice',
        };
    }

    /**
     * The order reference of the invoice recipient: the purchase order reference (BT-13), else the
     * buyer reference (BT-10), which carries the order reference (Auftragsreferenz) of the
     * federal government in CIUS-AT-GOV.
     */
    public static function orderId(InvoiceData $invoice): ?string
    {
        foreach ([$invoice->purchaseOrderReference, $invoice->buyerReference] as $reference) {
            if ($reference !== null && trim($reference) !== '') {
                return trim($reference);
            }
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function rootAttributes(InvoiceData $invoice): array
    {
        $language = $invoice->meta->locale === null ? null : strtolower(substr($invoice->meta->locale, 0, 2));

        return array_filter([
            'GeneratingSystem' => self::GENERATING_SYSTEM,
            'DocumentType' => self::documentType($invoice),
            'InvoiceCurrency' => $invoice->currency,
            'DocumentTitle' => $invoice->presentation?->title,
            'Language' => $language !== null && preg_match('/^[a-z]{2}$/', $language) === 1 ? $language : null,
        ], static fn (?string $value): bool => $value !== null && $value !== '');
    }

    /**
     * Sets the attributes of the root element, which XmlBuilder does not write.
     *
     * @param  array<string, string>  $attributes
     */
    private function withRootAttributes(string $xml, array $attributes): string
    {
        $document = new DOMDocument;
        $document->preserveWhiteSpace = false;
        $document->formatOutput = true;
        $document->loadXML($xml);
        $root = $document->documentElement ?? throw new InvalidArgumentException('ebInterface without root element.');
        foreach ($attributes as $name => $value) {
            $root->setAttribute($name, $value);
        }

        return (string) $document->saveXML();
    }

    private function delivery(XmlBuilder $xml, InvoiceData $invoice): void
    {
        $date = $invoice->delivery?->actualDeliveryDate;
        $period = $invoice->invoicingPeriod;
        if ($date === null && ($period?->start === null || $period->end === null)) {
            return;
        }
        $xml->group('Delivery', function (XmlBuilder $x) use ($invoice, $date, $period): void {
            if ($date !== null) {
                $x->element('Date', $date->toIsoString());
            } else {
                $this->period($x, $period);
            }
            $address = $invoice->delivery?->address;
            if ($address !== null) {
                $this->address($x, $invoice->delivery->recipientName ?? $invoice->buyer->name, $address, null);
            }
        });
    }

    private function period(XmlBuilder $xml, ?Period $period): void
    {
        if ($period?->start === null || $period->end === null) {
            return;
        }
        $xml->group('Period', fn (XmlBuilder $p) => $p
            ->element('FromDate', $period->start->toIsoString())
            ->element('ToDate', $period->end->toIsoString()));
    }

    private function address(XmlBuilder $xml, string $name, Address $address, ?Contact $contact): void
    {
        $xml->required('Address', fn (XmlBuilder $a) => $a
            ->element('Name', $name)
            ->element('Street', trim(implode(' ', array_filter([$address->line1, $address->line2], static fn (?string $l): bool => $l !== null && $l !== ''))))
            ->element('Town', $address->city ?? '-')
            ->element('ZIP', $address->postcode ?? '-')
            ->element('Country', $address->countryCode, ['CountryCode' => $address->countryCode])
            ->element('Phone', $contact?->phone)
            ->element('Email', $contact?->email));
    }

    private function contact(XmlBuilder $xml, ?Contact $contact): void
    {
        if ($contact?->name === null || $contact->name === '') {
            return;
        }
        $xml->group('Contact', fn (XmlBuilder $c) => $c
            ->element('Name', $contact->name)
            ->element('Phone', $contact->phone)
            ->element('Email', $contact->email));
    }

    private function line(XmlBuilder $xml, InvoiceData $invoice, InvoiceLine $line, int $position): void
    {
        $xml->required('ListLineItem', function (XmlBuilder $x) use ($invoice, $line, $position): void {
            $x->element('PositionNumber', ctype_digit($line->id) && (int) $line->id > 0 ? (string) (int) $line->id : (string) $position)
                ->element('Description', $line->item->name)
                ->element('Description', $line->item->description)
                ->element('ArticleNumber', $line->item->sellerId, ['ArticleNumberType' => 'BillersArticleNumber'])
                ->element('ArticleNumber', $line->item->buyerId, ['ArticleNumberType' => 'InvoiceRecipientsArticleNumber'])
                ->element('ArticleNumber', $line->item->standardId?->value, ['ArticleNumberType' => (string) $line->item->standardId?->scheme?->value === '0160' ? 'GTIN' : null])
                ->element('Quantity', self::decimal4($line->quantity), ['Unit' => (string) $line->unit->value])
                ->element('UnitPrice', self::decimal4($line->price->netPrice ?? BigDecimal::zero()), ['BaseQuantity' => $line->price->baseQuantity === null ? null : self::decimal4($line->price->baseQuantity)]);
            $x->group('ReductionAndSurchargeListLineItemDetails', function (XmlBuilder $d) use ($line): void {
                foreach ($line->allowances as $allowance) {
                    $this->lineAllowanceCharge($d, 'ReductionListLineItem', $allowance);
                }
                foreach ($line->charges as $charge) {
                    $this->lineAllowanceCharge($d, 'SurchargeListLineItem', $charge);
                }
            });
            if ($line->period?->start !== null && $line->period->end !== null) {
                $x->group('Delivery', fn (XmlBuilder $d) => $this->period($d, $line->period));
            }
            if (self::orderId($invoice) !== null) {
                $x->group('InvoiceRecipientsOrderReference', fn (XmlBuilder $o) => $o
                    ->element('OrderID', self::orderId($invoice))
                    ->element('OrderPositionNumber', $line->orderLineReference));
            }
            foreach ($line->item->classifications as $classification) {
                $x->element('Classification', $classification->code, ['ClassificationSchema' => (string) $classification->listId->value]);
            }
            $x->required('TaxItem', fn (XmlBuilder $t) => $t
                ->element('TaxableAmount', self::amount($line->netAmount))
                ->element('TaxPercent', self::rate($line->vat->rate), ['TaxCategoryCode' => $line->vat->category->value])
                ->element('Comment', $line->vat->exemptionReason));
            $x->element('LineItemAmount', self::amount($line->netAmount));
        });
    }

    private function lineAllowanceCharge(XmlBuilder $xml, string $name, LineAllowanceCharge $item): void
    {
        $xml->group($name, fn (XmlBuilder $x) => $x
            ->element('BaseAmount', self::amount($item->baseAmount ?? $item->amount))
            ->element('Percentage', $item->percent === null ? null : self::decimal2($item->percent))
            ->element('Amount', self::amount($item->amount))
            ->element('Comment', $item->reason));
    }

    private function documentAllowanceCharge(XmlBuilder $xml, string $name, DocumentAllowanceCharge $item): void
    {
        $xml->group($name, fn (XmlBuilder $x) => $x
            ->element('BaseAmount', self::amount($item->baseAmount ?? $item->amount))
            ->element('Percentage', $item->percent === null ? null : self::decimal2($item->percent))
            ->element('Amount', self::amount($item->amount))
            ->element('Comment', $item->reason)
            ->required('TaxItem', fn (XmlBuilder $t) => $t
                ->element('TaxableAmount', self::amount($item->amount))
                ->element('TaxPercent', self::rate($item->vatRate), ['TaxCategoryCode' => $item->vatCategory->value])
                ->element('Comment', $item->exemptionReason)));
    }

    private function paymentMethod(XmlBuilder $xml, InvoiceData $invoice): void
    {
        $payment = $invoice->paymentInstructions;
        if ($payment === null) {
            return;
        }
        $code = (string) $payment->meansCode->value;
        $xml->group('PaymentMethod', function (XmlBuilder $x) use ($payment, $code): void {
            $x->element('Comment', $payment->meansText);
            if (in_array($code, [PaymentMeansCode::SepaDirectDebit->value, PaymentMeansCode::DirectDebit->value], true)) {
                $x->required('SEPADirectDebit', fn (XmlBuilder $d) => $d
                    ->element('IBAN', $payment->directDebit?->debitedAccountId)
                    ->element('CreditorID', $payment->directDebit?->creditorId === null ? null : mb_substr($payment->directDebit->creditorId, 0, 35))
                    ->element('MandateReference', $payment->directDebit?->mandateReference === null ? null : mb_substr($payment->directDebit->mandateReference, 0, 35)));

                return;
            }
            if ($payment->card !== null) {
                $x->required('PaymentCard', fn (XmlBuilder $c) => $c
                    ->element('PrimaryAccountNumber', $payment->card->primaryAccountNumber)
                    ->element('CardHolderName', $payment->card->holderName));

                return;
            }
            if ($payment->creditTransfers !== []) {
                $x->required('UniversalBankTransaction', function (XmlBuilder $u) use ($payment): void {
                    foreach ($payment->creditTransfers as $account) {
                        $u->group('BeneficiaryAccount', fn (XmlBuilder $a) => $a
                            ->element('BIC', $account->serviceProviderId)
                            ->element('IBAN', $account->accountId)
                            ->element('BankAccountOwner', $account->accountName === null ? null : mb_substr($account->accountName, 0, 70)));
                    }
                    $u->element('PaymentReference', $payment->remittanceInformation === null ? null : mb_substr($payment->remittanceInformation, 0, 35));
                });

                return;
            }
            $x->required('OtherPayment', static fn () => null);
        });
    }

    private function paymentConditions(XmlBuilder $xml, InvoiceData $invoice): void
    {
        $terms = $invoice->paymentTerms;
        $xml->group('PaymentConditions', function (XmlBuilder $x) use ($invoice, $terms): void {
            $x->element('DueDate', $invoice->dueDate?->toIsoString());
            foreach ($terms->discounts ?? [] as $discount) {
                $x->group('Discount', fn (XmlBuilder $d) => $d
                    ->element('PaymentDate', $invoice->issueDate->addDays($discount->days)->toIsoString())
                    ->element('BaseAmount', $discount->baseAmount === null ? null : self::amount($discount->baseAmount))
                    ->element('Percentage', self::decimal2($discount->percent)));
            }
            $x->element('Comment', $terms?->text);
        });
    }

    private static function amount(?Money $money): string
    {
        return $money === null ? '0.00' : $money->getAmount()->toScale(2, RoundingMode::HalfUp)->toString();
    }

    private static function rate(?BigDecimal $rate): string
    {
        return Decimal::format(Decimal::round($rate ?? BigDecimal::zero(), 2));
    }

    private static function decimal2(BigDecimal $value): string
    {
        return Decimal::format(Decimal::round($value, 2));
    }

    private static function decimal4(BigDecimal $value): string
    {
        return Decimal::format(Decimal::round($value, 4));
    }
}
