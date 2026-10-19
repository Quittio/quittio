<?php

declare(strict_types=1);

// Messages of the MWSTG rules of the Swiss country pack. Not reviewed by a tax adviser yet.
return [
    'CH-MWSTG-01' => [
        'message' => 'Name und Ort des Leistungserbringers (BT-37) müssen angegeben sein (Art. 26 Abs. 2 Bst. a MWSTG).',
        'hint' => 'Ergänzen Sie die Anschrift mit ->address(Strasse, PLZ, Ort, Land) beim Verkäufer.',
    ],
    'CH-MWSTG-02' => [
        'message' => 'Eine Rechnung mit Mehrwertsteuer muss die UID-Nummer enthalten, unter der der Leistungserbringer im MWST-Register eingetragen ist (BT-31, Art. 26 Abs. 2 Bst. a MWSTG).',
        'hint' => 'Setzen Sie ->vatId(\'CHE-123.456.789 MWST\') beim Verkäufer.',
    ],
    'CH-MWSTG-03' => [
        'message' => 'Die UID-Nummer sollte den Hinweis auf die MWST-Registrierung tragen, z. B. „CHE-123.456.789 MWST“ (Art. 26 Abs. 2 Bst. a MWSTG).',
    ],
    'CH-MWSTG-04' => [
        'message' => 'Name und Ort des Leistungsempfängers (BT-52) müssen angegeben sein (Art. 26 Abs. 2 Bst. b MWSTG); nur Kassenzettel bis CHF 400 dürfen sie weglassen (Art. 57 MWSTV).',
        'hint' => 'Ergänzen Sie die Anschrift mit ->address(Strasse, PLZ, Ort, Land) beim Käufer.',
    ],
    'CH-MWSTG-05' => [
        'message' => 'Wer nicht im MWST-Register eingetragen ist, darf keine Mehrwertsteuer ausweisen; ausgewiesene Steuer wird aufgrund der Rechnung geschuldet (Art. 27 Abs. 2 MWSTG).',
        'hint' => 'Verwenden Sie VatPreset::NotRegistered für alle Positionen.',
    ],
];
