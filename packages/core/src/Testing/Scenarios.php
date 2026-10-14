<?php

declare(strict_types=1);

namespace Quittio\Core\Testing;

use InvalidArgumentException;
use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Data\Extensions\ExtendedData;
use Quittio\Core\Data\Extensions\ExtendedParty;
use Quittio\Core\Data\Party\Address;
use Quittio\Core\Enums\AllowanceReasonCode;
use Quittio\Core\Enums\ChargeReasonCode;
use Quittio\Core\Enums\InvoiceTypeCode;
use Quittio\Core\Enums\PaymentMeansCode;
use Quittio\Core\Enums\UnitCode;
use Quittio\Core\Enums\VatCategory;

/**
 * Example invoices for previews, tests and the official validation runs.
 *
 * The scenarios are country neutral and use VAT categories directly. Country packs
 * may provide their own scenarios with tax presets.
 */
final class Scenarios
{
    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return [
            'simple', 'many-lines', 'long-texts', 'mixed-vat', 'reverse-charge', 'intra-eu', 'export',
            'cancellation', 'sub-lines', 'foreign-currency', 'b2c-gross', 'allowances', 'attachments', 'peppol',
        ];
    }

    public static function make(string $name): InvoiceBuilder
    {
        return match ($name) {
            'simple' => self::simple(),
            'many-lines' => self::manyLines(),
            'long-texts' => self::longTexts(),
            'mixed-vat' => self::mixedVat(),
            'reverse-charge' => self::reverseCharge(),
            'intra-eu' => self::intraEu(),
            'export' => self::export(),
            'cancellation' => self::cancellation(),
            'sub-lines' => self::subLines(),
            'foreign-currency' => self::foreignCurrency(),
            'b2c-gross' => self::b2cGross(),
            'allowances' => self::allowances(),
            'attachments' => self::attachments(),
            'peppol' => self::peppol(),
            default => throw new InvalidArgumentException(sprintf('Unknown scenario "%s". Available: %s.', $name, implode(', ', self::names()))),
        };
    }

    /**
     * Profile that suits the scenario best.
     */
    public static function profile(string $name): string
    {
        return match ($name) {
            'sub-lines' => 'extended',
            'peppol' => 'peppol',
            default => 'en16931',
        };
    }

    public static function base(): InvoiceBuilder
    {
        return InvoiceBuilder::make()
            ->number('RE-2026-0042')
            ->issueDate('2026-10-03')
            ->currency('EUR')
            ->locale('de')
            ->seller(fn ($s) => $s
                ->name('Muster GmbH')
                ->vatId('DE123456789')
                ->taxNumber('12/345/67890')
                ->legalRegistration('HRB 12345')
                ->additionalLegalInfo('Amtsgericht Musterstadt · Geschäftsführer: Max Muster')
                ->address('Hauptstraße 1', '12345', 'Musterstadt', 'DE')
                ->contact('Max Muster', '+49 123 456789', 'rechnung@muster.de')
                ->electronicAddress('rechnung@muster.de', 'EM'))
            ->buyer(fn ($b) => $b
                ->name('Kunde AG')
                ->vatId('DE987654321')
                ->identifier('K-10001')
                ->address('Bahnhofstraße 5', '54321', 'Beispielstadt', 'DE')
                ->contact('Erika Kunde', '+49 987 654321', 'einkauf@kunde.de')
                ->electronicAddress('ap@kunde.de', 'EM'))
            ->buyerReference('PO-4711')
            ->deliveryDate('2026-09-30')
            ->paymentTerms(fn ($t) => $t->dueInDays(14))
            ->payment(PaymentMeansCode::SepaCreditTransfer, iban: 'DE02120300000000202051', bic: 'BYLADEM1001', accountName: 'Muster GmbH', remittanceInformation: 'RE-2026-0042');
    }

    public static function simple(): InvoiceBuilder
    {
        return self::base()
            ->line(fn ($l) => $l
                ->name('Webentwicklung')
                ->description('Umsetzung Kundenportal, Sprint 3')
                ->quantity(24, UnitCode::Hour)
                ->netPrice('95.00')
                ->vat(VatCategory::StandardRate, '19'))
            ->line(fn ($l) => $l
                ->name('Hosting Oktober')
                ->quantity(1, UnitCode::Month)
                ->netPrice('29.00')
                ->vat(VatCategory::StandardRate, '19')
                ->period('2026-10-01', '2026-10-31'))
            ->paymentTerms(fn ($t) => $t->dueInDays(14)->cashDiscount(days: 7, percent: '2.00'));
    }

    public static function manyLines(): InvoiceBuilder
    {
        $builder = self::base();
        for ($i = 1; $i <= 60; $i++) {
            $builder->line(fn ($l) => $l
                ->name(sprintf('Artikel %03d', $i))
                ->sellerId(sprintf('ART-%05d', $i))
                ->quantity((string) (($i % 7) + 1), UnitCode::Piece)
                ->netPrice(sprintf('%d.%02d', 3 + $i, ($i * 13) % 100))
                ->vat(VatCategory::StandardRate, $i % 4 === 0 ? '7' : '19'));
        }

        return $builder;
    }

    public static function longTexts(): InvoiceBuilder
    {
        $paragraph = 'Konzeption, Umsetzung und Test der Schnittstelle zum Warenwirtschaftssystem einschließlich Fehlerbehandlung, Protokollierung, Monitoring und Dokumentation für den Betrieb.';

        return self::base()
            ->note(implode(' ', array_fill(0, 3, $paragraph)), 'AAI')
            ->line(fn ($l) => $l
                ->name('Schnittstellenentwicklung ERP – Phase 2 mit erweitertem Funktionsumfang und Abnahme')
                ->description(implode("\n\n", array_fill(0, 6, $paragraph)))
                ->quantity(80, UnitCode::Hour)
                ->netPrice('110.00')
                ->vat(VatCategory::StandardRate, '19')
                ->attribute('Projekt', 'ERP-2026')
                ->attribute('Ansprechpartner', 'Erika Kunde'));
    }

    public static function mixedVat(): InvoiceBuilder
    {
        return self::base()
            ->line(fn ($l) => $l->name('Fachbuch')->quantity(2, UnitCode::Piece)->netPrice('39.90')->vat(VatCategory::StandardRate, '7'))
            ->line(fn ($l) => $l->name('Schulung')->quantity(1, UnitCode::Day)->netPrice('850.00')->vat(VatCategory::StandardRate, '19'))
            ->line(fn ($l) => $l->name('Photovoltaikmodul')->quantity(4, UnitCode::Piece)->netPrice('189.00')->vat(VatCategory::ZeroRated, '0'))
            ->discountPercent('10.00', 'Projektrabatt');
    }

    public static function reverseCharge(): InvoiceBuilder
    {
        return self::base()
            ->buyer(fn ($b) => $b->name('Client SARL')->vatId('FR12345678901')->address('12 Rue de la Paix', '75002', 'Paris', 'FR'))
            ->line(fn ($l) => $l
                ->name('Beratungsleistung')
                ->quantity(16, UnitCode::Hour)
                ->netPrice('120.00')
                ->vat(VatCategory::ReverseCharge, '0', 'Steuerschuldnerschaft des Leistungsempfängers', 'VATEX-EU-AE'));
    }

    public static function intraEu(): InvoiceBuilder
    {
        $address = Address::make('Via Roma 1', '00184', 'Roma', 'IT');

        return self::base()
            ->buyer(fn ($b) => $b->name('Cliente S.r.l.')->vatId('IT12345678901')->address('Via Roma 1', '00184', 'Roma', 'IT'))
            ->deliverTo('Cliente S.r.l.', $address)
            ->line(fn ($l) => $l
                ->name('Industriepumpe')
                ->quantity(2, UnitCode::Piece)
                ->netPrice('1450.00')
                ->vat(VatCategory::IntraCommunitySupply, '0', 'Steuerfreie innergemeinschaftliche Lieferung', 'VATEX-EU-IC')
                ->originCountry('DE')
                ->classification('84137030', 'HS'));
    }

    public static function export(): InvoiceBuilder
    {
        return self::base()
            ->buyer(fn ($b) => $b->name('Customer Inc.')->vatId(null)->address('1 Main Street', '10001', 'New York', 'US'))
            ->line(fn ($l) => $l
                ->name('Messgerät')
                ->quantity(1, UnitCode::Piece)
                ->netPrice('2400.00')
                ->vat(VatCategory::Export, '0', 'Steuerfreie Ausfuhrlieferung', 'VATEX-EU-G'));
    }

    public static function cancellation(): InvoiceBuilder
    {
        return self::simple()
            ->number('ST-2026-0007')
            ->type(InvoiceTypeCode::CreditNote)
            ->precedingInvoice('RE-2026-0042', '2026-10-03')
            ->note('Stornierung der Rechnung RE-2026-0042 vom 03.10.2026. Grund: Doppelt berechnet.', 'AAI')
            ->paymentTerms('Der Betrag wird erstattet.');
    }

    public static function subLines(): InvoiceBuilder
    {
        return self::base()
            ->extended(new ExtendedData(
                invoicee: new ExtendedParty('Kunde AG Buchhaltung', Address::make('Postfach 10 20 30', '54321', 'Beispielstadt', 'DE')),
                deliveryTermsCode: 'DAP',
            ))
            ->line(fn ($l) => $l
                ->name('Arbeitsplatz-Set')
                ->vat(VatCategory::StandardRate, '19')
                ->subLine(fn ($s) => $s->name('Notebook')->quantity(1, UnitCode::Piece)->netPrice('1290.00')->vat(VatCategory::StandardRate, '19'))
                ->subLine(fn ($s) => $s->name('Monitor 27"')->quantity(2, UnitCode::Piece)->netPrice('249.00')->vat(VatCategory::StandardRate, '19'))
                ->subLine(fn ($s) => $s->name('Einrichtung')->quantity('1.5', UnitCode::Hour)->netPrice('95.00')->vat(VatCategory::StandardRate, '19')))
            ->line(fn ($l) => $l->name('Versand')->quantity(1, UnitCode::LumpSum)->netPrice('19.90')->vat(VatCategory::StandardRate, '19'));
    }

    public static function foreignCurrency(): InvoiceBuilder
    {
        return self::base()
            ->currency('USD')
            ->taxCurrency('EUR', '0.912345', '2026-10-02')
            ->payment(PaymentMeansCode::CreditTransfer, iban: 'DE02120300000000202051', bic: 'BYLADEM1001', accountName: 'Muster GmbH')
            ->line(fn ($l) => $l->name('Software licence')->quantity(5, UnitCode::Unit)->netPrice('399.00')->vat(VatCategory::StandardRate, '19'));
    }

    public static function b2cGross(): InvoiceBuilder
    {
        return self::base()
            ->buyer(fn ($b) => $b->name('Erika Mustermann')->vatId(null)->identifier('K-20077')->consumer()->address('Lindenweg 3', '10115', 'Berlin', 'DE'))
            ->buyerReference(null)
            ->line(fn ($l) => $l->name('T-Shirt')->quantity(3, UnitCode::Piece)->priceInclVat('9.99')->vat(VatCategory::StandardRate, '19'))
            ->line(fn ($l) => $l->name('Kochbuch')->quantity(1, UnitCode::Piece)->priceInclVat('24.95')->vat(VatCategory::StandardRate, '7'))
            ->line(fn ($l) => $l->name('Versand')->quantity(1, UnitCode::LumpSum)->priceInclVat('4.95')->vat(VatCategory::StandardRate, '19'));
    }

    public static function allowances(): InvoiceBuilder
    {
        return self::base()
            ->line(fn ($l) => $l->name('Schrauben')->quantity(250, UnitCode::Piece)->netPrice('12.3456', '100')->vat(VatCategory::StandardRate, '19')
                ->allowance(percent: '5', reason: 'Mengenrabatt', reasonCode: AllowanceReasonCode::Discount))
            ->line(fn ($l) => $l->name('Dübel')->quantity(100, UnitCode::Piece)->listPrice('0.25', '0.05')->vat(VatCategory::StandardRate, '19')
                ->charge(amount: '2.50', reason: 'Sonderverpackung', reasonCode: ChargeReasonCode::Packing))
            ->charge(VatCategory::StandardRate, '19', amount: '15.00', reason: 'Fracht', reasonCode: ChargeReasonCode::Freight)
            ->allowance(VatCategory::StandardRate, '19', percent: '2', reason: 'Treuerabatt');
    }

    public static function attachments(): InvoiceBuilder
    {
        return self::simple()
            ->attachment('STD-2026-09', "Datum;Stunden\n2026-09-01;8\n2026-09-02;7.5\n", 'text/csv', 'stundenzettel.csv', 'Stundenzettel September')
            ->attachment('AGB', externalUri: 'https://muster.de/agb', description: 'Allgemeine Geschäftsbedingungen');
    }

    public static function peppol(): InvoiceBuilder
    {
        return self::base()
            ->seller(fn ($s) => $s->electronicAddress('DE123456789', '9930'))
            ->buyer(fn ($b) => $b->name('Klant BV')->vatId('BE0123456749')->address('Kerkstraat 1', '1000', 'Brussel', 'BE')->electronicAddress('0123456749', '0208'))
            ->line(fn ($l) => $l->name('Consulting')->quantity(10, UnitCode::Hour)->netPrice('100.00')
                ->vat(VatCategory::ReverseCharge, '0', 'Reverse charge', 'VATEX-EU-AE'));
    }
}
