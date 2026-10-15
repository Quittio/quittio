<?php

declare(strict_types=1);

// Legal and informational texts of German invoices. To be confirmed by a tax adviser before the first release.
// Override them in lang/vendor/quittio-de/de/legal.php.
return [
    'self_billing' => 'Gutschrift',
    'property_retention' => 'Sie sind gesetzlich verpflichtet, diese Rechnung, einen Zahlungsbeleg oder eine andere beweiskräftige Unterlage zwei Jahre lang aufzubewahren (§ 14b Abs. 1 Satz 5 UStG).',
    'prepayment' => 'Die Umsatzsteuer entsteht mit Vereinnahmung der Anzahlung. Die Leistung wird voraussichtlich am :date erbracht.',
    'advance_deduction' => 'Abzüglich Anzahlung gemäß Rechnung :number vom :date: :amounts (§ 14 Abs. 5 Satz 2 UStG).',
    'advance_deduction_taxed' => 'Entgelt :net zzgl. :rate Umsatzsteuer :vat',
    'advance_deduction_untaxed' => 'Entgelt :net ohne Umsatzsteuer',
    'consumer_default' => 'Sie kommen spätestens in Verzug, wenn Sie nicht innerhalb von 30 Tagen nach Fälligkeit und Zugang dieser Rechnung zahlen (§ 286 Abs. 3 BGB).',
    'new_vehicle' => 'Innergemeinschaftliche Lieferung eines neuen Fahrzeugs (§ 14a Abs. 4 UStG); die Fahrzeugmerkmale nach § 1b Abs. 2 und 3 UStG sind bei der Position angegeben.',
    'default_interest' => 'Verzugszinsen :rate % p. a. (Basiszinssatz :base_rate % + :points Prozentpunkte) vom :from bis :until',
];
