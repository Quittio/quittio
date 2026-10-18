<?php

declare(strict_types=1);

// Legal and informational texts of German invoices. To be confirmed by a tax adviser before the first release.
// Override them in lang/vendor/quittio-de/en/legal.php.
return [
    'self_billing' => 'Gutschrift (self-billed invoice)',
    'property_retention' => 'You are required by law to keep this invoice, a payment receipt or other conclusive document for two years (section 14b (1) sentence 5 UStG).',
    'prepayment' => 'VAT becomes due on receipt of the prepayment. The service is expected to be performed on :date.',
    'service_date_open' => 'The date of the service has not been agreed yet.',
    'advance_deduction' => 'Less advance payment according to invoice :number of :date: :amounts (section 14 (5) sentence 2 UStG).',
    'advance_deduction_taxed' => 'net :net plus :rate VAT :vat',
    'advance_deduction_untaxed' => 'net :net without VAT',
    'consumer_default' => 'You will be in default at the latest if you do not pay within 30 days of the due date and receipt of this invoice (section 286 (3) BGB).',
    'new_vehicle' => 'Intra-Community supply of a new means of transport (section 14a (4) UStG); the vehicle details under section 1b (2) and (3) UStG are stated with the line.',
    'default_interest' => 'Default interest :rate % p.a. (base rate :base_rate % + :points points) from :from to :until',
];
