<?php

declare(strict_types=1);

// Messages of the rules of the French country pack. Not reviewed by a tax adviser yet.
return [
    'FR-CGI-01' => [
        'message' => 'The seller\'s SIREN or SIRET (BT-30, scheme 0002 or 0009) must be stated (art. 242 nonies A annex II CGI, art. R123-237 Code de commerce).',
        'hint' => 'Set ->legalRegistration(\'732829320\', \'0002\') on the seller.',
    ],
    'FR-CGI-02' => [
        'message' => 'The seller\'s VAT number (BT-31) must be stated (art. 242 nonies A annex II CGI); only small businesses under the franchise do without.',
    ],
    'FR-CGI-03' => [
        'message' => 'The name and address of the seller and of the buyer must be complete: street, post code and city (art. 242 nonies A annex II CGI).',
    ],
    'FR-CGI-04' => [
        'message' => 'A reverse charge invoice must state "Autoliquidation" (art. 242 nonies A annex II CGI).',
        'hint' => 'Use VatPreset::ReverseChargeDomestic or VatPreset::ReverseChargeEu.',
    ],
    'FR-CGI-05' => [
        'message' => 'An exemption needs the reference to the provision as text (BT-120); a code alone is not enough (art. 242 nonies A annex II CGI).',
    ],
    'FR-CGI-06' => [
        'message' => 'A small business under the franchise (art. 293 B CGI) must not charge VAT; VAT shown is owed by reason of the invoice (art. 283-3 CGI).',
        'hint' => 'Use VatPreset::SmallBusiness on every line.',
    ],
    'FR-CGI-07' => [
        'message' => 'A self-billed invoice must state "Autofacturation" (art. 242 nonies A annex II CGI).',
    ],
    'FR-CGI-08' => [
        'message' => 'From 1 September 2026, an invoice to a business established in France states the buyer\'s SIREN (BT-47, scheme 0002) (art. 242 nonies A annex II CGI).',
        'hint' => 'Set ->legalRegistration(…, \'0002\') on the buyer.',
    ],
    'FR-CGI-09' => [
        'message' => 'From 1 September 2026, the invoice states the category of the operation: supply of goods, services or both (art. 242 nonies A annex II CGI), as operation category (BT-23) B1, S1 or M1.',
        'hint' => 'Set ->businessProcess(\'S1\') for services, \'B1\' for goods, \'M1\' for both.',
    ],
    'FR-COM-01' => [
        'message' => 'An invoice between businesses must state the due date (BT-9) or the payment terms (BT-20) (art. L441-9 Code de commerce).',
    ],
    'FR-COM-02' => [
        'message' => 'An invoice between businesses must state the late payment penalties, the fixed indemnity of 40 EUR for recovery costs and the conditions of a discount for early payment (art. L441-9 Code de commerce).',
    ],
];
