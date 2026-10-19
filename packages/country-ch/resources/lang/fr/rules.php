<?php

declare(strict_types=1);

// Messages of the MWSTG rules of the Swiss country pack. Not reviewed by a tax adviser yet.
return [
    'CH-MWSTG-01' => [
        'message' => 'Le nom et le lieu du fournisseur de la prestation (BT-37) doivent être indiqués (art. 26, al. 2, let. a, LTVA).',
    ],
    'CH-MWSTG-02' => [
        'message' => 'Une facture avec TVA doit indiquer le numéro IDE sous lequel le fournisseur est inscrit au registre des assujettis (BT-31, art. 26, al. 2, let. a, LTVA).',
        'hint' => 'Indiquez ->vatId(\'CHE-123.456.789 TVA\') pour le vendeur.',
    ],
    'CH-MWSTG-03' => [
        'message' => 'Le numéro IDE devrait porter la mention de l\'inscription à la TVA, p. ex. « CHE-123.456.789 TVA » (art. 26, al. 2, let. a, LTVA).',
    ],
    'CH-MWSTG-04' => [
        'message' => 'Le nom et le lieu du destinataire de la prestation (BT-52) doivent être indiqués (art. 26, al. 2, let. b, LTVA) ; seuls les tickets de caisse jusqu\'à CHF 400 peuvent les omettre (art. 57 OTVA).',
    ],
    'CH-MWSTG-05' => [
        'message' => 'Une personne non inscrite au registre des assujettis ne doit pas faire figurer la TVA ; l\'impôt indiqué est dû en vertu de la facture (art. 27, al. 2, LTVA).',
    ],
];
