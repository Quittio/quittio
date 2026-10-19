<?php

declare(strict_types=1);

// Exemption reason texts of the German tax presets (BT-120). Confirmed by a tax adviser in October 2026.
return [
    'de.small_business' => 'Steuerfreie Leistung nach § 19 Abs. 1 UStG (Steuerbefreiung für Kleinunternehmer).',
    'de.reverse_charge_domestic' => 'Steuerschuldnerschaft des Leistungsempfängers (§ 13b UStG)',
    'de.reverse_charge_eu' => 'Steuerschuldnerschaft des Leistungsempfängers',
    'de.intra_community_supply' => 'Steuerfreie innergemeinschaftliche Lieferung (§ 4 Nr. 1b i. V. m. § 6a UStG)',
    'de.triangular_transaction' => 'Innergemeinschaftliches Dreiecksgeschäft nach § 25b UStG; Steuerschuldnerschaft des letzten Abnehmers',
    'de.export' => 'Steuerfreie Ausfuhrlieferung (§ 4 Nr. 1a i. V. m. § 6 UStG)',
    'de.not_taxable' => 'Nicht im Inland steuerbare Leistung (Leistungsort im Ausland)',
    'de.business_transfer' => 'Nicht steuerbare Geschäftsveräußerung im Ganzen (§ 1 Abs. 1a UStG).',
    'de.margin_second_hand' => 'Gebrauchtgegenstände/Sonderregelung',
    'de.margin_art' => 'Kunstgegenstände/Sonderregelung',
    'de.margin_antiques' => 'Sammlungsstücke und Antiquitäten/Sonderregelung',
    'de.travel_agency' => 'Sonderregelung für Reisebüros',
    'de.exempt' => 'Steuerfrei nach :paragraph',
];
