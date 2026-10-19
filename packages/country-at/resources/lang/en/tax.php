<?php

declare(strict_types=1);

// Exemption reason texts of the Austrian tax presets (BT-120). Not reviewed by a tax adviser yet.
return [
    'at.small_business' => 'VAT exempt under the small business scheme (section 6 (1) no. 27 UStG).',
    'at.reverse_charge_domestic' => 'Reverse charge: the recipient owes the VAT (section 19 UStG)',
    'at.reverse_charge_eu' => 'Reverse charge: the recipient owes the VAT',
    'at.intra_community_supply' => 'VAT exempt intra-community supply (Art. 6 (1) with Art. 7 UStG)',
    'at.triangular_transaction' => 'Intra-community triangular transaction under Art. 25 UStG; the VAT is owed by the last customer',
    'at.export' => 'VAT exempt export supply (section 6 (1) no. 1 with section 7 UStG)',
    'at.not_taxable' => 'Not taxable in Austria (place of supply abroad)',
    'at.margin_second_hand' => 'Margin scheme – Second-hand goods',
    'at.margin_art' => 'Margin scheme – Works of art',
    'at.margin_antiques' => 'Margin scheme – Collector\'s items and antiques',
    'at.travel_agency' => 'Margin scheme – Travel agents (section 23 UStG)',
    'at.exempt' => 'VAT exempt under :paragraph',
];
