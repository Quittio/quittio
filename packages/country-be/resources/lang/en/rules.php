<?php

declare(strict_types=1);

// Messages of the rules of the Belgian country pack. Not reviewed by a tax adviser yet.
return [
    'BE-VAT-01' => [
        'message' => 'The seller\'s VAT number (BT-31) must be stated (Art. 5 § 1, 3° Royal Decree No. 1).',
        'hint' => 'Set ->vatId(\'BE0123456749\') on the seller.',
    ],
    'BE-VAT-02' => [
        'message' => 'The name and address of the seller and of the buyer must be complete: street, post code and city (Art. 5 § 1, 5° Royal Decree No. 1).',
    ],
    'BE-VAT-03' => [
        'message' => 'An invoice to a business in Belgium should state the buyer\'s VAT number (BT-48) if the buyer files VAT returns (Art. 5 § 1, 4° Royal Decree No. 1).',
    ],
    'BE-VAT-04' => [
        'message' => 'A reverse charge invoice must state "Verlegging van heffing" or "Autoliquidation" (Art. 5 § 1, 10° Royal Decree No. 1).',
        'hint' => 'Use VatPreset::ReverseChargeDomestic or VatPreset::ReverseChargeEu.',
    ],
    'BE-VAT-05' => [
        'message' => 'An exemption needs the reference to the provision as text (BT-120); a code alone is not enough (Art. 5 § 1, 10° Royal Decree No. 1).',
    ],
    'BE-VAT-06' => [
        'message' => 'A small business under the exemption scheme (Art. 56bis VAT Code) must not charge VAT.',
        'hint' => 'Use VatPreset::SmallBusiness on every line.',
    ],
    'BE-VAT-07' => [
        'message' => 'A self-billed invoice must state "self-billing", "autofacturation" or "zelffacturering" (Art. 5 § 1, 12° Royal Decree No. 1).',
    ],
    'BE-VAT-08' => [
        'message' => 'The invoice must be issued by the 15th day of the month following the supply (Art. 53 § 2 VAT Code); the deadline was :deadline.',
    ],
    'BE-LANG-01' => [
        'message' => 'A business in the Dutch, French or German language region should issue its invoices to Belgian customers in the language of the region (:language; language decrees of the Flemish and French Communities, for the German language region Art. 52 of the laws on the use of languages in administrative matters of 18 July 1966); the invoice is in :locale.',
        'hint' => 'Set ->locale(\'nl_BE\') for a business in Flanders, ->locale(\'fr_BE\') in Wallonia.',
    ],
];
