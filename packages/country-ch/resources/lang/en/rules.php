<?php

declare(strict_types=1);

// Messages of the MWSTG rules of the Swiss country pack. Not reviewed by a tax adviser yet.
return [
    'CH-MWSTG-01' => [
        'message' => 'The seller\'s name and place (BT-37) must be stated (Art. 26 (2) lit. a MWSTG).',
        'hint' => 'Add the address with ->address(street, post code, city, country) on the seller.',
    ],
    'CH-MWSTG-02' => [
        'message' => 'An invoice that charges VAT must state the seller\'s UID number under which it is registered for VAT (BT-31, Art. 26 (2) lit. a MWSTG).',
        'hint' => 'Set ->vatId(\'CHE-123.456.789 MWST\') on the seller.',
    ],
    'CH-MWSTG-03' => [
        'message' => 'The UID number should carry the note of the VAT registration, e.g. "CHE-123.456.789 MWST" (Art. 26 (2) lit. a MWSTG).',
    ],
    'CH-MWSTG-04' => [
        'message' => 'The buyer\'s name and place (BT-52) must be stated (Art. 26 (2) lit. b MWSTG); only till receipts up to CHF 400 may leave them out (Art. 57 MWSTV).',
        'hint' => 'Add the address with ->address(street, post code, city, country) on the buyer.',
    ],
    'CH-MWSTG-05' => [
        'message' => 'A seller that is not registered for VAT must not charge VAT; VAT shown is owed by reason of the invoice (Art. 27 (2) MWSTG).',
        'hint' => 'Use VatPreset::NotRegistered on every line.',
    ],
];
