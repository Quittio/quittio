<?php

declare(strict_types=1);

// Validation message catalog: message and solution hint per rule code.
return [
    'QUITTIO-NOT-CALCULATED' => [
        'message' => 'The invoice has not been calculated. Totals (BG-22) and the VAT breakdown (BG-23) are missing.',
        'hint' => 'Calculate the invoice before validating it, e.g. via Quittio::make($data) or the calculator.',
    ],
    'QUITTIO-FORMAT-COUNTRY' => [
        'message' => 'The country code ":value" must consist of two upper case letters.',
    ],
    'QUITTIO-FORMAT-CURRENCY' => [
        'message' => 'The currency code ":value" must consist of three upper case letters.',
    ],
    'QUITTIO-PRECISION' => [
        'message' => 'Line :line uses :value decimals for quantity or price. Many receiving systems support at most :max.',
    ],
    'BR-02' => [
        'message' => 'An invoice shall have an invoice number (BT-1).',
        'hint' => 'Set the invoice number with ->number(). In the Managed layer the number is assigned when the invoice is issued.',
    ],
    'BR-04' => [
        'message' => 'An invoice shall have an invoice type code (BT-3).',
    ],
    'BR-05' => [
        'message' => 'An invoice shall have an invoice currency code (BT-5).',
    ],
    'BR-06' => [
        'message' => 'An invoice shall contain the seller name (BT-27).',
    ],
    'BR-07' => [
        'message' => 'An invoice shall contain the buyer name (BT-44).',
    ],
    'BR-09' => [
        'message' => 'The seller postal address (BG-5) shall contain a country code (BT-40).',
    ],
    'BR-11' => [
        'message' => 'The buyer postal address (BG-8) shall contain a country code (BT-55).',
    ],
    'BR-16' => [
        'message' => 'An invoice shall have at least one invoice line (BG-25).',
    ],
    'BR-17' => [
        'message' => 'The payee name (BT-59) shall be provided if the payee (BG-10) is different from the seller.',
    ],
    'BR-18' => [
        'message' => 'The seller tax representative name (BT-62) shall be provided if the seller has a tax representative.',
    ],
    'BR-20' => [
        'message' => 'The seller tax representative postal address (BG-12) shall contain a country code (BT-69).',
    ],
    'BR-21' => [
        'message' => 'Each invoice line (BG-25) shall have an invoice line identifier (BT-126).',
    ],
    'BR-25' => [
        'message' => 'Each invoice line (BG-25) shall contain the item name (BT-153). Line :line.',
    ],
    'BR-27' => [
        'message' => 'The item net price (BT-146) shall not be negative. Line :line.',
    ],
    'BR-28' => [
        'message' => 'The item gross price (BT-148) shall not be negative. Line :line.',
    ],
    'BR-29' => [
        'message' => 'The invoicing period end date (BT-74) shall be later or equal to the start date (BT-73).',
    ],
    'BR-30' => [
        'message' => 'The invoice line period end date (BT-135) shall be later or equal to the start date (BT-134). Line :line.',
    ],
    'BR-33' => [
        'message' => 'Each document level allowance (BG-20) shall have a reason (BT-97) or a reason code (BT-98).',
        'hint' => 'Pass a reason or a reason code when adding the allowance.',
    ],
    'BR-38' => [
        'message' => 'Each document level charge (BG-21) shall have a reason (BT-104) or a reason code (BT-105).',
        'hint' => 'Pass a reason or a reason code when adding the charge.',
    ],
    'BR-42' => [
        'message' => 'Each invoice line allowance (BG-27) shall have a reason (BT-139) or a reason code (BT-140). Line :line.',
        'hint' => 'Pass a reason or a reason code when adding the line allowance.',
    ],
    'BR-44' => [
        'message' => 'Each invoice line charge (BG-28) shall have a reason (BT-144) or a reason code (BT-145). Line :line.',
        'hint' => 'Pass a reason or a reason code when adding the line charge.',
    ],
    'BR-48' => [
        'message' => 'Each VAT breakdown (BG-23) shall have a VAT category rate (BT-119), except if the invoice is not subject to VAT.',
    ],
    'BR-49' => [
        'message' => 'The payment instructions (BG-16) shall specify the payment means type code (BT-81).',
    ],
    'BR-50' => [
        'message' => 'A payment account identifier (BT-84) shall be present if credit transfer (BG-17) information is provided.',
    ],
    'BR-52' => [
        'message' => 'Each additional supporting document (BG-24) shall contain a supporting document reference (BT-122).',
    ],
    'BR-53' => [
        'message' => 'If the VAT accounting currency code (BT-6) is present, the invoice total VAT amount in accounting currency (BT-111) shall be provided.',
        'hint' => 'Set the exchange rate with ->taxCurrency(\'EUR\', rate: \'…\') so that BT-111 can be calculated.',
    ],
    'BR-54' => [
        'message' => 'Each item attribute (BG-32) shall contain an item attribute name (BT-160) and an item attribute value (BT-161). Line :line.',
    ],
    'BR-55' => [
        'message' => 'Each preceding invoice reference (BG-3) shall contain a preceding invoice reference (BT-25).',
    ],
    'BR-56' => [
        'message' => 'Each seller tax representative party (BG-11) shall have a seller tax representative VAT identifier (BT-63).',
    ],
    'BR-57' => [
        'message' => 'Each deliver to address (BG-15) shall contain a deliver to country code (BT-80).',
    ],
    'BR-61' => [
        'message' => 'If the payment means type code (BT-81) means SEPA credit transfer, local credit transfer or non-SEPA international credit transfer, the payment account identifier (BT-84) shall be present.',
        'hint' => 'Add the account with ->payment(PaymentMeansCode::SepaCreditTransfer, iban: …).',
    ],
    'BR-CO-03' => [
        'message' => 'The value added tax point date (BT-7) and the value added tax point date code (BT-8) are mutually exclusive.',
    ],
    'BR-CO-09' => [
        'message' => 'The VAT identifier :value shall have a prefix in accordance with ISO code 3166-1 alpha-2 (Greece may use EL).',
        'hint' => 'Write the VAT identifier with its country prefix, e.g. DE123456789.',
    ],
    'BR-CO-18' => [
        'message' => 'An invoice shall have at least one VAT breakdown group (BG-23).',
    ],
    'BR-CO-25' => [
        'message' => 'In case the amount due for payment (BT-115) is positive, either the payment due date (BT-9) or the payment terms (BT-20) shall be present.',
        'hint' => 'Add a payment target with ->dueInDays(14) or a due date with ->dueDate().',
    ],
    'BR-CO-26' => [
        'message' => 'In order for the buyer to automatically identify a supplier, the seller identifier (BT-29), the seller legal registration identifier (BT-30) and/or the seller VAT identifier (BT-31) shall be present.',
        'hint' => 'Set the seller VAT identifier with ->vatId(), a legal registration with ->legalRegistration() or an identifier with ->identifier().',
    ],
    'BR-CO-10' => [
        'message' => 'Sum of invoice line net amount (BT-106) = Σ invoice line net amount (BT-131).',
    ],
    'BR-CO-11' => [
        'message' => 'Sum of allowances on document level (BT-107) = Σ document level allowance amount (BT-92).',
    ],
    'BR-CO-12' => [
        'message' => 'Sum of charges on document level (BT-108) = Σ document level charge amount (BT-99).',
    ],
    'BR-CO-13' => [
        'message' => 'Invoice total amount without VAT (BT-109) = Σ invoice line net amount (BT-131) − sum of allowances (BT-107) + sum of charges (BT-108).',
    ],
    'BR-CO-14' => [
        'message' => 'Invoice total VAT amount (BT-110) = Σ VAT category tax amount (BT-117).',
    ],
    'BR-CO-15' => [
        'message' => 'Invoice total amount with VAT (BT-112) = invoice total amount without VAT (BT-109) + invoice total VAT amount (BT-110).',
    ],
    'BR-CO-16' => [
        'message' => 'Amount due for payment (BT-115) = invoice total amount with VAT (BT-112) − paid amount (BT-113) + rounding amount (BT-114).',
    ],
    'BR-CO-17' => [
        'message' => 'VAT category tax amount (BT-117) = VAT category taxable amount (BT-116) × VAT category rate (BT-119) / 100, rounded to two decimals. Category :category, rate :rate.',
    ],
    'BR-S-01' => [
        'message' => 'An invoice that contains VAT category ":category" shall contain at least one VAT breakdown (BG-23) with this category.',
    ],
    'BR-S-02' => [
        'message' => 'An invoice with VAT category "S" shall contain the seller VAT identifier (BT-31), the seller tax registration identifier (BT-32) or the seller tax representative VAT identifier (BT-63).',
        'hint' => 'Set the VAT identifiers of seller and buyer with ->vatId().',
    ],
    'BR-S-05' => [
        'message' => 'In an invoice line, document level allowance or charge with VAT category "S" the VAT rate shall be greater than zero.',
    ],
    'BR-S-08' => [
        'message' => 'For each VAT rate of category ":category" the taxable amount (BT-116) shall equal the sum of line net amounts minus allowances plus charges of this category and rate.',
    ],
    'BR-S-09' => [
        'message' => 'The VAT category tax amount (BT-117) for category "S" shall equal the taxable amount (BT-116) multiplied by the rate (BT-119).',
    ],
    'BR-S-10' => [
        'message' => 'A VAT breakdown with category ":category" shall not have a VAT exemption reason code (BT-121) or text (BT-120).',
        'hint' => 'Remove the exemption reason; it is allowed only for the categories E, AE, K, G and O. Legal references for the zero rate belong into a note (BT-22).',
    ],
    'BR-Z-01' => [
        'message' => 'An invoice that contains VAT category ":category" shall contain exactly one VAT breakdown (BG-23) with this category.',
    ],
    'BR-Z-02' => [
        'message' => 'An invoice with VAT category "Z" shall contain the seller VAT identifier (BT-31), the seller tax registration identifier (BT-32) or the seller tax representative VAT identifier (BT-63).',
        'hint' => 'Set the VAT identifiers of seller and buyer with ->vatId().',
    ],
    'BR-Z-05' => [
        'message' => 'In an invoice line, document level allowance or charge with VAT category ":category" the VAT rate shall be 0 (zero).',
    ],
    'BR-Z-08' => [
        'message' => 'For each VAT rate of category ":category" the taxable amount (BT-116) shall equal the sum of line net amounts minus allowances plus charges of this category and rate.',
    ],
    'BR-Z-09' => [
        'message' => 'The VAT category tax amount (BT-117) in a VAT breakdown with category ":category" shall be 0 (zero).',
    ],
    'BR-Z-10' => [
        'message' => 'A VAT breakdown with category ":category" shall not have a VAT exemption reason code (BT-121) or text (BT-120).',
        'hint' => 'Remove the exemption reason; it is allowed only for the categories E, AE, K, G and O. Legal references for the zero rate belong into a note (BT-22).',
    ],
    'BR-E-01' => [
        'message' => 'An invoice that contains VAT category ":category" shall contain exactly one VAT breakdown (BG-23) with this category.',
    ],
    'BR-E-02' => [
        'message' => 'An invoice with VAT category "E" shall contain the seller VAT identifier (BT-31), the seller tax registration identifier (BT-32) or the seller tax representative VAT identifier (BT-63).',
        'hint' => 'Set the VAT identifiers of seller and buyer with ->vatId().',
    ],
    'BR-E-05' => [
        'message' => 'In an invoice line, document level allowance or charge with VAT category ":category" the VAT rate shall be 0 (zero).',
    ],
    'BR-E-08' => [
        'message' => 'For each VAT rate of category ":category" the taxable amount (BT-116) shall equal the sum of line net amounts minus allowances plus charges of this category and rate.',
    ],
    'BR-E-09' => [
        'message' => 'The VAT category tax amount (BT-117) in a VAT breakdown with category ":category" shall be 0 (zero).',
    ],
    'BR-E-10' => [
        'message' => 'A VAT breakdown with category ":category" shall have a VAT exemption reason code (BT-121) or a VAT exemption reason text (BT-120).',
        'hint' => 'Use a tax preset of the country pack, which sets the exemption reason, or pass it with ->vat(category, rate, exemptionReason).',
    ],
    'BR-AE-01' => [
        'message' => 'An invoice that contains VAT category ":category" shall contain exactly one VAT breakdown (BG-23) with this category.',
    ],
    'BR-AE-02' => [
        'message' => 'An invoice with VAT category "AE" shall contain the seller VAT identifier (BT-31), the seller tax registration identifier (BT-32) or the seller tax representative VAT identifier (BT-63), and the buyer VAT identifier (BT-48) or the buyer legal registration identifier (BT-47).',
        'hint' => 'Set the VAT identifiers of seller and buyer with ->vatId().',
    ],
    'BR-AE-05' => [
        'message' => 'In an invoice line, document level allowance or charge with VAT category ":category" the VAT rate shall be 0 (zero).',
    ],
    'BR-AE-08' => [
        'message' => 'For each VAT rate of category ":category" the taxable amount (BT-116) shall equal the sum of line net amounts minus allowances plus charges of this category and rate.',
    ],
    'BR-AE-09' => [
        'message' => 'The VAT category tax amount (BT-117) in a VAT breakdown with category ":category" shall be 0 (zero).',
    ],
    'BR-AE-10' => [
        'message' => 'A VAT breakdown with category ":category" shall have a VAT exemption reason code (BT-121) or a VAT exemption reason text (BT-120).',
        'hint' => 'Use a tax preset of the country pack, which sets the exemption reason, or pass it with ->vat(category, rate, exemptionReason).',
    ],
    'BR-IC-01' => [
        'message' => 'An invoice that contains VAT category ":category" shall contain exactly one VAT breakdown (BG-23) with this category.',
    ],
    'BR-IC-02' => [
        'message' => 'An invoice with VAT category "K" (intra-community supply) shall contain the seller VAT identifier (BT-31) or the seller tax representative VAT identifier (BT-63), and the buyer VAT identifier (BT-48).',
        'hint' => 'Set the VAT identifiers of seller and buyer with ->vatId().',
    ],
    'BR-IC-05' => [
        'message' => 'In an invoice line, document level allowance or charge with VAT category ":category" the VAT rate shall be 0 (zero).',
    ],
    'BR-IC-08' => [
        'message' => 'For each VAT rate of category ":category" the taxable amount (BT-116) shall equal the sum of line net amounts minus allowances plus charges of this category and rate.',
    ],
    'BR-IC-09' => [
        'message' => 'The VAT category tax amount (BT-117) in a VAT breakdown with category ":category" shall be 0 (zero).',
    ],
    'BR-IC-10' => [
        'message' => 'A VAT breakdown with category ":category" shall have a VAT exemption reason code (BT-121) or a VAT exemption reason text (BT-120).',
        'hint' => 'Use a tax preset of the country pack, which sets the exemption reason, or pass it with ->vat(category, rate, exemptionReason).',
    ],
    'BR-G-01' => [
        'message' => 'An invoice that contains VAT category ":category" shall contain exactly one VAT breakdown (BG-23) with this category.',
    ],
    'BR-G-02' => [
        'message' => 'An invoice with VAT category "G" (export) shall contain the seller VAT identifier (BT-31) or the seller tax representative VAT identifier (BT-63).',
        'hint' => 'Set the VAT identifiers of seller and buyer with ->vatId().',
    ],
    'BR-G-05' => [
        'message' => 'In an invoice line, document level allowance or charge with VAT category ":category" the VAT rate shall be 0 (zero).',
    ],
    'BR-G-08' => [
        'message' => 'For each VAT rate of category ":category" the taxable amount (BT-116) shall equal the sum of line net amounts minus allowances plus charges of this category and rate.',
    ],
    'BR-G-09' => [
        'message' => 'The VAT category tax amount (BT-117) in a VAT breakdown with category ":category" shall be 0 (zero).',
    ],
    'BR-G-10' => [
        'message' => 'A VAT breakdown with category ":category" shall have a VAT exemption reason code (BT-121) or a VAT exemption reason text (BT-120).',
        'hint' => 'Use a tax preset of the country pack, which sets the exemption reason, or pass it with ->vat(category, rate, exemptionReason).',
    ],
    'BR-O-01' => [
        'message' => 'An invoice that contains VAT category ":category" shall contain exactly one VAT breakdown (BG-23) with this category.',
    ],
    'BR-O-02' => [
        'message' => 'An invoice with VAT category "O" (not subject to VAT) shall not contain the seller VAT identifier (BT-31), the seller tax representative VAT identifier (BT-63) or the buyer VAT identifier (BT-48).',
        'hint' => 'Set the VAT identifiers of seller and buyer with ->vatId().',
    ],
    'BR-O-05' => [
        'message' => 'An invoice line, document level allowance or charge with VAT category "O" shall not contain a VAT rate.',
    ],
    'BR-O-08' => [
        'message' => 'For each VAT rate of category ":category" the taxable amount (BT-116) shall equal the sum of line net amounts minus allowances plus charges of this category and rate.',
    ],
    'BR-O-09' => [
        'message' => 'The VAT category tax amount (BT-117) in a VAT breakdown with category ":category" shall be 0 (zero).',
    ],
    'BR-O-10' => [
        'message' => 'A VAT breakdown with category ":category" shall have a VAT exemption reason code (BT-121) or a VAT exemption reason text (BT-120).',
        'hint' => 'Use a tax preset of the country pack, which sets the exemption reason, or pass it with ->vat(category, rate, exemptionReason).',
    ],
    'BR-IC-11' => [
        'message' => 'In an invoice with VAT category "K" (intra-community supply) the actual delivery date (BT-72) or the invoicing period (BG-14) shall not be blank.',
        'hint' => 'Set the delivery date with ->deliveryDate() or the invoicing period with ->invoicingPeriod().',
    ],
    'BR-IC-12' => [
        'message' => 'In an invoice with VAT category "K" (intra-community supply) the deliver to country code (BT-80) shall not be blank.',
        'hint' => 'Add the deliver to address with ->deliverTo(name, Address::make(…)).',
    ],
    'BR-O-11' => [
        'message' => 'An invoice with VAT category "O" (not subject to VAT) shall not contain other VAT categories.',
        'hint' => 'Issue separate invoices for supplies that are not subject to VAT.',
    ],
    'BR-CL-01' => [
        'message' => 'The document type code (BT-3) ":value" is not part of the invoice and credit note related code lists of UNTDID 1001.',
    ],
    'BR-CL-04' => [
        'message' => 'The invoice currency code (BT-5) ":value" is not an ISO 4217 currency code.',
    ],
    'BR-CL-05' => [
        'message' => 'The VAT accounting currency code (BT-6) ":value" is not an ISO 4217 currency code.',
    ],
    'BR-CL-07' => [
        'message' => 'The object identifier scheme ":value" is not part of UNTDID 1153.',
    ],
    'BR-CL-08' => [
        'message' => 'The invoice note subject code (BT-21) ":value" is not part of UNTDID 4451.',
    ],
    'BR-CL-10' => [
        'message' => 'The identifier scheme ":value" is not part of ISO/IEC 6523.',
    ],
    'BR-CL-11' => [
        'message' => 'The legal registration identifier scheme ":value" is not part of ISO/IEC 6523.',
    ],
    'BR-CL-13' => [
        'message' => 'The item classification scheme ":value" is not part of UNTDID 7143.',
    ],
    'BR-CL-14' => [
        'message' => 'The country code ":value" is not an ISO 3166-1 alpha-2 code.',
    ],
    'BR-CL-16' => [
        'message' => 'The payment means code (BT-81) ":value" is not part of UNTDID 4461.',
    ],
    'BR-CL-19' => [
        'message' => 'The allowance reason code ":value" is not part of UNTDID 5189.',
    ],
    'BR-CL-20' => [
        'message' => 'The charge reason code ":value" is not part of UNTDID 7161.',
    ],
    'BR-CL-21' => [
        'message' => 'The item standard identifier scheme ":value" is not part of ISO/IEC 6523.',
    ],
    'BR-CL-22' => [
        'message' => 'The VAT exemption reason code (BT-121) ":value" is not part of the VATEX code list.',
    ],
    'BR-CL-23' => [
        'message' => 'The unit code ":value" is not part of UN/ECE Recommendation 20 or 21.',
    ],
    'BR-CL-25' => [
        'message' => 'The electronic address scheme ":value" is not part of the EAS code list.',
    ],
    'BR-CL-26' => [
        'message' => 'The delivery location identifier scheme ":value" is not part of ISO/IEC 6523.',
    ],
    'QUITTIO-NEGATIVE-TOTAL' => [
        'message' => 'The amount due is negative. Consider issuing a credit note (type code 381) with positive amounts instead.',
        'hint' => 'Use ->type(InvoiceTypeCode::CreditNote) and positive quantities.',
    ],
    'QUITTIO-SUBLINES' => [
        'message' => 'The net amount of line :line must equal the sum of its sub lines (:expected).',
    ],
    'QUITTIO-LINE-ID-DUPLICATE' => [
        'message' => 'The line identifier :line (BT-126) is used more than once. Lines cannot be referred to unambiguously, e.g. in partial cancellations.',
        'hint' => 'Leave out ->id() to let the builder number the lines, or give every line its own identifier.',
    ],
    'QUITTIO-SUBLINE-VAT' => [
        'message' => 'Sub line :line uses VAT category and rate :value, its parent line uses :expected. Sub lines must have the VAT category and rate of their parent line, because the VAT breakdown is calculated from the parent lines.',
        'hint' => 'Split bundles with different VAT rates into separate lines.',
    ],
    'QUITTIO-PRECEDING-INVOICES-CII' => [
        'message' => 'The invoice refers to :value preceding invoices (BG-3). CII (ZUGFeRD/Factur-X, XRechnung CII) transmits only the first reference; UBL transmits all of them.',
    ],
    'QUITTIO-EXEMPTION-CONFLICT' => [
        'message' => 'Lines with VAT category :category and the same rate use different exemption reason codes (:value). A VAT breakdown carries only one code (BT-121): it is omitted and the reason texts are joined in BT-120. Issue separate invoices if the recipient needs the codes.',
    ],
    'QUITTIO-DECLARED-AMOUNTS' => [
        'message' => 'The declared line net amounts (BT-131) or VAT category tax amounts (BT-117) of the original document differ from the recalculation by more than tolerated (BT-131: 0.02, PEPPOL-EN16931-R120; BT-117: less than one currency unit, BR-CO-17) and were recalculated. The document may not reverse the original exactly; compare its totals with the original.',
    ],
    'QUITTIO-THIRD-PARTY-PAYMENTS' => [
        'message' => 'Third party payments (BG-DEX-09) can only be transmitted in an XRechnung UBL invoice (XRechnung extension). In CII, in credit notes and in other profiles the amount due (BT-115) would contradict BR-CO-16. Use XRechnung UBL with an invoice type code (cancellations: strategy negative_invoice) or invoice the amounts as lines.',
    ],
    'QUITTIO-VAT-ROUNDING' => [
        'message' => 'The VAT amount of category :category (:rate %) differs from taxable amount × rate by :value. The official rules tolerate this, but the amount is not rounded commercially.',
    ],
    'QUITTIO-CANCELLATION-VAT-ALIGNED' => [
        'message' => 'The VAT amount of category :category (:rate %) differs from taxable amount × rate by :value: it is aligned with the earlier partial cancellations of the original, so that all cancellations together reverse exactly its VAT (cumulative rounding, at most 0.01, within the tolerance of BR-CO-17).',
    ],
    'QUITTIO-LINE-ROUNDING' => [
        'message' => 'The net amount of line :line differs from quantity × price ± allowances and charges by :value, e.g. rounded by another system and kept from the original document. EN 16931 does not check this (Peppol tolerates 0.02), but the amount is not rounded commercially.',
    ],
    'QUITTIO-ATTACHMENT-FILENAME' => [
        'message' => 'The file name ":value" of an attached document (BT-125-2) is used more than once.',
    ],
    'QUITTIO-ATTACHMENT-SIZE' => [
        'message' => 'Attached documents exceed the size limit (:value bytes). Receiving portals reject large invoices.',
    ],
    'QUITTIO-INVALID-CHARACTERS' => [
        'message' => 'The text in :value contains control characters or invalid UTF-8, which XML cannot represent.',
        'hint' => 'Remove control characters such as vertical tabs from text copied out of spreadsheets, and pass text in UTF-8.',
    ],
    'QUITTIO-GENERIC-COUNTRY' => [
        'message' => 'No country pack is installed for the seller country :value. Only EN 16931 rules are checked; national mandatory information is not validated.',
        'hint' => 'Install the country pack for the seller country, e.g. composer require quittio/country-de.',
    ],
    'QUITTIO-PROFILE-NOT-EINVOICE' => [
        'message' => 'The profile :value does not produce an e-invoice according to EN 16931 and is accepted only as an explicit choice.',
    ],
    'QUITTIO-STANDARD-VERSION' => [
        'message' => 'The profile :value does not support the EN 16931 version of the invoice.',
    ],
    'QUITTIO-IBAN' => [
        'message' => 'The IBAN ":value" has an invalid check digit or format.',
        'hint' => 'Check the IBAN for typing errors.',
    ],
    'QUITTIO-BIC' => [
        'message' => 'The BIC ":value" has an invalid format.',
    ],
    'QUITTIO-VATID-FORMAT' => [
        'message' => 'The VAT identifier ":value" does not match the format of its country.',
        'hint' => 'Check the VAT identifier for typing errors.',
    ],
    'QUITTIO-EMAIL' => [
        'message' => 'The e-mail address ":value" is not valid.',
    ],
    'QUITTIO-DUE-DATE' => [
        'message' => 'The payment due date (BT-9) lies before the invoice issue date (BT-2).',
    ],
    'QUITTIO-VIES' => [
        'message' => 'The VAT identifier ":value" is not valid according to VIES.',
    ],
    'QUITTIO-VIES-UNAVAILABLE' => [
        'message' => 'The VAT identifier ":value" could not be checked because VIES is not reachable.',
    ],
    'PEPPOL-EN16931-R001' => [
        'message' => 'The business process (BT-23) must be provided.',
    ],
    'PEPPOL-EN16931-R002' => [
        'message' => 'No more than one note is allowed on document level, unless both buyer and seller are German organisations.',
    ],
    'PEPPOL-EN16931-R003' => [
        'message' => 'A buyer reference (BT-10) or a purchase order reference (BT-13) must be provided.',
        'hint' => 'Set ->buyerReference() or ->purchaseOrderReference().',
    ],
    'PEPPOL-EN16931-R005' => [
        'message' => 'The VAT accounting currency code (BT-6) must be different from the invoice currency code (BT-5) when provided. BT-6 is therefore not written.',
        'hint' => 'Set ->taxCurrency() only for invoices in a foreign currency.',
    ],
    'PEPPOL-EN16931-R010' => [
        'message' => 'The buyer electronic address (BT-49) must be provided.',
        'hint' => 'Set the buyer\'s Peppol participant identifier with ->electronicAddress(value, scheme).',
    ],
    'PEPPOL-EN16931-R020' => [
        'message' => 'The seller electronic address (BT-34) must be provided.',
        'hint' => 'Set the seller\'s Peppol participant identifier with ->electronicAddress(value, scheme).',
    ],
    'PEPPOL-EN16931-R040' => [
        'message' => 'An allowance or charge amount must equal base amount × percentage / 100 if base amount and percentage are given.',
    ],
    'PEPPOL-EN16931-R041' => [
        'message' => 'An allowance or charge base amount must be provided when a percentage is provided.',
    ],
    'PEPPOL-EN16931-R042' => [
        'message' => 'An allowance or charge percentage must be provided when a base amount is provided.',
    ],
    'PEPPOL-EN16931-R061' => [
        'message' => 'The mandate reference (BT-89) must be provided for direct debit.',
    ],
    'PEPPOL-EN16931-R120' => [
        'message' => 'The invoice line net amount of line :line must equal quantity × (net price / base quantity) + charges − allowances (tolerance 0.02).',
    ],
    'PEPPOL-EN16931-R121' => [
        'message' => 'The base quantity (BT-149) of line :line must be a positive number above zero.',
    ],
    'PEPPOL-EN16931-R130' => [
        'message' => 'The unit code of the price base quantity (BT-150) of line :line must be the same as the invoiced quantity unit (BT-130).',
    ],
    'QUITTIO-PROFILE-REQUIRED' => [
        'message' => 'The field :field (:bt) is mandatory in profile :profile.',
        'hint' => 'Provide the field or choose a profile that does not require it.',
    ],
    'QUITTIO-PROFILE-NOT-ALLOWED' => [
        'message' => 'The field :field (:bt) is not part of profile :profile and will not be written to the XML.',
        'hint' => 'Choose a profile that contains the field (e.g. EN 16931) or remove it.',
    ],
    'QUITTIO-PROFILE-EXTENDED-ONLY' => [
        'message' => 'The field :field is available only in profile EXTENDED, not in :profile.',
        'hint' => 'Use ->profile(\'extended\') or remove the EXTENDED data.',
    ],
    'QUITTIO-PROFILE-XRECHNUNG-EXTENSION-ONLY' => [
        'message' => 'The field :field is available only in the XRechnung extension, not in :profile.',
        'hint' => 'Use the profile xrechnung-extension or remove the extension data.',
    ],
    'QUITTIO-PROFILE-SUBLINES' => [
        'message' => 'Sub lines are supported only in the profiles EXTENDED and XRechnung extension, not in :profile.',
        'hint' => 'Use the profile EXTENDED or the XRechnung extension, or flatten the sub lines.',
    ],
    'BR-CL-06' => [
        'message' => 'The VAT point date code (BT-8) ":value" is not part of UNTDID 2005 (3 issue date, 35 delivery date, 432 payment date).',
    ],
    'QUITTIO-PROFORMA' => [
        'message' => 'Type code 325 (proforma invoice) is not an invoice. Use DocumentBuilder::proforma() for proforma documents; e-invoice formats must not carry a proforma.',
        'hint' => 'A proforma invoice is not an invoice for tax purposes. As e-invoice XML with VAT amounts the recipient would book it as an invoice.',
    ],
    'QUITTIO-VAT-K-DOMESTIC' => [
        'message' => 'VAT category "K" (intra-community supply) is used, but the destination :value lies in the seller\'s member state :expected. An intra-community supply requires a destination in another EU member state.',
    ],
    'QUITTIO-VAT-K-OUTSIDE-EU' => [
        'message' => 'VAT category "K" (intra-community supply) is used, but the destination :value lies outside the EU VAT territory. Supplies to third countries are exports (category "G") or not taxable in the EU (category "O").',
    ],
    'QUITTIO-VAT-G-INSIDE-EU' => [
        'message' => 'VAT category "G" (export) is used, but the destination :value lies in the EU VAT territory. Supplies within the EU are intra-community supplies (category "K") or taxable in the member state.',
    ],
];
