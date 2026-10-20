<?php

declare(strict_types=1);

// Messages of the rules of the Belgian country pack. Not reviewed by a tax adviser yet.
return [
    'BE-VAT-01' => [
        'message' => 'Het btw-identificatienummer van de leverancier (BT-31) moet vermeld zijn (art. 5, § 1, 3° KB nr. 1).',
        'hint' => 'Stel ->vatId(\'BE0123456749\') in bij de verkoper.',
    ],
    'BE-VAT-02' => [
        'message' => 'Naam en adres van de leverancier en van de afnemer moeten volledig zijn: straat, postcode en gemeente (art. 5, § 1, 5° KB nr. 1).',
    ],
    'BE-VAT-03' => [
        'message' => 'Een factuur aan een onderneming in België vermeldt het btw-identificatienummer van de afnemer (BT-48) als die btw-aangiften indient (art. 5, § 1, 4° KB nr. 1).',
    ],
    'BE-VAT-04' => [
        'message' => 'Een factuur met verlegging van heffing moet „Verlegging van heffing” of „Autoliquidation” vermelden (art. 5, § 1, 10° KB nr. 1).',
        'hint' => 'Gebruik VatPreset::ReverseChargeDomestic of VatPreset::ReverseChargeEu.',
    ],
    'BE-VAT-05' => [
        'message' => 'Een vrijstelling vereist de verwijzing naar de bepaling als tekst (BT-120); een code alleen volstaat niet (art. 5, § 1, 10° KB nr. 1).',
    ],
    'BE-VAT-06' => [
        'message' => 'Een kleine onderneming onder de vrijstellingsregeling (art. 56bis Wetboek btw) mag geen btw aanrekenen.',
        'hint' => 'Gebruik VatPreset::SmallBusiness voor alle regels.',
    ],
    'BE-VAT-07' => [
        'message' => 'Een factuur uitgereikt door de afnemer moet „self-billing”, „autofacturation” of „zelffacturering” vermelden (art. 5, § 1, 12° KB nr. 1).',
    ],
    'BE-VAT-08' => [
        'message' => 'De factuur moet uiterlijk op de 15e dag van de maand na de levering worden uitgereikt (art. 53, § 2 Wetboek btw); de termijn liep af op :deadline.',
    ],
    'BE-LANG-01' => [
        'message' => 'Een onderneming in het Nederlandse, Franse of Duitse taalgebied reikt haar facturen aan Belgische afnemers uit in de taal van het taalgebied (:language; taaldecreten van de Vlaamse en de Franse Gemeenschap, voor het Duitse taalgebied art. 52 van de wetten op het gebruik van de talen in bestuurszaken van 18 juli 1966); de factuur is in :locale.',
        'hint' => 'Stel ->locale(\'nl_BE\') in voor een onderneming in Vlaanderen.',
    ],
];
