<?php

declare(strict_types=1);

// Exemption reason texts of the German tax presets (BT-120). To be confirmed by a tax adviser.
return [
    'de.small_business' => 'VAT-exempt supply under section 19 (1) UStG (small business exemption).',
    'de.reverse_charge_domestic' => 'Reverse charge, the recipient is liable for VAT (section 13b UStG)',
    'de.reverse_charge_eu' => 'Reverse charge, the recipient is liable for VAT',
    'de.intra_community_supply' => 'VAT-exempt intra-Community supply (section 4 no. 1b in conjunction with section 6a UStG)',
    'de.triangular_transaction' => 'Intra-Community triangular transaction (section 25b UStG); the last customer is liable for the VAT',
    'de.export' => 'VAT-exempt export supply (section 4 no. 1a in conjunction with section 6 UStG)',
    'de.not_taxable' => 'Service not taxable in Germany (place of supply abroad)',
    'de.business_transfer' => 'Transfer of a business as a whole, not subject to VAT (section 1 (1a) UStG).',
    'de.margin_second_hand' => 'Second-hand goods/special arrangements',
    'de.margin_art' => 'Works of art/special arrangements',
    'de.margin_antiques' => 'Collectors’ items and antiques/special arrangements',
    'de.travel_agency' => 'Special arrangements for travel agents',
    'de.exempt' => 'Exempt from VAT according to :paragraph',
];
