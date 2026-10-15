# Field reference

Generated from the mapping attributes of the data model (`php tools/docs/generate.php`). Do not edit by hand.

Paths use the property names of `InvoiceData`; `[]` marks lists. "Level" is the lowest Factur-X/ZUGFeRD profile that carries the field at this path (a part is never written without its parent, e.g. the buyer address starts with BASIC WL).

| Path | BT | Card. | Level | CII | UBL | Notes |
|---|---|---|---|---|---|---|
| `number` | BT-1 | 1 | MINIMUM | `rsm:ExchangedDocument/ram:ID` | `cbc:ID` |  |
| `issueDate` | BT-2 | 1 | MINIMUM | `rsm:ExchangedDocument/ram:IssueDateTime` | `cbc:IssueDate` |  |
| `typeCode` | BT-3 | 1 | MINIMUM | `rsm:ExchangedDocument/ram:TypeCode` | `cbc:InvoiceTypeCode \| cbc:CreditNoteTypeCode` |  |
| `currency` | BT-5 | 1 | MINIMUM | `ram:ApplicableHeaderTradeSettlement/ram:InvoiceCurrencyCode` | `cbc:DocumentCurrencyCode` |  |
| `seller` | BG-4 | 1 | MINIMUM | `ram:ApplicableHeaderTradeAgreement/ram:SellerTradeParty` | `cac:AccountingSupplierParty` |  |
| `seller.name` | BT-27 | 1 | MINIMUM | `ram:SellerTradeParty/ram:Name` | `cac:AccountingSupplierParty/cac:Party/cac:PartyLegalEntity/cbc:RegistrationName` |  |
| `seller.address` | BG-5 | 1 | MINIMUM | `ram:SellerTradeParty/ram:PostalTradeAddress` | `cac:AccountingSupplierParty/cac:Party/cac:PostalAddress` |  |
| `seller.address.countryCode` | BT-40 | 1 | MINIMUM | `ram:CountryID` | `cac:Country/cbc:IdentificationCode` |  |
| `seller.address.line1` | BT-35 | 0..1 | BASIC WL | `ram:LineOne` | `cbc:StreetName` |  |
| `seller.address.line2` | BT-36 | 0..1 | BASIC WL | `ram:LineTwo` | `cbc:AdditionalStreetName` |  |
| `seller.address.line3` | BT-162 | 0..1 | BASIC WL | `ram:LineThree` | `cac:AddressLine/cbc:Line` |  |
| `seller.address.city` | BT-37 | 0..1 | BASIC WL | `ram:CityName` | `cbc:CityName` |  |
| `seller.address.postcode` | BT-38 | 0..1 | BASIC WL | `ram:PostcodeCode` | `cbc:PostalZone` |  |
| `seller.address.subdivision` | BT-39 | 0..1 | BASIC WL | `ram:CountrySubDivisionName` | `cbc:CountrySubentity` |  |
| `seller.tradingName` | BT-28 | 0..1 | BASIC WL | `ram:SellerTradeParty/ram:SpecifiedLegalOrganization/ram:TradingBusinessName` | `cac:PartyName/cbc:Name` |  |
| `seller.identifiers[]` | BT-29 | 0..n | BASIC WL | `ram:SellerTradeParty/ram:ID \| ram:GlobalID` | `cac:PartyIdentification/cbc:ID` |  |
| `seller.identifiers[].value` |  |  |  |  |  |  |
| `seller.identifiers[].scheme` |  |  |  |  |  |  |
| `seller.legalRegistration` | BT-30 | 0..1 | MINIMUM | `ram:SellerTradeParty/ram:SpecifiedLegalOrganization/ram:ID` | `cac:PartyLegalEntity/cbc:CompanyID` |  |
| `seller.legalRegistration.value` |  |  |  |  |  |  |
| `seller.legalRegistration.scheme` |  |  |  |  |  |  |
| `seller.vatId` | BT-31 | 0..1 | MINIMUM | `ram:SpecifiedTaxRegistration/ram:ID[@schemeID="VA"]` | `cac:PartyTaxScheme[cac:TaxScheme/cbc:ID="VAT"]/cbc:CompanyID` |  |
| `seller.taxNumber` | BT-32 | 0..1 | MINIMUM | `ram:SpecifiedTaxRegistration/ram:ID[@schemeID="FC"]` | `cac:PartyTaxScheme[cac:TaxScheme/cbc:ID!="VAT"]/cbc:CompanyID` |  |
| `seller.additionalLegalInfo` | BT-33 | 0..1 | EN 16931 | `ram:SellerTradeParty/ram:Description` | `cac:PartyLegalEntity/cbc:CompanyLegalForm` |  |
| `seller.electronicAddress` | BT-34 | 0..1 | BASIC WL | `ram:SellerTradeParty/ram:URIUniversalCommunication/ram:URIID` | `cbc:EndpointID` | required in xrechnung, peppol |
| `seller.electronicAddress.value` |  |  |  |  |  |  |
| `seller.electronicAddress.scheme` |  |  |  |  |  |  |
| `seller.contact` | BG-6 | 0..1 | EN 16931 | `ram:SellerTradeParty/ram:DefinedTradeContact` | `cac:Contact` | required in xrechnung |
| `seller.contact.name` | BT-41 | 0..1 | EN 16931 | `ram:PersonName` | `cbc:Name` |  |
| `seller.contact.phone` | BT-42 | 0..1 | EN 16931 | `ram:TelephoneUniversalCommunication/ram:CompleteNumber` | `cbc:Telephone` |  |
| `seller.contact.email` | BT-43 | 0..1 | EN 16931 | `ram:EmailURIUniversalCommunication/ram:URIID` | `cbc:ElectronicMail` |  |
| `buyer` | BG-7 | 1 | MINIMUM | `ram:ApplicableHeaderTradeAgreement/ram:BuyerTradeParty` | `cac:AccountingCustomerParty` |  |
| `buyer.name` | BT-44 | 1 | MINIMUM | `ram:BuyerTradeParty/ram:Name` | `cac:AccountingCustomerParty/cac:Party/cac:PartyLegalEntity/cbc:RegistrationName` |  |
| `buyer.address` | BG-8 | 1 | BASIC WL | `ram:BuyerTradeParty/ram:PostalTradeAddress` | `cac:AccountingCustomerParty/cac:Party/cac:PostalAddress` |  |
| `buyer.address.countryCode` | BT-55 | 1 | BASIC WL | `ram:CountryID` | `cac:Country/cbc:IdentificationCode` |  |
| `buyer.address.line1` | BT-50 | 0..1 | BASIC WL | `ram:LineOne` | `cbc:StreetName` |  |
| `buyer.address.line2` | BT-51 | 0..1 | BASIC WL | `ram:LineTwo` | `cbc:AdditionalStreetName` |  |
| `buyer.address.line3` | BT-163 | 0..1 | BASIC WL | `ram:LineThree` | `cac:AddressLine/cbc:Line` |  |
| `buyer.address.city` | BT-52 | 0..1 | BASIC WL | `ram:CityName` | `cbc:CityName` |  |
| `buyer.address.postcode` | BT-53 | 0..1 | BASIC WL | `ram:PostcodeCode` | `cbc:PostalZone` |  |
| `buyer.address.subdivision` | BT-54 | 0..1 | BASIC WL | `ram:CountrySubDivisionName` | `cbc:CountrySubentity` |  |
| `buyer.tradingName` | BT-45 | 0..1 | EN 16931 | `ram:BuyerTradeParty/ram:SpecifiedLegalOrganization/ram:TradingBusinessName` | `cac:PartyName/cbc:Name` |  |
| `buyer.identifier` | BT-46 | 0..1 | BASIC WL | `ram:BuyerTradeParty/ram:ID \| ram:GlobalID` | `cac:PartyIdentification/cbc:ID` |  |
| `buyer.identifier.value` |  |  |  |  |  |  |
| `buyer.identifier.scheme` |  |  |  |  |  |  |
| `buyer.legalRegistration` | BT-47 | 0..1 | MINIMUM | `ram:BuyerTradeParty/ram:SpecifiedLegalOrganization/ram:ID` | `cac:PartyLegalEntity/cbc:CompanyID` |  |
| `buyer.legalRegistration.value` |  |  |  |  |  |  |
| `buyer.legalRegistration.scheme` |  |  |  |  |  |  |
| `buyer.vatId` | BT-48 | 0..1 | BASIC WL | `ram:BuyerTradeParty/ram:SpecifiedTaxRegistration/ram:ID[@schemeID="VA"]` | `cac:PartyTaxScheme/cbc:CompanyID` |  |
| `buyer.electronicAddress` | BT-49 | 0..1 | BASIC WL | `ram:BuyerTradeParty/ram:URIUniversalCommunication/ram:URIID` | `cbc:EndpointID` | required in xrechnung, peppol |
| `buyer.electronicAddress.value` |  |  |  |  |  |  |
| `buyer.electronicAddress.scheme` |  |  |  |  |  |  |
| `buyer.contact` | BG-9 | 0..1 | EN 16931 | `ram:BuyerTradeParty/ram:DefinedTradeContact` | `cac:Contact` |  |
| `buyer.contact.name` | BT-56 | 0..1 | EN 16931 | `ram:PersonName` | `cbc:Name` |  |
| `buyer.contact.phone` | BT-57 | 0..1 | EN 16931 | `ram:TelephoneUniversalCommunication/ram:CompleteNumber` | `cbc:Telephone` |  |
| `buyer.contact.email` | BT-58 | 0..1 | EN 16931 | `ram:EmailURIUniversalCommunication/ram:URIID` | `cbc:ElectronicMail` |  |
| `buyer.isConsumer` |  |  |  |  |  | internal |
| `lines[]` | BG-25 | 1..n | BASIC | `rsm:SupplyChainTradeTransaction/ram:IncludedSupplyChainTradeLineItem` | `cac:InvoiceLine \| cac:CreditNoteLine` |  |
| `lines[].id` | BT-126 | 1 | BASIC | `ram:AssociatedDocumentLineDocument/ram:LineID` | `cbc:ID` |  |
| `lines[].quantity` | BT-129 | 1 | BASIC | `ram:SpecifiedLineTradeDelivery/ram:BilledQuantity` | `cbc:InvoicedQuantity` |  |
| `lines[].unit` | BT-130 | 1 | BASIC | `ram:SpecifiedLineTradeDelivery/ram:BilledQuantity/@unitCode` | `cbc:InvoicedQuantity/@unitCode` |  |
| `lines[].price` | BG-29 | 1 | BASIC | `ram:SpecifiedLineTradeAgreement` | `cac:Price` |  |
| `lines[].price.netPrice` | BT-146 | 1 | BASIC | `ram:NetPriceProductTradePrice/ram:ChargeAmount` | `cac:Price/cbc:PriceAmount` |  |
| `lines[].price.discount` | BT-147 | 0..1 | BASIC | `ram:GrossPriceProductTradePrice/ram:AppliedTradeAllowanceCharge/ram:ActualAmount` | `cac:Price/cac:AllowanceCharge/cbc:Amount` |  |
| `lines[].price.grossPrice` | BT-148 | 0..1 | BASIC | `ram:GrossPriceProductTradePrice/ram:ChargeAmount` | `cac:Price/cac:AllowanceCharge/cbc:BaseAmount` |  |
| `lines[].price.baseQuantity` | BT-149 | 0..1 | BASIC | `ram:NetPriceProductTradePrice/ram:BasisQuantity` | `cac:Price/cbc:BaseQuantity` |  |
| `lines[].price.baseQuantityUnit` | BT-150 | 0..1 | BASIC | `ram:NetPriceProductTradePrice/ram:BasisQuantity/@unitCode` | `cac:Price/cbc:BaseQuantity/@unitCode` |  |
| `lines[].price.priceInclVat` |  |  |  |  |  | internal |
| `lines[].vat` | BG-30 | 1 | BASIC | `ram:SpecifiedLineTradeSettlement/ram:ApplicableTradeTax` | `cac:Item/cac:ClassifiedTaxCategory` |  |
| `lines[].vat.category` | BT-151 | 1 | BASIC | `ram:ApplicableTradeTax/ram:CategoryCode` | `cac:ClassifiedTaxCategory/cbc:ID` |  |
| `lines[].vat.rate` | BT-152 | 0..1 | BASIC | `ram:ApplicableTradeTax/ram:RateApplicablePercent` | `cac:ClassifiedTaxCategory/cbc:Percent` |  |
| `lines[].vat.exemptionReason` |  |  |  |  |  | internal |
| `lines[].vat.exemptionReasonCode` |  |  |  |  |  | internal |
| `lines[].vat.presetKey` |  |  |  |  |  | internal |
| `lines[].item` | BG-31 | 1 | BASIC | `ram:SpecifiedTradeProduct` | `cac:Item` |  |
| `lines[].item.name` | BT-153 | 1 | BASIC | `ram:SpecifiedTradeProduct/ram:Name` | `cac:Item/cbc:Name` |  |
| `lines[].item.description` | BT-154 | 0..1 | EN 16931 | `ram:SpecifiedTradeProduct/ram:Description` | `cac:Item/cbc:Description` |  |
| `lines[].item.sellerId` | BT-155 | 0..1 | EN 16931 | `ram:SpecifiedTradeProduct/ram:SellerAssignedID` | `cac:Item/cac:SellersItemIdentification/cbc:ID` |  |
| `lines[].item.buyerId` | BT-156 | 0..1 | EN 16931 | `ram:SpecifiedTradeProduct/ram:BuyerAssignedID` | `cac:Item/cac:BuyersItemIdentification/cbc:ID` |  |
| `lines[].item.standardId` | BT-157 | 0..1 | BASIC | `ram:SpecifiedTradeProduct/ram:GlobalID` | `cac:Item/cac:StandardItemIdentification/cbc:ID` |  |
| `lines[].item.standardId.value` |  |  |  |  |  |  |
| `lines[].item.standardId.scheme` |  |  |  |  |  |  |
| `lines[].item.classifications[]` | BT-158 | 0..n | EN 16931 | `ram:SpecifiedTradeProduct/ram:DesignatedProductClassification` | `cac:Item/cac:CommodityClassification` |  |
| `lines[].item.classifications[].code` |  |  |  |  |  |  |
| `lines[].item.classifications[].listId` |  |  |  |  |  |  |
| `lines[].item.classifications[].listVersion` |  |  |  |  |  |  |
| `lines[].item.originCountry` | BT-159 | 0..1 | EN 16931 | `ram:SpecifiedTradeProduct/ram:OriginTradeCountry/ram:ID` | `cac:Item/cac:OriginCountry/cbc:IdentificationCode` |  |
| `lines[].item.attributes[]` | BG-32 | 0..n | EN 16931 | `ram:SpecifiedTradeProduct/ram:ApplicableProductCharacteristic` | `cac:Item/cac:AdditionalItemProperty` |  |
| `lines[].item.attributes[].name` | BT-160 | 1 | EN 16931 | `ram:ApplicableProductCharacteristic/ram:Description` | `cac:AdditionalItemProperty/cbc:Name` |  |
| `lines[].item.attributes[].value` | BT-161 | 1 | EN 16931 | `ram:ApplicableProductCharacteristic/ram:Value` | `cac:AdditionalItemProperty/cbc:Value` |  |
| `lines[].note` | BT-127 | 0..1 | BASIC | `ram:AssociatedDocumentLineDocument/ram:IncludedNote/ram:Content` | `cbc:Note` |  |
| `lines[].objectId` | BT-128 | 0..1 | EN 16931 | `ram:SpecifiedLineTradeSettlement/ram:AdditionalReferencedDocument[ram:TypeCode="130"]` | `cac:DocumentReference[cbc:DocumentTypeCode="130"]/cbc:ID` |  |
| `lines[].objectId.value` |  |  |  |  |  |  |
| `lines[].objectId.scheme` |  |  |  |  |  |  |
| `lines[].netAmount` | BT-131 | 1 | BASIC | `ram:SpecifiedLineTradeSettlement/ram:SpecifiedTradeSettlementLineMonetarySummation/ram:LineTotalAmount` | `cbc:LineExtensionAmount` | calculated |
| `lines[].orderLineReference` | BT-132 | 0..1 | EN 16931 | `ram:SpecifiedLineTradeAgreement/ram:BuyerOrderReferencedDocument/ram:LineID` | `cac:OrderLineReference/cbc:LineID` |  |
| `lines[].buyerAccountingReference` | BT-133 | 0..1 | EN 16931 | `ram:SpecifiedLineTradeSettlement/ram:ReceivableSpecifiedTradeAccountingAccount/ram:ID` | `cbc:AccountingCost` |  |
| `lines[].period` | BG-26 | 0..1 | BASIC | `ram:SpecifiedLineTradeSettlement/ram:BillingSpecifiedPeriod` | `cac:InvoicePeriod` |  |
| `lines[].period.start` | BT-134 |  |  |  |  |  |
| `lines[].period.end` | BT-135 |  |  |  |  |  |
| `lines[].allowances[]` | BG-27 | 0..n | BASIC | `ram:SpecifiedLineTradeSettlement/ram:SpecifiedTradeAllowanceCharge` | `cac:AllowanceCharge` |  |
| `lines[].allowances[].amount` | BT-136 | 1 | BASIC | `ram:SpecifiedTradeAllowanceCharge/ram:ActualAmount` | `cac:AllowanceCharge/cbc:Amount` |  |
| `lines[].allowances[].baseAmount` | BT-137 | 0..1 | BASIC | `ram:BasisAmount` | `cbc:BaseAmount` |  |
| `lines[].allowances[].percent` | BT-138 | 0..1 | BASIC | `ram:CalculationPercent` | `cbc:MultiplierFactorNumeric` |  |
| `lines[].allowances[].reason` | BT-139 | 0..1 | BASIC | `ram:Reason` | `cbc:AllowanceChargeReason` |  |
| `lines[].allowances[].reasonCode` | BT-140 | 0..1 | BASIC | `ram:ReasonCode` | `cbc:AllowanceChargeReasonCode` |  |
| `lines[].charges[]` | BG-28 | 0..n | BASIC | `ram:SpecifiedLineTradeSettlement/ram:SpecifiedTradeAllowanceCharge` | `cac:AllowanceCharge` |  |
| `lines[].charges[].amount` | BT-141 | 1 | BASIC | `ram:SpecifiedTradeAllowanceCharge/ram:ActualAmount` | `cac:AllowanceCharge/cbc:Amount` |  |
| `lines[].charges[].baseAmount` | BT-142 | 0..1 | BASIC | `ram:BasisAmount` | `cbc:BaseAmount` |  |
| `lines[].charges[].percent` | BT-143 | 0..1 | BASIC | `ram:CalculationPercent` | `cbc:MultiplierFactorNumeric` |  |
| `lines[].charges[].reason` | BT-144 | 0..1 | BASIC | `ram:Reason` | `cbc:AllowanceChargeReason` |  |
| `lines[].charges[].reasonCode` | BT-145 | 0..1 | BASIC | `ram:ReasonCode` | `cbc:AllowanceChargeReasonCode` |  |
| `lines[].subLines[]` |  |  |  |  |  | internal |
| `lines[].lineReferences[]` |  |  |  |  |  | EXTENDED only |
| `lines[].lineReferences[].id` |  |  |  |  |  |  |
| `lines[].lineReferences[].kind` |  |  |  |  |  |  |
| `lines[].lineReferences[].lineId` |  |  |  |  |  |  |
| `lines[].lineReferences[].issueDate` |  |  |  |  |  |  |
| `lines[].lineReferences[].typeCode` |  |  |  |  |  |  |
| `lines[].lineReferences[].description` |  |  |  |  |  |  |
| `lines[].extended` |  |  |  |  |  | EXTENDED only |
| `lines[].extended.lineStatusReasonCode` |  |  |  |  |  | EXTENDED only |
| `lines[].extended.shipTo` |  |  |  |  |  | EXTENDED only |
| `lines[].extended.shipTo.name` |  |  |  |  |  |  |
| `lines[].extended.shipTo.address` |  |  |  |  |  |  |
| `lines[].extended.shipTo.address.countryCode` | BT-40 | 1 | EXTENDED | `ram:CountryID` | `cac:Country/cbc:IdentificationCode` |  |
| `lines[].extended.shipTo.address.line1` | BT-35 | 0..1 | EXTENDED | `ram:LineOne` | `cbc:StreetName` |  |
| `lines[].extended.shipTo.address.line2` | BT-36 | 0..1 | EXTENDED | `ram:LineTwo` | `cbc:AdditionalStreetName` |  |
| `lines[].extended.shipTo.address.line3` | BT-162 | 0..1 | EXTENDED | `ram:LineThree` | `cac:AddressLine/cbc:Line` |  |
| `lines[].extended.shipTo.address.city` | BT-37 | 0..1 | EXTENDED | `ram:CityName` | `cbc:CityName` |  |
| `lines[].extended.shipTo.address.postcode` | BT-38 | 0..1 | EXTENDED | `ram:PostcodeCode` | `cbc:PostalZone` |  |
| `lines[].extended.shipTo.address.subdivision` | BT-39 | 0..1 | EXTENDED | `ram:CountrySubDivisionName` | `cbc:CountrySubentity` |  |
| `lines[].extended.shipTo.identifiers[]` |  |  |  |  |  |  |
| `lines[].extended.shipTo.identifiers[].value` |  |  |  |  |  |  |
| `lines[].extended.shipTo.identifiers[].scheme` |  |  |  |  |  |  |
| `lines[].extended.shipTo.vatId` |  |  |  |  |  |  |
| `lines[].extended.shipTo.contact` |  |  |  |  |  |  |
| `lines[].extended.shipTo.contact.name` | BT-41 | 0..1 | EXTENDED | `ram:PersonName` | `cbc:Name` |  |
| `lines[].extended.shipTo.contact.phone` | BT-42 | 0..1 | EXTENDED | `ram:TelephoneUniversalCommunication/ram:CompleteNumber` | `cbc:Telephone` |  |
| `lines[].extended.shipTo.contact.email` | BT-43 | 0..1 | EXTENDED | `ram:EmailURIUniversalCommunication/ram:URIID` | `cbc:ElectronicMail` |  |
| `lines[].extended.deliveryDate` |  |  |  |  |  | EXTENDED only |
| `lines[].extended.includedProducts[]` |  |  |  |  |  | EXTENDED only |
| `lines[].extended.includedProducts[].name` |  |  |  |  |  |  |
| `lines[].extended.includedProducts[].quantity` |  |  |  |  |  |  |
| `lines[].extended.includedProducts[].unit` |  |  |  |  |  |  |
| `lines[].extended.includedProducts[].description` |  |  |  |  |  |  |
| `lines[].extended.includedProducts[].sellerId` |  |  |  |  |  |  |
| `lines[].presentation` |  |  |  |  |  | PDF only |
| `lines[].presentation.groupId` |  |  |  |  |  |  |
| `lines[].presentation.highlight` |  |  |  |  |  |  |
| `lines[].presentation.showGrossPrice` |  |  |  |  |  |  |
| `taxCurrency` | BT-6 | 0..1 | BASIC WL | `ram:ApplicableHeaderTradeSettlement/ram:TaxCurrencyCode` | `cbc:TaxCurrencyCode` |  |
| `taxPointDate` | BT-7 | 0..1 | BASIC WL | `ram:ApplicableTradeTax/ram:TaxPointDate` | `cbc:TaxPointDate` |  |
| `taxPointDateCode` | BT-8 | 0..1 | BASIC WL | `ram:ApplicableTradeTax/ram:DueDateTypeCode` | `cac:InvoicePeriod/cbc:DescriptionCode` |  |
| `dueDate` | BT-9 | 0..1 | BASIC WL | `ram:SpecifiedTradePaymentTerms/ram:DueDateDateTime` | `cbc:DueDate (CreditNote: cac:PaymentMeans/cbc:PaymentDueDate)` |  |
| `buyerReference` | BT-10 | 0..1 | MINIMUM | `ram:ApplicableHeaderTradeAgreement/ram:BuyerReference` | `cbc:BuyerReference` | required in xrechnung |
| `projectReference` | BT-11 | 0..1 | EN 16931 | `ram:ApplicableHeaderTradeAgreement/ram:SpecifiedProcuringProject` | `cac:ProjectReference/cbc:ID` |  |
| `projectReference.id` |  |  |  |  |  |  |
| `projectReference.name` |  |  |  |  |  |  |
| `contractReference` | BT-12 | 0..1 | BASIC WL | `ram:ContractReferencedDocument/ram:IssuerAssignedID` | `cac:ContractDocumentReference/cbc:ID` |  |
| `purchaseOrderReference` | BT-13 | 0..1 | MINIMUM | `ram:BuyerOrderReferencedDocument/ram:IssuerAssignedID` | `cac:OrderReference/cbc:ID` |  |
| `salesOrderReference` | BT-14 | 0..1 | EN 16931 | `ram:SellerOrderReferencedDocument/ram:IssuerAssignedID` | `cac:OrderReference/cbc:SalesOrderID` |  |
| `receivingAdviceReference` | BT-15 | 0..1 | EN 16931 | `ram:ReceivingAdviceReferencedDocument/ram:IssuerAssignedID` | `cac:ReceiptDocumentReference/cbc:ID` |  |
| `despatchAdviceReference` | BT-16 | 0..1 | BASIC WL | `ram:DespatchAdviceReferencedDocument/ram:IssuerAssignedID` | `cac:DespatchDocumentReference/cbc:ID` |  |
| `tenderReference` | BT-17 | 0..1 | EN 16931 | `ram:AdditionalReferencedDocument[ram:TypeCode="50"]/ram:IssuerAssignedID` | `cac:OriginatorDocumentReference/cbc:ID` |  |
| `invoicedObject` | BT-18 | 0..1 | EN 16931 | `ram:AdditionalReferencedDocument[ram:TypeCode="130"]/ram:IssuerAssignedID` | `cac:AdditionalDocumentReference[cbc:DocumentTypeCode="130"]/cbc:ID` |  |
| `invoicedObject.value` |  |  |  |  |  |  |
| `invoicedObject.scheme` |  |  |  |  |  |  |
| `buyerAccountingReference` | BT-19 | 0..1 | BASIC WL | `ram:ReceivableSpecifiedTradeAccountingAccount/ram:ID` | `cbc:AccountingCost` |  |
| `paymentTerms` | BT-20 | 0..1 | BASIC WL | `ram:SpecifiedTradePaymentTerms` | `cac:PaymentTerms` |  |
| `paymentTerms.text` | BT-20 | 0..1 | BASIC WL | `ram:SpecifiedTradePaymentTerms/ram:Description` | `cac:PaymentTerms/cbc:Note` |  |
| `paymentTerms.dueDays` |  |  |  |  |  | internal |
| `paymentTerms.discounts[]` |  |  |  |  |  | internal |
| `paymentTerms.discounts[].days` |  |  |  |  |  |  |
| `paymentTerms.discounts[].percent` |  |  |  |  |  |  |
| `paymentTerms.discounts[].baseAmount` |  |  |  |  |  |  |
| `paymentTerms.lateFeeTerms` |  |  |  |  |  | internal |
| `paymentTerms.lateFeeTerms.days` |  |  |  |  |  |  |
| `paymentTerms.lateFeeTerms.percent` |  |  |  |  |  |  |
| `paymentTerms.lateFeeTerms.text` |  |  |  |  |  |  |
| `notes[]` | BG-1 | 0..n | BASIC WL | `rsm:ExchangedDocument/ram:IncludedNote` | `cbc:Note` |  |
| `notes[].content` | BT-22 | 1 | BASIC WL | `ram:IncludedNote/ram:Content` | `cbc:Note` |  |
| `notes[].subjectCode` | BT-21 | 0..1 | BASIC WL | `ram:IncludedNote/ram:SubjectCode` | `cbc:Note (prefix #CODE#)` |  |
| `businessProcess` | BT-23 | 0..1 | MINIMUM | `ram:BusinessProcessSpecifiedDocumentContextParameter/ram:ID` | `cbc:ProfileID` |  |
| `precedingInvoices[]` | BG-3 | 0..n | BASIC WL | `ram:InvoiceReferencedDocument` | `cac:BillingReference` |  |
| `precedingInvoices[].number` | BT-25 | 1 | BASIC WL | `ram:InvoiceReferencedDocument/ram:IssuerAssignedID` | `cac:BillingReference/cac:InvoiceDocumentReference/cbc:ID` |  |
| `precedingInvoices[].issueDate` | BT-26 | 0..1 | BASIC WL | `ram:InvoiceReferencedDocument/ram:FormattedIssueDateTime` | `cac:BillingReference/cac:InvoiceDocumentReference/cbc:IssueDate` |  |
| `payee` | BG-10 | 0..1 | BASIC WL | `ram:PayeeTradeParty` | `cac:PayeeParty` |  |
| `payee.name` | BT-59 | 1 | BASIC WL | `ram:PayeeTradeParty/ram:Name` | `cac:PayeeParty/cac:PartyName/cbc:Name` |  |
| `payee.identifier` | BT-60 | 0..1 | BASIC WL | `ram:PayeeTradeParty/ram:ID \| ram:GlobalID` | `cac:PayeeParty/cac:PartyIdentification/cbc:ID` |  |
| `payee.identifier.value` |  |  |  |  |  |  |
| `payee.identifier.scheme` |  |  |  |  |  |  |
| `payee.legalRegistration` | BT-61 | 0..1 | BASIC WL | `ram:PayeeTradeParty/ram:SpecifiedLegalOrganization/ram:ID` | `cac:PayeeParty/cac:PartyLegalEntity/cbc:CompanyID` |  |
| `payee.legalRegistration.value` |  |  |  |  |  |  |
| `payee.legalRegistration.scheme` |  |  |  |  |  |  |
| `sellerTaxRepresentative` | BG-11 | 0..1 | BASIC WL | `ram:SellerTaxRepresentativeTradeParty` | `cac:TaxRepresentativeParty` |  |
| `sellerTaxRepresentative.name` | BT-62 | 1 | BASIC WL | `ram:SellerTaxRepresentativeTradeParty/ram:Name` | `cac:TaxRepresentativeParty/cac:PartyName/cbc:Name` |  |
| `sellerTaxRepresentative.vatId` | BT-63 | 1 | BASIC WL | `ram:SpecifiedTaxRegistration/ram:ID[@schemeID="VA"]` | `cac:PartyTaxScheme/cbc:CompanyID` |  |
| `sellerTaxRepresentative.address` | BG-12 | 1 | BASIC WL | `ram:PostalTradeAddress` | `cac:PostalAddress` |  |
| `sellerTaxRepresentative.address.countryCode` | BT-69 | 1 | BASIC WL | `ram:CountryID` | `cac:Country/cbc:IdentificationCode` |  |
| `sellerTaxRepresentative.address.line1` | BT-64 | 0..1 | BASIC WL | `ram:LineOne` | `cbc:StreetName` |  |
| `sellerTaxRepresentative.address.line2` | BT-65 | 0..1 | BASIC WL | `ram:LineTwo` | `cbc:AdditionalStreetName` |  |
| `sellerTaxRepresentative.address.line3` | BT-164 | 0..1 | BASIC WL | `ram:LineThree` | `cac:AddressLine/cbc:Line` |  |
| `sellerTaxRepresentative.address.city` | BT-66 | 0..1 | BASIC WL | `ram:CityName` | `cbc:CityName` |  |
| `sellerTaxRepresentative.address.postcode` | BT-67 | 0..1 | BASIC WL | `ram:PostcodeCode` | `cbc:PostalZone` |  |
| `sellerTaxRepresentative.address.subdivision` | BT-68 | 0..1 | BASIC WL | `ram:CountrySubDivisionName` | `cbc:CountrySubentity` |  |
| `delivery` | BG-13 | 0..1 | BASIC WL | `ram:ApplicableHeaderTradeDelivery` | `cac:Delivery` |  |
| `delivery.recipientName` | BT-70 | 0..1 | BASIC WL | `ram:ShipToTradeParty/ram:Name` | `cac:Delivery/cac:DeliveryParty/cac:PartyName/cbc:Name` |  |
| `delivery.locationId` | BT-71 | 0..1 | BASIC WL | `ram:ShipToTradeParty/ram:ID \| ram:GlobalID` | `cac:Delivery/cac:DeliveryLocation/cbc:ID` |  |
| `delivery.locationId.value` |  |  |  |  |  |  |
| `delivery.locationId.scheme` |  |  |  |  |  |  |
| `delivery.actualDeliveryDate` | BT-72 | 0..1 | BASIC WL | `ram:ActualDeliverySupplyChainEvent/ram:OccurrenceDateTime` | `cac:Delivery/cbc:ActualDeliveryDate` |  |
| `delivery.address` | BG-15 | 0..1 | BASIC WL | `ram:ShipToTradeParty/ram:PostalTradeAddress` | `cac:Delivery/cac:DeliveryLocation/cac:Address` |  |
| `delivery.address.countryCode` | BT-80 | 1 | BASIC WL | `ram:CountryID` | `cac:Country/cbc:IdentificationCode` |  |
| `delivery.address.line1` | BT-75 | 0..1 | BASIC WL | `ram:LineOne` | `cbc:StreetName` |  |
| `delivery.address.line2` | BT-76 | 0..1 | BASIC WL | `ram:LineTwo` | `cbc:AdditionalStreetName` |  |
| `delivery.address.line3` | BT-165 | 0..1 | BASIC WL | `ram:LineThree` | `cac:AddressLine/cbc:Line` |  |
| `delivery.address.city` | BT-77 | 0..1 | BASIC WL | `ram:CityName` | `cbc:CityName` |  |
| `delivery.address.postcode` | BT-78 | 0..1 | BASIC WL | `ram:PostcodeCode` | `cbc:PostalZone` |  |
| `delivery.address.subdivision` | BT-79 | 0..1 | BASIC WL | `ram:CountrySubDivisionName` | `cbc:CountrySubentity` |  |
| `invoicingPeriod` | BG-14 | 0..1 | BASIC WL | `ram:BillingSpecifiedPeriod` | `cac:InvoicePeriod` |  |
| `invoicingPeriod.start` | BT-73 |  |  |  |  |  |
| `invoicingPeriod.end` | BT-74 |  |  |  |  |  |
| `paymentInstructions` | BG-16 | 0..1 | BASIC WL | `ram:SpecifiedTradeSettlementPaymentMeans` | `cac:PaymentMeans` | required in xrechnung |
| `paymentInstructions.meansCode` | BT-81 | 1 | BASIC WL | `ram:SpecifiedTradeSettlementPaymentMeans/ram:TypeCode` | `cac:PaymentMeans/cbc:PaymentMeansCode` |  |
| `paymentInstructions.meansText` | BT-82 | 0..1 | EN 16931 | `ram:SpecifiedTradeSettlementPaymentMeans/ram:Information` | `cac:PaymentMeans/cbc:PaymentMeansCode/@name` |  |
| `paymentInstructions.remittanceInformation` | BT-83 | 0..1 | BASIC WL | `ram:PaymentReference` | `cac:PaymentMeans/cbc:PaymentID` |  |
| `paymentInstructions.creditTransfers[]` | BG-17 | 0..n | BASIC WL | `ram:SpecifiedTradeSettlementPaymentMeans` | `cac:PaymentMeans` |  |
| `paymentInstructions.creditTransfers[].accountId` | BT-84 | 1 | BASIC WL | `ram:PayeePartyCreditorFinancialAccount/ram:IBANID \| ram:ProprietaryID` | `cac:PayeeFinancialAccount/cbc:ID` |  |
| `paymentInstructions.creditTransfers[].accountName` | BT-85 | 0..1 | EN 16931 | `ram:PayeePartyCreditorFinancialAccount/ram:AccountName` | `cac:PayeeFinancialAccount/cbc:Name` |  |
| `paymentInstructions.creditTransfers[].serviceProviderId` | BT-86 | 0..1 | EN 16931 | `ram:PayeeSpecifiedCreditorFinancialInstitution/ram:BICID` | `cac:PayeeFinancialAccount/cac:FinancialInstitutionBranch/cbc:ID` |  |
| `paymentInstructions.card` | BG-18 | 0..1 | EN 16931 | `ram:SpecifiedTradeSettlementPaymentMeans/ram:ApplicableTradeSettlementFinancialCard` | `cac:PaymentMeans/cac:CardAccount` |  |
| `paymentInstructions.card.primaryAccountNumber` | BT-87 | 1 | EN 16931 | `ram:ApplicableTradeSettlementFinancialCard/ram:ID` | `cac:CardAccount/cbc:PrimaryAccountNumberID` |  |
| `paymentInstructions.card.holderName` | BT-88 | 0..1 | EN 16931 | `ram:ApplicableTradeSettlementFinancialCard/ram:CardholderName` | `cac:CardAccount/cbc:HolderName` |  |
| `paymentInstructions.directDebit` | BG-19 | 0..1 | BASIC WL | `ram:SpecifiedTradeSettlementPaymentMeans/ram:PayerPartyDebtorFinancialAccount` | `cac:PaymentMeans/cac:PaymentMandate` |  |
| `paymentInstructions.directDebit.mandateReference` | BT-89 | 0..1 | BASIC WL | `ram:SpecifiedTradePaymentTerms/ram:DirectDebitMandateID` | `cac:PaymentMandate/cbc:ID` |  |
| `paymentInstructions.directDebit.creditorId` | BT-90 | 0..1 | BASIC WL | `ram:CreditorReferenceID` | `cac:AccountingSupplierParty/cac:Party/cac:PartyIdentification/cbc:ID[@schemeID="SEPA"]` |  |
| `paymentInstructions.directDebit.debitedAccountId` | BT-91 | 0..1 | BASIC WL | `ram:PayerPartyDebtorFinancialAccount/ram:IBANID` | `cac:PaymentMandate/cac:PayerFinancialAccount/cbc:ID` |  |
| `allowances[]` | BG-20 | 0..n | BASIC WL | `ram:SpecifiedTradeAllowanceCharge[ram:ChargeIndicator/udt:Indicator="false"]` | `cac:AllowanceCharge[cbc:ChargeIndicator="false"]` |  |
| `allowances[].amount` | BT-92 | 1 | BASIC WL | `ram:SpecifiedTradeAllowanceCharge/ram:ActualAmount` | `cac:AllowanceCharge/cbc:Amount` |  |
| `allowances[].vatCategory` | BT-95 | 1 | BASIC WL | `ram:CategoryTradeTax/ram:CategoryCode` | `cac:TaxCategory/cbc:ID` |  |
| `allowances[].vatRate` | BT-96 | 0..1 | BASIC WL | `ram:CategoryTradeTax/ram:RateApplicablePercent` | `cac:TaxCategory/cbc:Percent` |  |
| `allowances[].baseAmount` | BT-93 | 0..1 | BASIC WL | `ram:BasisAmount` | `cbc:BaseAmount` |  |
| `allowances[].percent` | BT-94 | 0..1 | BASIC WL | `ram:CalculationPercent` | `cbc:MultiplierFactorNumeric` |  |
| `allowances[].reason` | BT-97 | 0..1 | BASIC WL | `ram:Reason` | `cbc:AllowanceChargeReason` |  |
| `allowances[].reasonCode` | BT-98 | 0..1 | BASIC WL | `ram:ReasonCode` | `cbc:AllowanceChargeReasonCode` |  |
| `allowances[].exemptionReason` |  |  |  |  |  | internal |
| `allowances[].exemptionReasonCode` |  |  |  |  |  | internal |
| `charges[]` | BG-21 | 0..n | BASIC WL | `ram:SpecifiedTradeAllowanceCharge[ram:ChargeIndicator/udt:Indicator="true"]` | `cac:AllowanceCharge[cbc:ChargeIndicator="true"]` |  |
| `charges[].amount` | BT-99 | 1 | BASIC WL | `ram:SpecifiedTradeAllowanceCharge/ram:ActualAmount` | `cac:AllowanceCharge/cbc:Amount` |  |
| `charges[].vatCategory` | BT-102 | 1 | BASIC WL | `ram:CategoryTradeTax/ram:CategoryCode` | `cac:TaxCategory/cbc:ID` |  |
| `charges[].vatRate` | BT-103 | 0..1 | BASIC WL | `ram:CategoryTradeTax/ram:RateApplicablePercent` | `cac:TaxCategory/cbc:Percent` |  |
| `charges[].baseAmount` | BT-100 | 0..1 | BASIC WL | `ram:BasisAmount` | `cbc:BaseAmount` |  |
| `charges[].percent` | BT-101 | 0..1 | BASIC WL | `ram:CalculationPercent` | `cbc:MultiplierFactorNumeric` |  |
| `charges[].reason` | BT-104 | 0..1 | BASIC WL | `ram:Reason` | `cbc:AllowanceChargeReason` |  |
| `charges[].reasonCode` | BT-105 | 0..1 | BASIC WL | `ram:ReasonCode` | `cbc:AllowanceChargeReasonCode` |  |
| `charges[].exemptionReason` |  |  |  |  |  | internal |
| `charges[].exemptionReasonCode` |  |  |  |  |  | internal |
| `totals` | BG-22 | 1 | MINIMUM | `ram:SpecifiedTradeSettlementHeaderMonetarySummation` | `cac:LegalMonetaryTotal` | calculated |
| `totals.lineNetSum` | BT-106 | 1 | BASIC WL | `ram:LineTotalAmount` | `cac:LegalMonetaryTotal/cbc:LineExtensionAmount` | calculated |
| `totals.netTotal` | BT-109 | 1 | MINIMUM | `ram:TaxBasisTotalAmount` | `cac:LegalMonetaryTotal/cbc:TaxExclusiveAmount` | calculated |
| `totals.grossTotal` | BT-112 | 1 | MINIMUM | `ram:GrandTotalAmount` | `cac:LegalMonetaryTotal/cbc:TaxInclusiveAmount` | calculated |
| `totals.amountDue` | BT-115 | 1 | MINIMUM | `ram:DuePayableAmount` | `cac:LegalMonetaryTotal/cbc:PayableAmount` | calculated |
| `totals.allowanceSum` | BT-107 | 0..1 | BASIC WL | `ram:AllowanceTotalAmount` | `cac:LegalMonetaryTotal/cbc:AllowanceTotalAmount` | calculated |
| `totals.chargeSum` | BT-108 | 0..1 | BASIC WL | `ram:ChargeTotalAmount` | `cac:LegalMonetaryTotal/cbc:ChargeTotalAmount` | calculated |
| `totals.vatTotal` | BT-110 | 0..1 | MINIMUM | `ram:TaxTotalAmount[@currencyID=BT-5]` | `cac:TaxTotal/cbc:TaxAmount` | calculated |
| `totals.vatTotalInTaxCurrency` | BT-111 | 0..1 | BASIC WL | `ram:TaxTotalAmount[@currencyID=BT-6]` | `cac:TaxTotal/cbc:TaxAmount` | calculated |
| `totals.prepaidAmount` | BT-113 | 0..1 | BASIC WL | `ram:TotalPrepaidAmount` | `cac:LegalMonetaryTotal/cbc:PrepaidAmount` |  |
| `totals.roundingAmount` | BT-114 | 0..1 | BASIC WL | `ram:RoundingAmount` | `cac:LegalMonetaryTotal/cbc:PayableRoundingAmount` |  |
| `vatBreakdown[]` | BG-23 | 1..n | BASIC WL | `ram:ApplicableTradeTax` | `cac:TaxTotal/cac:TaxSubtotal` | calculated |
| `vatBreakdown[].taxableAmount` | BT-116 | 1 | BASIC WL | `ram:ApplicableTradeTax/ram:BasisAmount` | `cac:TaxSubtotal/cbc:TaxableAmount` | calculated |
| `vatBreakdown[].taxAmount` | BT-117 | 1 | BASIC WL | `ram:ApplicableTradeTax/ram:CalculatedAmount` | `cac:TaxSubtotal/cbc:TaxAmount` | calculated |
| `vatBreakdown[].category` | BT-118 | 1 | BASIC WL | `ram:ApplicableTradeTax/ram:CategoryCode` | `cac:TaxSubtotal/cac:TaxCategory/cbc:ID` | calculated |
| `vatBreakdown[].rate` | BT-119 | 0..1 | BASIC WL | `ram:ApplicableTradeTax/ram:RateApplicablePercent` | `cac:TaxSubtotal/cac:TaxCategory/cbc:Percent` | calculated |
| `vatBreakdown[].exemptionReason` | BT-120 | 0..1 | BASIC WL | `ram:ApplicableTradeTax/ram:ExemptionReason` | `cac:TaxSubtotal/cac:TaxCategory/cbc:TaxExemptionReason` |  |
| `vatBreakdown[].exemptionReasonCode` | BT-121 | 0..1 | BASIC WL | `ram:ApplicableTradeTax/ram:ExemptionReasonCode` | `cac:TaxSubtotal/cac:TaxCategory/cbc:TaxExemptionReasonCode` |  |
| `attachments[]` | BG-24 | 0..n | EN 16931 | `ram:AdditionalReferencedDocument[ram:TypeCode="916"]` | `cac:AdditionalDocumentReference` |  |
| `attachments[].id` | BT-122 | 1 | EN 16931 | `ram:AdditionalReferencedDocument/ram:IssuerAssignedID` | `cac:AdditionalDocumentReference/cbc:ID` |  |
| `attachments[].description` | BT-123 | 0..1 | EN 16931 | `ram:AdditionalReferencedDocument/ram:Name` | `cac:AdditionalDocumentReference/cbc:DocumentDescription` |  |
| `attachments[].externalUri` | BT-124 | 0..1 | EN 16931 | `ram:AdditionalReferencedDocument/ram:URIID` | `cac:AdditionalDocumentReference/cac:Attachment/cac:ExternalReference/cbc:URI` |  |
| `attachments[].content` | BT-125 | 0..1 | EN 16931 | `ram:AdditionalReferencedDocument/ram:AttachmentBinaryObject` | `cac:AdditionalDocumentReference/cac:Attachment/cbc:EmbeddedDocumentBinaryObject` |  |
| `attachments[].mimeType` | BT-125-1 | 0..1 | EN 16931 | `ram:AttachmentBinaryObject/@mimeCode` | `cbc:EmbeddedDocumentBinaryObject/@mimeCode` |  |
| `attachments[].filename` | BT-125-2 | 0..1 | EN 16931 | `ram:AttachmentBinaryObject/@filename` | `cbc:EmbeddedDocumentBinaryObject/@filename` |  |
| `prepaidAmount` |  |  |  |  |  |  |
| `roundingAmount` |  |  |  |  |  |  |
| `extended` |  |  |  |  |  | EXTENDED only |
| `extended.invoicer` |  |  |  |  |  | EXTENDED only |
| `extended.invoicer.name` |  |  |  |  |  |  |
| `extended.invoicer.address` |  |  |  |  |  |  |
| `extended.invoicer.address.countryCode` | BT-40 | 1 | EXTENDED | `ram:CountryID` | `cac:Country/cbc:IdentificationCode` |  |
| `extended.invoicer.address.line1` | BT-35 | 0..1 | EXTENDED | `ram:LineOne` | `cbc:StreetName` |  |
| `extended.invoicer.address.line2` | BT-36 | 0..1 | EXTENDED | `ram:LineTwo` | `cbc:AdditionalStreetName` |  |
| `extended.invoicer.address.line3` | BT-162 | 0..1 | EXTENDED | `ram:LineThree` | `cac:AddressLine/cbc:Line` |  |
| `extended.invoicer.address.city` | BT-37 | 0..1 | EXTENDED | `ram:CityName` | `cbc:CityName` |  |
| `extended.invoicer.address.postcode` | BT-38 | 0..1 | EXTENDED | `ram:PostcodeCode` | `cbc:PostalZone` |  |
| `extended.invoicer.address.subdivision` | BT-39 | 0..1 | EXTENDED | `ram:CountrySubDivisionName` | `cbc:CountrySubentity` |  |
| `extended.invoicer.identifiers[]` |  |  |  |  |  |  |
| `extended.invoicer.identifiers[].value` |  |  |  |  |  |  |
| `extended.invoicer.identifiers[].scheme` |  |  |  |  |  |  |
| `extended.invoicer.vatId` |  |  |  |  |  |  |
| `extended.invoicer.contact` |  |  |  |  |  |  |
| `extended.invoicer.contact.name` | BT-41 | 0..1 | EXTENDED | `ram:PersonName` | `cbc:Name` |  |
| `extended.invoicer.contact.phone` | BT-42 | 0..1 | EXTENDED | `ram:TelephoneUniversalCommunication/ram:CompleteNumber` | `cbc:Telephone` |  |
| `extended.invoicer.contact.email` | BT-43 | 0..1 | EXTENDED | `ram:EmailURIUniversalCommunication/ram:URIID` | `cbc:ElectronicMail` |  |
| `extended.invoicee` |  |  |  |  |  | EXTENDED only |
| `extended.invoicee.name` |  |  |  |  |  |  |
| `extended.invoicee.address` |  |  |  |  |  |  |
| `extended.invoicee.address.countryCode` | BT-40 | 1 | EXTENDED | `ram:CountryID` | `cac:Country/cbc:IdentificationCode` |  |
| `extended.invoicee.address.line1` | BT-35 | 0..1 | EXTENDED | `ram:LineOne` | `cbc:StreetName` |  |
| `extended.invoicee.address.line2` | BT-36 | 0..1 | EXTENDED | `ram:LineTwo` | `cbc:AdditionalStreetName` |  |
| `extended.invoicee.address.line3` | BT-162 | 0..1 | EXTENDED | `ram:LineThree` | `cac:AddressLine/cbc:Line` |  |
| `extended.invoicee.address.city` | BT-37 | 0..1 | EXTENDED | `ram:CityName` | `cbc:CityName` |  |
| `extended.invoicee.address.postcode` | BT-38 | 0..1 | EXTENDED | `ram:PostcodeCode` | `cbc:PostalZone` |  |
| `extended.invoicee.address.subdivision` | BT-39 | 0..1 | EXTENDED | `ram:CountrySubDivisionName` | `cbc:CountrySubentity` |  |
| `extended.invoicee.identifiers[]` |  |  |  |  |  |  |
| `extended.invoicee.identifiers[].value` |  |  |  |  |  |  |
| `extended.invoicee.identifiers[].scheme` |  |  |  |  |  |  |
| `extended.invoicee.vatId` |  |  |  |  |  |  |
| `extended.invoicee.contact` |  |  |  |  |  |  |
| `extended.invoicee.contact.name` | BT-41 | 0..1 | EXTENDED | `ram:PersonName` | `cbc:Name` |  |
| `extended.invoicee.contact.phone` | BT-42 | 0..1 | EXTENDED | `ram:TelephoneUniversalCommunication/ram:CompleteNumber` | `cbc:Telephone` |  |
| `extended.invoicee.contact.email` | BT-43 | 0..1 | EXTENDED | `ram:EmailURIUniversalCommunication/ram:URIID` | `cbc:ElectronicMail` |  |
| `extended.ultimateCustomer` |  |  |  |  |  | EXTENDED only |
| `extended.ultimateCustomer.name` |  |  |  |  |  |  |
| `extended.ultimateCustomer.address` |  |  |  |  |  |  |
| `extended.ultimateCustomer.address.countryCode` | BT-40 | 1 | EXTENDED | `ram:CountryID` | `cac:Country/cbc:IdentificationCode` |  |
| `extended.ultimateCustomer.address.line1` | BT-35 | 0..1 | EXTENDED | `ram:LineOne` | `cbc:StreetName` |  |
| `extended.ultimateCustomer.address.line2` | BT-36 | 0..1 | EXTENDED | `ram:LineTwo` | `cbc:AdditionalStreetName` |  |
| `extended.ultimateCustomer.address.line3` | BT-162 | 0..1 | EXTENDED | `ram:LineThree` | `cac:AddressLine/cbc:Line` |  |
| `extended.ultimateCustomer.address.city` | BT-37 | 0..1 | EXTENDED | `ram:CityName` | `cbc:CityName` |  |
| `extended.ultimateCustomer.address.postcode` | BT-38 | 0..1 | EXTENDED | `ram:PostcodeCode` | `cbc:PostalZone` |  |
| `extended.ultimateCustomer.address.subdivision` | BT-39 | 0..1 | EXTENDED | `ram:CountrySubDivisionName` | `cbc:CountrySubentity` |  |
| `extended.ultimateCustomer.identifiers[]` |  |  |  |  |  |  |
| `extended.ultimateCustomer.identifiers[].value` |  |  |  |  |  |  |
| `extended.ultimateCustomer.identifiers[].scheme` |  |  |  |  |  |  |
| `extended.ultimateCustomer.vatId` |  |  |  |  |  |  |
| `extended.ultimateCustomer.contact` |  |  |  |  |  |  |
| `extended.ultimateCustomer.contact.name` | BT-41 | 0..1 | EXTENDED | `ram:PersonName` | `cbc:Name` |  |
| `extended.ultimateCustomer.contact.phone` | BT-42 | 0..1 | EXTENDED | `ram:TelephoneUniversalCommunication/ram:CompleteNumber` | `cbc:Telephone` |  |
| `extended.ultimateCustomer.contact.email` | BT-43 | 0..1 | EXTENDED | `ram:EmailURIUniversalCommunication/ram:URIID` | `cbc:ElectronicMail` |  |
| `extended.shipFrom` |  |  |  |  |  | EXTENDED only |
| `extended.shipFrom.name` |  |  |  |  |  |  |
| `extended.shipFrom.address` |  |  |  |  |  |  |
| `extended.shipFrom.address.countryCode` | BT-40 | 1 | EXTENDED | `ram:CountryID` | `cac:Country/cbc:IdentificationCode` |  |
| `extended.shipFrom.address.line1` | BT-35 | 0..1 | EXTENDED | `ram:LineOne` | `cbc:StreetName` |  |
| `extended.shipFrom.address.line2` | BT-36 | 0..1 | EXTENDED | `ram:LineTwo` | `cbc:AdditionalStreetName` |  |
| `extended.shipFrom.address.line3` | BT-162 | 0..1 | EXTENDED | `ram:LineThree` | `cac:AddressLine/cbc:Line` |  |
| `extended.shipFrom.address.city` | BT-37 | 0..1 | EXTENDED | `ram:CityName` | `cbc:CityName` |  |
| `extended.shipFrom.address.postcode` | BT-38 | 0..1 | EXTENDED | `ram:PostcodeCode` | `cbc:PostalZone` |  |
| `extended.shipFrom.address.subdivision` | BT-39 | 0..1 | EXTENDED | `ram:CountrySubDivisionName` | `cbc:CountrySubentity` |  |
| `extended.shipFrom.identifiers[]` |  |  |  |  |  |  |
| `extended.shipFrom.identifiers[].value` |  |  |  |  |  |  |
| `extended.shipFrom.identifiers[].scheme` |  |  |  |  |  |  |
| `extended.shipFrom.vatId` |  |  |  |  |  |  |
| `extended.shipFrom.contact` |  |  |  |  |  |  |
| `extended.shipFrom.contact.name` | BT-41 | 0..1 | EXTENDED | `ram:PersonName` | `cbc:Name` |  |
| `extended.shipFrom.contact.phone` | BT-42 | 0..1 | EXTENDED | `ram:TelephoneUniversalCommunication/ram:CompleteNumber` | `cbc:Telephone` |  |
| `extended.shipFrom.contact.email` | BT-43 | 0..1 | EXTENDED | `ram:EmailURIUniversalCommunication/ram:URIID` | `cbc:ElectronicMail` |  |
| `extended.deliveryTermsCode` |  |  |  |  |  | EXTENDED only |
| `extended.additionalReferences[]` |  |  |  |  |  | EXTENDED only |
| `extended.additionalReferences[].id` |  |  |  |  |  |  |
| `extended.additionalReferences[].kind` |  |  |  |  |  |  |
| `extended.additionalReferences[].lineId` |  |  |  |  |  |  |
| `extended.additionalReferences[].issueDate` |  |  |  |  |  |  |
| `extended.additionalReferences[].typeCode` |  |  |  |  |  |  |
| `extended.additionalReferences[].description` |  |  |  |  |  |  |
| `extended.installments[]` |  |  |  |  |  | EXTENDED only |
| `extended.installments[].dueDate` |  |  |  |  |  |  |
| `extended.installments[].amount` |  |  |  |  |  |  |
| `extended.installments[].description` |  |  |  |  |  |  |
| `extended.additionalPaymentInstructions[]` |  |  |  |  |  | EXTENDED only |
| `extended.additionalPaymentInstructions[].meansCode` | BT-81 | 1 | EXTENDED | `ram:SpecifiedTradeSettlementPaymentMeans/ram:TypeCode` | `cac:PaymentMeans/cbc:PaymentMeansCode` |  |
| `extended.additionalPaymentInstructions[].meansText` | BT-82 | 0..1 | EXTENDED | `ram:SpecifiedTradeSettlementPaymentMeans/ram:Information` | `cac:PaymentMeans/cbc:PaymentMeansCode/@name` |  |
| `extended.additionalPaymentInstructions[].remittanceInformation` | BT-83 | 0..1 | EXTENDED | `ram:PaymentReference` | `cac:PaymentMeans/cbc:PaymentID` |  |
| `extended.additionalPaymentInstructions[].creditTransfers[]` | BG-17 | 0..n | EXTENDED | `ram:SpecifiedTradeSettlementPaymentMeans` | `cac:PaymentMeans` |  |
| `extended.additionalPaymentInstructions[].creditTransfers[].accountId` | BT-84 | 1 | EXTENDED | `ram:PayeePartyCreditorFinancialAccount/ram:IBANID \| ram:ProprietaryID` | `cac:PayeeFinancialAccount/cbc:ID` |  |
| `extended.additionalPaymentInstructions[].creditTransfers[].accountName` | BT-85 | 0..1 | EXTENDED | `ram:PayeePartyCreditorFinancialAccount/ram:AccountName` | `cac:PayeeFinancialAccount/cbc:Name` |  |
| `extended.additionalPaymentInstructions[].creditTransfers[].serviceProviderId` | BT-86 | 0..1 | EXTENDED | `ram:PayeeSpecifiedCreditorFinancialInstitution/ram:BICID` | `cac:PayeeFinancialAccount/cac:FinancialInstitutionBranch/cbc:ID` |  |
| `extended.additionalPaymentInstructions[].card` | BG-18 | 0..1 | EXTENDED | `ram:SpecifiedTradeSettlementPaymentMeans/ram:ApplicableTradeSettlementFinancialCard` | `cac:PaymentMeans/cac:CardAccount` |  |
| `extended.additionalPaymentInstructions[].card.primaryAccountNumber` | BT-87 | 1 | EXTENDED | `ram:ApplicableTradeSettlementFinancialCard/ram:ID` | `cac:CardAccount/cbc:PrimaryAccountNumberID` |  |
| `extended.additionalPaymentInstructions[].card.holderName` | BT-88 | 0..1 | EXTENDED | `ram:ApplicableTradeSettlementFinancialCard/ram:CardholderName` | `cac:CardAccount/cbc:HolderName` |  |
| `extended.additionalPaymentInstructions[].directDebit` | BG-19 | 0..1 | EXTENDED | `ram:SpecifiedTradeSettlementPaymentMeans/ram:PayerPartyDebtorFinancialAccount` | `cac:PaymentMeans/cac:PaymentMandate` |  |
| `extended.additionalPaymentInstructions[].directDebit.mandateReference` | BT-89 | 0..1 | EXTENDED | `ram:SpecifiedTradePaymentTerms/ram:DirectDebitMandateID` | `cac:PaymentMandate/cbc:ID` |  |
| `extended.additionalPaymentInstructions[].directDebit.creditorId` | BT-90 | 0..1 | EXTENDED | `ram:CreditorReferenceID` | `cac:AccountingSupplierParty/cac:Party/cac:PartyIdentification/cbc:ID[@schemeID="SEPA"]` |  |
| `extended.additionalPaymentInstructions[].directDebit.debitedAccountId` | BT-91 | 0..1 | EXTENDED | `ram:PayerPartyDebtorFinancialAccount/ram:IBANID` | `cac:PaymentMandate/cac:PayerFinancialAccount/cbc:ID` |  |
| `xrechnungExtension` |  |  |  |  |  | XRechnung extension only |
| `xrechnungExtension.thirdPartyPayments[]` |  |  |  |  |  | XRechnung extension only |
| `xrechnungExtension.thirdPartyPayments[].type` |  |  |  |  |  |  |
| `xrechnungExtension.thirdPartyPayments[].amount` |  |  |  |  |  |  |
| `xrechnungExtension.thirdPartyPayments[].description` |  |  |  |  |  |  |
| `presentation` |  |  |  |  |  | PDF only |
| `presentation.template` |  |  |  |  |  | PDF only |
| `presentation.logo` |  |  |  |  |  | PDF only |
| `presentation.colors` |  |  |  |  |  | PDF only |
| `presentation.colors.primary` |  |  |  |  |  |  |
| `presentation.colors.text` |  |  |  |  |  |  |
| `presentation.colors.muted` |  |  |  |  |  |  |
| `presentation.colors.secondary` |  |  |  |  |  |  |
| `presentation.font` |  |  |  |  |  | PDF only |
| `presentation.senderLine` |  |  |  |  |  | PDF only |
| `presentation.letterLayout` |  |  |  |  |  | PDF only |
| `presentation.salutation` |  |  |  |  |  | PDF only |
| `presentation.introText` |  |  |  |  |  | PDF only |
| `presentation.closingText` |  |  |  |  |  | PDF only |
| `presentation.footerColumns[]` |  |  |  |  |  | PDF only |
| `presentation.lineGroups[]` |  |  |  |  |  | PDF only |
| `presentation.lineGroups[].id` |  |  |  |  |  |  |
| `presentation.lineGroups[].title` |  |  |  |  |  |  |
| `presentation.lineGroups[].showSubtotal` |  |  |  |  |  |  |
| `presentation.showGrossPrices` |  |  |  |  |  | PDF only |
| `presentation.showItemNumbers` |  |  |  |  |  | PDF only |
| `presentation.paymentQr` |  |  |  |  |  | PDF only |
| `presentation.watermark` |  |  |  |  |  | PDF only |
| `presentation.paper` |  |  |  |  |  | PDF only |
| `presentation.labels[]` |  |  |  |  |  | PDF only |
| `presentation.title` |  |  |  |  |  | PDF only |
| `presentation.explicit[]` |  |  |  |  |  | PDF only |
| `meta` |  |  |  |  |  | internal |
| `meta.locale` |  |  |  |  |  | internal |
| `meta.timezone` |  |  |  |  |  | internal |
| `meta.exchangeRate` |  |  |  |  |  | internal |
| `meta.exchangeRateDate` |  |  |  |  |  | internal |
| `meta.custom[]` |  |  |  |  |  | internal |
| `meta.source` |  |  |  |  |  | internal |
| `meta.flags[]` |  |  |  |  |  | internal |
| `standardVersion` |  |  |  |  |  | internal |
