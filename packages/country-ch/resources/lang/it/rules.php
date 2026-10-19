<?php

declare(strict_types=1);

// Messages of the MWSTG rules of the Swiss country pack. Not reviewed by a tax adviser yet.
return [
    'CH-MWSTG-01' => [
        'message' => 'Devono essere indicati il nome e il luogo del fornitore della prestazione (BT-37) (art. 26 cpv. 2 lett. a LIVA).',
    ],
    'CH-MWSTG-02' => [
        'message' => 'Una fattura con IVA deve indicare il numero IDI con cui il fornitore è iscritto nel registro dei contribuenti (BT-31, art. 26 cpv. 2 lett. a LIVA).',
        'hint' => 'Indicare ->vatId(\'CHE-123.456.789 IVA\') per il venditore.',
    ],
    'CH-MWSTG-03' => [
        'message' => 'Il numero IDI dovrebbe recare l\'indicazione dell\'iscrizione all\'IVA, p. es. «CHE-123.456.789 IVA» (art. 26 cpv. 2 lett. a LIVA).',
    ],
    'CH-MWSTG-04' => [
        'message' => 'Devono essere indicati il nome e il luogo del destinatario della prestazione (BT-52) (art. 26 cpv. 2 lett. b LIVA); solo gli scontrini di cassa fino a CHF 400 possono ometterli (art. 57 OIVA).',
    ],
    'CH-MWSTG-05' => [
        'message' => 'Chi non è iscritto nel registro dei contribuenti non può indicare l\'IVA; l\'imposta indicata è dovuta in base alla fattura (art. 27 cpv. 2 LIVA).',
    ],
];
