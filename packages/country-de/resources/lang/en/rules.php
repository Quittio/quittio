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
    'BR-DEX-15' => [
        'message' => 'Sub invoice lines are not supported by XRechnung in CII. Use the UBL syntax for the XRechnung extension.',
    ],
    'DE-XRECHNUNG-EXT-01' => [
        'message' => 'The XRechnung extension has no credit note in UBL. Cancel with the strategy negative_invoice (type code 380 with negative amounts).',
        'hint' => 'Set corrections.cancellation_strategy to negative_invoice or pass the strategy when cancelling.',
    ],
    'DE-USTG-01' => [
        'message' => 'Name and full address of the seller and the buyer must be stated (section 14 (4) no. 1 UStG).',
        'hint' => 'Add street, post code and city for seller and buyer.',
    ],
    'DE-USTG-02' => [
        'message' => 'The seller\'s tax number (BT-32) or VAT identifier (BT-31) must be stated (section 14 (4) no. 2 UStG).',
        'hint' => 'Set ->vatId() or ->taxNumber() on the seller.',
    ],
    'DE-USTG-03' => [
        'message' => 'The date of the supply or service must be stated: delivery date (BT-72), invoicing period (BG-14) or periods on all lines (BG-26) (section 14 (4) no. 6 UStG).',
        'hint' => 'Set ->deliveryDate(), even if it equals the invoice date, or ->invoicingPeriod().',
    ],
    'DE-USTG-04' => [
        'message' => 'A self-billed invoice must be labelled "Gutschrift" (section 14 (4) no. 10 UStG).',
        'hint' => 'Use ->selfBilled(); the builder adds the note.',
    ],
    'DE-USTG-05' => [
        'message' => 'Services in connection with a property to a non-business buyer require the notice on the buyer\'s retention obligation (section 14 (4) no. 9 UStG).',
        'hint' => 'Use ->propertyRelatedService(); the builder adds the note.',
    ],
    'DE-USTG-06' => [
        'message' => 'Reverse charge invoices must state "Steuerschuldnerschaft des Leistungsempfängers" (section 14a (1) and (5) UStG).',
        'hint' => 'Use the presets VatPreset::ReverseChargeDomestic, VatPreset::ReverseChargeEu or VatPreset::TriangularTransaction; their texts are accepted in every language of the country pack.',
    ],
    'DE-USTG-07' => [
        'message' => 'The intra-community supply of a new vehicle requires the vehicle details (BG-32) on every line (section 14a (4) UStG).',
        'hint' => 'Add e.g. ->attribute(\'First registration\', …) and ->attribute(\'Mileage\', …).',
    ],
    'DE-USTG-08' => [
        'message' => 'A small amount invoice must not exceed :limit gross (section 33 UStDV).',
        'hint' => 'Remove ->smallAmount() and issue a full invoice.',
    ],
    'DE-USTG-09' => [
        'message' => 'A small business (section 19 UStG) must not charge VAT in the same invoice.',
        'hint' => 'Use VatPreset::SmallBusiness for all lines.',
    ],
    'DE-USTG-10' => [
        'message' => 'For invoices in a foreign currency, the VAT amount should also be stated in EUR (BT-111 with BT-6 = EUR).',
        'hint' => 'Set ->taxCurrency(\'EUR\', $rate, $date).',
    ],
    'DE-USTG-11' => [
        'message' => 'Reverse charge services in another EU member state and intra-community triangular transactions must state the VAT identifiers of the seller (BT-31) and the buyer (BT-48) (section 14a (1) and (7) UStG).',
        'hint' => 'Add ->vatId(…) for seller and buyer; the tax number is not sufficient here.',
    ],
    'DE-USTG-12' => [
        'message' => 'The simplifications for small amount invoices do not apply to distance sales, intra-community supplies and reverse charge supplies (section 33 sentence 3 UStDV).',
        'hint' => 'Remove ->smallAmount() and issue a full invoice.',
    ],
    'DE-USTG-13' => [
        'message' => 'The intra-community supply of a new vehicle (section 14a (4) UStG) is marked, but no line uses the VAT category K (intra-community supply).',
        'hint' => 'Use VatPreset::IntraCommunitySupply for the vehicle lines, or remove ->newVehicle() if the vehicle is not supplied to another member state.',
    ],
    'DE-LEITWEG-ID' => [
        'message' => 'The check digits of the Leitweg-ID ":value" (BT-10) are wrong.',
        'hint' => 'Check the Leitweg-ID with the public buyer.',
    ],
    'DE-TAX-NUMBER' => [
        'message' => 'The tax number ":value" (BT-32) of the seller is not a valid German tax number: it matches neither the 13-digit ELSTER format nor the notation of a federal state, or its check digit is wrong.',
        'hint' => 'Copy the tax number from a notice of the tax office (e.g. 181/815/08155) or use the 13-digit ELSTER format. A VAT identifier (DE…) belongs in ->vatId().',
    ],
    'DE-LEITWEG-ID-SUSPECT' => [
        'message' => 'The buyer reference ":value" (BT-10) is shaped like a Leitweg-ID, but its check digits are wrong. It is not treated as a Leitweg-ID, so the invoice is not issued as XRechnung automatically.',
        'hint' => 'If it is the Leitweg-ID of a public buyer, correct it (XRechnung is then chosen automatically) or set the format explicitly. An order number can be ignored.',
    ],
];
