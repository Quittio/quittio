<?php

declare(strict_types=1);

// Exemption reason texts of the French tax presets (BT-120). Not reviewed by a tax adviser yet.
return [
    'fr.small_business' => 'VAT not applicable, art. 293 B of the French Tax Code (CGI)',
    'fr.reverse_charge_domestic' => 'Reverse charge (autoliquidation)',
    'fr.reverse_charge_eu' => 'Reverse charge (autoliquidation)',
    'fr.intra_community_supply' => 'VAT exempt, article 262 ter I CGI',
    'fr.export' => 'VAT exempt, article 262 I CGI',
    'fr.not_taxable' => 'Not subject to French VAT (place of supply outside France)',
    'fr.margin_second_hand' => 'Margin scheme – Second-hand goods',
    'fr.margin_art' => 'Margin scheme – Works of art',
    'fr.margin_antiques' => 'Margin scheme – Collector\'s items and antiques',
    'fr.travel_agency' => 'Margin scheme – Travel agents',
    'fr.exempt' => 'VAT exempt, :paragraph',
];
