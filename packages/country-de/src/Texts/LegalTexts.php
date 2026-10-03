<?php

declare(strict_types=1);

namespace Quittio\Country\DE\Texts;

use Quittio\Core\Countries\LegalTextCatalog;

/**
 * Mandatory and informational texts for German invoices (specification 8.2, 8.3).
 *
 * The wording and legal references are proposals; confirm them with a tax adviser
 * before the first release (open decision 8).
 */
final class LegalTexts
{
    public const string SELF_BILLING = 'self_billing';

    public const string PROPERTY_RETENTION = 'property_retention';

    public const string REVERSE_CHARGE = 'reverse_charge';

    public const string INTRA_COMMUNITY_SUPPLY = 'intra_community_supply';

    public const string EXPORT = 'export';

    public const string SMALL_BUSINESS = 'small_business';

    public const string PREPAYMENT = 'prepayment';

    public const string CONSUMER_DEFAULT = 'consumer_default';

    public const string NEW_VEHICLE = 'new_vehicle';

    public static function catalog(): LegalTextCatalog
    {
        return new LegalTextCatalog([
            self::SELF_BILLING => [
                'de' => 'Gutschrift',
                'en' => 'Gutschrift (self-billed invoice)',
            ],
            self::PROPERTY_RETENTION => [
                'de' => 'Sie sind gesetzlich verpflichtet, diese Rechnung, einen Zahlungsbeleg oder eine andere beweiskräftige Unterlage zwei Jahre lang aufzubewahren (§ 14b Abs. 1 Satz 5 UStG).',
                'en' => 'You are required by law to keep this invoice, a payment receipt or other conclusive document for two years (section 14b (1) sentence 5 UStG).',
            ],
            self::REVERSE_CHARGE => [
                'de' => 'Steuerschuldnerschaft des Leistungsempfängers',
                'en' => 'Reverse charge: the recipient of the service is liable for the VAT',
            ],
            self::INTRA_COMMUNITY_SUPPLY => [
                'de' => 'Steuerfreie innergemeinschaftliche Lieferung (§ 4 Nr. 1b i. V. m. § 6a UStG)',
                'en' => 'VAT-exempt intra-community supply (section 4 no. 1b in conjunction with section 6a UStG)',
            ],
            self::EXPORT => [
                'de' => 'Steuerfreie Ausfuhrlieferung (§ 4 Nr. 1a i. V. m. § 6 UStG)',
                'en' => 'VAT-exempt export supply (section 4 no. 1a in conjunction with section 6 UStG)',
            ],
            self::SMALL_BUSINESS => [
                'de' => 'Steuerfreie Leistung nach § 19 Abs. 1 UStG (Steuerbefreiung für Kleinunternehmer).',
                'en' => 'VAT-exempt supply under section 19 (1) UStG (small business exemption).',
            ],
            self::PREPAYMENT => [
                'de' => 'Die Umsatzsteuer entsteht mit Vereinnahmung der Anzahlung. Die Leistung wird voraussichtlich am :date erbracht.',
                'en' => 'VAT becomes due on receipt of the prepayment. The service is expected to be performed on :date.',
            ],
            self::CONSUMER_DEFAULT => [
                'de' => 'Sie kommen spätestens in Verzug, wenn Sie nicht innerhalb von 30 Tagen nach Fälligkeit und Zugang dieser Rechnung zahlen (§ 286 Abs. 3 BGB).',
                'en' => 'You will be in default at the latest if you do not pay within 30 days of the due date and receipt of this invoice (section 286 (3) BGB).',
            ],
            self::NEW_VEHICLE => [
                'de' => 'Lieferung eines neuen Fahrzeugs (§ 1b UStG); die Fahrzeugmerkmale sind bei der Position angegeben.',
                'en' => 'Supply of a new means of transport (section 1b UStG); the vehicle details are stated with the line.',
            ],
        ], fallbackLocale: 'de');
    }
}
