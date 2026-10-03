<?php

declare(strict_types=1);

// Exemption reason texts of the German tax presets (BT-120). To be confirmed by a tax adviser.
return [
    'de.zero_rated_pv' => 'Zero rate according to section 12 (3) UStG',
    'de.small_business' => 'VAT-exempt supply under section 19 (1) UStG (small business exemption).',
    'de.reverse_charge_domestic' => 'Reverse charge, the recipient is liable for VAT (section 13b UStG)',
    'de.reverse_charge_eu' => 'Reverse charge, the recipient is liable for VAT',
    'de.intra_community_supply' => 'Tax-exempt intra-Community supply (section 4 no. 1b in conjunction with section 6a UStG)',
    'de.export' => 'Tax-exempt export delivery (section 4 no. 1a in conjunction with section 6 UStG)',
    'de.not_taxable' => 'Service not taxable in Germany',
    'de.margin_second_hand' => 'Second-hand goods/special arrangements',
    'de.margin_art' => 'Works of art/special arrangements',
    'de.margin_antiques' => 'Collectors’ items and antiques/special arrangements',
    'de.travel_agency' => 'Special arrangements for travel agents',
];
