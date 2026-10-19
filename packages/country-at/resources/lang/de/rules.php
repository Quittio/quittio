<?php

declare(strict_types=1);

// Messages of the UStG rules and of the CIUS-AT-NAT and CIUS-AT-GOV rules of the Austrian
// country pack. Not reviewed by a tax adviser yet.
return [
    'AT-USTG-01' => [
        'message' => 'Name und Anschrift des leistenden Unternehmers müssen vollständig sein: Straße (BT-35), Postleitzahl (BT-38) und Ort (BT-37) (§ 11 Abs. 1 Z 3 lit. a UStG).',
        'hint' => 'Ergänzen Sie die Anschrift mit ->address(Straße, PLZ, Ort, Land) beim Verkäufer.',
    ],
    'AT-USTG-02' => [
        'message' => 'Name und Anschrift des Leistungsempfängers müssen vollständig sein: Straße (BT-50), Postleitzahl (BT-53) und Ort (BT-52) (§ 11 Abs. 1 Z 3 lit. b UStG); nur vereinfachte Rechnungen bis 400 Euro und von Kleinunternehmern dürfen sie weglassen (§ 11 Abs. 6 UStG).',
        'hint' => 'Ergänzen Sie die Anschrift mit ->address(Straße, PLZ, Ort, Land) beim Käufer.',
    ],
    'AT-USTG-03' => [
        'message' => 'Die Umsatzsteuer-Identifikationsnummer (UID, BT-31) des leistenden Unternehmers muss angegeben sein (§ 11 Abs. 1 Z 3 lit. i UStG).',
        'hint' => 'Setzen Sie ->vatId(\'ATU…\') beim Verkäufer.',
    ],
    'AT-USTG-04' => [
        'message' => 'Der Tag der Lieferung oder sonstigen Leistung muss angegeben sein: Lieferdatum (BT-72), Abrechnungszeitraum (BG-14) oder Zeiträume an allen Positionen (BG-26) (§ 11 Abs. 1 Z 3 lit. d UStG).',
        'hint' => 'Setzen Sie ->deliveryDate(…) oder ->invoicingPeriod(…, …).',
    ],
    'AT-USTG-05' => [
        'message' => 'Rechnungen an Unternehmer über 10.000 Euro müssen die UID des Leistungsempfängers (BT-48) enthalten (§ 11 Abs. 1 Z 3 lit. b UStG).',
        'hint' => 'Setzen Sie ->vatId(…) beim Käufer.',
    ],
    'AT-USTG-06' => [
        'message' => 'Rechnungen mit Übergang der Steuerschuld müssen auf die Steuerschuldnerschaft des Leistungsempfängers hinweisen (§ 11 Abs. 1a UStG).',
        'hint' => 'Verwenden Sie die Presets VatPreset::ReverseChargeDomestic, VatPreset::ReverseChargeEu oder VatPreset::TriangularTransaction.',
    ],
    'AT-USTG-07' => [
        'message' => 'Eine Steuerbefreiung braucht einen Hinweis als Text (BT-120); ein Code allein genügt nicht (§ 11 Abs. 1 Z 3 lit. e UStG).',
        'hint' => 'Verwenden Sie ein Preset wie VatPreset::IntraCommunitySupply oder VatPreset::exempt(\'§ 6 Abs. 1 Z … UStG\').',
    ],
    'AT-USTG-08' => [
        'message' => 'Ein Kleinunternehmer, der die Befreiung nach § 6 Abs. 1 Z 27 UStG anwendet, darf keine Umsatzsteuer ausweisen; ausgewiesene Steuer schuldet er aufgrund der Rechnung (§ 11 Abs. 12 UStG).',
        'hint' => 'Verwenden Sie VatPreset::SmallBusiness für alle Positionen.',
    ],
    'AT-USTG-09' => [
        'message' => 'Eine Gutschrift muss als „Gutschrift“ bezeichnet sein (§ 11 Abs. 8 Z 3 UStG).',
        'hint' => 'Ergänzen Sie den Hinweis „Gutschrift“ (->note(\'Gutschrift\')).',
    ],
    'AT-USTG-10' => [
        'message' => 'Eine Rechnung in einer anderen Währung als Euro muss den Steuerbetrag zusätzlich in Euro angeben (Steuerwährung BT-6 „EUR“ und BT-111) oder die Umrechnungsmethode nennen (§ 11 Abs. 1 Z 3 lit. f UStG).',
        'hint' => 'Setzen Sie ->taxCurrency(\'EUR\', Kurs) am Builder.',
    ],
    'AT-USTG-11' => [
        'message' => 'Die Rechnung wird mehr als sechs Monate nach der Leistung (:supply) ausgestellt; Rechnungen an Unternehmer und juristische Personen sind innerhalb von sechs Monaten auszustellen (§ 11 Abs. 1 Z 1 UStG).',
    ],
    'AT-USTG-12' => [
        'message' => 'Rechnungen über sonstige Leistungen im übrigen Gemeinschaftsgebiet, für die der Empfänger die Steuer schuldet, sind bis zum 15. Tag des Folgemonats auszustellen (§ 11 Abs. 1 Z 2 UStG); die Frist endete am :deadline.',
    ],
    'AT-NAT-01' => [
        'message' => 'Die Adresszeile 1 jeder Postanschrift muss angegeben sein (BT-35, BT-50, BT-64, BT-75).',
    ],
    'AT-NAT-02' => [
        'message' => 'Der Ort jeder Postanschrift muss angegeben sein (BT-37, BT-52, BT-66, BT-77).',
    ],
    'AT-NAT-03' => [
        'message' => 'Die Postleitzahl jeder Postanschrift muss angegeben sein (BT-38, BT-53, BT-67, BT-78).',
    ],
    'AT-NAT-04' => [
        'message' => 'Das Lieferdatum (BT-72) oder der Abrechnungszeitraum (BG-14) muss angegeben sein.',
        'hint' => 'Zeiträume an den Positionen genügen in CIUS-AT-NAT nicht; setzen Sie ->deliveryDate(…) oder ->invoicingPeriod(…, …).',
    ],
    'AT-NAT-05' => [
        'message' => 'Der Steuersatz eines Nachlasses auf Dokumentenebene (BT-96) muss angegeben sein.',
    ],
    'AT-NAT-06' => [
        'message' => 'Der Steuersatz eines Zuschlags auf Dokumentenebene (BT-103) muss angegeben sein.',
    ],
    'AT-NAT-07' => [
        'message' => 'Externe Verweise auf Anhänge (BT-124) werden nicht unterstützt; betten Sie den Anhang ein.',
    ],
    'AT-NAT-08' => [
        'message' => 'Der Steuersatz jeder Rechnungsposition (BT-152) muss angegeben sein.',
    ],
    'AT-GOV-01' => [
        'message' => 'Die Käuferreferenz (BT-10) muss angegeben sein: beim Bund die Auftragsreferenz.',
        'hint' => 'Setzen Sie ->buyerReference(…) mit der Auftragsreferenz der bestellenden Stelle.',
    ],
    'AT-GOV-02' => [
        'message' => 'Der Ansprechpartner des Verkäufers (BG-6) mit E-Mail-Adresse (BT-43) muss angegeben sein.',
        'hint' => 'Setzen Sie ->contact(Name, Telefon, E-Mail) beim Verkäufer.',
    ],
    'AT-GOV-03' => [
        'message' => 'Der Code der Zahlungsart (BT-81) muss 30, 31, 42, 49, 58 oder 59 sein.',
    ],
    'AT-GOV-04' => [
        'message' => 'Der Zahlbetrag (BT-115) darf 999.999.999,99 nicht übersteigen.',
    ],
    'AT-GOV-05' => [
        'message' => 'Es werden höchstens 200 Anhänge (BG-24) mit zusammen höchstens 15 MB verarbeitet.',
    ],
    'AT-GOV-06' => [
        'message' => 'Anhänge (BT-125) müssen PDF-, PNG-, XML- oder Excel-Dateien sein (Mime-Code und Dateiendung .pdf, .png, .xml, .xls oder .xlsx).',
    ],
    'AT-GOV-07' => [
        'message' => 'Eine Rechnung darf höchstens 999 Positionen (BG-25) haben.',
    ],
    'AT-GOV-08' => [
        'message' => 'Der Nettobetrag einer Position (BT-131) muss zwischen -999.999.999.999,99 und 999.999.999.999,99 liegen.',
    ],
    'AT-GOV-09' => [
        'message' => 'Bei einer zehnstelligen Bestellnummer des Bundes (BT-13) muss jede Position auf ihre Bestellposition verweisen (BT-132).',
        'hint' => 'Setzen Sie ->orderLineReference(…) an jeder Position.',
    ],
];
