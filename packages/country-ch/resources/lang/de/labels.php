<?php

declare(strict_types=1);

// Labels of the core (quittio::labels) in the words used in Switzerland (MWST, UID).
// Override them in lang/vendor/quittio-ch/de/labels.php.
return [
    'vat_rate' => 'MWST',
    'vat_row' => ':rate MWST auf :base',
    'vat_row_single' => 'MWST :rate',
    'vat_row_exempt' => 'Von der Steuer ausgenommen oder befreit (:category) auf :base',
    'vat_row_reverse_charge' => 'Keine MWST (Bezugsteuer beim Leistungsempfänger) auf :base',
    'vat_row_zero_rated' => '0 % MWST auf :base',
    'allowance_vat_rate' => ':rate MWST',
    'vat_id' => 'MWST-Nr.',
    'your_vat_id' => 'Ihre MWST-Nr.',
    'bonus_vat_row' => 'Minderung MWST :rate',
    'bonus_vat_row_untaxed' => 'Minderung ohne MWST (:category)',
];
