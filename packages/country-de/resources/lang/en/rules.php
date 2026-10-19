<?php

declare(strict_types=1);

// Messages of the XRechnung and UStG rules of the German country pack.
return [
    'BR-DE-1' => [
        'message' => 'An invoice must contain payment instructions (BG-16).',
        'hint' => 'Add the payment means with ->payment(PaymentMeansCode::SepaCreditTransfer, iban: …).',
    ],
    'BR-DE-2' => [
        'message' => 'The seller contact (BG-6) must be provided.',
        'hint' => 'Add the seller contact with ->contact(name, phone, email).',
    ],
    'BR-DE-3' => [
        'message' => 'The seller city (BT-37) must be provided.',
    ],
    'BR-DE-4' => [
        'message' => 'The seller post code (BT-38) must be provided.',
    ],
    'BR-DE-5' => [
        'message' => 'The seller contact point (BT-41) must be provided.',
    ],
    'BR-DE-6' => [
        'message' => 'The seller contact telephone number (BT-42) must be provided.',
    ],
    'BR-DE-7' => [
        'message' => 'The seller contact email address (BT-43) must be provided.',
    ],
    'BR-DE-8' => [
        'message' => 'The buyer city (BT-52) must be provided.',
    ],
    'BR-DE-9' => [
        'message' => 'The buyer post code (BT-53) must be provided.',
    ],
    'BR-DE-10' => [
        'message' => 'The deliver to city (BT-77) must be provided if a deliver to address (BG-15) is given.',
    ],
    'BR-DE-11' => [
        'message' => 'The deliver to post code (BT-78) must be provided if a deliver to address (BG-15) is given.',
    ],
    'BR-DE-15' => [
        'message' => 'The buyer reference (BT-10) must be provided.',
        'hint' => 'Set the Leitweg-ID with ->buyerReference(\'04011000-12345-03\').',
    ],
    'BR-DE-16' => [
        'message' => 'If VAT categories S, Z, E, AE, K, G, L or M are used, the seller VAT identifier (BT-31), the seller tax registration identifier (BT-32) or the seller tax representative (BG-11) must be provided.',
    ],
    'BR-DE-17' => [
        'message' => 'The invoice type code (BT-3) should be one of 326, 380, 384, 389, 381, 875, 876 or 877.',
    ],
    'BR-DE-18' => [
        'message' => 'Cash discount lines in the payment terms (BT-20) must follow the format #SKONTO#TAGE=n#PROZENT=n.nn# and end with a line break.',
    ],
    'BR-DE-19' => [
        'message' => 'The payment account identifier (BT-84) should contain a valid IBAN for SEPA credit transfers (code 58).',
    ],
    'BR-DE-20' => [
        'message' => 'The debited account identifier (BT-91) should contain a valid IBAN for SEPA direct debit (code 59).',
    ],
    'BR-DE-22' => [
        'message' => 'The file names of attached documents (BT-125-2) must be unique.',
    ],
    'BR-DE-23-a' => [
        'message' => 'For credit transfers (codes 30, 58), credit transfer information (BG-17) must be provided.',
    ],
    'BR-DE-23-b' => [
        'message' => 'For credit transfers (codes 30, 58), payment card (BG-18) and direct debit (BG-19) information must not be provided.',
    ],
    'BR-DE-24-a' => [
        'message' => 'For card payments (codes 48, 54, 55), payment card information (BG-18) must be provided.',
    ],
    'BR-DE-24-b' => [
        'message' => 'For card payments (codes 48, 54, 55), credit transfer (BG-17) and direct debit (BG-19) information must not be provided.',
    ],
    'BR-DE-25-a' => [
        'message' => 'For direct debit (code 59), direct debit information (BG-19) must be provided.',
    ],
    'BR-DE-25-b' => [
        'message' => 'For direct debit (code 59), credit transfer (BG-17) and payment card (BG-18) information must not be provided.',
    ],
    'BR-DE-26' => [
        'message' => 'A corrected invoice (type code 384) should reference the preceding invoice (BG-3).',
    ],
    'BR-DE-27' => [
        'message' => 'The seller contact telephone number (BT-42) should contain at least three digits.',
    ],
    'BR-DE-28' => [
        'message' => 'The seller contact email address (BT-43) should contain exactly one @ with at least two characters on both sides.',
    ],
    'BR-DE-30' => [
        'message' => 'For direct debit (BG-19), the bank assigned creditor identifier (BT-90) must be provided.',
    ],
    'BR-DE-31' => [
        'message' => 'For direct debit (BG-19), the debited account identifier (BT-91) must be provided.',
    ],
    'BR-DE-TMP-32' => [
        'message' => 'The invoice should state the delivery or service date: actual delivery date (BT-72), invoicing period (BG-14) or a period on every line (BG-26).',
    ],
    'BR-DEX-02' => [
        'message' => 'The net amount of line :line (BT-131) should equal the sum of the net amounts of its direct sub lines (:expected).',
    ],
    'BR-DEX-04' => [
        'message' => 'The party identifier scheme ":value" is not part of ISO/IEC 6523 or the schemes XR01 to XR03 of the XRechnung extension (SEPA only for seller and payee in UBL).',
    ],
    'BR-DEX-05' => [
        'message' => 'The legal registration identifier scheme ":value" is not part of ISO/IEC 6523 or the schemes XR01 to XR03 of the XRechnung extension.',
    ],
    'BR-DEX-06' => [
        'message' => 'The item standard identifier scheme ":value" is not part of ISO/IEC 6523 or the schemes XR01 to XR03 of the XRechnung extension.',
    ],
    'BR-DEX-07' => [
        'message' => 'The electronic address scheme ":value" is not part of the EAS code list or the schemes XR01 to XR03 of the XRechnung extension.',
    ],
    'BR-DEX-08' => [
        'message' => 'The delivery location identifier scheme ":value" is not part of ISO/IEC 6523 or the schemes XR01 to XR03 of the XRechnung extension.',
    ],
    'BR-DEX-09' => [
        'message' => 'Amount due for payment (BT-115) = invoice total amount with VAT (BT-112) − paid amount (BT-113) + rounding amount (BT-114) + sum of the third party payment amounts (BT-DEX-002).',
    ],
    'BR-DEX-10' => [
        'message' => 'The third party payment type (BT-DEX-001) must be provided for every third party payment (BG-DEX-09).',
    ],
    'BR-DEX-12' => [
        'message' => 'The third party payment description (BT-DEX-003) must be provided for every third party payment (BG-DEX-09).',
    ],
    'BR-DEX-13' => [
        'message' => 'The third party payment amount (BT-DEX-002) may have at most two decimals, :value given.',
    ],
    'BR-DEX-14' => [
        'message' => 'The third party payment amount (BT-DEX-002) is in :value, but must be in the invoice currency (BT-5) :expected.',
    ],
    'BR-DEX-15' => [
        'message' => 'Sub invoice lines are not supported by XRechnung in CII. Use the UBL syntax for the XRechnung extension.',
    ],
    'DE-XRECHNUNG-EXT-01' => [
        'message' => 'The XRechnung extension has no credit note in UBL. Cancel with the strategy negative_invoice (type code 380 with negative amounts).',
        'hint' => 'Set corrections.cancellation_strategy to negative_invoice or pass the strategy when cancelling.',
    ],
    'BR-DE-CVD-01' => [
        'message' => 'The contract reference (BT-12) must be provided.',
        'hint' => 'Set the contract reference with ->contractReference(…).',
    ],
    'BR-DE-CVD-02' => [
        'message' => 'The tender or lot reference (BT-17) must be provided.',
        'hint' => 'Set the tender or lot reference with ->tenderReference(…).',
    ],
    'BR-DE-CVD-03' => [
        'message' => 'At least one invoice line (BG-25) must contain an item classification identifier (BT-158) with the scheme identifier CVD and an item attribute named cva (BT-160).',
        'hint' => 'Describe the vehicle on its line with ->classification(\'M1\', \'CVD\') and ->attribute(\'cva\', \'clean\').',
    ],
    'BR-DE-CVD-04' => [
        'message' => 'The item classification identifier (BT-158) ":value" with the scheme identifier CVD must be one of the vehicle categories M1, M2, M3, N1, N2 or N3. Line :line.',
    ],
    'BR-DE-CVD-05' => [
        'message' => 'The value ":value" (BT-161) of the item attribute cva must be clean, zero-emission or other. Line :line.',
    ],
    'BR-DE-CVD-06-a' => [
        'message' => 'An invoice line with an item classification identifier (BT-158) with the scheme identifier CVD must contain exactly one item attribute named cva (BT-160). Line :line.',
    ],
    'BR-DE-CVD-06-b' => [
        'message' => 'An invoice line with an item attribute named cva (BT-160) must contain exactly one item classification identifier (BT-158) with the scheme identifier CVD. Line :line.',
    ],
    'DE-USTG-01' => [
        'message' => 'The address :party is incomplete; missing: :fields (section 14 (4) no. 1 UStG).',
        'hint' => ':advice',
        'party' => [
            'seller' => 'of the seller (BG-5)',
            'buyer' => 'of the buyer (BG-8)',
        ],
        'fields' => ['line1' => 'street and number', 'postcode' => 'post code', 'city' => 'city'],
        'advice' => [
            'seller' => 'With Quittio::invoice() the seller comes from config/quittio.php: fill in seller.address (line1, postcode, city). Otherwise: ->seller(Party::make(…)->address(street, post code, city, country)).',
            'buyer' => 'Add the buyer\'s address: ->buyer(Party::make(…)->address(street, post code, city, country)) or in fillParty() of your model.',
        ],
    ],
    'DE-USTG-02' => [
        'message' => 'The seller\'s tax number (BT-32) or VAT identifier (BT-31) must be stated (section 14 (4) no. 2 UStG).',
        'hint' => 'Set ->vatId() or ->taxNumber() on the seller.',
    ],
    'DE-USTG-03' => [
        'message' => 'The date of the supply or service must be stated: delivery date (BT-72), invoicing period (BG-14) or periods on all lines (BG-26) (section 14 (4) no. 6 UStG).',
        'hint' => 'Set ->deliveryDate(), even if it equals the invoice date, or ->invoicingPeriod(). A prepayment invoice (386) names the expected date or month; if it is not agreed yet, use ->de()->serviceDateOpen().',
    ],
    'DE-USTG-04' => [
        'message' => 'A self-billed invoice must be labelled "Gutschrift" (section 14 (4) no. 10 UStG).',
        'hint' => 'Use ->de()->selfBilled(); the builder adds the note. In an e-invoice the type code is label enough (section 14.5 (24) UStAE), so only a warning there.',
    ],
    'DE-USTG-05' => [
        'message' => 'Services in connection with a property to a non-business buyer require the notice on the buyer\'s retention obligation (section 14 (4) no. 9 UStG).',
        'hint' => 'Use ->de()->propertyRelatedService(); the builder adds the note.',
    ],
    'DE-USTG-06' => [
        'message' => 'Reverse charge invoices must state "Steuerschuldnerschaft des Leistungsempfängers" (section 14a (1) and (5) UStG).',
        'hint' => 'Use the presets VatPreset::ReverseChargeDomestic, VatPreset::ReverseChargeEu or VatPreset::TriangularTransaction; their texts are accepted in every language of the country pack. Without the statement the invoice is incomplete; the buyer still owes the VAT.',
    ],
    'DE-USTG-07' => [
        'message' => 'The intra-community supply of a new vehicle requires the vehicle details (BG-32) on every line (section 14a (4) UStG).',
        'hint' => 'Add e.g. ->attribute(\'First registration\', …) and ->attribute(\'Mileage\', …).',
    ],
    'DE-USTG-08' => [
        'message' => 'A small amount invoice must not exceed :limit gross (section 33 UStDV).',
        'hint' => 'Remove ->de()->smallAmount() and issue a full invoice.',
    ],
    'DE-USTG-09' => [
        'message' => 'A small business (section 19 UStG) must not charge VAT in the same invoice.',
        'hint' => 'Use VatPreset::SmallBusiness for all lines.',
    ],
    'DE-USTG-10' => [
        'message' => 'For invoices in a foreign currency, the VAT amount should also be stated in EUR (BT-111 with BT-6 = EUR) (Article 230 VAT Directive, section 16 (6) UStG).',
        'hint' => 'Set ->taxCurrency(\'EUR\', $rate, $date).',
    ],
    'DE-USTG-11' => [
        'message' => 'Reverse charge services in another EU member state and intra-community triangular transactions must state the VAT identifiers of the seller (BT-31) and the buyer (BT-48) (section 14a (1) and (7) UStG).',
        'hint' => 'Add ->vatId(…) for seller and buyer; the tax number is not sufficient here. A small business (section 19 UStG) needs no VAT identifier of its own.',
    ],
    'DE-USTG-12' => [
        'message' => 'The simplifications for small amount invoices do not apply to distance sales, intra-community supplies and reverse charge supplies (section 33 sentence 3 UStDV).',
        'hint' => 'Remove ->de()->smallAmount() and issue a full invoice.',
    ],
    'DE-USTG-13' => [
        'message' => 'The intra-community supply of a new vehicle (section 14a (4) UStG) is marked, but no line uses the VAT category K (intra-community supply).',
        'hint' => 'Use VatPreset::IntraCommunitySupply for the vehicle lines, or remove ->de()->newVehicle() if the vehicle is not supplied to another member state.',
    ],
    'DE-USTG-14' => [
        'message' => 'A small business cannot treat a supply as a tax-exempt intra-community supply (VAT category K) (section 19 (1) sentence 2 UStG); only the intra-community supply of a new vehicle is excepted (section 19 (6) sentence 1 UStG).',
        'hint' => 'Use VatPreset::SmallBusiness for the lines; for a new vehicle add ->de()->newVehicle().',
    ],
    'DE-USTG-15' => [
        'message' => 'Invoices for intra-community supplies and for services under section 3a (2) UStG in another member state must be issued by the 15th day of the month following the supply (section 14a (1) sentence 2 and (3) sentence 1 UStG); the deadline was :deadline.',
        'hint' => 'Check the invoice date (BT-2) and the date of supply. The invoice stays valid, but it is issued late.',
    ],
    'DE-USTG-16' => [
        'message' => 'The invoice is issued more than six months after the supply (:supply); invoices to businesses and for services in connection with a property must be issued within six months (section 14 (2) sentence 2 UStG).',
        'hint' => 'Check the invoice date (BT-2) and the date of supply. The invoice stays valid, but it is issued late.',
    ],
    'DE-USTG-17' => [
        'message' => 'A prepayment invoice that states an amount already paid (BT-113) must state the date the payment was received as VAT point date (BT-7) if it differs from the issue date (section 14 (4) sentence 1 no. 6 UStG).',
        'hint' => 'Set ->taxPointDate($received). If the payment was received on the invoice date, nothing is missing.',
    ],
    'DE-USTG-18' => [
        'message' => 'A corrected invoice (384) should state in a note that it corrects and replaces the original invoice (section 31 (5) UStDV, section 15.2a (7) UStAE).',
        'hint' => 'Use Quittio::correction(), which adds the note "Diese Rechnung berichtigt und ersetzt die Rechnung … vom …", or add such a note.',
    ],
    'DE-USTG-19' => [
        'message' => 'The final invoice deducts an advance that was settled by self-billing (Gutschrift), but is no self-billed invoice itself. The advance must be deducted (section 14 (2) sentence 5 UStG); changing the way of invoicing is allowed but unusual.',
        'hint' => 'Check with the buyer how the final payment is settled. If the buyer settles it by self-billing as well, the buyer issues the final document.',
    ],
    'DE-USTG-20' => [
        'message' => 'The final invoice deducts an advance invoice in full without the amount received being confirmed. Only advance payments actually received are deducted (section 14 (5) sentence 2 UStG, section 14.8 (2), (5) and (10) UStAE).',
        'hint' => 'Pass the amount received: ->de()->deductAdvance($advance, received: \'500.00\') for a part, received: GermanInvoice::RECEIVED_IN_FULL for the whole advance. Do not deduct an advance that was not paid.',
    ],
    'DE-EINVOICE-01' => [
        'message' => 'The invoice must be issued as e-invoice: :reason (section 14 (1) and section 27 (38) UStG). A PDF, MINIMUM or BASIC WL is an "other invoice".',
        'hint' => 'Render it as zugferd (EN 16931) or xrechnung. For 2027, quittio.countries.de.prior_year_turnover_above_800k tells whether the transition applies to you. An "other invoice" instead of an e-invoice is no administrative offence (BMF letter of 15 October 2026, marginal no. 28), but the buyer may lose the input tax deduction.',
    ],
    'DE-EINVOICE-02' => [
        'message' => 'Until :until an "other invoice" is allowed for this supply instead of an e-invoice, as PDF only with the consent of the buyer (section 27 (38) UStG, section 14 (1) sentence 5 UStG).',
        'hint' => 'Make sure the buyer agrees to a PDF invoice, or render an e-invoice (zugferd, xrechnung).',
    ],
    'DE-LEITWEG-ID' => [
        'message' => 'The check digits of the Leitweg-ID ":value" (BT-10) are wrong.',
        'hint' => 'Check the Leitweg-ID with the public buyer.',
    ],
    'DE-TAX-NUMBER' => [
        'message' => 'The tax number ":value" (BT-32) of the seller is not a valid German tax number: it matches neither the 13-digit ELSTER format nor the notation of a federal state, or its check digit is wrong.',
        'hint' => 'Copy the tax number from a notice of the tax office (e.g. 181/815/08155) or use the 13-digit ELSTER format. A VAT identifier (DE…) belongs in ->vatId().',
    ],
    'DE-VAT-RATE' => [
        'message' => 'The VAT rate :rate % of line :line is neither the standard rate (:standard %) nor the reduced rate (:reduced %) that applied on its supply date :date.',
        'hint' => 'Check the line period, the delivery date or the VAT point date: the rate of the date of the supply applies, e.g. 16 % and 5 % from July to December 2020. Average rates of agriculture and forestry (section 24 UStG) are a legitimate exception; switch the rule off for them with validation.rules.',
    ],
    'DE-LEITWEG-ID-SUSPECT' => [
        'message' => 'The buyer reference ":value" (BT-10) is shaped like a Leitweg-ID, but its check digits are wrong. It is not treated as a Leitweg-ID, so the invoice is not issued as XRechnung automatically.',
        'hint' => 'If it is the Leitweg-ID of a public buyer, correct it (XRechnung is then chosen automatically) or set the format explicitly. An order number can be ignored.',
    ],
];
