<?php

declare(strict_types=1);

// Messages of the UStG rules and of the CIUS-AT-NAT and CIUS-AT-GOV rules of the Austrian
// country pack. Not reviewed by a tax adviser yet.
return [
    'AT-USTG-01' => [
        'message' => 'The name and address of the seller must be complete: street (BT-35), post code (BT-38) and city (BT-37) (section 11 (1) no. 3 lit. a UStG).',
        'hint' => 'Add the address with ->address(street, post code, city, country) on the seller.',
    ],
    'AT-USTG-02' => [
        'message' => 'The name and address of the buyer must be complete: street (BT-50), post code (BT-53) and city (BT-52) (section 11 (1) no. 3 lit. b UStG); only simplified invoices up to 400 EUR and of small businesses may leave them out (section 11 (6) UStG).',
        'hint' => 'Add the address with ->address(street, post code, city, country) on the buyer.',
    ],
    'AT-USTG-03' => [
        'message' => 'The seller\'s VAT identifier (UID, BT-31) must be stated (section 11 (1) no. 3 lit. i UStG).',
        'hint' => 'Set ->vatId(\'ATU…\') on the seller.',
    ],
    'AT-USTG-04' => [
        'message' => 'The date of the supply or service must be stated: delivery date (BT-72), invoicing period (BG-14) or periods on all lines (BG-26) (section 11 (1) no. 3 lit. d UStG).',
        'hint' => 'Set ->deliveryDate(…) or ->invoicingPeriod(…, …).',
    ],
    'AT-USTG-05' => [
        'message' => 'Invoices to a business above 10,000 EUR must state the buyer\'s VAT identifier (UID, BT-48) (section 11 (1) no. 3 lit. b UStG).',
        'hint' => 'Set ->vatId(…) on the buyer.',
    ],
    'AT-USTG-06' => [
        'message' => 'Reverse charge invoices must point out that the recipient owes the VAT ("Übergang der Steuerschuld auf den Leistungsempfänger") (section 11 (1a) UStG).',
        'hint' => 'Use the presets VatPreset::ReverseChargeDomestic, VatPreset::ReverseChargeEu or VatPreset::TriangularTransaction.',
    ],
    'AT-USTG-07' => [
        'message' => 'A tax exemption needs a note on the exemption as text (BT-120); a code alone is not enough (section 11 (1) no. 3 lit. e UStG).',
        'hint' => 'Use a preset such as VatPreset::IntraCommunitySupply or VatPreset::exempt(\'§ 6 Abs. 1 Z … UStG\').',
    ],
    'AT-USTG-08' => [
        'message' => 'A small business that applies the exemption of section 6 (1) no. 27 UStG must not show VAT; VAT shown is owed by reason of the invoice (section 11 (12) UStG).',
        'hint' => 'Use VatPreset::SmallBusiness on every line.',
    ],
    'AT-USTG-09' => [
        'message' => 'A self-billed invoice must be labelled "Gutschrift" (section 11 (8) no. 3 UStG).',
        'hint' => 'Add the note "Gutschrift" (->note(\'Gutschrift\')).',
    ],
    'AT-USTG-10' => [
        'message' => 'An invoice in another currency than euro must state the VAT amount in euro as well (VAT accounting currency BT-6 "EUR" and BT-111), or how it is converted (section 11 (1) no. 3 lit. f UStG).',
        'hint' => 'Set ->taxCurrency(\'EUR\', rate) on the builder.',
    ],
    'AT-USTG-11' => [
        'message' => 'The invoice is issued more than six months after the supply (:supply); invoices to businesses and legal persons must be issued within six months (section 11 (1) no. 1 UStG).',
    ],
    'AT-USTG-12' => [
        'message' => 'Invoices for services in another member state for which the recipient owes the VAT must be issued by the 15th day of the month following the service (section 11 (1) no. 2 UStG); the deadline was :deadline.',
    ],
    'AT-NAT-01' => [
        'message' => 'The address line 1 of every postal address must be provided (BT-35, BT-50, BT-64, BT-75).',
    ],
    'AT-NAT-02' => [
        'message' => 'The city of every postal address must be provided (BT-37, BT-52, BT-66, BT-77).',
    ],
    'AT-NAT-03' => [
        'message' => 'The post code of every postal address must be provided (BT-38, BT-53, BT-67, BT-78).',
    ],
    'AT-NAT-04' => [
        'message' => 'The actual delivery date (BT-72) or the invoicing period (BG-14) must be provided.',
        'hint' => 'Periods on the lines are not enough in CIUS-AT-NAT; set ->deliveryDate(…) or ->invoicingPeriod(…, …).',
    ],
    'AT-NAT-05' => [
        'message' => 'The VAT rate of a document level allowance (BT-96) must be provided.',
    ],
    'AT-NAT-06' => [
        'message' => 'The VAT rate of a document level charge (BT-103) must be provided.',
    ],
    'AT-NAT-07' => [
        'message' => 'An external document location (BT-124) is not supported; embed the attachment instead.',
    ],
    'AT-NAT-08' => [
        'message' => 'The VAT rate of every invoice line (BT-152) must be provided.',
    ],
    'AT-GOV-01' => [
        'message' => 'The buyer reference (BT-10) must be provided: for the federal government the order reference (Auftragsreferenz).',
        'hint' => 'Set ->buyerReference(…) to the order reference of the ordering office.',
    ],
    'AT-GOV-02' => [
        'message' => 'The seller contact (BG-6) with an email address (BT-43) must be provided.',
        'hint' => 'Set ->contact(name, phone, email) on the seller.',
    ],
    'AT-GOV-03' => [
        'message' => 'The payment means type code (BT-81) must be one of 30, 31, 42, 49, 58 or 59.',
    ],
    'AT-GOV-04' => [
        'message' => 'The amount due for payment (BT-115) must not exceed 999,999,999.99.',
    ],
    'AT-GOV-05' => [
        'message' => 'At most 200 attachments (BG-24) of together at most 15 MB can be handled.',
    ],
    'AT-GOV-06' => [
        'message' => 'Attachments (BT-125) must be PDF, PNG, XML or Excel files (mime code and file name extension .pdf, .png, .xml, .xls or .xlsx).',
    ],
    'AT-GOV-07' => [
        'message' => 'An invoice can have at most 999 lines (BG-25).',
    ],
    'AT-GOV-08' => [
        'message' => 'The net amount of an invoice line (BT-131) must lie between -999,999,999,999.99 and 999,999,999,999.99.',
    ],
    'AT-GOV-09' => [
        'message' => 'With a ten digit order number of the federal government (BT-13) every line must reference its order line (BT-132).',
        'hint' => 'Set ->orderLineReference(…) on every line.',
    ],
];
