<?php

declare(strict_types=1);

// Legal and informational texts of German invoices. Confirmed by a tax adviser in October 2026.
// Override them in lang/vendor/quittio-de/de/legal.php.
return [
    'self_billing' => 'Gutschrift',
    'property_retention' => 'Sie sind gesetzlich verpflichtet, diese Rechnung, einen Zahlungsbeleg oder eine andere beweiskräftige Unterlage zwei Jahre lang aufzubewahren (§ 14b Abs. 1 Satz 5 UStG).',
    'prepayment' => 'Anzahlungsrechnung. Die Leistung wird voraussichtlich am :date erbracht.',
    'prepayment_month' => 'Anzahlungsrechnung. Die Leistung wird voraussichtlich im :month erbracht.',
    'prepayment_received' => 'Die Anzahlung wurde am :date vereinnahmt.',
    'service_date_open' => 'Der Zeitpunkt der Leistung ist noch nicht vereinbart.',
    'advance_deduction' => 'Abzüglich Anzahlung gemäß Rechnung :number vom :date: :amounts (§ 14 Abs. 5 Satz 2 UStG).',
    'advance_deduction_remainder' => 'Restrechnung: Abgezogen ist die vereinnahmte Anzahlung gemäß Rechnung :number vom :date: :amounts (§ 14 Abs. 5 Satz 2 UStG).',
    'advance_deduction_line' => 'Abzüglich Anzahlung gemäß Rechnung :number vom :date',
    'advance_deduction_taxed' => 'Entgelt :net zzgl. :rate Umsatzsteuer :vat',
    'advance_deduction_untaxed' => 'Entgelt :net ohne Umsatzsteuer',
    'consumer_default' => 'Sie kommen spätestens in Verzug, wenn Sie nicht innerhalb von 30 Tagen nach Fälligkeit und Zugang dieser Rechnung zahlen (§ 286 Abs. 3 BGB).',
    'new_vehicle' => 'Innergemeinschaftliche Lieferung eines neuen Fahrzeugs (§ 14a Abs. 4 UStG); die Fahrzeugmerkmale nach § 1b Abs. 2 und 3 UStG sind bei der Position angegeben.',
    'discount_agreement' => 'Etwaige Minderungen des Entgelts (Boni, Rabatte) ergeben sich aus :agreement.',
    'zero_rated_pv' => 'Steuersatz 0 % nach § 12 Abs. 3 UStG.',
    'default_interest' => 'Verzugszinsen :rate % p. a. (Basiszinssatz :base_rate % + :points Prozentpunkte) vom :from bis :until',
];
