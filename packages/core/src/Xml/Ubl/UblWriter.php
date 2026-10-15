<?php

declare(strict_types=1);

namespace Quittio\Core\Xml\Ubl;

use Brick\Math\BigDecimal;
use Brick\Money\Money;
use Quittio\Core\Calculation\CalculationException;
use Quittio\Core\Data\AllowanceCharge\DocumentAllowanceCharge;
use Quittio\Core\Data\AllowanceCharge\LineAllowanceCharge;
use Quittio\Core\Data\Delivery\Period;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Data\Lines\InvoiceLine;
use Quittio\Core\Data\Party\Address;
use Quittio\Core\Data\Party\Contact;
use Quittio\Core\Enums\DocumentReferenceTypeCode;
use Quittio\Core\Enums\TaxPointDateCode;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Exceptions\InvalidArgumentException;
use Quittio\Core\Profiles\Capabilities\RequiresVatBreakdownRate;
use Quittio\Core\Profiles\Profile;
use Quittio\Core\Profiles\Syntax;
use Quittio\Core\Xml\Format;
use Quittio\Core\Xml\PaymentTermsText;
use Quittio\Core\Xml\SerializationOptions;
use Quittio\Core\Xml\XmlBuilder;
use Quittio\Core\Xml\XmlSerializer;

/**
 * Writes OASIS UBL 2.1 Invoice and CreditNote documents according to the EN 16931
 * binding (CEN/TS 16931-3-2). Credit note type codes produce a CreditNote document.
 */
final class UblWriter implements XmlSerializer
{
    public const string NS_INVOICE = 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2';

    public const string NS_CREDIT_NOTE = 'urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2';

    public const string NS_CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';

    public const string NS_CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    private InvoiceData $invoice;

    private Profile $profile;

    private SerializationOptions $options;

    private bool $creditNote;

    public function syntax(): Syntax
    {
        return Syntax::Ubl;
    }

    public function serialize(InvoiceData $invoice, Profile $profile, SerializationOptions $options = new SerializationOptions): string
    {
        if (! $invoice->isCalculated()) {
            throw new CalculationException('The invoice must be calculated before it can be serialised.');
        }
        if (! in_array(Syntax::Ubl, $profile->syntaxes(), true)) {
            throw new InvalidArgumentException(sprintf('The profile %s cannot be written as UBL.', $profile->name()));
        }

        $this->invoice = $invoice;
        $this->profile = $profile;
        $this->options = $options;
        $this->creditNote = $invoice->isCreditNote();

        $xml = new XmlBuilder($options->indent);
        $xml->root($this->creditNote ? 'CreditNote' : 'Invoice', ['cac' => self::NS_CAC, 'cbc' => self::NS_CBC], $this->creditNote ? self::NS_CREDIT_NOTE : self::NS_INVOICE);

        $this->header($xml);
        $this->parties($xml);
        $this->delivery($xml);
        $this->payment($xml);
        $this->allowancesAndCharges($xml);
        $this->taxTotals($xml);
        $this->monetaryTotal($xml);
        foreach ($invoice->lines as $line) {
            $this->line($xml, $line, $this->creditNote ? 'cac:CreditNoteLine' : 'cac:InvoiceLine');
        }

        return $xml->xml();
    }

    private function header(XmlBuilder $x): void
    {
        $i = $this->invoice;

        $x->element('cbc:CustomizationID', $this->profile->specificationId($i->standardVersion));
        $x->element('cbc:ProfileID', $i->businessProcess ?? $this->profile->defaultBusinessProcess());
        $x->element('cbc:ID', $i->number);
        $x->element('cbc:IssueDate', $i->issueDate->toIsoString());
        if (! $this->creditNote) {
            $x->element('cbc:DueDate', $i->dueDate?->toIsoString());
            $x->element('cbc:InvoiceTypeCode', $i->typeCodeValue());
        } else {
            $x->element('cbc:TaxPointDate', $i->taxPointDate?->toIsoString());
            $x->element('cbc:CreditNoteTypeCode', $i->typeCodeValue());
        }
        foreach ($i->notes as $note) {
            $x->element('cbc:Note', $note->subjectCode === null ? $note->content : '#'.$note->subjectCode->value.'#'.$note->content);
        }
        if (! $this->creditNote) {
            $x->element('cbc:TaxPointDate', $i->taxPointDate?->toIsoString());
        }
        $x->element('cbc:DocumentCurrencyCode', $i->currency);
        // BT-6 only if it differs from BT-5 (BR-53, PEPPOL-EN16931-R005).
        $x->element('cbc:TaxCurrencyCode', $i->taxCurrency === $i->currency ? null : $i->taxCurrency);
        $x->element('cbc:AccountingCost', $i->buyerAccountingReference);
        $x->element('cbc:BuyerReference', $i->buyerReference);

        $code = $i->taxPointDateCode;
        if ($i->invoicingPeriod !== null || $code !== null) {
            $x->group('cac:InvoicePeriod', function (XmlBuilder $x) use ($i, $code): void {
                $x->element('cbc:StartDate', $i->invoicingPeriod?->start?->toIsoString());
                $x->element('cbc:EndDate', $i->invoicingPeriod?->end?->toIsoString());
                $x->element('cbc:DescriptionCode', $code instanceof TaxPointDateCode ? $code->ublCode() : $code?->value);
            });
        }

        if ($i->purchaseOrderReference !== null || $i->salesOrderReference !== null) {
            $x->group('cac:OrderReference', function (XmlBuilder $x) use ($i): void {
                $x->element('cbc:ID', $i->purchaseOrderReference ?? 'NA');
                $x->element('cbc:SalesOrderID', $i->salesOrderReference);
            });
        }

        foreach ($i->precedingInvoices as $preceding) {
            $x->group('cac:BillingReference', fn (XmlBuilder $x) => $x->group('cac:InvoiceDocumentReference', function (XmlBuilder $x) use ($preceding): void {
                $x->element('cbc:ID', $preceding->number);
                $x->element('cbc:IssueDate', $preceding->issueDate?->toIsoString());
            }));
        }

        $x->group('cac:DespatchDocumentReference', fn (XmlBuilder $x) => $x->element('cbc:ID', $i->despatchAdviceReference));
        $x->group('cac:ReceiptDocumentReference', fn (XmlBuilder $x) => $x->element('cbc:ID', $i->receivingAdviceReference));
        if (! $this->creditNote) {
            // CreditNote: after the AdditionalDocumentReferences, see below.
            $x->group('cac:OriginatorDocumentReference', fn (XmlBuilder $x) => $x->element('cbc:ID', $i->tenderReference));
        }
        $x->group('cac:ContractDocumentReference', fn (XmlBuilder $x) => $x->element('cbc:ID', $i->contractReference));

        foreach ($i->attachments as $attachment) {
            $x->group('cac:AdditionalDocumentReference', function (XmlBuilder $x) use ($attachment): void {
                $x->element('cbc:ID', $attachment->id);
                $x->element('cbc:DocumentDescription', $attachment->description);
                $x->group('cac:Attachment', function (XmlBuilder $x) use ($attachment): void {
                    if ($attachment->content !== null) {
                        $x->element('cbc:EmbeddedDocumentBinaryObject', base64_encode($attachment->content), [
                            'mimeCode' => $attachment->mimeType?->value,
                            'filename' => $attachment->filename,
                        ]);
                    }
                    $x->group('cac:ExternalReference', fn (XmlBuilder $x) => $x->element('cbc:URI', $attachment->externalUri));
                });
            });
        }
        if ($i->invoicedObject !== null) {
            $object = $i->invoicedObject;
            $x->group('cac:AdditionalDocumentReference', function (XmlBuilder $x) use ($object): void {
                $x->element('cbc:ID', $object->value, ['schemeID' => $object->schemeValue()]);
                $x->element('cbc:DocumentTypeCode', DocumentReferenceTypeCode::InvoicedObject->value);
            });
        }
        if ($this->creditNote) {
            // CreditNote has no ProjectReference (BT-11 is an AdditionalDocumentReference with
            // code 50), and its OriginatorDocumentReference (BT-17) follows the
            // AdditionalDocumentReferences.
            $x->group('cac:AdditionalDocumentReference', function (XmlBuilder $x) use ($i): void {
                $x->element('cbc:ID', $i->projectReference?->id);
                if ($i->projectReference !== null) {
                    $x->element('cbc:DocumentTypeCode', DocumentReferenceTypeCode::CallForTender->value);
                }
            });
            $x->group('cac:OriginatorDocumentReference', fn (XmlBuilder $x) => $x->element('cbc:ID', $i->tenderReference));
        } else {
            $x->group('cac:ProjectReference', fn (XmlBuilder $x) => $x->element('cbc:ID', $i->projectReference?->id));
        }
    }

    private function parties(XmlBuilder $x): void
    {
        $i = $this->invoice;
        $seller = $i->seller;
        $buyer = $i->buyer;

        $x->required('cac:AccountingSupplierParty', fn (XmlBuilder $x) => $x->required('cac:Party', function (XmlBuilder $x) use ($seller, $i): void {
            $x->element('cbc:EndpointID', $seller->electronicAddress?->value, ['schemeID' => $seller->electronicAddress?->scheme->value]);
            foreach ($seller->identifiers as $identifier) {
                $x->group('cac:PartyIdentification', fn (XmlBuilder $x) => $x->element('cbc:ID', $identifier->value, ['schemeID' => $identifier->schemeValue()]));
            }
            $creditorId = $i->paymentInstructions?->directDebit?->creditorId;
            if ($creditorId !== null) {
                $x->group('cac:PartyIdentification', fn (XmlBuilder $x) => $x->element('cbc:ID', $creditorId, ['schemeID' => 'SEPA']));
            }
            $x->group('cac:PartyName', fn (XmlBuilder $x) => $x->element('cbc:Name', $seller->tradingName));
            $this->address($x, 'cac:PostalAddress', $seller->address);
            $this->partyTaxScheme($x, $seller->vatId, 'VAT');
            $this->partyTaxScheme($x, $seller->taxNumber, 'FC');
            $x->required('cac:PartyLegalEntity', function (XmlBuilder $x) use ($seller): void {
                $x->element('cbc:RegistrationName', $seller->name);
                $x->element('cbc:CompanyID', $seller->legalRegistration?->value, ['schemeID' => $seller->legalRegistration?->schemeValue()]);
                $x->element('cbc:CompanyLegalForm', $seller->additionalLegalInfo);
            });
            $this->contact($x, $seller->contact);
        }));

        $x->required('cac:AccountingCustomerParty', fn (XmlBuilder $x) => $x->required('cac:Party', function (XmlBuilder $x) use ($buyer): void {
            $x->element('cbc:EndpointID', $buyer->electronicAddress?->value, ['schemeID' => $buyer->electronicAddress?->scheme->value]);
            if ($buyer->identifier !== null) {
                $identifier = $buyer->identifier;
                $x->group('cac:PartyIdentification', fn (XmlBuilder $x) => $x->element('cbc:ID', $identifier->value, ['schemeID' => $identifier->schemeValue()]));
            }
            $x->group('cac:PartyName', fn (XmlBuilder $x) => $x->element('cbc:Name', $buyer->tradingName));
            $this->address($x, 'cac:PostalAddress', $buyer->address);
            $this->partyTaxScheme($x, $buyer->vatId, 'VAT');
            $x->required('cac:PartyLegalEntity', function (XmlBuilder $x) use ($buyer): void {
                $x->element('cbc:RegistrationName', $buyer->name);
                $x->element('cbc:CompanyID', $buyer->legalRegistration?->value, ['schemeID' => $buyer->legalRegistration?->schemeValue()]);
            });
            $this->contact($x, $buyer->contact);
        }));

        if ($i->payee !== null) {
            $payee = $i->payee;
            $x->group('cac:PayeeParty', function (XmlBuilder $x) use ($payee): void {
                if ($payee->identifier !== null) {
                    $identifier = $payee->identifier;
                    $x->group('cac:PartyIdentification', fn (XmlBuilder $x) => $x->element('cbc:ID', $identifier->value, ['schemeID' => $identifier->schemeValue()]));
                }
                $x->group('cac:PartyName', fn (XmlBuilder $x) => $x->element('cbc:Name', $payee->name));
                $x->group('cac:PartyLegalEntity', fn (XmlBuilder $x) => $x->element('cbc:CompanyID', $payee->legalRegistration?->value, ['schemeID' => $payee->legalRegistration?->schemeValue()]));
            });
        }

        if ($i->sellerTaxRepresentative !== null) {
            $representative = $i->sellerTaxRepresentative;
            $x->group('cac:TaxRepresentativeParty', function (XmlBuilder $x) use ($representative): void {
                $x->group('cac:PartyName', fn (XmlBuilder $x) => $x->element('cbc:Name', $representative->name));
                $this->address($x, 'cac:PostalAddress', $representative->address);
                $this->partyTaxScheme($x, $representative->vatId, 'VAT');
            });
        }
    }

    private function delivery(XmlBuilder $x): void
    {
        $delivery = $this->invoice->delivery;
        if ($delivery === null) {
            return;
        }

        $x->group('cac:Delivery', function (XmlBuilder $x) use ($delivery): void {
            $x->element('cbc:ActualDeliveryDate', $delivery->actualDeliveryDate?->toIsoString());
            if ($delivery->locationId !== null || $delivery->address !== null) {
                $x->group('cac:DeliveryLocation', function (XmlBuilder $x) use ($delivery): void {
                    $x->element('cbc:ID', $delivery->locationId?->value, ['schemeID' => $delivery->locationId?->schemeValue()]);
                    if ($delivery->address !== null) {
                        $this->address($x, 'cac:Address', $delivery->address);
                    }
                });
            }
            $x->group('cac:DeliveryParty', fn (XmlBuilder $x) => $x->group('cac:PartyName', fn (XmlBuilder $x) => $x->element('cbc:Name', $delivery->recipientName)));
        });
    }

    private function payment(XmlBuilder $x): void
    {
        $i = $this->invoice;
        $instructions = $i->paymentInstructions;

        if ($instructions !== null) {
            $transfers = $instructions->creditTransfers === [] ? [null] : $instructions->creditTransfers;
            foreach ($transfers as $index => $transfer) {
                $x->group('cac:PaymentMeans', function (XmlBuilder $x) use ($instructions, $transfer, $index, $i): void {
                    // BT-82 and the credit note due date may occur only once (UBL-SR-45, UBL-SR-46);
                    // further accounts repeat only the code and the payment id (UBL-SR-44).
                    $x->element('cbc:PaymentMeansCode', (string) $instructions->meansCode->value, ['name' => $index === 0 ? $instructions->meansText : null]);
                    if ($this->creditNote && $index === 0) {
                        $x->element('cbc:PaymentDueDate', $i->dueDate?->toIsoString());
                    }
                    $x->element('cbc:PaymentID', $instructions->remittanceInformation);
                    if ($index === 0 && $instructions->card !== null) {
                        $card = $instructions->card;
                        $x->group('cac:CardAccount', function (XmlBuilder $x) use ($card): void {
                            $x->element('cbc:PrimaryAccountNumberID', $card->primaryAccountNumber);
                            // NetworkID is mandatory in UBL but has no business term in EN 16931.
                            $x->element('cbc:NetworkID', 'NA');
                            $x->element('cbc:HolderName', $card->holderName);
                        });
                    }
                    if ($transfer !== null) {
                        $x->group('cac:PayeeFinancialAccount', function (XmlBuilder $x) use ($transfer): void {
                            $x->element('cbc:ID', $transfer->accountId);
                            $x->element('cbc:Name', $transfer->accountName);
                            $x->group('cac:FinancialInstitutionBranch', fn (XmlBuilder $x) => $x->element('cbc:ID', $transfer->serviceProviderId));
                        });
                    }
                    if ($index === 0 && $instructions->directDebit !== null) {
                        $debit = $instructions->directDebit;
                        $x->group('cac:PaymentMandate', function (XmlBuilder $x) use ($debit): void {
                            $x->element('cbc:ID', $debit->mandateReference);
                            $x->group('cac:PayerFinancialAccount', fn (XmlBuilder $x) => $x->element('cbc:ID', $debit->debitedAccountId));
                        });
                    }
                });
            }
        }

        $text = PaymentTermsText::render($i, $this->profile, $this->options->locale);
        $x->group('cac:PaymentTerms', fn (XmlBuilder $x) => $x->element('cbc:Note', $text));

        if (! $this->creditNote) {
            foreach ($i->xrechnungExtension->thirdPartyPayments ?? [] as $payment) {
                $x->group('cac:PrepaidPayment', function (XmlBuilder $x) use ($payment): void {
                    $x->element('cbc:ID', $payment->type);
                    $x->element('cbc:PaidAmount', Format::amount($payment->amount), ['currencyID' => $payment->amount->getCurrency()->getCurrencyCode()]);
                    $x->element('cbc:InstructionID', $payment->description);
                });
            }
        }
    }

    private function allowancesAndCharges(XmlBuilder $x): void
    {
        foreach ([...$this->invoice->allowances, ...$this->invoice->charges] as $entry) {
            $this->documentAllowanceCharge($x, $entry);
        }
    }

    private function documentAllowanceCharge(XmlBuilder $x, DocumentAllowanceCharge $entry): void
    {
        $x->group('cac:AllowanceCharge', function (XmlBuilder $x) use ($entry): void {
            $x->element('cbc:ChargeIndicator', $entry->isCharge() ? 'true' : 'false');
            $x->element('cbc:AllowanceChargeReasonCode', $entry->reasonCodeValue());
            $x->element('cbc:AllowanceChargeReason', $entry->reason);
            if ($entry->percent !== null) {
                $x->element('cbc:MultiplierFactorNumeric', Format::percent($entry->percent));
            }
            $x->element('cbc:Amount', $this->amount($entry->amount), $this->currency());
            $x->element('cbc:BaseAmount', $this->amount($entry->baseAmount), $this->currency());
            $this->taxCategory($x, 'cac:TaxCategory', $entry->vatCategory, $entry->vatRate);
        });
    }

    private function taxTotals(XmlBuilder $x): void
    {
        $i = $this->invoice;
        $totals = $i->totals ?? throw new CalculationException('Missing totals.');

        $x->required('cac:TaxTotal', function (XmlBuilder $x) use ($i, $totals): void {
            $x->element('cbc:TaxAmount', $this->amount($totals->vatTotal) ?? '0.00', $this->currency());
            foreach ($i->vatBreakdown as $breakdown) {
                $x->group('cac:TaxSubtotal', function (XmlBuilder $x) use ($breakdown): void {
                    $x->element('cbc:TaxableAmount', Format::amount($breakdown->taxableAmount), $this->currency());
                    $x->element('cbc:TaxAmount', Format::amount($breakdown->taxAmount), $this->currency());
                    $rate = $breakdown->rate ?? ($this->profile instanceof RequiresVatBreakdownRate ? BigDecimal::zero() : null);
                    $this->taxCategory($x, 'cac:TaxCategory', $breakdown->category, $rate, $breakdown->exemptionReasonCode?->value, $breakdown->exemptionReason, force: $this->profile instanceof RequiresVatBreakdownRate);
                });
            }
        });

        if ($totals->vatTotalInTaxCurrency !== null) {
            $amount = $totals->vatTotalInTaxCurrency;
            $x->group('cac:TaxTotal', fn (XmlBuilder $x) => $x->element('cbc:TaxAmount', Format::amount($amount), ['currencyID' => $amount->getCurrency()->getCurrencyCode()]));
        }
    }

    private function monetaryTotal(XmlBuilder $x): void
    {
        $totals = $this->invoice->totals ?? throw new CalculationException('Missing totals.');

        $x->required('cac:LegalMonetaryTotal', function (XmlBuilder $x) use ($totals): void {
            $x->element('cbc:LineExtensionAmount', Format::amount($totals->lineNetSum), $this->currency());
            $x->element('cbc:TaxExclusiveAmount', Format::amount($totals->netTotal), $this->currency());
            $x->element('cbc:TaxInclusiveAmount', Format::amount($totals->grossTotal), $this->currency());
            $x->element('cbc:AllowanceTotalAmount', $this->amount($totals->allowanceSum), $this->currency());
            $x->element('cbc:ChargeTotalAmount', $this->amount($totals->chargeSum), $this->currency());
            $x->element('cbc:PrepaidAmount', $this->amount($totals->prepaidAmount), $this->currency());
            $x->element('cbc:PayableRoundingAmount', $this->amount($totals->roundingAmount), $this->currency());
            $x->element('cbc:PayableAmount', Format::amount($totals->amountDue), $this->currency());
        });
    }

    private function line(XmlBuilder $x, InvoiceLine $line, string $element): void
    {
        $x->required($element, function (XmlBuilder $x) use ($line, $element): void {
            $x->element('cbc:ID', $line->id);
            $x->element('cbc:Note', $line->note);
            $x->element($this->creditNote ? 'cbc:CreditedQuantity' : 'cbc:InvoicedQuantity', Format::quantity($line->quantity), ['unitCode' => $line->unit->value]);
            $x->element('cbc:LineExtensionAmount', $this->amount($line->netAmount), $this->currency());
            $x->element('cbc:AccountingCost', $line->buyerAccountingReference);
            if ($line->period !== null) {
                $this->period($x, $line->period);
            }
            $x->group('cac:OrderLineReference', fn (XmlBuilder $x) => $x->element('cbc:LineID', $line->orderLineReference));
            if ($line->objectId !== null) {
                $object = $line->objectId;
                $x->group('cac:DocumentReference', function (XmlBuilder $x) use ($object): void {
                    $x->element('cbc:ID', $object->value, ['schemeID' => $object->schemeValue()]);
                    $x->element('cbc:DocumentTypeCode', DocumentReferenceTypeCode::InvoicedObject->value);
                });
            }
            foreach ([...$line->allowances, ...$line->charges] as $entry) {
                $this->lineAllowanceCharge($x, $entry);
            }

            $x->required('cac:Item', function (XmlBuilder $x) use ($line): void {
                $item = $line->item;
                $x->element('cbc:Description', $item->description);
                $x->element('cbc:Name', $item->name);
                $x->group('cac:BuyersItemIdentification', fn (XmlBuilder $x) => $x->element('cbc:ID', $item->buyerId));
                $x->group('cac:SellersItemIdentification', fn (XmlBuilder $x) => $x->element('cbc:ID', $item->sellerId));
                $x->group('cac:StandardItemIdentification', fn (XmlBuilder $x) => $x->element('cbc:ID', $item->standardId?->value, ['schemeID' => $item->standardId?->schemeValue()]));
                $x->group('cac:OriginCountry', fn (XmlBuilder $x) => $x->element('cbc:IdentificationCode', $item->originCountry));
                foreach ($item->classifications as $classification) {
                    $x->group('cac:CommodityClassification', fn (XmlBuilder $x) => $x->element('cbc:ItemClassificationCode', $classification->code, [
                        'listID' => $classification->listId->value,
                        'listVersionID' => $classification->listVersion,
                    ]));
                }
                $this->taxCategory($x, 'cac:ClassifiedTaxCategory', $line->vat->category, $line->vat->rate);
                foreach ($item->attributes as $attribute) {
                    $x->group('cac:AdditionalItemProperty', function (XmlBuilder $x) use ($attribute): void {
                        $x->element('cbc:Name', $attribute->name);
                        $x->element('cbc:Value', $attribute->value);
                    });
                }
            });

            $isSubLine = str_starts_with($element, 'cac:Sub');
            if ($isSubLine && $line->price->netPrice === null) {
                // Grouping sub lines of the XRechnung extension may carry only a line amount.
                foreach ($line->subLines as $subLine) {
                    $this->line($x, $subLine, $element);
                }

                return;
            }

            $x->required('cac:Price', function (XmlBuilder $x) use ($line): void {
                $price = $line->price;
                $x->element('cbc:PriceAmount', Format::price($price->netPrice ?? throw new CalculationException('Missing net price.')), $this->currency());
                if ($price->baseQuantity !== null) {
                    $x->element('cbc:BaseQuantity', Format::quantity($price->baseQuantity), ['unitCode' => $price->baseQuantityUnit?->value]);
                }
                if ($price->grossPrice !== null) {
                    $x->group('cac:AllowanceCharge', function (XmlBuilder $x) use ($price): void {
                        $x->element('cbc:ChargeIndicator', 'false');
                        $x->element('cbc:Amount', Format::price($price->discount ?? BigDecimal::zero()), $this->currency());
                        $x->element('cbc:BaseAmount', Format::price($price->grossPrice ?? BigDecimal::zero()), $this->currency());
                    });
                }
            });

            $subElement = $this->creditNote ? 'cac:SubCreditNoteLine' : 'cac:SubInvoiceLine';
            foreach ($line->subLines as $subLine) {
                $this->line($x, $subLine, $subElement);
            }
        });
    }

    private function lineAllowanceCharge(XmlBuilder $x, LineAllowanceCharge $entry): void
    {
        $x->group('cac:AllowanceCharge', function (XmlBuilder $x) use ($entry): void {
            $x->element('cbc:ChargeIndicator', $entry->isCharge() ? 'true' : 'false');
            $x->element('cbc:AllowanceChargeReasonCode', $entry->reasonCodeValue());
            $x->element('cbc:AllowanceChargeReason', $entry->reason);
            if ($entry->percent !== null) {
                $x->element('cbc:MultiplierFactorNumeric', Format::percent($entry->percent));
            }
            $x->element('cbc:Amount', $this->amount($entry->amount), $this->currency());
            $x->element('cbc:BaseAmount', $this->amount($entry->baseAmount), $this->currency());
        });
    }

    private function taxCategory(XmlBuilder $x, string $element, VatCategory $category, ?BigDecimal $rate, ?string $exemptionCode = null, ?string $exemptionReason = null, bool $force = false): void
    {
        $x->required($element, function (XmlBuilder $x) use ($category, $rate, $exemptionCode, $exemptionReason, $force): void {
            $x->element('cbc:ID', $category->value);
            if ($rate !== null && ($category->requiresRate() || $force)) {
                $x->element('cbc:Percent', Format::percent($rate));
            }
            $x->element('cbc:TaxExemptionReasonCode', $exemptionCode);
            $x->element('cbc:TaxExemptionReason', $exemptionReason);
            $x->required('cac:TaxScheme', fn (XmlBuilder $x) => $x->element('cbc:ID', 'VAT'));
        });
    }

    private function partyTaxScheme(XmlBuilder $x, ?string $id, string $scheme): void
    {
        if ($id === null || $id === '') {
            return;
        }

        $x->group('cac:PartyTaxScheme', function (XmlBuilder $x) use ($id, $scheme): void {
            $x->element('cbc:CompanyID', $id);
            $x->required('cac:TaxScheme', fn (XmlBuilder $x) => $x->element('cbc:ID', $scheme));
        });
    }

    private function address(XmlBuilder $x, string $element, Address $address): void
    {
        $x->required($element, function (XmlBuilder $x) use ($address): void {
            $x->element('cbc:StreetName', $address->line1);
            $x->element('cbc:AdditionalStreetName', $address->line2);
            $x->element('cbc:CityName', $address->city);
            $x->element('cbc:PostalZone', $address->postcode);
            $x->element('cbc:CountrySubentity', $address->subdivision);
            $x->group('cac:AddressLine', fn (XmlBuilder $x) => $x->element('cbc:Line', $address->line3));
            $x->required('cac:Country', fn (XmlBuilder $x) => $x->element('cbc:IdentificationCode', $address->countryCode));
        });
    }

    private function contact(XmlBuilder $x, ?Contact $contact): void
    {
        if ($contact === null) {
            return;
        }

        $x->group('cac:Contact', function (XmlBuilder $x) use ($contact): void {
            $x->element('cbc:Name', $contact->name);
            $x->element('cbc:Telephone', $contact->phone);
            $x->element('cbc:ElectronicMail', $contact->email);
        });
    }

    private function period(XmlBuilder $x, Period $period): void
    {
        $x->group('cac:InvoicePeriod', function (XmlBuilder $x) use ($period): void {
            $x->element('cbc:StartDate', $period->start?->toIsoString());
            $x->element('cbc:EndDate', $period->end?->toIsoString());
        });
    }

    /**
     * @return array{currencyID: string}
     */
    private function currency(): array
    {
        return ['currencyID' => $this->invoice->currency];
    }

    private function amount(?Money $money): ?string
    {
        return $money === null ? null : Format::amount($money);
    }
}
