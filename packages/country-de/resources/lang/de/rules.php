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
    'BR-DEX-02' => [
        'message' => 'Der Nettobetrag der Position :line (BT-131) soll der Summe der Nettobeträge ihrer direkten Unterpositionen (:expected) entsprechen.',
    ],
    'BR-DEX-04' => [
        'message' => 'Das Kennungsschema „:value“ einer Partei ist weder in ISO/IEC 6523 noch eines der Schemata XR01 bis XR03 der XRechnung-Extension (SEPA nur für Verkäufer und Zahlungsempfänger in UBL).',
    ],
    'BR-DEX-05' => [
        'message' => 'Das Schema „:value“ der Registrierungskennung ist weder in ISO/IEC 6523 noch eines der Schemata XR01 bis XR03 der XRechnung-Extension.',
    ],
    'BR-DEX-06' => [
        'message' => 'Das Schema „:value“ der Artikelkennung (Standard Identifier) ist weder in ISO/IEC 6523 noch eines der Schemata XR01 bis XR03 der XRechnung-Extension.',
    ],
    'BR-DEX-07' => [
        'message' => 'Das Schema „:value“ der elektronischen Adresse ist weder in der EAS-Codeliste noch eines der Schemata XR01 bis XR03 der XRechnung-Extension.',
    ],
    'BR-DEX-08' => [
        'message' => 'Das Schema „:value“ der Kennung des Lieferorts ist weder in ISO/IEC 6523 noch eines der Schemata XR01 bis XR03 der XRechnung-Extension.',
    ],
    'BR-DEX-09' => [
        'message' => 'Der Zahlbetrag (BT-115) muss dem Bruttobetrag (BT-112) abzüglich bereits gezahlter Beträge (BT-113) zuzüglich Rundungsbetrag (BT-114) und der Summe der Zahlungen an Dritte (BT-DEX-002) entsprechen.',
    ],
    'BR-DEX-10' => [
        'message' => 'Die Art der Zahlung an Dritte (BT-DEX-001) muss für jede Zahlung an Dritte (BG-DEX-09) übermittelt werden.',
    ],
    'BR-DEX-12' => [
        'message' => 'Die Beschreibung der Zahlung an Dritte (BT-DEX-003) muss für jede Zahlung an Dritte (BG-DEX-09) übermittelt werden.',
    ],
    'BR-DEX-13' => [
        'message' => 'Der Betrag der Zahlung an Dritte (BT-DEX-002) darf höchstens zwei Nachkommastellen haben, angegeben ist :value.',
    ],
    'BR-DEX-14' => [
        'message' => 'Der Betrag der Zahlung an Dritte (BT-DEX-002) ist in :value angegeben, muss aber in der Rechnungswährung (BT-5) :expected angegeben werden.',
    ],
    'BR-DEX-15' => [
        'message' => 'Unterpositionen werden von XRechnung in CII nicht unterstützt. Verwenden Sie für die XRechnung-Extension die Syntax UBL.',
    ],
    'DE-XRECHNUNG-EXT-01' => [
        'message' => 'Die XRechnung-Extension kennt in UBL keine Gutschriftsanzeige (CreditNote). Stornieren Sie mit der Strategie negative_invoice (Rechnungsart 380 mit negativen Beträgen).',
        'hint' => 'Setzen Sie corrections.cancellation_strategy auf negative_invoice oder übergeben Sie die Strategie beim Storno.',
    ],
    'BR-DE-CVD-01' => [
        'message' => 'Die Vertragsreferenz (BT-12) muss übermittelt werden.',
        'hint' => 'Setzen Sie die Vertragsreferenz mit ->contractReference(…).',
    ],
    'BR-DE-CVD-02' => [
        'message' => 'Die Vergabe- oder Losreferenz (BT-17) muss übermittelt werden.',
        'hint' => 'Setzen Sie die Vergabe- oder Losreferenz mit ->tenderReference(…).',
    ],
    'BR-DE-CVD-03' => [
        'message' => 'Mindestens eine Rechnungsposition (BG-25) muss eine Artikelklassifizierung (BT-158) mit der Kennung des Schemas CVD und ein Artikelmerkmal mit dem Namen cva (BT-160) enthalten.',
        'hint' => 'Beschreiben Sie das Fahrzeug in seiner Position mit ->classification(\'M1\', \'CVD\') und ->attribute(\'cva\', \'clean\').',
    ],
    'BR-DE-CVD-04' => [
        'message' => 'Die Artikelklassifizierung (BT-158) ":value" mit der Kennung des Schemas CVD muss eine der Fahrzeugklassen M1, M2, M3, N1, N2 oder N3 sein. Position :line.',
    ],
    'BR-DE-CVD-05' => [
        'message' => 'Der Wert ":value" (BT-161) des Artikelmerkmals cva muss clean, zero-emission oder other sein. Position :line.',
    ],
    'BR-DE-CVD-06-a' => [
        'message' => 'Eine Rechnungsposition mit einer Artikelklassifizierung (BT-158) mit der Kennung des Schemas CVD muss genau ein Artikelmerkmal mit dem Namen cva (BT-160) enthalten. Position :line.',
    ],
    'BR-DE-CVD-06-b' => [
        'message' => 'Eine Rechnungsposition mit einem Artikelmerkmal mit dem Namen cva (BT-160) muss genau eine Artikelklassifizierung (BT-158) mit der Kennung des Schemas CVD enthalten. Position :line.',
    ],
    'DE-USTG-01' => [
        'message' => 'Die Anschrift :party ist unvollständig, es fehlt: :fields (§ 14 Abs. 4 Nr. 1 UStG).',
        'hint' => ':advice',
        'party' => [
            'seller' => 'des leistenden Unternehmers (Verkäufer, BG-5)',
            'buyer' => 'des Leistungsempfängers (Käufer, BG-8)',
        ],
        'fields' => ['line1' => 'Straße und Hausnummer', 'postcode' => 'Postleitzahl', 'city' => 'Ort'],
        'advice' => [
            'seller' => 'Mit Quittio::invoice() kommt der Verkäufer aus config/quittio.php: ergänzen Sie seller.address (line1, postcode, city). Sonst: ->seller(Party::make(…)->address(Straße, PLZ, Ort, Land)).',
            'buyer' => 'Ergänzen Sie die Anschrift des Käufers: ->buyer(Party::make(…)->address(Straße, PLZ, Ort, Land)) oder in fillParty() Ihres Models.',
        ],
    ],
    'DE-USTG-02' => [
        'message' => 'Die Steuernummer (BT-32) oder die USt-IdNr. (BT-31) des leistenden Unternehmers muss angegeben sein (§ 14 Abs. 4 Nr. 2 UStG).',
        'hint' => 'Setzen Sie ->vatId() oder ->taxNumber() beim Verkäufer.',
    ],
    'DE-USTG-03' => [
        'message' => 'Der Zeitpunkt der Lieferung oder Leistung muss angegeben sein: Lieferdatum (BT-72), Abrechnungszeitraum (BG-14) oder Leistungszeiträume aller Positionen (BG-26) (§ 14 Abs. 4 Nr. 6 UStG).',
        'hint' => 'Setzen Sie ->deliveryDate() – auch wenn es dem Rechnungsdatum entspricht – oder ->invoicingPeriod(). Eine Anzahlungsrechnung (386) nennt den voraussichtlichen Zeitpunkt oder Monat; ist er noch nicht vereinbart, verwenden Sie ->de()->serviceDateOpen().',
    ],
    'DE-USTG-04' => [
        'message' => 'Eine Gutschrift im Sinne des UStG muss die Angabe „Gutschrift“ enthalten (§ 14 Abs. 4 Nr. 10 UStG).',
        'hint' => 'Verwenden Sie ->de()->selfBilled(); der Builder ergänzt den Hinweis. In einer E-Rechnung genügt der Rechnungstyp als Kennzeichnung (Abschnitt 14.5 Abs. 24 UStAE), dort ist es nur eine Warnung.',
    ],
    'DE-USTG-05' => [
        'message' => 'Bei Leistungen im Zusammenhang mit einem Grundstück an Nichtunternehmer ist der Hinweis auf die Aufbewahrungspflicht des Empfängers Pflicht (§ 14 Abs. 4 Nr. 9 UStG).',
        'hint' => 'Verwenden Sie ->de()->propertyRelatedService(); der Builder ergänzt den Hinweis.',
    ],
    'DE-USTG-06' => [
        'message' => 'Rechnungen mit Steuerschuldnerschaft des Leistungsempfängers müssen die Angabe „Steuerschuldnerschaft des Leistungsempfängers“ enthalten (§ 14a Abs. 1 und 5 UStG).',
        'hint' => 'Verwenden Sie die Presets VatPreset::ReverseChargeDomestic, VatPreset::ReverseChargeEu oder VatPreset::TriangularTransaction; ihre Texte werden in jeder Sprache des Länderpakets akzeptiert. Ohne die Angabe ist die Rechnung nicht ordnungsgemäß; die Steuerschuld des Empfängers bleibt davon unberührt.',
    ],
    'DE-USTG-07' => [
        'message' => 'Bei der innergemeinschaftlichen Lieferung neuer Fahrzeuge müssen die Fahrzeugmerkmale (BG-32) bei jeder Position angegeben sein (§ 14a Abs. 4 UStG).',
        'hint' => 'Ergänzen Sie z. B. ->attribute(\'Erstzulassung\', …) und ->attribute(\'Kilometerstand\', …).',
    ],
    'DE-USTG-08' => [
        'message' => 'Eine Kleinbetragsrechnung darf höchstens :limit brutto betragen (§ 33 UStDV).',
        'hint' => 'Entfernen Sie ->de()->smallAmount() und stellen Sie eine vollständige Rechnung aus.',
    ],
    'DE-USTG-09' => [
        'message' => 'Ein Kleinunternehmer (§ 19 UStG) darf in derselben Rechnung keine Umsatzsteuer ausweisen.',
        'hint' => 'Verwenden Sie für alle Positionen VatPreset::SmallBusiness.',
    ],
    'DE-USTG-10' => [
        'message' => 'Bei Rechnungen in fremder Währung soll der Steuerbetrag zusätzlich in Euro angegeben werden (BT-111 mit BT-6 = EUR) (Art. 230 MwStSystRL, § 16 Abs. 6 UStG).',
        'hint' => 'Setzen Sie ->taxCurrency(\'EUR\', $kurs, $datum).',
    ],
    'DE-USTG-11' => [
        'message' => 'Bei Reverse-Charge-Leistungen in einem anderen EU-Mitgliedstaat und bei innergemeinschaftlichen Dreiecksgeschäften müssen die USt-IdNr. des Verkäufers (BT-31) und des Käufers (BT-48) angegeben werden (§ 14a Abs. 1 und 7 UStG).',
        'hint' => 'Ergänzen Sie ->vatId(…) bei Verkäufer und Käufer; die Steuernummer genügt hier nicht. Ein Kleinunternehmer (§ 19 UStG) braucht keine eigene USt-IdNr.',
    ],
    'DE-USTG-12' => [
        'message' => 'Die Erleichterungen für Kleinbetragsrechnungen gelten nicht für Fernverkäufe, innergemeinschaftliche Lieferungen und Leistungen mit Steuerschuldnerschaft des Leistungsempfängers (§ 33 Satz 3 UStDV).',
        'hint' => 'Entfernen Sie ->de()->smallAmount() und stellen Sie eine vollständige Rechnung aus.',
    ],
    'DE-USTG-13' => [
        'message' => 'Die innergemeinschaftliche Lieferung eines neuen Fahrzeugs (§ 14a Abs. 4 UStG) ist markiert, aber keine Position verwendet die Steuerkategorie K (innergemeinschaftliche Lieferung).',
        'hint' => 'Verwenden Sie VatPreset::IntraCommunitySupply für die Fahrzeugpositionen oder entfernen Sie ->de()->newVehicle(), wenn das Fahrzeug nicht in einen anderen Mitgliedstaat geliefert wird.',
    ],
    'DE-USTG-14' => [
        'message' => 'Ein Kleinunternehmer kann eine Lieferung nicht als steuerfreie innergemeinschaftliche Lieferung (Steuerkategorie K) behandeln (§ 19 Abs. 1 Satz 2 UStG); ausgenommen ist nur die innergemeinschaftliche Lieferung eines neuen Fahrzeugs (§ 19 Abs. 6 Satz 1 UStG).',
        'hint' => 'Verwenden Sie VatPreset::SmallBusiness für die Positionen; bei einem neuen Fahrzeug ergänzen Sie ->de()->newVehicle().',
    ],
    'DE-USTG-15' => [
        'message' => 'Rechnungen über innergemeinschaftliche Lieferungen und über sonstige Leistungen nach § 3a Abs. 2 UStG in einem anderen Mitgliedstaat sind bis zum 15. Tag des Folgemonats auszustellen (§ 14a Abs. 1 Satz 2 und Abs. 3 Satz 1 UStG); die Frist endete am :deadline.',
        'hint' => 'Prüfen Sie Rechnungsdatum (BT-2) und Leistungsdatum. Die Rechnung bleibt gültig, wird aber verspätet ausgestellt.',
    ],
    'DE-USTG-16' => [
        'message' => 'Die Rechnung wird mehr als sechs Monate nach der Leistung (:supply) ausgestellt; Rechnungen an Unternehmer und über Leistungen im Zusammenhang mit einem Grundstück sind innerhalb von sechs Monaten auszustellen (§ 14 Abs. 2 Satz 2 UStG).',
        'hint' => 'Prüfen Sie Rechnungsdatum (BT-2) und Leistungsdatum. Die Rechnung bleibt gültig, wird aber verspätet ausgestellt.',
    ],
    'DE-USTG-17' => [
        'message' => 'Eine Anzahlungsrechnung mit bereits gezahltem Betrag (BT-113) muss den Tag der Vereinnahmung als Steuerzeitpunkt (BT-7) angeben, wenn er vom Rechnungsdatum abweicht (§ 14 Abs. 4 Satz 1 Nr. 6 UStG).',
        'hint' => 'Setzen Sie ->taxPointDate($vereinnahmtAm). Ging die Zahlung am Rechnungsdatum ein, fehlt nichts.',
    ],
    'DE-LEITWEG-ID' => [
        'message' => 'Die Prüfziffer der Leitweg-ID „:value“ (BT-10) ist falsch.',
        'hint' => 'Prüfen Sie die Leitweg-ID beim öffentlichen Auftraggeber.',
    ],
    'DE-TAX-NUMBER' => [
        'message' => 'Die Steuernummer „:value“ (BT-32) des Verkäufers ist keine gültige deutsche Steuernummer: Sie entspricht weder dem 13-stelligen ELSTER-Format noch der Schreibweise eines Bundeslandes, oder ihre Prüfziffer ist falsch.',
        'hint' => 'Übernehmen Sie die Steuernummer aus einem Bescheid des Finanzamts (z. B. 181/815/08155) oder im 13-stelligen ELSTER-Format. Eine USt-IdNr. (DE…) gehört in ->vatId().',
    ],
    'DE-VAT-RATE' => [
        'message' => 'Der Steuersatz :rate % der Position :line ist weder der Regelsteuersatz (:standard %) noch der ermäßigte Steuersatz (:reduced %), der am Leistungsdatum :date galt.',
        'hint' => 'Prüfen Sie Leistungszeitraum, Lieferdatum oder Steuerzeitpunkt: Es gilt der Steuersatz am Tag der Leistung, z. B. 16 % und 5 % von Juli bis Dezember 2020. Durchschnittssätze der Land- und Forstwirtschaft (§ 24 UStG) sind eine zulässige Ausnahme; schalten Sie die Regel dafür mit validation.rules ab.',
    ],
    'DE-LEITWEG-ID-SUSPECT' => [
        'message' => 'Die Käuferreferenz „:value“ (BT-10) hat die Form einer Leitweg-ID, aber ihre Prüfziffer ist falsch. Sie wird nicht als Leitweg-ID behandelt, die Rechnung wird daher nicht automatisch als XRechnung ausgegeben.',
        'hint' => 'Ist es die Leitweg-ID eines öffentlichen Auftraggebers, korrigieren Sie sie (dann wird automatisch XRechnung gewählt) oder geben Sie das Format ausdrücklich an. Eine Bestellnummer können Sie ignorieren.',
    ],
];
