<?php

declare(strict_types=1);

// Validation message catalog: message and solution hint per rule code.
return [
    'QUITTIO-NOT-CALCULATED' => [
        'message' => 'Die Rechnung wurde nicht berechnet. Summen (BG-22) und Steueraufschlüsselung (BG-23) fehlen.',
        'hint' => 'Berechnen Sie die Rechnung vor der Prüfung, z. B. über Quittio::make($data) oder den Calculator.',
    ],
    'QUITTIO-FORMAT-COUNTRY' => [
        'message' => 'Der Ländercode „:value“ muss aus zwei Großbuchstaben bestehen.',
    ],
    'QUITTIO-FORMAT-CURRENCY' => [
        'message' => 'Der Währungscode „:value“ muss aus drei Großbuchstaben bestehen.',
    ],
    'QUITTIO-PRECISION' => [
        'message' => 'Position :line verwendet :value Nachkommastellen für Menge oder Preis. Viele Empfangssysteme unterstützen höchstens :max.',
    ],
    'BR-02' => [
        'message' => 'Eine Rechnung muss eine Rechnungsnummer (BT-1) enthalten.',
        'hint' => 'Setzen Sie die Rechnungsnummer mit ->number(). In der Managed-Schicht wird die Nummer bei der Ausstellung vergeben.',
    ],
    'BR-04' => [
        'message' => 'Eine Rechnung muss einen Rechnungsartcode (BT-3) enthalten.',
    ],
    'BR-05' => [
        'message' => 'Eine Rechnung muss einen Währungscode (BT-5) enthalten.',
    ],
    'BR-06' => [
        'message' => 'Eine Rechnung muss den Namen des Verkäufers (BT-27) enthalten.',
    ],
    'BR-07' => [
        'message' => 'Eine Rechnung muss den Namen des Käufers (BT-44) enthalten.',
    ],
    'BR-09' => [
        'message' => 'Die Anschrift des Verkäufers (BG-5) muss einen Ländercode (BT-40) enthalten.',
    ],
    'BR-11' => [
        'message' => 'Die Anschrift des Käufers (BG-8) muss einen Ländercode (BT-55) enthalten.',
    ],
    'BR-16' => [
        'message' => 'Eine Rechnung muss mindestens eine Rechnungsposition (BG-25) enthalten.',
    ],
    'BR-17' => [
        'message' => 'Der Name des Zahlungsempfängers (BT-59) muss angegeben werden, wenn der Zahlungsempfänger (BG-10) vom Verkäufer abweicht.',
    ],
    'BR-18' => [
        'message' => 'Der Name des Steuervertreters (BT-62) muss angegeben werden, wenn der Verkäufer einen Steuervertreter hat.',
    ],
    'BR-20' => [
        'message' => 'Die Anschrift des Steuervertreters (BG-12) muss einen Ländercode (BT-69) enthalten.',
    ],
    'BR-21' => [
        'message' => 'Jede Rechnungsposition (BG-25) muss eine Positionsnummer (BT-126) haben.',
    ],
    'BR-25' => [
        'message' => 'Jede Rechnungsposition (BG-25) muss eine Artikelbezeichnung (BT-153) haben. Position :line.',
    ],
    'BR-27' => [
        'message' => 'Der Nettopreis (BT-146) darf nicht negativ sein. Position :line.',
    ],
    'BR-28' => [
        'message' => 'Der Listenpreis (BT-148) darf nicht negativ sein. Position :line.',
    ],
    'BR-29' => [
        'message' => 'Das Ende des Rechnungszeitraums (BT-74) darf nicht vor dem Beginn (BT-73) liegen.',
    ],
    'BR-30' => [
        'message' => 'Das Ende des Leistungszeitraums der Position (BT-135) darf nicht vor dem Beginn (BT-134) liegen. Position :line.',
    ],
    'BR-33' => [
        'message' => 'Jeder Nachlass auf Belegebene (BG-20) muss einen Grund (BT-97) oder einen Grundcode (BT-98) haben.',
        'hint' => 'Geben Sie beim Nachlass einen Grund oder Grundcode an.',
    ],
    'BR-38' => [
        'message' => 'Jeder Zuschlag auf Belegebene (BG-21) muss einen Grund (BT-104) oder einen Grundcode (BT-105) haben.',
        'hint' => 'Geben Sie beim Zuschlag einen Grund oder Grundcode an.',
    ],
    'BR-42' => [
        'message' => 'Jeder Nachlass auf Positionsebene (BG-27) muss einen Grund (BT-139) oder einen Grundcode (BT-140) haben. Position :line.',
        'hint' => 'Geben Sie beim Positionsnachlass einen Grund oder Grundcode an.',
    ],
    'BR-44' => [
        'message' => 'Jeder Zuschlag auf Positionsebene (BG-28) muss einen Grund (BT-144) oder einen Grundcode (BT-145) haben. Position :line.',
        'hint' => 'Geben Sie beim Positionszuschlag einen Grund oder Grundcode an.',
    ],
    'BR-48' => [
        'message' => 'Jede Steueraufschlüsselung (BG-23) muss einen Steuersatz (BT-119) haben, außer bei nicht steuerbaren Umsätzen.',
    ],
    'BR-49' => [
        'message' => 'Die Zahlungsanweisungen (BG-16) müssen einen Code für die Zahlungsart (BT-81) enthalten.',
    ],
    'BR-50' => [
        'message' => 'Bei Angaben zur Überweisung (BG-17) muss die Kontokennung (BT-84) angegeben werden.',
    ],
    'BR-52' => [
        'message' => 'Jede rechnungsbegründende Unterlage (BG-24) muss eine Kennung (BT-122) haben.',
    ],
    'BR-53' => [
        'message' => 'Wenn eine Steuerwährung (BT-6) angegeben ist, muss der Steuerbetrag in Steuerwährung (BT-111) angegeben werden.',
        'hint' => 'Setzen Sie den Wechselkurs mit ->taxCurrency(\'EUR\', rate: \'…\'), damit BT-111 berechnet werden kann.',
    ],
    'BR-54' => [
        'message' => 'Jedes Artikelmerkmal (BG-32) muss einen Namen (BT-160) und einen Wert (BT-161) haben. Position :line.',
    ],
    'BR-55' => [
        'message' => 'Jeder Verweis auf eine vorausgegangene Rechnung (BG-3) muss deren Nummer (BT-25) enthalten.',
    ],
    'BR-56' => [
        'message' => 'Der Steuervertreter (BG-11) muss eine USt-IdNr. (BT-63) haben.',
    ],
    'BR-57' => [
        'message' => 'Die Lieferanschrift (BG-15) muss einen Ländercode (BT-80) enthalten.',
    ],
    'BR-61' => [
        'message' => 'Bei Zahlung per Überweisung (Code 30 oder 58) muss die Kontokennung (BT-84) angegeben werden.',
        'hint' => 'Ergänzen Sie das Konto mit ->payment(PaymentMeansCode::SepaCreditTransfer, iban: …).',
    ],
    'BR-CO-03' => [
        'message' => 'Steuerzeitpunkt (BT-7) und Code für den Steuerzeitpunkt (BT-8) schließen sich gegenseitig aus.',
    ],
    'BR-CO-09' => [
        'message' => 'Die USt-IdNr. „:value“ muss mit einem Ländercode nach ISO 3166-1 beginnen (Griechenland: EL).',
        'hint' => 'Schreiben Sie die USt-IdNr. mit Länderpräfix, z. B. DE123456789.',
    ],
    'BR-CO-18' => [
        'message' => 'Eine Rechnung muss mindestens eine Steueraufschlüsselung (BG-23) enthalten.',
    ],
    'BR-CO-25' => [
        'message' => 'Ist der Zahlbetrag (BT-115) positiv, muss ein Fälligkeitsdatum (BT-9) oder eine Zahlungsbedingung (BT-20) angegeben werden.',
        'hint' => 'Ergänzen Sie ein Zahlungsziel mit ->dueInDays(14) oder ein Fälligkeitsdatum mit ->dueDate().',
    ],
    'BR-CO-26' => [
        'message' => 'Damit der Käufer den Lieferanten automatisch erkennen kann, muss eine Kennung des Verkäufers (BT-29), eine Registernummer (BT-30) oder die USt-IdNr. (BT-31) angegeben werden.',
        'hint' => 'Setzen Sie die USt-IdNr. mit ->vatId(), eine Registernummer mit ->legalRegistration() oder eine Kennung mit ->identifier().',
    ],
    'BR-CO-10' => [
        'message' => 'Die Summe der Positionsbeträge (BT-106) muss der Summe aller Nettobeträge der Positionen (BT-131) entsprechen.',
    ],
    'BR-CO-11' => [
        'message' => 'Die Summe der Nachlässe (BT-107) muss der Summe der Nachlässe auf Belegebene (BT-92) entsprechen.',
    ],
    'BR-CO-12' => [
        'message' => 'Die Summe der Zuschläge (BT-108) muss der Summe der Zuschläge auf Belegebene (BT-99) entsprechen.',
    ],
    'BR-CO-13' => [
        'message' => 'Der Gesamtbetrag ohne Umsatzsteuer (BT-109) muss der Summe der Positionen (BT-106) abzüglich Nachlässen (BT-107) zuzüglich Zuschlägen (BT-108) entsprechen.',
    ],
    'BR-CO-14' => [
        'message' => 'Der Umsatzsteuer-Gesamtbetrag (BT-110) muss der Summe der Steuerbeträge je Kategorie (BT-117) entsprechen.',
    ],
    'BR-CO-15' => [
        'message' => 'Der Bruttobetrag (BT-112) muss dem Nettobetrag (BT-109) zuzüglich Umsatzsteuer (BT-110) entsprechen.',
    ],
    'BR-CO-16' => [
        'message' => 'Der Zahlbetrag (BT-115) muss dem Bruttobetrag (BT-112) abzüglich bereits gezahlter Beträge (BT-113) zuzüglich Rundungsbetrag (BT-114) entsprechen.',
    ],
    'BR-CO-17' => [
        'message' => 'Der Steuerbetrag (BT-117) muss der Bemessungsgrundlage (BT-116) multipliziert mit dem Steuersatz (BT-119) / 100 entsprechen, gerundet auf zwei Stellen. Kategorie :category, Satz :rate.',
    ],
    'BR-S-01' => [
        'message' => 'Eine Rechnung mit der Umsatzsteuerkategorie „:category“ muss mindestens eine Steueraufschlüsselung (BG-23) mit dieser Kategorie enthalten.',
    ],
    'BR-S-02' => [
        'message' => 'Eine Rechnung mit der Kategorie „S“ muss die USt-IdNr. des Verkäufers (BT-31), seine Steuernummer (BT-32) oder die USt-IdNr. des Steuervertreters (BT-63) enthalten.',
        'hint' => 'Setzen Sie die USt-IdNr. von Verkäufer und Käufer mit ->vatId().',
    ],
    'BR-S-05' => [
        'message' => 'Bei Positionen, Nachlässen und Zuschlägen mit der Kategorie „S“ muss der Steuersatz größer als null sein.',
    ],
    'BR-S-08' => [
        'message' => 'Für jeden Steuersatz der Kategorie „:category“ muss die Bemessungsgrundlage (BT-116) der Summe der Positionsbeträge abzüglich Nachlässen zuzüglich Zuschlägen dieser Kategorie und dieses Satzes entsprechen.',
    ],
    'BR-S-09' => [
        'message' => 'Der Steuerbetrag (BT-117) der Kategorie „S“ muss der Bemessungsgrundlage (BT-116) multipliziert mit dem Steuersatz (BT-119) entsprechen.',
    ],
    'BR-S-10' => [
        'message' => 'Eine Steueraufschlüsselung mit der Kategorie „:category“ darf keinen Befreiungsgrund (BT-120, BT-121) enthalten.',
        'hint' => 'Entfernen Sie den Befreiungsgrund; er ist nur bei den Kategorien E, AE, K, G und O zulässig. Rechtliche Hinweise zum Nullsteuersatz gehören in einen Hinweis (BT-22).',
    ],
    'BR-Z-01' => [
        'message' => 'Eine Rechnung mit der Umsatzsteuerkategorie „:category“ muss genau eine Steueraufschlüsselung (BG-23) mit dieser Kategorie enthalten.',
    ],
    'BR-Z-02' => [
        'message' => 'Eine Rechnung mit der Kategorie „Z“ muss die USt-IdNr. des Verkäufers (BT-31), seine Steuernummer (BT-32) oder die USt-IdNr. des Steuervertreters (BT-63) enthalten.',
        'hint' => 'Setzen Sie die USt-IdNr. von Verkäufer und Käufer mit ->vatId().',
    ],
    'BR-Z-05' => [
        'message' => 'Bei Positionen, Nachlässen und Zuschlägen mit der Kategorie „:category“ muss der Steuersatz 0 sein.',
    ],
    'BR-Z-08' => [
        'message' => 'Für jeden Steuersatz der Kategorie „:category“ muss die Bemessungsgrundlage (BT-116) der Summe der Positionsbeträge abzüglich Nachlässen zuzüglich Zuschlägen dieser Kategorie und dieses Satzes entsprechen.',
    ],
    'BR-Z-09' => [
        'message' => 'Der Steuerbetrag (BT-117) in einer Steueraufschlüsselung mit der Kategorie „:category“ muss 0 sein.',
    ],
    'BR-Z-10' => [
        'message' => 'Eine Steueraufschlüsselung mit der Kategorie „:category“ darf keinen Befreiungsgrund (BT-120, BT-121) enthalten.',
        'hint' => 'Entfernen Sie den Befreiungsgrund; er ist nur bei den Kategorien E, AE, K, G und O zulässig. Rechtliche Hinweise zum Nullsteuersatz gehören in einen Hinweis (BT-22).',
    ],
    'BR-E-01' => [
        'message' => 'Eine Rechnung mit der Umsatzsteuerkategorie „:category“ muss genau eine Steueraufschlüsselung (BG-23) mit dieser Kategorie enthalten.',
    ],
    'BR-E-02' => [
        'message' => 'Eine Rechnung mit der Kategorie „E“ muss die USt-IdNr. des Verkäufers (BT-31), seine Steuernummer (BT-32) oder die USt-IdNr. des Steuervertreters (BT-63) enthalten.',
        'hint' => 'Setzen Sie die USt-IdNr. von Verkäufer und Käufer mit ->vatId().',
    ],
    'BR-E-05' => [
        'message' => 'Bei Positionen, Nachlässen und Zuschlägen mit der Kategorie „:category“ muss der Steuersatz 0 sein.',
    ],
    'BR-E-08' => [
        'message' => 'Für jeden Steuersatz der Kategorie „:category“ muss die Bemessungsgrundlage (BT-116) der Summe der Positionsbeträge abzüglich Nachlässen zuzüglich Zuschlägen dieser Kategorie und dieses Satzes entsprechen.',
    ],
    'BR-E-09' => [
        'message' => 'Der Steuerbetrag (BT-117) in einer Steueraufschlüsselung mit der Kategorie „:category“ muss 0 sein.',
    ],
    'BR-E-10' => [
        'message' => 'Eine Steueraufschlüsselung mit der Kategorie „:category“ muss einen Befreiungsgrund als Text (BT-120) oder Code (BT-121) enthalten.',
        'hint' => 'Verwenden Sie ein Steuer-Preset des Länderpakets, das den Befreiungsgrund setzt, oder übergeben Sie ihn mit ->vat(Kategorie, Satz, Befreiungsgrund).',
    ],
    'BR-AE-01' => [
        'message' => 'Eine Rechnung mit der Umsatzsteuerkategorie „:category“ muss genau eine Steueraufschlüsselung (BG-23) mit dieser Kategorie enthalten.',
    ],
    'BR-AE-02' => [
        'message' => 'Eine Rechnung mit der Kategorie „AE“ muss die USt-IdNr. oder Steuernummer des Verkäufers (BT-31, BT-32) bzw. des Steuervertreters (BT-63) sowie die USt-IdNr. (BT-48) oder Registernummer (BT-47) des Käufers enthalten.',
        'hint' => 'Setzen Sie die USt-IdNr. von Verkäufer und Käufer mit ->vatId().',
    ],
    'BR-AE-05' => [
        'message' => 'Bei Positionen, Nachlässen und Zuschlägen mit der Kategorie „:category“ muss der Steuersatz 0 sein.',
    ],
    'BR-AE-08' => [
        'message' => 'Für jeden Steuersatz der Kategorie „:category“ muss die Bemessungsgrundlage (BT-116) der Summe der Positionsbeträge abzüglich Nachlässen zuzüglich Zuschlägen dieser Kategorie und dieses Satzes entsprechen.',
    ],
    'BR-AE-09' => [
        'message' => 'Der Steuerbetrag (BT-117) in einer Steueraufschlüsselung mit der Kategorie „:category“ muss 0 sein.',
    ],
    'BR-AE-10' => [
        'message' => 'Eine Steueraufschlüsselung mit der Kategorie „:category“ muss einen Befreiungsgrund als Text (BT-120) oder Code (BT-121) enthalten.',
        'hint' => 'Verwenden Sie ein Steuer-Preset des Länderpakets, das den Befreiungsgrund setzt, oder übergeben Sie ihn mit ->vat(Kategorie, Satz, Befreiungsgrund).',
    ],
    'BR-IC-01' => [
        'message' => 'Eine Rechnung mit der Umsatzsteuerkategorie „:category“ muss genau eine Steueraufschlüsselung (BG-23) mit dieser Kategorie enthalten.',
    ],
    'BR-IC-02' => [
        'message' => 'Eine Rechnung mit der Kategorie „K“ (innergemeinschaftliche Lieferung) muss die USt-IdNr. des Verkäufers (BT-31) oder des Steuervertreters (BT-63) und die USt-IdNr. des Käufers (BT-48) enthalten.',
        'hint' => 'Setzen Sie die USt-IdNr. von Verkäufer und Käufer mit ->vatId().',
    ],
    'BR-IC-05' => [
        'message' => 'Bei Positionen, Nachlässen und Zuschlägen mit der Kategorie „:category“ muss der Steuersatz 0 sein.',
    ],
    'BR-IC-08' => [
        'message' => 'Für jeden Steuersatz der Kategorie „:category“ muss die Bemessungsgrundlage (BT-116) der Summe der Positionsbeträge abzüglich Nachlässen zuzüglich Zuschlägen dieser Kategorie und dieses Satzes entsprechen.',
    ],
    'BR-IC-09' => [
        'message' => 'Der Steuerbetrag (BT-117) in einer Steueraufschlüsselung mit der Kategorie „:category“ muss 0 sein.',
    ],
    'BR-IC-10' => [
        'message' => 'Eine Steueraufschlüsselung mit der Kategorie „:category“ muss einen Befreiungsgrund als Text (BT-120) oder Code (BT-121) enthalten.',
        'hint' => 'Verwenden Sie ein Steuer-Preset des Länderpakets, das den Befreiungsgrund setzt, oder übergeben Sie ihn mit ->vat(Kategorie, Satz, Befreiungsgrund).',
    ],
    'BR-G-01' => [
        'message' => 'Eine Rechnung mit der Umsatzsteuerkategorie „:category“ muss genau eine Steueraufschlüsselung (BG-23) mit dieser Kategorie enthalten.',
    ],
    'BR-G-02' => [
        'message' => 'Eine Rechnung mit der Kategorie „G“ (Ausfuhr) muss die USt-IdNr. des Verkäufers (BT-31) oder des Steuervertreters (BT-63) enthalten.',
        'hint' => 'Setzen Sie die USt-IdNr. von Verkäufer und Käufer mit ->vatId().',
    ],
    'BR-G-05' => [
        'message' => 'Bei Positionen, Nachlässen und Zuschlägen mit der Kategorie „:category“ muss der Steuersatz 0 sein.',
    ],
    'BR-G-08' => [
        'message' => 'Für jeden Steuersatz der Kategorie „:category“ muss die Bemessungsgrundlage (BT-116) der Summe der Positionsbeträge abzüglich Nachlässen zuzüglich Zuschlägen dieser Kategorie und dieses Satzes entsprechen.',
    ],
    'BR-G-09' => [
        'message' => 'Der Steuerbetrag (BT-117) in einer Steueraufschlüsselung mit der Kategorie „:category“ muss 0 sein.',
    ],
    'BR-G-10' => [
        'message' => 'Eine Steueraufschlüsselung mit der Kategorie „:category“ muss einen Befreiungsgrund als Text (BT-120) oder Code (BT-121) enthalten.',
        'hint' => 'Verwenden Sie ein Steuer-Preset des Länderpakets, das den Befreiungsgrund setzt, oder übergeben Sie ihn mit ->vat(Kategorie, Satz, Befreiungsgrund).',
    ],
    'BR-O-01' => [
        'message' => 'Eine Rechnung mit der Umsatzsteuerkategorie „:category“ muss genau eine Steueraufschlüsselung (BG-23) mit dieser Kategorie enthalten.',
    ],
    'BR-O-02' => [
        'message' => 'Eine Rechnung mit der Kategorie „O“ (nicht steuerbar) darf keine USt-IdNr. des Verkäufers (BT-31), des Steuervertreters (BT-63) oder des Käufers (BT-48) enthalten.',
        'hint' => 'Setzen Sie die USt-IdNr. von Verkäufer und Käufer mit ->vatId().',
    ],
    'BR-O-05' => [
        'message' => 'Positionen, Nachlässe und Zuschläge mit der Kategorie „O“ dürfen keinen Steuersatz enthalten.',
    ],
    'BR-O-08' => [
        'message' => 'Für jeden Steuersatz der Kategorie „:category“ muss die Bemessungsgrundlage (BT-116) der Summe der Positionsbeträge abzüglich Nachlässen zuzüglich Zuschlägen dieser Kategorie und dieses Satzes entsprechen.',
    ],
    'BR-O-09' => [
        'message' => 'Der Steuerbetrag (BT-117) in einer Steueraufschlüsselung mit der Kategorie „:category“ muss 0 sein.',
    ],
    'BR-O-10' => [
        'message' => 'Eine Steueraufschlüsselung mit der Kategorie „:category“ muss einen Befreiungsgrund als Text (BT-120) oder Code (BT-121) enthalten.',
        'hint' => 'Verwenden Sie ein Steuer-Preset des Länderpakets, das den Befreiungsgrund setzt, oder übergeben Sie ihn mit ->vat(Kategorie, Satz, Befreiungsgrund).',
    ],
    'BR-IC-11' => [
        'message' => 'Bei einer innergemeinschaftlichen Lieferung (Kategorie „K“) muss das Lieferdatum (BT-72) oder der Rechnungszeitraum (BG-14) angegeben werden.',
        'hint' => 'Setzen Sie das Lieferdatum mit ->deliveryDate() oder den Rechnungszeitraum mit ->invoicingPeriod().',
    ],
    'BR-IC-12' => [
        'message' => 'Bei einer innergemeinschaftlichen Lieferung (Kategorie „K“) muss der Ländercode der Lieferanschrift (BT-80) angegeben werden.',
        'hint' => 'Ergänzen Sie die Lieferanschrift mit ->deliverTo(Name, Address::make(…)).',
    ],
    'BR-O-11' => [
        'message' => 'Eine Rechnung mit der Kategorie „O“ (nicht steuerbar) darf keine anderen Umsatzsteuerkategorien enthalten.',
        'hint' => 'Stellen Sie für nicht steuerbare Umsätze eine eigene Rechnung aus.',
    ],
    'BR-AF-01' => [
        'message' => 'Eine Rechnung mit der Umsatzsteuerkategorie „:category“ muss mindestens eine Steueraufschlüsselung (BG-23) mit dieser Kategorie enthalten.',
    ],
    'BR-AF-02' => [
        'message' => 'Eine Rechnung mit der Kategorie „L“ (IGIC, Kanarische Inseln) muss die USt-IdNr. des Verkäufers (BT-31), seine Steuernummer (BT-32) oder die USt-IdNr. des Steuervertreters (BT-63) enthalten.',
        'hint' => 'Setzen Sie die USt-IdNr. des Verkäufers mit ->vatId() oder seine Steuernummer mit ->taxNumber().',
    ],
    'BR-AF-05' => [
        'message' => 'Bei Positionen, Nachlässen und Zuschlägen mit der Kategorie „:category“ muss der Steuersatz 0 oder größer als null sein.',
    ],
    'BR-AF-08' => [
        'message' => 'Für jeden Steuersatz der Kategorie „:category“ muss die Bemessungsgrundlage (BT-116) der Summe der Positionsbeträge abzüglich Nachlässen zuzüglich Zuschlägen dieser Kategorie und dieses Satzes entsprechen.',
    ],
    'BR-AF-09' => [
        'message' => 'Der Steuerbetrag (BT-117) der Kategorie „:category“ muss der Bemessungsgrundlage (BT-116) multipliziert mit dem Steuersatz (BT-119) entsprechen.',
    ],
    'BR-AF-10' => [
        'message' => 'Eine Steueraufschlüsselung mit der Kategorie „:category“ darf keinen Befreiungsgrund (BT-120, BT-121) enthalten.',
        'hint' => 'Entfernen Sie den Befreiungsgrund; er ist nur bei den Kategorien E, AE, K, G und O zulässig.',
    ],
    'BR-AG-01' => [
        'message' => 'Eine Rechnung mit der Umsatzsteuerkategorie „:category“ muss mindestens eine Steueraufschlüsselung (BG-23) mit dieser Kategorie enthalten.',
    ],
    'BR-AG-02' => [
        'message' => 'Eine Rechnung mit der Kategorie „M“ (IPSI, Ceuta und Melilla) muss die USt-IdNr. des Verkäufers (BT-31), seine Steuernummer (BT-32) oder die USt-IdNr. des Steuervertreters (BT-63) enthalten.',
        'hint' => 'Setzen Sie die USt-IdNr. des Verkäufers mit ->vatId() oder seine Steuernummer mit ->taxNumber().',
    ],
    'BR-AG-05' => [
        'message' => 'Bei Positionen, Nachlässen und Zuschlägen mit der Kategorie „:category“ muss der Steuersatz 0 oder größer als null sein.',
    ],
    'BR-AG-08' => [
        'message' => 'Für jeden Steuersatz der Kategorie „:category“ muss die Bemessungsgrundlage (BT-116) der Summe der Positionsbeträge abzüglich Nachlässen zuzüglich Zuschlägen dieser Kategorie und dieses Satzes entsprechen.',
    ],
    'BR-AG-09' => [
        'message' => 'Der Steuerbetrag (BT-117) der Kategorie „:category“ muss der Bemessungsgrundlage (BT-116) multipliziert mit dem Steuersatz (BT-119) entsprechen.',
    ],
    'BR-AG-10' => [
        'message' => 'Eine Steueraufschlüsselung mit der Kategorie „:category“ darf keinen Befreiungsgrund (BT-120, BT-121) enthalten.',
        'hint' => 'Entfernen Sie den Befreiungsgrund; er ist nur bei den Kategorien E, AE, K, G und O zulässig.',
    ],
    'BR-B-01' => [
        'message' => 'Eine Rechnung mit der Kategorie „B“ (Split Payment) muss eine italienische Inlandsrechnung sein: Jeder Ländercode (BT-40, BT-55, BT-69, BT-80, BT-159) muss „IT“ sein, gefunden „:value“.',
        'hint' => 'Das Split Payment (scissione dei pagamenti) gilt nur zwischen italienischen Parteien; verwenden Sie für andere Rechnungen die Kategorie „S“.',
    ],
    'BR-B-02' => [
        'message' => 'Eine Rechnung mit der Kategorie „B“ (Split Payment) darf keine Positionen, Nachlässe, Zuschläge oder Steueraufschlüsselungen mit der Kategorie „S“ enthalten.',
        'hint' => 'Stellen Sie für Umsätze mit Split Payment und für Umsätze zum Normalsatz getrennte Rechnungen aus.',
    ],
    'QUITTIO-IGIC-ZERO-RATE-CII' => [
        'message' => 'Kategorie „L“ (IGIC) mit dem Steuersatz 0 in :value: EN 16931 und UBL lassen ihn zu (BR-AF-05 bis BR-AF-07), die offizielle CII-Schematron-Prüfung verlangt einen Steuersatz größer als null. Verwenden Sie für diese Rechnung UBL.',
        'hint' => 'Erzeugen Sie die Rechnung als UBL (z. B. Peppol BIS oder XRechnung UBL); CII (ZUGFeRD/Factur-X, XRechnung CII) wird von der offiziellen Prüfung abgelehnt.',
    ],
    'BR-CL-01' => [
        'message' => 'Der Rechnungsartcode (BT-3) „:value“ ist in der Codeliste UNTDID 1001 für Rechnungen und Gutschriften nicht enthalten.',
    ],
    'BR-CL-04' => [
        'message' => 'Der Währungscode (BT-5) „:value“ ist kein Code nach ISO 4217.',
    ],
    'BR-CL-05' => [
        'message' => 'Der Code der Steuerwährung (BT-6) „:value“ ist kein Code nach ISO 4217.',
    ],
    'BR-CL-07' => [
        'message' => 'Das Kennungsschema „:value“ ist in UNTDID 1153 nicht enthalten.',
    ],
    'BR-CL-08' => [
        'message' => 'Der Betreffcode des Hinweises (BT-21) „:value“ ist in UNTDID 4451 nicht enthalten.',
    ],
    'BR-CL-10' => [
        'message' => 'Das Kennungsschema „:value“ ist in ISO/IEC 6523 nicht enthalten.',
    ],
    'BR-CL-11' => [
        'message' => 'Das Schema der Registernummer „:value“ ist in ISO/IEC 6523 nicht enthalten.',
    ],
    'BR-CL-13' => [
        'message' => 'Das Klassifizierungsschema „:value“ ist in UNTDID 7143 nicht enthalten.',
    ],
    'BR-CL-14' => [
        'message' => 'Der Ländercode „:value“ ist kein Code nach ISO 3166-1 Alpha-2.',
    ],
    'BR-CL-16' => [
        'message' => 'Der Code für die Zahlungsart (BT-81) „:value“ ist in UNTDID 4461 nicht enthalten.',
    ],
    'BR-CL-19' => [
        'message' => 'Der Grundcode für den Nachlass „:value“ ist in UNTDID 5189 nicht enthalten.',
    ],
    'BR-CL-20' => [
        'message' => 'Der Grundcode für den Zuschlag „:value“ ist in UNTDID 7161 nicht enthalten.',
    ],
    'BR-CL-21' => [
        'message' => 'Das Schema der Artikelkennung „:value“ ist in ISO/IEC 6523 nicht enthalten.',
    ],
    'BR-CL-22' => [
        'message' => 'Der Code für den Befreiungsgrund (BT-121) „:value“ ist in der VATEX-Codeliste nicht enthalten.',
    ],
    'BR-CL-23' => [
        'message' => 'Der Einheitencode „:value“ ist in UN/ECE Recommendation 20 oder 21 nicht enthalten.',
    ],
    'BR-CL-24' => [
        'message' => 'Der MIME-Code „:value“ des angehängten Dokuments (BT-125-1) ist in der Codeliste der EN 16931 nicht enthalten.',
        'hint' => 'Nur das Profil xrechnung-extension erlaubt application/xml (BR-DEX-01). Hängen Sie das Dokument als PDF, PNG, JPEG, CSV, XLSX oder ODS an oder wählen Sie ->profile(\'xrechnung-extension\').',
    ],
    'BR-CL-25' => [
        'message' => 'Das Schema der elektronischen Adresse „:value“ ist in der EAS-Codeliste nicht enthalten.',
    ],
    'BR-CL-26' => [
        'message' => 'Das Schema der Lieferortkennung „:value“ ist in ISO/IEC 6523 nicht enthalten.',
    ],
    'QUITTIO-NEGATIVE-TOTAL' => [
        'message' => 'Der Zahlbetrag ist negativ. Stellen Sie stattdessen eine Gutschriftsanzeige (Rechnungsart 381) mit positiven Beträgen aus.',
        'hint' => 'Verwenden Sie ->type(InvoiceTypeCode::CreditNote) und positive Mengen.',
    ],
    'QUITTIO-SUBLINES' => [
        'message' => 'Der Nettobetrag der Position :line muss der Summe ihrer Unterpositionen entsprechen (:expected).',
    ],
    'QUITTIO-LINE-ID-DUPLICATE' => [
        'message' => 'Die Positionsnummer :line (BT-126) kommt mehrfach vor. Positionen lassen sich so nicht eindeutig ansprechen, z. B. bei Teilstornierungen.',
        'hint' => 'Lassen Sie ->id() weg, damit der Builder die Positionen nummeriert, oder geben Sie jeder Position eine eigene Nummer.',
    ],
    'QUITTIO-SUBLINE-VAT' => [
        'message' => 'Die Unterposition :line verwendet Steuerkategorie und -satz :value, ihre übergeordnete Position :expected. Unterpositionen müssen Kategorie und Satz der übergeordneten Position haben, weil die Steueraufschlüsselung aus den übergeordneten Positionen berechnet wird.',
        'hint' => 'Teilen Sie Bündel mit unterschiedlichen Steuersätzen in getrennte Positionen auf.',
    ],
    'QUITTIO-PRECEDING-INVOICES-CII' => [
        'message' => 'Die Rechnung verweist auf :value vorausgegangene Rechnungen (BG-3). CII (ZUGFeRD/Factur-X, XRechnung CII) überträgt nur den ersten Verweis, UBL überträgt alle.',
    ],
    'QUITTIO-EXEMPTION-CONFLICT' => [
        'message' => 'Positionen mit Kategorie :category und gleichem Satz verwenden unterschiedliche Befreiungscodes (:value). Eine Steueraufschlüsselung trägt nur einen Code (BT-121): Er entfällt, die Befreiungsgründe werden in BT-120 zusammengefasst. Stellen Sie getrennte Rechnungen aus, wenn der Empfänger die Codes benötigt.',
    ],
    'QUITTIO-DECLARED-AMOUNTS' => [
        'message' => 'Die angegebenen Nettobeträge der Positionen (BT-131) oder Steuerbeträge je Kategorie (BT-117) des Originalbelegs weichen stärker als toleriert von der Neuberechnung ab (BT-131: 0,02, PEPPOL-EN16931-R120; BT-117: weniger als eine Währungseinheit, BR-CO-17) und wurden neu berechnet. Der Beleg kehrt das Original möglicherweise nicht exakt um; vergleichen Sie die Summen mit dem Original.',
    ],
    'QUITTIO-THIRD-PARTY-PAYMENTS' => [
        'message' => 'Zahlungen an Dritte (BG-DEX-09) können nur in einer XRechnung-UBL-Rechnung (XRechnung-Extension) übermittelt werden. In CII, in Gutschriften (Credit Notes) und in anderen Profilen widerspräche der Zahlbetrag (BT-115) BR-CO-16. Verwenden Sie XRechnung UBL mit einem Rechnungstyp (Stornos: Strategie negative_invoice) oder rechnen Sie die Beträge als Positionen ab.',
    ],
    'QUITTIO-CREDIT-NOTE-DUE-DATE' => [
        'message' => 'Eine UBL-Gutschrift überträgt das Fälligkeitsdatum (BT-9) nur zusammen mit Zahlungsanweisungen (BG-16); das Fälligkeitsdatum :value entfällt.',
        'hint' => 'Geben Sie Zahlungsanweisungen mit ->payment() an oder nennen Sie die Fälligkeit in den Zahlungsbedingungen (BT-20).',
    ],
    'QUITTIO-VAT-ROUNDING' => [
        'message' => 'Der Steuerbetrag der Kategorie :category (:rate %) weicht um :value von Bemessungsgrundlage × Steuersatz ab. Die offiziellen Regeln tolerieren das, kaufmännisch gerundet ist der Betrag aber nicht.',
    ],
    'QUITTIO-CANCELLATION-VAT-ALIGNED' => [
        'message' => 'Der Steuerbetrag der Kategorie :category (:rate %) weicht um :value von Bemessungsgrundlage × Steuersatz ab: Er ist auf die früheren Teilstornierungen und Entgeltminderungen des Originals abgestimmt, damit alle zusammen genau dessen Umsatzsteuer berichtigen (kumulative Rundung, höchstens 0,01, innerhalb der Toleranz von BR-CO-17).',
    ],
    'QUITTIO-LINE-ROUNDING' => [
        'message' => 'Der Nettobetrag der Position :line weicht um :value von Menge × Preis ± Nachlässe und Zuschläge ab, z. B. von einem anderen System gerundet und aus dem Originalbeleg übernommen. EN 16931 prüft das nicht (Peppol toleriert 0,02), kaufmännisch gerundet ist der Betrag aber nicht.',
    ],
    'QUITTIO-ATTACHMENT-FILENAME' => [
        'message' => 'Der Dateiname „:value“ einer Anlage (BT-125-2) wird mehrfach verwendet.',
    ],
    'QUITTIO-ATTACHMENT-SIZE' => [
        'message' => 'Die Anlagen überschreiten die Größengrenze (:value Bytes). Empfangsportale lehnen zu große Rechnungen ab.',
    ],
    'QUITTIO-INVALID-CHARACTERS' => [
        'message' => 'Der Text in :value enthält Steuerzeichen oder ungültiges UTF-8, die XML nicht darstellen kann.',
        'hint' => 'Entfernen Sie Steuerzeichen wie vertikale Tabulatoren aus Text, der aus Tabellenkalkulationen kopiert wurde, und übergeben Sie Text in UTF-8.',
    ],
    'QUITTIO-GENERIC-COUNTRY' => [
        'message' => 'Für das Land des Verkäufers :value ist kein Länderpaket installiert. Geprüft werden nur die Regeln der EN 16931, nationale Pflichtangaben nicht.',
        'hint' => 'Installieren Sie das Länderpaket für das Land des Verkäufers, z. B. composer require quittio/country-de.',
    ],
    'QUITTIO-PROFILE-NOT-EINVOICE' => [
        'message' => 'Das Profil :value erzeugt keine E-Rechnung nach EN 16931 und ist nur als ausdrückliche Wahl zulässig.',
    ],
    'QUITTIO-STANDARD-VERSION' => [
        'message' => 'Das Profil :value unterstützt die EN-16931-Fassung der Rechnung nicht.',
    ],
    'QUITTIO-IBAN' => [
        'message' => 'Die IBAN „:value“ hat eine ungültige Prüfziffer oder ein ungültiges Format.',
        'hint' => 'Prüfen Sie die IBAN auf Tippfehler.',
    ],
    'QUITTIO-BIC' => [
        'message' => 'Die BIC „:value“ hat ein ungültiges Format.',
    ],
    'QUITTIO-VATID-FORMAT' => [
        'message' => 'Die USt-IdNr. „:value“ entspricht nicht dem Format ihres Landes.',
        'hint' => 'Prüfen Sie die USt-IdNr. auf Tippfehler.',
    ],
    'QUITTIO-DUNNING-FLAT-RATE-FEES' => [
        'message' => 'Die Mahnung verlangt Gebühren von :fees neben der Pauschale für Beitreibungskosten von :flat_rate. Mahngebühren sind Beitreibungskosten und werden auf die Pauschale angerechnet (Art. 6 Abs. 3 Richtlinie 2011/7/EU, § 288 Abs. 5 Satz 3 BGB): Verlangen Sie sie nur, soweit sie die Pauschale übersteigen.',
        'hint' => 'Lassen Sie die Mahngebühr weg oder verlangen Sie nur die Pauschale. Gebühren über der Pauschale dürfen Sie in Höhe des übersteigenden Betrags verlangen.',
    ],
    'QUITTIO-SME-ID' => [
        'message' => '„:value“ ist eine Kleinunternehmer-Identifikationsnummer (Suffix EX), keine USt-IdNr.',
        'hint' => 'Die Nummer der grenzüberschreitenden Kleinunternehmerregelung gehört in die Steuernummer des Verkäufers (BT-32, ->taxNumber()), nicht in die USt-IdNr.: Sie ist keine USt-IdNr. (Art. 284 Abs. 3 MwStSystRL) und lässt sich nicht über VIES prüfen.',
    ],
    'QUITTIO-EMAIL' => [
        'message' => 'Die E-Mail-Adresse „:value“ ist ungültig.',
    ],
    'QUITTIO-DUE-DATE' => [
        'message' => 'Das Fälligkeitsdatum (BT-9) liegt vor dem Rechnungsdatum (BT-2).',
    ],
    'QUITTIO-VIES' => [
        'message' => 'Die USt-IdNr. „:value“ ist laut VIES ungültig.',
    ],
    'QUITTIO-VIES-UNAVAILABLE' => [
        'message' => 'Die USt-IdNr. „:value“ konnte nicht geprüft werden, weil VIES nicht erreichbar ist.',
    ],
    'PEPPOL-EN16931-R001' => [
        'message' => 'Die Prozesskennung (BT-23) muss angegeben werden.',
    ],
    'PEPPOL-EN16931-R002' => [
        'message' => 'Auf Belegebene ist höchstens ein Hinweis erlaubt, außer Käufer und Verkäufer sind deutsche Unternehmen.',
    ],
    'PEPPOL-EN16931-R003' => [
        'message' => 'Eine Käuferreferenz (BT-10) oder eine Bestellnummer (BT-13) muss angegeben werden.',
        'hint' => 'Setzen Sie ->buyerReference() oder ->purchaseOrderReference().',
    ],
    'PEPPOL-EN16931-R005' => [
        'message' => 'Die Währung der Umsatzsteuer (BT-6) muss sich von der Rechnungswährung (BT-5) unterscheiden. BT-6 wird deshalb nicht geschrieben.',
        'hint' => 'Setzen Sie ->taxCurrency() nur bei Rechnungen in Fremdwährung.',
    ],
    'PEPPOL-EN16931-R010' => [
        'message' => 'Die elektronische Adresse des Käufers (BT-49) muss angegeben werden.',
        'hint' => 'Setzen Sie die Peppol-Teilnehmerkennung des Käufers mit ->electronicAddress(Wert, Schema).',
    ],
    'PEPPOL-EN16931-R020' => [
        'message' => 'Die elektronische Adresse des Verkäufers (BT-34) muss angegeben werden.',
        'hint' => 'Setzen Sie die Peppol-Teilnehmerkennung des Verkäufers mit ->electronicAddress(Wert, Schema).',
    ],
    'PEPPOL-EN16931-CL008' => [
        'message' => 'Das Schema ":value" der elektronischen Adresse ist in Peppol nicht zulässig.',
        'hint' => 'Peppol stellt an die elektronische Adresse zu, eine E-Mail-Adresse (EM) ist deshalb nicht möglich. Verwenden Sie die Peppol-Teilnehmerkennung, z. B. ->electronicAddress(\'DE123456789\', ElectronicAddressScheme::GermanVatNumber) (9930) oder eine GLN (0088).',
    ],
    'PEPPOL-EN16931-R040' => [
        'message' => 'Der Betrag eines Nachlasses oder Zuschlags muss Basisbetrag × Prozentsatz / 100 entsprechen, wenn beide angegeben sind.',
    ],
    'PEPPOL-EN16931-R041' => [
        'message' => 'Bei einem Prozentsatz muss der Basisbetrag des Nachlasses oder Zuschlags angegeben werden.',
    ],
    'PEPPOL-EN16931-R042' => [
        'message' => 'Bei einem Basisbetrag muss der Prozentsatz des Nachlasses oder Zuschlags angegeben werden.',
    ],
    'PEPPOL-EN16931-R061' => [
        'message' => 'Bei Lastschrift muss die Mandatsreferenz (BT-89) angegeben werden.',
    ],
    'PEPPOL-EN16931-R120' => [
        'message' => 'Der Nettobetrag der Position :line muss Menge × (Nettopreis / Basismenge) + Zuschläge − Nachlässe entsprechen (Toleranz 0,02).',
    ],
    'PEPPOL-EN16931-R121' => [
        'message' => 'Die Basismenge (BT-149) der Position :line muss größer als null sein.',
    ],
    'PEPPOL-EN16931-R130' => [
        'message' => 'Die Einheit der Basismenge (BT-150) der Position :line muss der Einheit der Menge (BT-130) entsprechen.',
    ],
    'PEPPOL-EN16931-R007' => [
        'message' => 'Die Prozesskennung (BT-23) ":value" muss das Format urn:fdc:peppol.eu:2017:poacc:billing:NN:1.0 haben, wobei NN die Prozessnummer ist.',
        'hint' => 'Lassen Sie ->businessProcess() weg, um den Standardprozess urn:fdc:peppol.eu:2017:poacc:billing:01:1.0 zu verwenden.',
    ],
    'PEPPOL-EN16931-R008' => [
        'message' => 'Der Wert von :field (:bt) besteht nur aus Leerzeichen. Peppol erlaubt keine leeren Elemente.',
        'hint' => 'Lassen Sie das Feld weg oder geben Sie einen Wert an.',
    ],
    'PEPPOL-EN16931-R046' => [
        'message' => 'Der Nettopreis (BT-146) der Position :line muss dem Bruttopreis (BT-148) abzüglich des Preisnachlasses (BT-147) entsprechen.',
        'hint' => 'Setzen Sie nur ->listPrice(Bruttopreis, Nachlass), der Nettopreis wird daraus berechnet.',
    ],
    'PEPPOL-EN16931-R055' => [
        'message' => 'Der Gesamtbetrag der Umsatzsteuer (BT-110) und der Gesamtbetrag der Umsatzsteuer in Buchungswährung (BT-111) müssen dasselbe Vorzeichen haben.',
        'hint' => 'Prüfen Sie den Wechselkurs (->exchangeRate()), er muss positiv sein.',
    ],
    'PEPPOL-EN16931-R110' => [
        'message' => 'Das Startdatum (BT-134) des Zeitraums der Position :line darf nicht vor dem Beginn des Abrechnungszeitraums (BT-73) liegen.',
    ],
    'PEPPOL-EN16931-R111' => [
        'message' => 'Das Enddatum (BT-135) des Zeitraums der Position :line darf nicht nach dem Ende des Abrechnungszeitraums (BT-74) liegen.',
    ],
    'PEPPOL-EN16931-CL007' => [
        'message' => 'Der Währungscode ":value" ist in Peppol nicht zulässig.',
        'hint' => 'Die Peppol-Regeln verwenden einen eigenen Stand von ISO 4217, der diesen Code (noch) nicht enthält.',
    ],
    'PEPPOL-EN16931-F001' => [
        'message' => 'Das Datum :value (:bt) muss im Format JJJJ-MM-TT angegeben werden.',
    ],
    'PEPPOL-EN16931-P0100' => [
        'message' => 'Der Rechnungsartcode (BT-3) ":value" ist im Peppol-Geschäftsprozess 01 nicht zulässig.',
        'hint' => 'Verwenden Sie einen Code des Peppol-Geschäftsprozesses 01, z. B. 380 (Handelsrechnung).',
    ],
    'PEPPOL-EN16931-P0101' => [
        'message' => 'Der Gutschriftsartcode (BT-3) ":value" ist im Peppol-Geschäftsprozess 01 nicht zulässig.',
        'hint' => 'Der Peppol-Geschäftsprozess 01 erlaubt die Gutschriftsartcodes 81, 83, 381, 396 und 532.',
    ],
    'PEPPOL-EN16931-P0112' => [
        'message' => 'Die Rechnungsartcodes 326 (Teilrechnung) und 384 (korrigierte Rechnung) sind nur erlaubt, wenn Käufer und Verkäufer deutsche Unternehmen sind.',
    ],
    'PEPPOL-EN16931-P0104' => [
        'message' => 'Der Befreiungsgrundcode VATEX-EU-G (BT-121) erfordert die Umsatzsteuerkategorie G (BT-118), nicht :category.',
    ],
    'PEPPOL-EN16931-P0105' => [
        'message' => 'Der Befreiungsgrundcode VATEX-EU-O (BT-121) erfordert die Umsatzsteuerkategorie O (BT-118), nicht :category.',
    ],
    'PEPPOL-EN16931-P0106' => [
        'message' => 'Der Befreiungsgrundcode VATEX-EU-IC (BT-121) erfordert die Umsatzsteuerkategorie K (BT-118), nicht :category.',
    ],
    'PEPPOL-EN16931-P0107' => [
        'message' => 'Der Befreiungsgrundcode VATEX-EU-AE (BT-121) erfordert die Umsatzsteuerkategorie AE (BT-118), nicht :category.',
    ],
    'PEPPOL-EN16931-P0108' => [
        'message' => 'Der Befreiungsgrundcode VATEX-EU-D (BT-121) erfordert die Umsatzsteuerkategorie E (BT-118), nicht :category.',
    ],
    'PEPPOL-EN16931-P0109' => [
        'message' => 'Der Befreiungsgrundcode VATEX-EU-F (BT-121) erfordert die Umsatzsteuerkategorie E (BT-118), nicht :category.',
    ],
    'PEPPOL-EN16931-P0110' => [
        'message' => 'Der Befreiungsgrundcode VATEX-EU-I (BT-121) erfordert die Umsatzsteuerkategorie E (BT-118), nicht :category.',
    ],
    'PEPPOL-EN16931-P0111' => [
        'message' => 'Der Befreiungsgrundcode VATEX-EU-J (BT-121) erfordert die Umsatzsteuerkategorie E (BT-118), nicht :category.',
    ],
    'PEPPOL-COMMON-R040' => [
        'message' => 'Die GLN ":value" (Schema 0088) muss nach den GS1-Regeln gültig sein.',
    ],
    'PEPPOL-COMMON-R041' => [
        'message' => 'Die norwegische Organisationsnummer ":value" (Schema 0192) muss aus neun Ziffern mit gültiger Prüfziffer bestehen.',
    ],
    'PEPPOL-COMMON-R042' => [
        'message' => 'Die dänische Organisationsnummer (CVR) ":value" (Schema 0184) muss aus acht Ziffern bestehen, optional mit vorangestelltem DK.',
    ],
    'PEPPOL-COMMON-R043' => [
        'message' => 'Die belgische Unternehmensnummer ":value" (Schema 0208) muss aus zehn Ziffern mit gültigen Prüfziffern bestehen.',
    ],
    'PEPPOL-COMMON-R044' => [
        'message' => 'Der IPA-Code ":value" (Schema 0201) sollte aus sechs Buchstaben oder Ziffern bestehen.',
    ],
    'PEPPOL-COMMON-R045' => [
        'message' => 'Die italienische Steuernummer (Codice Fiscale) ":value" (Schema 0210) sollte im richtigen Format angegeben werden.',
    ],
    'PEPPOL-COMMON-R046' => [
        'message' => 'Die italienische Steuernummer (Codice Fiscale) ":value" (Schema 9907) sollte im richtigen Format angegeben werden.',
    ],
    'PEPPOL-COMMON-R047' => [
        'message' => 'Die italienische Umsatzsteuer-Identifikationsnummer (Partita IVA) ":value" (Schema 0211) sollte im richtigen Format angegeben werden.',
    ],
    'PEPPOL-COMMON-R049' => [
        'message' => 'Die schwedische Organisationsnummer ":value" (Schema 0007) muss aus zehn Ziffern mit gültiger Prüfziffer bestehen.',
    ],
    'PEPPOL-COMMON-R050' => [
        'message' => 'Die australische Unternehmensnummer (ABN) ":value" (Schema 0151) muss aus elf Ziffern mit gültigen Prüfziffern bestehen.',
    ],
    'PEPPOL-COMMON-R052' => [
        'message' => 'Die dänische P-Nummer ":value" (Schema 0096) sollte aus zehn Ziffern bestehen.',
    ],
    'PEPPOL-COMMON-R053' => [
        'message' => 'Die dänische ERSTORG-Nummer (SE) ":value" (Schema 0198) sollte aus DK und acht Ziffern bestehen.',
    ],
    'QUITTIO-PROFILE-REQUIRED' => [
        'message' => 'Das Feld :field (:bt) ist im Profil :profile Pflicht.',
        'hint' => 'Geben Sie das Feld an oder wählen Sie ein Profil, das es nicht verlangt.',
    ],
    'QUITTIO-PROFILE-NOT-ALLOWED' => [
        'message' => 'Das Feld :field (:bt) ist nicht Teil des Profils :profile und wird nicht in das XML geschrieben.',
        'hint' => 'Wählen Sie ein Profil, das das Feld enthält (z. B. EN 16931), oder entfernen Sie es.',
    ],
    'QUITTIO-PROFILE-EXTENDED-ONLY' => [
        'message' => 'Das Feld :field ist nur im Profil EXTENDED verfügbar, nicht in :profile.',
        'hint' => 'Verwenden Sie ->profile(\'extended\') oder entfernen Sie die EXTENDED-Daten.',
    ],
    'QUITTIO-PROFILE-XRECHNUNG-EXTENSION-ONLY' => [
        'message' => 'Das Feld :field ist nur in der XRechnung-Extension verfügbar, nicht in :profile.',
        'hint' => 'Verwenden Sie das Profil xrechnung-extension oder entfernen Sie die Extension-Daten.',
    ],
    'QUITTIO-PROFILE-SUBLINES' => [
        'message' => 'Unterpositionen werden nur in den Profilen EXTENDED und XRechnung-Extension unterstützt, nicht in :profile.',
        'hint' => 'Verwenden Sie das Profil EXTENDED oder die XRechnung-Extension oder lösen Sie die Unterpositionen auf.',
    ],
    'BR-CL-06' => [
        'message' => 'Der Code des Steuerzeitpunkts (BT-8) „:value“ ist nicht Teil von UNTDID 2005 (3 Rechnungsdatum, 35 Lieferdatum, 432 Zahlungsdatum).',
    ],
    'QUITTIO-PROFORMA' => [
        'message' => 'Der Rechnungsartcode 325 (Proforma-Rechnung) bezeichnet keine Rechnung. Erstellen Sie Proforma-Dokumente mit DocumentBuilder::proforma(); E-Rechnungsformate dürfen keine Proforma-Rechnung enthalten.',
        'hint' => 'Eine Proforma-Rechnung ist keine Rechnung im steuerlichen Sinne. Als E-Rechnungs-XML mit Umsatzsteuer würde sie beim Empfänger als Rechnung gebucht (Risiko nach § 14c UStG).',
    ],
    'QUITTIO-VAT-K-DOMESTIC' => [
        'message' => 'Die Steuerkategorie „K“ (innergemeinschaftliche Lieferung) wird verwendet, aber der Bestimmungsort :value liegt im Mitgliedstaat des Verkäufers (:expected). Eine innergemeinschaftliche Lieferung setzt einen Bestimmungsort in einem anderen EU-Mitgliedstaat voraus.',
        'hint' => 'Prüfen Sie Lieferland (BT-80) und Steuerkategorie; im Inland ist die Lieferung steuerpflichtig (§ 6a UStG).',
    ],
    'QUITTIO-VAT-K-OUTSIDE-EU' => [
        'message' => 'Die Steuerkategorie „K“ (innergemeinschaftliche Lieferung) wird verwendet, aber der Bestimmungsort :value liegt außerhalb des Mehrwertsteuergebiets der EU. Lieferungen in Drittländer sind Ausfuhrlieferungen (Kategorie „G“) oder in der EU nicht steuerbar (Kategorie „O“).',
    ],
    'QUITTIO-VAT-G-INSIDE-EU' => [
        'message' => 'Die Steuerkategorie „G“ (Ausfuhr) wird verwendet, aber der Bestimmungsort :value liegt im Mehrwertsteuergebiet der EU. Lieferungen innerhalb der EU sind innergemeinschaftliche Lieferungen (Kategorie „K“) oder im Mitgliedstaat steuerpflichtig.',
        'hint' => 'Eine Ausfuhrlieferung (§ 6 UStG) setzt voraus, dass der Gegenstand in das Drittlandsgebiet gelangt (z. B. Schweiz, Büsingen, Helgoland).',
    ],
];
