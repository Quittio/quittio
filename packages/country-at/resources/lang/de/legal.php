<?php

declare(strict_types=1);

// Legal and informational texts of Austrian invoices. Not reviewed by a tax adviser yet.
// Override them in lang/vendor/quittio-at/de/legal.php.
return [
    'self_billing' => 'Gutschrift',
    'default_interest' => 'Verzugszinsen :rate % p. a. (Basiszinssatz :base_rate % + :points Prozentpunkte, § 456 UGB) vom :from bis :until',
    'default_interest_consumer' => 'Verzugszinsen :rate % p. a. (§ 1000 Abs. 1 ABGB) vom :from bis :until',
];
