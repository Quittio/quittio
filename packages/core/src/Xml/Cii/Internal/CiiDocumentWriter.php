<?php

declare(strict_types=1);

namespace Quittio\Core\Xml\Cii\Internal;

use Brick\Math\BigDecimal;
use Brick\Money\Money;
use Quittio\Core\Calculation\CalculationException;
use Quittio\Core\Data\AllowanceCharge\DocumentAllowanceCharge;
use Quittio\Core\Data\AllowanceCharge\LineAllowanceCharge;
use Quittio\Core\Data\Delivery\Period;
use Quittio\Core\Data\Extensions\ExtendedParty;
use Quittio\Core\Data\Identifier;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Data\Lines\InvoiceLine;
use Quittio\Core\Data\Lines\LineVat;
use Quittio\Core\Data\Party\Address;
use Quittio\Core\Data\Party\Contact;
use Quittio\Core\Data\Party\ElectronicAddress;
use Quittio\Core\Data\Payment\CashDiscount;
use Quittio\Core\Data\Payment\PaymentInstructions;
use Quittio\Core\Data\References\DocumentReference;
use Quittio\Core\Enums\DocumentReferenceTypeCode;
use Quittio\Core\Enums\TaxPointDateCode;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Mapping\FieldMatrix;
use Quittio\Core\Profiles\Capabilities\RequiresVatBreakdownRate;
use Quittio\Core\Profiles\Profile;
use Quittio\Core\Profiles\ProfileLevel;
use Quittio\Core\Support\Date;
use Quittio\Core\Xml\Cii\CiiWriter;
use Quittio\Core\Xml\Format;
use Quittio\Core\Xml\PaymentTermsText;
use Quittio\Core\Xml\SerializationOptions;
use Quittio\Core\Xml\XmlBuilder;

/**
 * One serialisation of CiiWriter: holds the invoice, the profile and the options of the call,
 * so that the writer itself keeps no state.
 *
 * @internal
 */
final readonly class CiiDocumentWriter
{
    private ProfileLevel $level;

    public function __construct(
        private InvoiceData $invoice,
        private Profile $profile,
        private SerializationOptions $options,
    ) {
        $this->level = $profile->level();
    }

    public function write(): string
    {
        $xml = new XmlBuilder($this->options->indent);
        $xml->root('rsm:CrossIndustryInvoice', [
            'rsm' => CiiWriter::NS_RSM,
            'qdt' => CiiWriter::NS_QDT,
            'ram' => CiiWriter::NS_RAM,
            'udt' => CiiWriter::NS_UDT,
        ]);

        $this->context($xml);
        $this->document($xml);
        $xml->required('rsm:SupplyChainTradeTransaction', function (XmlBuilder $x): void {
            if ($this->level->includes(ProfileLevel::Basic)) {
                foreach ($this->flattenLines($this->invoice->lines, null) as [$line, $parentId]) {
                    $this->line($x, $line, $parentId);
                }
            }
            $x->required('ram:ApplicableHeaderTradeAgreement', fn (XmlBuilder $x) => $this->agreement($x));
            $x->required('ram:ApplicableHeaderTradeDelivery', fn (XmlBuilder $x) => $this->delivery($x));
            $x->required('ram:ApplicableHeaderTradeSettlement', fn (XmlBuilder $x) => $this->settlement($x));
        });

        return $xml->xml();
    }

    private function context(XmlBuilder $x): void
    {
        $x->required('rsm:ExchangedDocumentContext', function (XmlBuilder $x): void {
            $process = $this->invoice->businessProcess ?? $this->profile->defaultBusinessProcess();
            $x->group('ram:BusinessProcessSpecifiedDocumentContextParameter', fn (XmlBuilder $x) => $x->element('ram:ID', $process));
            $x->required('ram:GuidelineSpecifiedDocumentContextParameter', fn (XmlBuilder $x) => $x->element('ram:ID', $this->profile->specificationId($this->invoice->standardVersion)));
        });
    }

    private function document(XmlBuilder $x): void
    {
        $x->required('rsm:ExchangedDocument', function (XmlBuilder $x): void {
            $x->element('ram:ID', $this->invoice->number);
            $x->element('ram:TypeCode', $this->invoice->typeCodeValue());
            $x->required('ram:IssueDateTime', fn (XmlBuilder $x) => $this->dateTime($x, $this->invoice->issueDate));
            if ($this->allowed(InvoiceData::class, 'notes')) {
                foreach ($this->invoice->notes as $note) {
                    $x->group('ram:IncludedNote', function (XmlBuilder $x) use ($note): void {
                        $x->element('ram:Content', $note->content);
                        $x->element('ram:SubjectCode', $note->subjectCode?->value);
                    });
                }
            }
        });
    }

    /**
     * Sub lines are written as separate lines with a parent line reference.
     *
     * @param  list<InvoiceLine>  $lines
     * @return list<array{0: InvoiceLine, 1: ?string}>
     */
    private function flattenLines(array $lines, ?string $parentId): array
    {
        $result = [];
        foreach ($lines as $line) {
            $result[] = [$line, $parentId];
            foreach ($this->flattenLines($line->subLines, $line->id) as $entry) {
                $result[] = $entry;
            }
        }

        return $result;
    }

    private function line(XmlBuilder $x, InvoiceLine $line, ?string $parentId): void
    {
        $extended = $this->level === ProfileLevel::Extended;

        $x->required('ram:IncludedSupplyChainTradeLineItem', function (XmlBuilder $x) use ($line, $parentId, $extended): void {
            $x->required('ram:AssociatedDocumentLineDocument', function (XmlBuilder $x) use ($line, $parentId, $extended): void {
                $x->element('ram:LineID', $line->id);
                if ($extended) {
                    $x->element('ram:ParentLineID', $parentId);
                    $x->element('ram:LineStatusReasonCode', $line->extended->lineStatusReasonCode
                        ?? ($line->subLines !== [] ? 'GROUP' : ($parentId !== null ? 'DETAIL' : null)));
                }
                $x->group('ram:IncludedNote', fn (XmlBuilder $x) => $x->element('ram:Content', $line->note));
            });

            $x->required('ram:SpecifiedTradeProduct', function (XmlBuilder $x) use ($line, $extended): void {
                $item = $line->item;
                $this->identifier($x, 'ram:GlobalID', $item->standardId, true);
                if ($this->level->includes(ProfileLevel::En16931)) {
                    $x->element('ram:SellerAssignedID', $item->sellerId);
                    $x->element('ram:BuyerAssignedID', $item->buyerId);
                }
                $x->element('ram:Name', $item->name);
                if ($this->level->includes(ProfileLevel::En16931)) {
                    $x->element('ram:Description', $item->description);
                    foreach ($item->attributes as $attribute) {
                        $x->group('ram:ApplicableProductCharacteristic', function (XmlBuilder $x) use ($attribute): void {
                            $x->element('ram:Description', $attribute->name);
                            $x->element('ram:Value', $attribute->value);
                        });
                    }
                    foreach ($item->classifications as $classification) {
                        $x->group('ram:DesignatedProductClassification', fn (XmlBuilder $x) => $x->element('ram:ClassCode', $classification->code, [
                            'listID' => $classification->listId->value,
                            'listVersionID' => $classification->listVersion,
                        ]));
                    }
                    $x->group('ram:OriginTradeCountry', fn (XmlBuilder $x) => $x->element('ram:ID', $item->originCountry));
                }
                if ($extended) {
                    foreach ($line->extended->includedProducts ?? [] as $product) {
                        $x->group('ram:IncludedReferencedProduct', function (XmlBuilder $x) use ($product): void {
                            $x->element('ram:SellerAssignedID', $product->sellerId);
                            $x->element('ram:Name', $product->name);
                            $x->element('ram:Description', $product->description);
                            if ($product->quantity !== null) {
                                $x->element('ram:UnitQuantity', Format::quantity($product->quantity), ['unitCode' => $product->unit?->value]);
                            }
                        });
                    }
                }
            });

            $x->required('ram:SpecifiedLineTradeAgreement', function (XmlBuilder $x) use ($line, $extended): void {
                $references = $this->lineReferences($line, DocumentReference::TYPE_ORDER);
                if ($this->level->includes(ProfileLevel::En16931) || $extended) {
                    $orderReference = $references[0] ?? null;
                    $x->group('ram:BuyerOrderReferencedDocument', function (XmlBuilder $x) use ($line, $orderReference, $extended): void {
                        if ($extended) {
                            $x->element('ram:IssuerAssignedID', $orderReference?->id);
                        }
                        $x->element('ram:LineID', $line->orderLineReference ?? $orderReference?->lineId);
                    });
                }
                if ($extended) {
                    foreach ($this->lineReferences($line, DocumentReference::TYPE_CONTRACT) as $contract) {
                        $x->group('ram:ContractReferencedDocument', function (XmlBuilder $x) use ($contract): void {
                            $x->element('ram:IssuerAssignedID', $contract->id);
                            $x->element('ram:LineID', $contract->lineId);
                        });
                    }
                }

                $price = $line->price;
                if ($price->grossPrice !== null) {
                    $x->group('ram:GrossPriceProductTradePrice', function (XmlBuilder $x) use ($price): void {
                        $x->element('ram:ChargeAmount', Format::price($price->grossPrice));
                        if ($price->baseQuantity !== null) {
                            $x->element('ram:BasisQuantity', Format::quantity($price->baseQuantity), ['unitCode' => $price->baseQuantityUnit?->value]);
                        }
                        if ($price->discount !== null && ! $price->discount->isZero()) {
                            $x->group('ram:AppliedTradeAllowanceCharge', function (XmlBuilder $x) use ($price): void {
                                $x->group('ram:ChargeIndicator', fn (XmlBuilder $x) => $x->element('udt:Indicator', 'false'));
                                $x->element('ram:ActualAmount', Format::price($price->discount));
                            });
                        }
                    });
                }
                $x->required('ram:NetPriceProductTradePrice', function (XmlBuilder $x) use ($price): void {
                    $x->element('ram:ChargeAmount', Format::price($price->netPrice ?? throw new CalculationException('Missing net price.')));
                    if ($price->baseQuantity !== null) {
                        $x->element('ram:BasisQuantity', Format::quantity($price->baseQuantity), ['unitCode' => $price->baseQuantityUnit?->value]);
                    }
                });
            });

            $x->required('ram:SpecifiedLineTradeDelivery', function (XmlBuilder $x) use ($line, $extended): void {
                $x->element('ram:BilledQuantity', Format::quantity($line->quantity), ['unitCode' => $line->unit->value]);
                if ($extended) {
                    if ($line->extended?->shipTo !== null) {
                        $x->group('ram:ShipToTradeParty', fn (XmlBuilder $x) => $this->extendedParty($x, $line->extended->shipTo));
                    }
                    if ($line->extended?->deliveryDate !== null) {
                        $x->group('ram:ActualDeliverySupplyChainEvent', fn (XmlBuilder $x) => $x->required('ram:OccurrenceDateTime', fn (XmlBuilder $x) => $this->dateTime($x, $line->extended->deliveryDate)));
                    }
                    foreach ($this->lineReferences($line, DocumentReference::TYPE_DESPATCH_ADVICE) as $reference) {
                        $x->group('ram:DespatchAdviceReferencedDocument', function (XmlBuilder $x) use ($reference): void {
                            $x->element('ram:IssuerAssignedID', $reference->id);
                            $x->element('ram:LineID', $reference->lineId);
                        });
                    }
                    foreach ($this->lineReferences($line, DocumentReference::TYPE_RECEIVING_ADVICE) as $reference) {
                        $x->group('ram:ReceivingAdviceReferencedDocument', function (XmlBuilder $x) use ($reference): void {
                            $x->element('ram:IssuerAssignedID', $reference->id);
                            $x->element('ram:LineID', $reference->lineId);
                        });
                    }
                }
            });

            $x->required('ram:SpecifiedLineTradeSettlement', function (XmlBuilder $x) use ($line, $extended): void {
                $x->required('ram:ApplicableTradeTax', fn (XmlBuilder $x) => $this->categoryTax($x, $line->vat->category, $line->vat->rate));
                if ($line->period !== null) {
                    $this->period($x, 'ram:BillingSpecifiedPeriod', $line->period);
                }
                foreach ([...$line->charges, ...$line->allowances] as $entry) {
                    $this->lineAllowanceCharge($x, $entry);
                }
                $x->required('ram:SpecifiedTradeSettlementLineMonetarySummation', fn (XmlBuilder $x) => $x->element('ram:LineTotalAmount', $this->amount($line->netAmount)));
                if ($this->level->includes(ProfileLevel::En16931)) {
                    if ($line->objectId !== null) {
                        $x->group('ram:AdditionalReferencedDocument', function (XmlBuilder $x) use ($line): void {
                            $x->element('ram:IssuerAssignedID', $line->objectId?->value);
                            $x->element('ram:TypeCode', DocumentReferenceTypeCode::InvoicedObject->value);
                            $x->element('ram:ReferenceTypeCode', $line->objectId?->schemeValue());
                        });
                    }
                    if ($extended) {
                        foreach ($this->lineReferences($line, DocumentReference::TYPE_OTHER) as $reference) {
                            $x->group('ram:AdditionalReferencedDocument', function (XmlBuilder $x) use ($reference): void {
                                $x->element('ram:IssuerAssignedID', $reference->id);
                                $x->element('ram:LineID', $reference->lineId);
                                $x->element('ram:TypeCode', $reference->typeCode->value ?? DocumentReferenceTypeCode::AdditionalDocument->value);
                                $x->element('ram:Name', $reference->description);
                            });
                        }
                    }
                    $x->group('ram:ReceivableSpecifiedTradeAccountingAccount', fn (XmlBuilder $x) => $x->element('ram:ID', $line->buyerAccountingReference));
                }
            });
        });
    }

    private function agreement(XmlBuilder $x): void
    {
        $invoice = $this->invoice;
        $en16931 = $this->level->includes(ProfileLevel::En16931);
        $extended = $this->level === ProfileLevel::Extended;

        $x->element('ram:BuyerReference', $invoice->buyerReference);

        $x->required('ram:SellerTradeParty', function (XmlBuilder $x) use ($invoice, $en16931): void {
            $seller = $invoice->seller;
            if ($this->level->includes(ProfileLevel::BasicWl)) {
                $this->partyIdentifiers($x, $seller->identifiers);
            }
            $x->element('ram:Name', $seller->name);
            if ($en16931) {
                $x->element('ram:Description', $seller->additionalLegalInfo);
            }
            $x->group('ram:SpecifiedLegalOrganization', function (XmlBuilder $x) use ($seller): void {
                $this->identifier($x, 'ram:ID', $seller->legalRegistration);
                if ($this->level->includes(ProfileLevel::BasicWl)) {
                    $x->element('ram:TradingBusinessName', $seller->tradingName);
                }
            });
            if ($en16931 && $seller->contact !== null) {
                $this->contact($x, $seller->contact);
            }
            // MINIMUM contains only the seller country code (BT-40) of the postal address.
            $this->address($x, $seller->address, countryOnly: ! $this->level->includes(ProfileLevel::BasicWl));
            $this->electronicAddress($x, $seller->electronicAddress);
            $this->taxRegistration($x, $seller->vatId, 'VA');
            $this->taxRegistration($x, $seller->taxNumber, 'FC');
        });

        $x->required('ram:BuyerTradeParty', function (XmlBuilder $x) use ($invoice, $en16931): void {
            $buyer = $invoice->buyer;
            if ($this->level->includes(ProfileLevel::BasicWl) && $buyer->identifier !== null) {
                $this->partyIdentifier($x, $buyer->identifier);
            }
            $x->element('ram:Name', $buyer->name);
            $x->group('ram:SpecifiedLegalOrganization', function (XmlBuilder $x) use ($buyer, $en16931): void {
                $this->identifier($x, 'ram:ID', $buyer->legalRegistration);
                if ($en16931) {
                    $x->element('ram:TradingBusinessName', $buyer->tradingName);
                }
            });
            if ($en16931 && $buyer->contact !== null) {
                $this->contact($x, $buyer->contact);
            }
            if ($this->level->includes(ProfileLevel::BasicWl)) {
                $this->address($x, $buyer->address);
                $this->electronicAddress($x, $buyer->electronicAddress);
                $this->taxRegistration($x, $buyer->vatId, 'VA');
            }
        });

        if ($this->level->includes(ProfileLevel::BasicWl) && $invoice->sellerTaxRepresentative !== null) {
            $representative = $invoice->sellerTaxRepresentative;
            $x->group('ram:SellerTaxRepresentativeTradeParty', function (XmlBuilder $x) use ($representative): void {
                $x->element('ram:Name', $representative->name);
                $this->address($x, $representative->address);
                $this->taxRegistration($x, $representative->vatId, 'VA');
            });
        }

        if ($extended && $invoice->extended?->ultimateCustomer !== null) {
            $x->group('ram:ProductEndUserTradeParty', fn (XmlBuilder $x) => $this->extendedParty($x, $invoice->extended->ultimateCustomer));
        }
        if ($extended && $invoice->extended?->deliveryTermsCode !== null) {
            $x->group('ram:ApplicableTradeDeliveryTerms', fn (XmlBuilder $x) => $x->element('ram:DeliveryTypeCode', $invoice->extended->deliveryTermsCode));
        }

        if ($en16931) {
            $x->group('ram:SellerOrderReferencedDocument', fn (XmlBuilder $x) => $x->element('ram:IssuerAssignedID', $invoice->salesOrderReference));
        }
        $x->group('ram:BuyerOrderReferencedDocument', fn (XmlBuilder $x) => $x->element('ram:IssuerAssignedID', $invoice->purchaseOrderReference));
        if ($this->level->includes(ProfileLevel::BasicWl)) {
            $x->group('ram:ContractReferencedDocument', fn (XmlBuilder $x) => $x->element('ram:IssuerAssignedID', $invoice->contractReference));
        }

        if ($en16931) {
            foreach ($invoice->attachments as $attachment) {
                $x->group('ram:AdditionalReferencedDocument', function (XmlBuilder $x) use ($attachment): void {
                    $x->element('ram:IssuerAssignedID', $attachment->id);
                    $x->element('ram:URIID', $attachment->externalUri);
                    $x->element('ram:TypeCode', DocumentReferenceTypeCode::AdditionalDocument->value);
                    $x->element('ram:Name', $attachment->description);
                    if ($attachment->content !== null) {
                        $x->element('ram:AttachmentBinaryObject', base64_encode($attachment->content), [
                            'mimeCode' => $attachment->mimeType?->value,
                            'filename' => $attachment->filename,
                        ]);
                    }
                });
            }
            $x->group('ram:AdditionalReferencedDocument', function (XmlBuilder $x) use ($invoice): void {
                $x->element('ram:IssuerAssignedID', $invoice->tenderReference);
                if ($invoice->tenderReference !== null) {
                    $x->element('ram:TypeCode', DocumentReferenceTypeCode::CallForTender->value);
                }
            });
            $x->group('ram:AdditionalReferencedDocument', function (XmlBuilder $x) use ($invoice): void {
                $x->element('ram:IssuerAssignedID', $invoice->invoicedObject?->value);
                if ($invoice->invoicedObject !== null) {
                    $x->element('ram:TypeCode', DocumentReferenceTypeCode::InvoicedObject->value);
                    $x->element('ram:ReferenceTypeCode', $invoice->invoicedObject->schemeValue());
                }
            });
        }
        if ($extended) {
            foreach ($invoice->extended->additionalReferences ?? [] as $reference) {
                $x->group('ram:AdditionalReferencedDocument', function (XmlBuilder $x) use ($reference): void {
                    $x->element('ram:IssuerAssignedID', $reference->id);
                    $x->element('ram:TypeCode', $reference->typeCode->value ?? DocumentReferenceTypeCode::AdditionalDocument->value);
                    $x->element('ram:Name', $reference->description);
                    $issueDate = $reference->issueDate;
                    if ($issueDate !== null) {
                        $x->group('ram:FormattedIssueDateTime', fn (XmlBuilder $x) => $x->element('qdt:DateTimeString', $issueDate->toFormat102(), ['format' => '102']));
                    }
                });
            }
        }

        if ($en16931 && $invoice->projectReference !== null) {
            $project = $invoice->projectReference;
            $x->group('ram:SpecifiedProcuringProject', function (XmlBuilder $x) use ($project): void {
                $x->element('ram:ID', $project->id);
                $x->element('ram:Name', $project->name ?? $project->id);
            });
        }
    }

    private function delivery(XmlBuilder $x): void
    {
        $invoice = $this->invoice;
        if (! $this->level->includes(ProfileLevel::BasicWl)) {
            return;
        }
        $delivery = $invoice->delivery;
        $extended = $this->level === ProfileLevel::Extended;

        if ($delivery !== null) {
            $x->group('ram:ShipToTradeParty', function (XmlBuilder $x) use ($delivery): void {
                if ($delivery->locationId !== null) {
                    $this->partyIdentifier($x, $delivery->locationId);
                }
                $x->element('ram:Name', $delivery->recipientName);
                if ($delivery->address !== null) {
                    $this->address($x, $delivery->address);
                }
            });
        }
        if ($extended && $invoice->extended?->shipFrom !== null) {
            $x->group('ram:ShipFromTradeParty', fn (XmlBuilder $x) => $this->extendedParty($x, $invoice->extended->shipFrom));
        }
        if ($delivery?->actualDeliveryDate !== null) {
            $x->group('ram:ActualDeliverySupplyChainEvent', fn (XmlBuilder $x) => $x->required('ram:OccurrenceDateTime', fn (XmlBuilder $x) => $this->dateTime($x, $delivery->actualDeliveryDate)));
        }
        $x->group('ram:DespatchAdviceReferencedDocument', fn (XmlBuilder $x) => $x->element('ram:IssuerAssignedID', $invoice->despatchAdviceReference));
        if ($this->level->includes(ProfileLevel::En16931)) {
            $x->group('ram:ReceivingAdviceReferencedDocument', fn (XmlBuilder $x) => $x->element('ram:IssuerAssignedID', $invoice->receivingAdviceReference));
        }
    }

    private function settlement(XmlBuilder $x): void
    {
        $invoice = $this->invoice;
        $totals = $invoice->totals ?? throw new CalculationException('Missing totals.');
        $basicWl = $this->level->includes(ProfileLevel::BasicWl);
        $extended = $this->level === ProfileLevel::Extended;
        $instructions = $invoice->paymentInstructions;

        if ($basicWl) {
            $x->element('ram:CreditorReferenceID', $instructions?->directDebit?->creditorId);
            $x->element('ram:PaymentReference', $instructions?->remittanceInformation);
            // BT-6 only if it differs from BT-5 (BR-53 in the CII Schematron requires that).
            $x->element('ram:TaxCurrencyCode', $invoice->taxCurrency === $invoice->currency ? null : $invoice->taxCurrency);
        }
        $x->element('ram:InvoiceCurrencyCode', $invoice->currency);

        if ($extended && $invoice->extended?->invoicer !== null) {
            $x->group('ram:InvoicerTradeParty', fn (XmlBuilder $x) => $this->extendedParty($x, $invoice->extended->invoicer));
        }
        if ($extended && $invoice->extended?->invoicee !== null) {
            $x->group('ram:InvoiceeTradeParty', fn (XmlBuilder $x) => $this->extendedParty($x, $invoice->extended->invoicee));
        }

        if ($basicWl && $invoice->payee !== null) {
            $payee = $invoice->payee;
            $x->group('ram:PayeeTradeParty', function (XmlBuilder $x) use ($payee): void {
                if ($payee->identifier !== null) {
                    $this->partyIdentifier($x, $payee->identifier);
                }
                $x->element('ram:Name', $payee->name);
                $x->group('ram:SpecifiedLegalOrganization', fn (XmlBuilder $x) => $this->identifier($x, 'ram:ID', $payee->legalRegistration));
            });
        }

        // EXTENDED can state the exchange rate behind BT-111: invoice currency × rate = tax currency.
        $rate = $invoice->meta->exchangeRate;
        if ($extended && $rate !== null && $invoice->taxCurrency !== null && $invoice->taxCurrency !== $invoice->currency) {
            $rateDate = $invoice->meta->exchangeRateDate;
            $x->group('ram:TaxApplicableTradeCurrencyExchange', function (XmlBuilder $x) use ($invoice, $rate, $rateDate): void {
                $x->element('ram:SourceCurrencyCode', $invoice->currency);
                $x->element('ram:TargetCurrencyCode', $invoice->taxCurrency);
                $x->element('ram:ConversionRate', $rate->toString());
                if ($rateDate !== null) {
                    $x->required('ram:ConversionRateDateTime', fn (XmlBuilder $x) => $this->dateTime($x, $rateDate));
                }
            });
        }

        if ($basicWl && $instructions !== null) {
            foreach ([$instructions, ...($extended ? $invoice->extended->additionalPaymentInstructions ?? [] : [])] as $entry) {
                $this->paymentMeans($x, $entry);
            }
        }

        if ($basicWl) {
            foreach ($invoice->vatBreakdown as $index => $breakdown) {
                $x->group('ram:ApplicableTradeTax', function (XmlBuilder $x) use ($breakdown, $index, $invoice): void {
                    $x->element('ram:CalculatedAmount', Format::amount($breakdown->taxAmount));
                    $x->element('ram:TypeCode', 'VAT');
                    $x->element('ram:ExemptionReason', $breakdown->exemptionReason);
                    $x->element('ram:BasisAmount', Format::amount($breakdown->taxableAmount));
                    $x->element('ram:CategoryCode', $breakdown->category->value);
                    $x->element('ram:ExemptionReasonCode', $breakdown->exemptionReasonCode?->value);
                    $taxPointDate = $invoice->taxPointDate;
                    if ($index === 0 && $taxPointDate !== null) {
                        $x->group('ram:TaxPointDate', fn (XmlBuilder $x) => $x->element('udt:DateString', $taxPointDate->toFormat102(), ['format' => '102']));
                    }
                    $code = $invoice->taxPointDateCode;
                    $x->element('ram:DueDateTypeCode', $code instanceof TaxPointDateCode ? $code->ciiCode() : $code?->value);
                    $rate = $breakdown->rate ?? ($this->profile instanceof RequiresVatBreakdownRate ? BigDecimal::zero() : null);
                    if ($rate !== null) {
                        $x->element('ram:RateApplicablePercent', Format::percent($rate));
                    }
                });
            }

            if ($invoice->invoicingPeriod !== null) {
                $this->period($x, 'ram:BillingSpecifiedPeriod', $invoice->invoicingPeriod);
            }

            foreach ([...$invoice->charges, ...$invoice->allowances] as $entry) {
                $this->documentAllowanceCharge($x, $entry);
            }

            $this->paymentTerms($x);
        }

        $x->required('ram:SpecifiedTradeSettlementHeaderMonetarySummation', function (XmlBuilder $x) use ($totals, $basicWl, $invoice): void {
            if ($basicWl) {
                $x->element('ram:LineTotalAmount', Format::amount($totals->lineNetSum));
                $x->element('ram:ChargeTotalAmount', $this->amount($totals->chargeSum));
                $x->element('ram:AllowanceTotalAmount', $this->amount($totals->allowanceSum));
            }
            $x->element('ram:TaxBasisTotalAmount', Format::amount($totals->netTotal));
            $x->element('ram:TaxTotalAmount', $this->amount($totals->vatTotal), ['currencyID' => $invoice->currency]);
            if ($basicWl && $totals->vatTotalInTaxCurrency !== null) {
                $x->element('ram:TaxTotalAmount', Format::amount($totals->vatTotalInTaxCurrency), ['currencyID' => $invoice->taxCurrency]);
            }
            if ($basicWl) {
                $x->element('ram:RoundingAmount', $this->amount($totals->roundingAmount));
            }
            $x->element('ram:GrandTotalAmount', Format::amount($totals->grossTotal));
            if ($basicWl) {
                $x->element('ram:TotalPrepaidAmount', $this->amount($totals->prepaidAmount));
            }
            $x->element('ram:DuePayableAmount', Format::amount($totals->amountDue));
        });

        if ($basicWl && $invoice->precedingInvoices !== []) {
            // The CII binding allows a single preceding invoice reference on header level.
            $preceding = $invoice->precedingInvoices[0];
            $x->group('ram:InvoiceReferencedDocument', function (XmlBuilder $x) use ($preceding): void {
                $x->element('ram:IssuerAssignedID', $preceding->number);
                $issueDate = $preceding->issueDate;
                if ($issueDate !== null) {
                    $x->group('ram:FormattedIssueDateTime', fn (XmlBuilder $x) => $x->element('qdt:DateTimeString', $issueDate->toFormat102(), ['format' => '102']));
                }
            });
        }

        if ($basicWl) {
            $x->group('ram:ReceivableSpecifiedTradeAccountingAccount', fn (XmlBuilder $x) => $x->element('ram:ID', $invoice->buyerAccountingReference));
        }
    }

    private function paymentMeans(XmlBuilder $x, PaymentInstructions $instructions): void
    {
        $en16931 = $this->level->includes(ProfileLevel::En16931);
        $transfers = $instructions->creditTransfers === [] ? [null] : $instructions->creditTransfers;

        foreach ($transfers as $index => $transfer) {
            $x->group('ram:SpecifiedTradeSettlementPaymentMeans', function (XmlBuilder $x) use ($instructions, $transfer, $index, $en16931): void {
                $x->element('ram:TypeCode', (string) $instructions->meansCode->value);
                if ($en16931) {
                    $x->element('ram:Information', $instructions->meansText);
                }
                if ($index === 0 && $en16931 && $instructions->card !== null) {
                    $card = $instructions->card;
                    $x->group('ram:ApplicableTradeSettlementFinancialCard', function (XmlBuilder $x) use ($card): void {
                        $x->element('ram:ID', $card->primaryAccountNumber);
                        $x->element('ram:CardholderName', $card->holderName);
                    });
                }
                if ($index === 0) {
                    $x->group('ram:PayerPartyDebtorFinancialAccount', fn (XmlBuilder $x) => $x->element('ram:IBANID', $instructions->directDebit?->debitedAccountId));
                }
                if ($transfer !== null) {
                    $x->group('ram:PayeePartyCreditorFinancialAccount', function (XmlBuilder $x) use ($transfer, $en16931): void {
                        if ($transfer->isIban()) {
                            $x->element('ram:IBANID', $transfer->accountId);
                        }
                        if ($en16931) {
                            $x->element('ram:AccountName', $transfer->accountName);
                        }
                        if (! $transfer->isIban()) {
                            $x->element('ram:ProprietaryID', $transfer->accountId);
                        }
                    });
                    if ($en16931) {
                        $x->group('ram:PayeeSpecifiedCreditorFinancialInstitution', fn (XmlBuilder $x) => $x->element('ram:BICID', $transfer->serviceProviderId));
                    }
                }
            });
        }
    }

    private function paymentTerms(XmlBuilder $x): void
    {
        $invoice = $this->invoice;
        $extended = $this->level === ProfileLevel::Extended;
        $terms = $invoice->paymentTerms;
        $text = PaymentTermsText::render($invoice, $this->profile, $this->options->locale);
        $installments = $extended ? ($invoice->extended->installments ?? []) : [];

        if ($installments !== []) {
            foreach ($installments as $index => $installment) {
                $x->group('ram:SpecifiedTradePaymentTerms', function (XmlBuilder $x) use ($installment, $index, $text, $invoice): void {
                    $x->element('ram:Description', $installment->description ?? ($index === 0 ? $text : null));
                    $x->group('ram:DueDateDateTime', fn (XmlBuilder $x) => $this->dateTime($x, $installment->dueDate));
                    if ($index === 0) {
                        $x->element('ram:DirectDebitMandateID', $invoice->paymentInstructions?->directDebit?->mandateReference);
                    }
                    $x->element('ram:PartialPaymentAmount', $this->amount($installment->amount));
                });
            }

            return;
        }

        $x->group('ram:SpecifiedTradePaymentTerms', function (XmlBuilder $x) use ($invoice, $text, $terms, $extended): void {
            $x->element('ram:Description', $text);
            if ($invoice->dueDate !== null) {
                $x->required('ram:DueDateDateTime', fn (XmlBuilder $x) => $this->dateTime($x, $invoice->dueDate));
            }
            $x->element('ram:DirectDebitMandateID', $invoice->paymentInstructions?->directDebit?->mandateReference);
            if ($extended && $terms !== null) {
                $penalty = $terms->lateFeeTerms;
                if ($penalty !== null && ($penalty->days !== null || $penalty->percent !== null)) {
                    $x->group('ram:ApplicableTradePaymentPenaltyTerms', function (XmlBuilder $x) use ($penalty): void {
                        if ($penalty->days !== null) {
                            $x->element('ram:BasisPeriodMeasure', (string) $penalty->days, ['unitCode' => 'DAY']);
                        }
                        if ($penalty->percent !== null) {
                            $x->element('ram:CalculationPercent', Format::percent($penalty->percent));
                        }
                    });
                }
                $discount = $terms->discounts[0] ?? null;
                if ($discount !== null) {
                    $this->discountTerms($x, $discount);
                }
            }
        });

        // A payment terms group holds one cash discount; EXTENDED repeats the group for further
        // tiers (BT-20 and BT-9 stay in the first group).
        if ($extended && $terms !== null) {
            foreach (array_slice($terms->discounts, 1) as $discount) {
                $x->group('ram:SpecifiedTradePaymentTerms', fn (XmlBuilder $x) => $this->discountTerms($x, $discount));
            }
        }
    }

    private function discountTerms(XmlBuilder $x, CashDiscount $discount): void
    {
        $x->group('ram:ApplicableTradePaymentDiscountTerms', function (XmlBuilder $x) use ($discount): void {
            $x->element('ram:BasisPeriodMeasure', (string) $discount->days, ['unitCode' => 'DAY']);
            $x->element('ram:BasisAmount', $this->amount($discount->baseAmount));
            $x->element('ram:CalculationPercent', Format::percent($discount->percent));
        });
    }

    private function documentAllowanceCharge(XmlBuilder $x, DocumentAllowanceCharge $entry): void
    {
        $x->group('ram:SpecifiedTradeAllowanceCharge', function (XmlBuilder $x) use ($entry): void {
            $x->required('ram:ChargeIndicator', fn (XmlBuilder $x) => $x->element('udt:Indicator', $entry->isCharge() ? 'true' : 'false'));
            if ($entry->percent !== null) {
                $x->element('ram:CalculationPercent', Format::percent($entry->percent));
            }
            $x->element('ram:BasisAmount', $this->amount($entry->baseAmount));
            $x->element('ram:ActualAmount', $this->amount($entry->amount));
            $x->element('ram:ReasonCode', $entry->reasonCodeValue());
            $x->element('ram:Reason', $entry->reason);
            $x->required('ram:CategoryTradeTax', fn (XmlBuilder $x) => $this->categoryTax($x, $entry->vatCategory, $entry->vatRate));
        });
    }

    /**
     * VAT category of a line (BG-30) or a document allowance or charge (BT-95, BT-102).
     *
     * EXTENDED repeats the exemption reason and code of the VAT breakdown (BT-120, BT-121) for
     * the categories that require one: the Factur-X rules BR-FXEXT-{E,AE,K,G,O}-08rev only
     * count lines and allowances or charges whose reason and code match the breakdown.
     * EN 16931 and the profiles below have no such elements on these levels.
     */
    private function categoryTax(XmlBuilder $x, VatCategory $category, ?BigDecimal $rate): void
    {
        [$reason, $code] = $this->level === ProfileLevel::Extended && $category->requiresExemptionReason()
            ? $this->breakdownExemption($category, $rate)
            : [null, null];

        $x->element('ram:TypeCode', 'VAT');
        $x->element('ram:ExemptionReason', $reason);
        $x->element('ram:CategoryCode', $category->value);
        $x->element('ram:ExemptionReasonCode', $code);
        if ($rate !== null && $category->requiresRate()) {
            $x->element('ram:RateApplicablePercent', Format::percent($rate));
        }
    }

    /**
     * Exemption reason and code of the VAT breakdown row (one per category and rate) that
     * contains the given category and rate.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function breakdownExemption(VatCategory $category, ?BigDecimal $rate): array
    {
        // The breakdown carries a rate for every category except O, zero when the input had none.
        $key = LineVat::key($category, $category->requiresRate() ? ($rate ?? BigDecimal::zero()) : null);
        foreach ($this->invoice->vatBreakdown as $breakdown) {
            if (LineVat::key($breakdown->category, $breakdown->rate) === $key) {
                $code = $breakdown->exemptionReasonCode;

                return [$breakdown->exemptionReason, $code === null ? null : (string) $code->value];
            }
        }

        return [null, null];
    }

    private function lineAllowanceCharge(XmlBuilder $x, LineAllowanceCharge $entry): void
    {
        $x->group('ram:SpecifiedTradeAllowanceCharge', function (XmlBuilder $x) use ($entry): void {
            $x->required('ram:ChargeIndicator', fn (XmlBuilder $x) => $x->element('udt:Indicator', $entry->isCharge() ? 'true' : 'false'));
            if ($entry->percent !== null) {
                $x->element('ram:CalculationPercent', Format::percent($entry->percent));
            }
            $x->element('ram:BasisAmount', $this->amount($entry->baseAmount));
            $x->element('ram:ActualAmount', $this->amount($entry->amount));
            $x->element('ram:ReasonCode', $entry->reasonCodeValue());
            $x->element('ram:Reason', $entry->reason);
        });
    }

    private function contact(XmlBuilder $x, Contact $contact): void
    {
        $x->group('ram:DefinedTradeContact', function (XmlBuilder $x) use ($contact): void {
            $x->element('ram:PersonName', $contact->name);
            $x->group('ram:TelephoneUniversalCommunication', fn (XmlBuilder $x) => $x->element('ram:CompleteNumber', $contact->phone));
            $x->group('ram:EmailURIUniversalCommunication', fn (XmlBuilder $x) => $x->element('ram:URIID', $contact->email));
        });
    }

    private function address(XmlBuilder $x, Address $address, bool $countryOnly = false): void
    {
        if ($countryOnly) {
            $x->group('ram:PostalTradeAddress', fn (XmlBuilder $x) => $x->element('ram:CountryID', $address->countryCode));

            return;
        }

        $x->group('ram:PostalTradeAddress', function (XmlBuilder $x) use ($address): void {
            $x->element('ram:PostcodeCode', $address->postcode);
            $x->element('ram:LineOne', $address->line1);
            $x->element('ram:LineTwo', $address->line2);
            $x->element('ram:LineThree', $address->line3);
            $x->element('ram:CityName', $address->city);
            $x->element('ram:CountryID', $address->countryCode);
            $x->element('ram:CountrySubDivisionName', $address->subdivision);
        });
    }

    private function electronicAddress(XmlBuilder $x, ?ElectronicAddress $address): void
    {
        if ($address === null || ! $this->level->includes(ProfileLevel::BasicWl)) {
            return;
        }

        $x->group('ram:URIUniversalCommunication', fn (XmlBuilder $x) => $x->element('ram:URIID', $address->value, ['schemeID' => $address->scheme->value]));
    }

    private function taxRegistration(XmlBuilder $x, ?string $id, string $scheme): void
    {
        $x->group('ram:SpecifiedTaxRegistration', fn (XmlBuilder $x) => $x->element('ram:ID', $id, ['schemeID' => $scheme]));
    }

    /**
     * Party identifiers without scheme go into ram:ID, with scheme into ram:GlobalID.
     * The XSD requires all ram:ID elements before the ram:GlobalID elements.
     *
     * @param  list<Identifier>  $identifiers
     */
    private function partyIdentifiers(XmlBuilder $x, array $identifiers): void
    {
        foreach ($identifiers as $identifier) {
            if ($identifier->scheme === null) {
                $x->element('ram:ID', $identifier->value);
            }
        }
        foreach ($identifiers as $identifier) {
            if ($identifier->scheme !== null) {
                $x->element('ram:GlobalID', $identifier->value, ['schemeID' => $identifier->schemeValue()]);
            }
        }
    }

    private function partyIdentifier(XmlBuilder $x, Identifier $identifier): void
    {
        $this->partyIdentifiers($x, [$identifier]);
    }

    private function identifier(XmlBuilder $x, string $element, ?Identifier $identifier, bool $requireScheme = false): void
    {
        if ($identifier === null || ($requireScheme && $identifier->scheme === null)) {
            return;
        }

        $x->element($element, $identifier->value, ['schemeID' => $identifier->schemeValue()]);
    }

    private function extendedParty(XmlBuilder $x, ExtendedParty $party): void
    {
        $this->partyIdentifiers($x, $party->identifiers);
        $x->element('ram:Name', $party->name);
        if ($party->contact !== null) {
            $this->contact($x, $party->contact);
        }
        if ($party->address !== null) {
            $this->address($x, $party->address);
        }
        $this->taxRegistration($x, $party->vatId, 'VA');
    }

    private function period(XmlBuilder $x, string $element, Period $period): void
    {
        $x->group($element, function (XmlBuilder $x) use ($period): void {
            if ($period->start !== null) {
                $x->required('ram:StartDateTime', fn (XmlBuilder $x) => $this->dateTime($x, $period->start));
            }
            if ($period->end !== null) {
                $x->required('ram:EndDateTime', fn (XmlBuilder $x) => $this->dateTime($x, $period->end));
            }
        });
    }

    private function dateTime(XmlBuilder $x, ?Date $date): void
    {
        $x->element('udt:DateTimeString', $date?->toFormat102(), ['format' => '102']);
    }

    private function amount(?Money $money): ?string
    {
        return $money === null ? null : Format::amount($money);
    }

    /**
     * @return list<DocumentReference>
     */
    private function lineReferences(InvoiceLine $line, string $kind): array
    {
        return array_values(array_filter($line->lineReferences, static fn (DocumentReference $r): bool => $r->kind === $kind));
    }

    /**
     * @param  class-string  $class
     */
    private function allowed(string $class, string $property): bool
    {
        $field = FieldMatrix::fields($class)[$property] ?? null;

        return $field === null || $this->level->includes($field->level);
    }
}
