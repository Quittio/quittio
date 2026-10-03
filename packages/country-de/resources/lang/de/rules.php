<?php

declare(strict_types=1);

// Messages of the XRechnung and UStG rules of the German country pack.
return [
    'BR-DE-1' => [
        'message' => 'Eine Rechnung muss Angaben zu den Zahlungsanweisungen (BG-16) enthalten.',
        'hint' => 'Ergänzen Sie die Zahlungsart mit ->payment(PaymentMeansCode::SepaCreditTransfer, iban: …).',
    ],
    'BR-DE-2' => [
        'message' => 'Die Gruppe „Seller contact“ (BG-6) muss übermittelt werden.',
        'hint' => 'Ergänzen Sie den Ansprechpartner mit ->contact(Name, Telefon, E-Mail) beim Verkäufer.',
    ],
    'BR-DE-3' => [
        'message' => 'Der Ort des Verkäufers (BT-37) muss übermittelt werden.',
    ],
    'BR-DE-4' => [
        'message' => 'Die Postleitzahl des Verkäufers (BT-38) muss übermittelt werden.',
    ],
    'BR-DE-5' => [
        'message' => 'Der Ansprechpartner des Verkäufers (BT-41) muss übermittelt werden.',
    ],
    'BR-DE-6' => [
        'message' => 'Die Telefonnummer des Ansprechpartners (BT-42) muss übermittelt werden.',
    ],
    'BR-DE-7' => [
        'message' => 'Die E-Mail-Adresse des Ansprechpartners (BT-43) muss übermittelt werden.',
    ],
    'BR-DE-8' => [
        'message' => 'Der Ort des Käufers (BT-52) muss übermittelt werden.',
    ],
    'BR-DE-9' => [
        'message' => 'Die Postleitzahl des Käufers (BT-53) muss übermittelt werden.',
    ],
    'BR-DE-10' => [
        'message' => 'Der Ort der Lieferanschrift (BT-77) muss übermittelt werden, wenn eine Lieferanschrift (BG-15) angegeben ist.',
    ],
    'BR-DE-11' => [
        'message' => 'Die Postleitzahl der Lieferanschrift (BT-78) muss übermittelt werden, wenn eine Lieferanschrift (BG-15) angegeben ist.',
    ],
    'BR-DE-15' => [
        'message' => 'Die Käuferreferenz (BT-10) muss übermittelt werden. Bei öffentlichen Auftraggebern ist das die Leitweg-ID.',
        'hint' => 'Setzen Sie die Leitweg-ID mit ->buyerReference(\'04011000-12345-03\').',
    ],
    'BR-DE-16' => [
        'message' => 'Bei den Umsatzsteuerkategorien S, Z, E, AE, K, G, L oder M muss die USt-IdNr. (BT-31), die Steuernummer (BT-32) oder ein Steuervertreter (BG-11) des Verkäufers angegeben werden.',
    ],
    'BR-DE-17' => [
        'message' => 'Der Rechnungsartcode (BT-3) soll einer der Codes 326, 380, 384, 389, 381, 875, 876 oder 877 sein.',
    ],
    'BR-DE-18' => [
        'message' => 'Skontozeilen in den Zahlungsbedingungen (BT-20) müssen dem Format #SKONTO#TAGE=n#PROZENT=n.nn# entsprechen und mit einem Zeilenumbruch enden.',
    ],
    'BR-DE-19' => [
        'message' => 'Die Kontokennung (BT-84) soll bei SEPA-Überweisung (Code 58) eine gültige IBAN enthalten.',
    ],
    'BR-DE-20' => [
        'message' => 'Die Kennung des belasteten Kontos (BT-91) soll bei SEPA-Lastschrift (Code 59) eine gültige IBAN enthalten.',
    ],
    'BR-DE-22' => [
        'message' => 'Die Dateinamen der Anlagen (BT-125-2) müssen eindeutig sein.',
    ],
    'BR-DE-23-a' => [
        'message' => 'Bei Überweisung (Codes 30, 58) müssen Angaben zur Überweisung (BG-17) übermittelt werden.',
    ],
    'BR-DE-23-b' => [
        'message' => 'Bei Überweisung (Codes 30, 58) dürfen keine Angaben zu Karte (BG-18) oder Lastschrift (BG-19) übermittelt werden.',
    ],
    'BR-DE-24-a' => [
        'message' => 'Bei Kartenzahlung (Codes 48, 54, 55) müssen Angaben zur Karte (BG-18) übermittelt werden.',
    ],
    'BR-DE-24-b' => [
        'message' => 'Bei Kartenzahlung (Codes 48, 54, 55) dürfen keine Angaben zu Überweisung (BG-17) oder Lastschrift (BG-19) übermittelt werden.',
    ],
    'BR-DE-25-a' => [
        'message' => 'Bei Lastschrift (Code 59) müssen Angaben zur Lastschrift (BG-19) übermittelt werden.',
    ],
    'BR-DE-25-b' => [
        'message' => 'Bei Lastschrift (Code 59) dürfen keine Angaben zu Überweisung (BG-17) oder Karte (BG-18) übermittelt werden.',
    ],
    'BR-DE-26' => [
        'message' => 'Eine korrigierte Rechnung (Code 384) soll auf die vorausgegangene Rechnung (BG-3) verweisen.',
    ],
    'BR-DE-27' => [
        'message' => 'Die Telefonnummer des Ansprechpartners (BT-42) soll mindestens drei Ziffern enthalten.',
    ],
    'BR-DE-28' => [
        'message' => 'Die E-Mail-Adresse des Ansprechpartners (BT-43) soll genau ein @-Zeichen enthalten, das auf beiden Seiten von mindestens zwei Zeichen umgeben ist.',
    ],
    'BR-DE-30' => [
        'message' => 'Bei Lastschrift (BG-19) muss die Gläubiger-Identifikationsnummer (BT-90) übermittelt werden.',
    ],
    'BR-DE-31' => [
        'message' => 'Bei Lastschrift (BG-19) muss die Kennung des belasteten Kontos (BT-91) übermittelt werden.',
    ],
    'BR-DE-TMP-32' => [
        'message' => 'Die Rechnung soll das Liefer- oder Leistungsdatum enthalten: Lieferdatum (BT-72), Rechnungszeitraum (BG-14) oder einen Leistungszeitraum in jeder Position (BG-26).',
    ],
    'BR-DEX-15' => [
        'message' => 'Unterpositionen werden von XRechnung in CII nicht unterstützt. Verwenden Sie für die XRechnung-Extension die Syntax UBL.',
    ],
    'DE-USTG-01' => [
        'message' => 'Name und vollständige Anschrift des leistenden Unternehmers und des Leistungsempfängers müssen angegeben sein (§ 14 Abs. 4 Nr. 1 UStG).',
        'hint' => 'Ergänzen Sie Straße, Postleitzahl und Ort bei Verkäufer und Käufer.',
    ],
    'DE-USTG-02' => [
        'message' => 'Die Steuernummer (BT-32) oder die USt-IdNr. (BT-31) des leistenden Unternehmers muss angegeben sein (§ 14 Abs. 4 Nr. 2 UStG).',
        'hint' => 'Setzen Sie ->vatId() oder ->taxNumber() beim Verkäufer.',
    ],
    'DE-USTG-03' => [
        'message' => 'Der Zeitpunkt der Lieferung oder Leistung muss angegeben sein: Lieferdatum (BT-72), Abrechnungszeitraum (BG-14) oder Leistungszeiträume aller Positionen (BG-26) (§ 14 Abs. 4 Nr. 6 UStG).',
        'hint' => 'Setzen Sie ->deliveryDate() – auch wenn es dem Rechnungsdatum entspricht – oder ->invoicingPeriod().',
    ],
    'DE-USTG-04' => [
        'message' => 'Eine Gutschrift im Sinne des UStG muss die Angabe „Gutschrift“ enthalten (§ 14 Abs. 4 Nr. 10 UStG).',
        'hint' => 'Verwenden Sie ->selfBilled(); der Builder ergänzt den Hinweis.',
    ],
    'DE-USTG-05' => [
        'message' => 'Bei Leistungen im Zusammenhang mit einem Grundstück an Nichtunternehmer ist der Hinweis auf die Aufbewahrungspflicht des Empfängers Pflicht (§ 14 Abs. 4 Nr. 9 UStG).',
        'hint' => 'Verwenden Sie ->propertyRelatedService(); der Builder ergänzt den Hinweis.',
    ],
    'DE-USTG-06' => [
        'message' => 'Rechnungen mit Steuerschuldnerschaft des Leistungsempfängers müssen die Angabe „Steuerschuldnerschaft des Leistungsempfängers“ enthalten (§ 14a Abs. 5 UStG).',
        'hint' => 'Verwenden Sie die Presets VatPreset::ReverseChargeDomestic oder VatPreset::ReverseChargeEu.',
    ],
    'DE-USTG-07' => [
        'message' => 'Bei der innergemeinschaftlichen Lieferung neuer Fahrzeuge müssen die Fahrzeugmerkmale (BG-32) bei jeder Position angegeben sein (§ 14a Abs. 4 UStG).',
        'hint' => 'Ergänzen Sie z. B. ->attribute(\'Erstzulassung\', …) und ->attribute(\'Kilometerstand\', …).',
    ],
    'DE-USTG-08' => [
        'message' => 'Eine Kleinbetragsrechnung darf höchstens :limit brutto betragen (§ 33 UStDV).',
        'hint' => 'Entfernen Sie ->smallAmount() und stellen Sie eine vollständige Rechnung aus.',
    ],
    'DE-USTG-09' => [
        'message' => 'Ein Kleinunternehmer (§ 19 UStG) darf in derselben Rechnung keine Umsatzsteuer ausweisen.',
        'hint' => 'Verwenden Sie für alle Positionen VatPreset::SmallBusiness.',
    ],
    'DE-USTG-10' => [
        'message' => 'Bei Rechnungen in fremder Währung soll der Steuerbetrag zusätzlich in Euro angegeben werden (BT-111 mit BT-6 = EUR).',
        'hint' => 'Setzen Sie ->taxCurrency(\'EUR\', $kurs, $datum).',
    ],
    'DE-LEITWEG-ID' => [
        'message' => 'Die Prüfziffer der Leitweg-ID „:value“ (BT-10) ist falsch.',
        'hint' => 'Prüfen Sie die Leitweg-ID beim öffentlichen Auftraggeber.',
    ],
];
