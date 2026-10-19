<?php

declare(strict_types=1);

namespace Quittio\Country\CH;

use Quittio\Core\Countries\AbstractCountryPack;
use Quittio\Core\Countries\Capabilities\CompletesInvoices;
use Quittio\Core\Countries\CorrectionPolicy;
use Quittio\Core\Countries\CountryDefaults;
use Quittio\Core\Countries\DunningLaw;
use Quittio\Core\Countries\MandatoryField;
use Quittio\Core\Countries\MandatoryFieldList;
use Quittio\Core\Countries\NumberingRules;
use Quittio\Core\Countries\RetentionRule;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\I18n\Translations;
use Quittio\Core\Pdf\Qr\PaymentQrGenerator;
use Quittio\Core\Tax\TaxPresetCollection;
use Quittio\Core\Validation\Rules\Inspect;
use Quittio\Country\CH\QrBill\SwissQrBillGenerator;
use Quittio\Country\CH\Rounding\CashRounding;
use Quittio\Country\CH\Rules\MwstgRules;
use Quittio\Country\CH\Tax\VatPreset;

/**
 * Country pack for Switzerland.
 *
 * Not reviewed by a tax adviser yet and not released (see the README of the pack).
 */
class CountryCH extends AbstractCountryPack implements CompletesInvoices
{
    public const string CODE = 'CH';

    public const string TRANSLATION_NAMESPACE = 'quittio-ch';

    /** Meta flag: round the amount due to 5 Rappen (CashRounding). */
    public const string FLAG_CASH_ROUNDING = 'ch.cash_rounding';

    private static ?int $translationsRegisteredFor = null;

    /** @var list<string>|null */
    private static ?array $locales = null;

    public function code(): string
    {
        return self::CODE;
    }

    public function defaults(): CountryDefaults
    {
        return new CountryDefaults(
            currency: 'CHF',
            timezone: 'Europe/Zurich',
            // Swiss formats (1'590.00); texts in German.
            locale: 'de_CH',
            paper: 'A4',
            letterLayout: 'free',
            profile: 'en16931',
            paymentQr: SwissQrBillGenerator::KEY,
            vatPreset: VatPreset::Standard->value,
            smallBusinessVatPreset: VatPreset::NotRegistered->value,
        );
    }

    public function taxPresets(): TaxPresetCollection
    {
        return new TaxPresetCollection(VatPreset::cases());
    }

    /**
     * Elements of an invoice according to Art. 26 MWSTG (applies to every format).
     */
    public function rules(): array
    {
        return MwstgRules::all();
    }

    /**
     * The UID of a seller that charges VAT, with the note of its registration (Art. 26 (2) lit. a MWSTG).
     */
    public function mandatoryOutput(InvoiceData $invoice): MandatoryFieldList
    {
        $fields = [];
        if (Inspect::filled($invoice->seller->vatId)) {
            $fields[] = new MandatoryField('seller.vatId', 'UID of the seller with the note MWST', 'Art. 26 Abs. 2 Bst. a MWSTG');
        }
        if ($invoice->delivery?->actualDeliveryDate !== null || $invoice->invoicingPeriod !== null) {
            $fields[] = new MandatoryField('servicePeriod|deliveryDate|delivery.date', 'Date or period of the supply', 'Art. 26 Abs. 2 Bst. c MWSTG');
        }

        return new MandatoryFieldList($fields);
    }

    /**
     * The Swiss QR bill: Swiss QR Code with payment part and receipt.
     */
    public function paymentQr(): PaymentQrGenerator
    {
        return new SwissQrBillGenerator;
    }

    /**
     * Rounds the amount due to 5 Rappen (BT-114) for invoices in CHF with the flag
     * FLAG_CASH_ROUNDING or the setting cash_rounding = true (quittio.countries.ch).
     */
    public function complete(InvoiceData $invoice, array $settings): InvoiceData
    {
        if (! $invoice->meta->hasFlag(self::FLAG_CASH_ROUNDING) && ($settings['cash_rounding'] ?? false) !== true) {
            return $invoice;
        }

        return CashRounding::apply($invoice);
    }

    /**
     * Invoice numbers are not prescribed in Switzerland; unique numbers are good practice.
     */
    public function numberingRules(): NumberingRules
    {
        return new NumberingRules(
            defaultPattern: 'RE-{Y}-{seq:4}',
            defaultReset: 'yearly',
            allowedResets: ['never', 'yearly', 'monthly'],
            gapless: false,
            numberConsumedOnRejection: true,
        );
    }

    /**
     * Art. 104 OR: 5 % default interest for businesses and consumers alike, no flat rate.
     */
    public function dunningLaw(): DunningLaw
    {
        return self::swissDunningLaw();
    }

    public static function swissDunningLaw(): DunningLaw
    {
        return new DunningLaw(
            b2bInterestPoints: '0.00',
            b2cInterestPoints: '0.00',
            b2bFlatRate: null,
            dayCount: 365,
            consumerDefaultAfterDays: null,
            referenceRateName: null,
            b2cFixedRate: '5.00',
            b2bFixedRate: '5.00',
        );
    }

    /**
     * Cancellation by credit note, correction by cancel and reissue.
     */
    public function correctionPolicy(): CorrectionPolicy
    {
        return new CorrectionPolicy(
            cancellationStrategy: CorrectionPolicy::CANCELLATION_CREDIT_NOTE,
            correctionStrategy: CorrectionPolicy::CORRECTION_CANCEL_AND_REISSUE,
            titles: [
                'cancellation' => ['de' => 'Gutschrift', 'fr' => 'Note de crédit', 'it' => 'Nota di credito', 'en' => 'Credit note'],
                'correction' => ['de' => 'Korrigierte Rechnung', 'fr' => 'Facture corrigée', 'it' => 'Fattura corretta', 'en' => 'Corrected invoice'],
                'self_billed' => ['de' => 'Gutschrift (Selbstfakturierung)', 'fr' => 'Autofacturation', 'it' => 'Autofatturazione', 'en' => 'Self-billed invoice'],
            ],
        );
    }

    /**
     * Ten years (Art. 70 (2) MWSTG, Art. 958f OR). Invoices on immovable property have 20 years
     * (Art. 70 (3) MWSTG), which the pack does not model.
     */
    public function retention(): RetentionRule
    {
        return new RetentionRule(10, fromEndOfCalendarYear: true);
    }

    public function translationPath(): string
    {
        return dirname(__DIR__).'/resources/lang';
    }

    /**
     * Locales the pack ships translations for, e.g. ["de", "en", "fr", "it"].
     *
     * @return list<string>
     */
    public static function locales(): array
    {
        if (self::$locales === null) {
            $directories = glob(dirname(__DIR__).'/resources/lang/*', GLOB_ONLYDIR);
            self::$locales = array_map(basename(...), $directories === false ? [] : $directories);
        }

        return self::$locales;
    }

    /**
     * Makes the pack's translations available to the active translator. Called lazily, so presets
     * work even without a registered Quittio instance.
     */
    public static function registerTranslations(): void
    {
        $translator = Translations::translator();
        if (self::$translationsRegisteredFor === Translations::version()) {
            return;
        }

        $translator->addPath(dirname(__DIR__).'/resources/lang', self::TRANSLATION_NAMESPACE);
        self::$translationsRegisteredFor = Translations::version();
    }
}
