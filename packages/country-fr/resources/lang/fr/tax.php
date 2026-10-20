<?php

declare(strict_types=1);

// Exemption reason texts of the French tax presets (BT-120). Not reviewed by a tax adviser yet.
return [
    'fr.small_business' => 'TVA non applicable, art. 293 B du CGI',
    'fr.reverse_charge_domestic' => 'Autoliquidation',
    'fr.reverse_charge_eu' => 'Autoliquidation',
    'fr.intra_community_supply' => 'Exonération de TVA, article 262 ter I du CGI',
    'fr.export' => 'Exonération de TVA, article 262 I du CGI',
    'fr.not_taxable' => 'TVA non applicable en France (lieu de la prestation hors de France)',
    'fr.margin_second_hand' => 'Régime particulier – Biens d\'occasion',
    'fr.margin_art' => 'Régime particulier – Objets d\'art',
    'fr.margin_antiques' => 'Régime particulier – Objets de collection et d\'antiquité',
    'fr.travel_agency' => 'Régime particulier – Agences de voyages',
    'fr.exempt' => 'Exonération de TVA, :paragraph',
    // Invoices issued from 1 January 2027 cite the CIBS (ordonnance n° 2026-671).
    'fr.small_business_cibs' => 'TVA non applicable, art. L. 233-3 du CIBS',
    'fr.intra_community_supply_cibs' => 'Exonération de TVA, article L. 213-12, 1° du CIBS',
    'fr.export_cibs' => 'Exonération de TVA, article L. 213-12, 2° du CIBS',
];
