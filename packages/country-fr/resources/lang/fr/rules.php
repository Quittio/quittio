<?php

declare(strict_types=1);

// Messages of the rules of the French country pack. Not reviewed by a tax adviser yet.
return [
    'FR-CGI-01' => [
        'message' => 'Le SIREN ou le SIRET du vendeur (BT-30, schéma 0002 ou 0009) doit être mentionné (art. 242 nonies A de l\'annexe II au CGI, art. R123-237 du Code de commerce).',
        'hint' => 'Indiquez ->legalRegistration(\'732829320\', \'0002\') pour le vendeur.',
    ],
    'FR-CGI-02' => [
        'message' => 'Le numéro de TVA intracommunautaire du vendeur (BT-31) doit être mentionné (art. 242 nonies A de l\'annexe II au CGI) ; seules les entreprises en franchise en base en sont dispensées.',
    ],
    'FR-CGI-03' => [
        'message' => 'Le nom et l\'adresse du vendeur et de l\'acheteur doivent être complets : rue, code postal et ville (art. 242 nonies A de l\'annexe II au CGI).',
    ],
    'FR-CGI-04' => [
        'message' => 'Une facture en autoliquidation doit porter la mention « Autoliquidation » (art. 242 nonies A de l\'annexe II au CGI).',
        'hint' => 'Utilisez VatPreset::ReverseChargeDomestic ou VatPreset::ReverseChargeEu.',
    ],
    'FR-CGI-05' => [
        'message' => 'Une exonération nécessite la référence à la disposition sous forme de texte (BT-120) ; un code seul ne suffit pas (art. 242 nonies A de l\'annexe II au CGI).',
    ],
    'FR-CGI-06' => [
        'message' => 'Une entreprise en franchise en base (art. 293 B du CGI) ne peut pas facturer la TVA ; la TVA mentionnée est due du seul fait de sa facturation (art. 283-3 du CGI).',
        'hint' => 'Utilisez VatPreset::SmallBusiness pour toutes les lignes.',
    ],
    'FR-CGI-07' => [
        'message' => 'Une facture établie par l\'acheteur doit porter la mention « Autofacturation » (art. 242 nonies A de l\'annexe II au CGI).',
    ],
    'FR-CGI-08' => [
        'message' => 'À partir du 1er septembre 2026, une facture à une entreprise établie en France mentionne le SIREN du client (BT-47, schéma 0002) (art. 242 nonies A de l\'annexe II au CGI).',
        'hint' => 'Indiquez ->legalRegistration(…, \'0002\') pour l\'acheteur.',
    ],
    'FR-CGI-09' => [
        'message' => 'À partir du 1er septembre 2026, la facture indique la catégorie de l\'opération : livraison de biens, prestation de services ou les deux (art. 242 nonies A de l\'annexe II au CGI), comme cadre de facturation (BT-23) B1, S1 ou M1.',
        'hint' => 'Indiquez ->businessProcess(\'S1\') pour des prestations de services.',
    ],
    'FR-COM-01' => [
        'message' => 'Une facture entre professionnels doit mentionner la date d\'échéance (BT-9) ou les conditions de paiement (BT-20) (art. L441-9 du Code de commerce).',
    ],
    'FR-COM-02' => [
        'message' => 'Une facture entre professionnels doit mentionner les pénalités de retard, l\'indemnité forfaitaire de 40 € pour frais de recouvrement et les conditions d\'escompte (art. L441-9 du Code de commerce).',
    ],
];
