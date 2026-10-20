<?php

declare(strict_types=1);

namespace Quittio\Country\BE;

use Quittio\Core\Countries\AbstractCountryPack;
use Quittio\Core\Countries\Capabilities\CompletesInvoices;
use Quittio\Core\Countries\Capabilities\ProvidesReferenceRates;
use Quittio\Core\Countries\CorrectionPolicy;
use Quittio\Core\Countries\CountryDefaults;
use Quittio\Core\Countries\DunningLaw;
use Quittio\Core\Countries\MandatoryField;
use Quittio\Core\Countries\MandatoryFieldList;
use Quittio\Core\Countries\NumberingRules;
use Quittio\Core\Countries\RetentionRule;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Data\Party\ElectronicAddress;
use Quittio\Core\Dunning\ReferenceRates;
use Quittio\Core\Enums\ElectronicAddressScheme;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\I18n\Translations;
use Quittio\Core\Tax\TaxPresetCollection;
use Quittio\Core\Validation\Rules\Inspect;
use Quittio\Core\View\ViewModelFactory;
use Quittio\Country\BE\Dunning\ReferenceRateTable;
use Quittio\Country\BE\Formats\BelgianFormatRule;
use Quittio\Country\BE\Rules\VatCodeRules;
use Quittio\Country\BE\Support\EnterpriseNumber;
use Quittio\Country\BE\Tax\VatPreset;

/**
 * Country pack for Belgium.
 *
 * Not reviewed by a tax adviser yet (see the README of the pack).
 */
class CountryBE extends AbstractCountryPack implements CompletesInvoices, ProvidesReferenceRates
{
    public const string CODE = 'BE';

    public const string TRANSLATION_NAMESPACE = 'quittio-be';

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
            currency: 'EUR',
            timezone: 'Europe/Brussels',
            locale: 'nl_BE',
            paper: 'A4',
            letterLayout: 'free',
            profile: 'peppol',
            paymentQr: 'girocode',
            vatPreset: VatPreset::Standard->value,
            smallBusinessVatPreset: VatPreset::SmallBusiness->value,
        );
    }

    public function taxPresets(): TaxPresetCollection
    {
        return new TaxPresetCollection(VatPreset::cases());
    }

    /**
     * Mentions of Art. 5 Royal Decree No. 1 and the use of languages (applies to every format).
     */
    public function rules(): array
    {
        return VatCodeRules::all();
    }

    public function formatRules(): array
    {
        return [new BelgianFormatRule];
    }

    public function mandatoryOutput(InvoiceData $invoice): MandatoryFieldList
    {
        $fields = [];
        if (Inspect::filled($invoice->seller->vatId) || Inspect::filled($invoice->sellerTaxRepresentative?->vatId)) {
            $fields[] = new MandatoryField('seller.vatId|taxRepresentative.vatId', 'VAT number of the seller', 'Art. 5 § 1, 3° KB nr. 1');
        }
        if (Inspect::filled($invoice->buyer->vatId)) {
            $fields[] = new MandatoryField('buyer.vatId', 'VAT number of the buyer', 'Art. 5 § 1, 4° KB nr. 1');
        }

        $legalNotes = ViewModelFactory::legalNoteTexts($invoice);
        $rowIndexes = ViewModelFactory::vatRowIndexes($invoice);
        foreach ($invoice->vatBreakdown as $index => $row) {
            if (! in_array($row->category, [VatCategory::ReverseCharge, VatCategory::IntraCommunitySupply, VatCategory::Export, VatCategory::Exempt], true)) {
                continue;
            }
            $note = $row->exemptionReason === null ? false : array_search($row->exemptionReason, $legalNotes, true);
            $fields[] = new MandatoryField('vatRows['.$rowIndexes[$index].'].label'.($note === false ? '' : '|legalNotes['.$note.']'), 'Reverse charge or exemption mention', 'Art. 5 § 1, 10° KB nr. 1');
        }

        return new MandatoryFieldList($fields);
    }

    /**
     * Peppol identifies Belgian businesses by their enterprise number (scheme 0208): a seller or
     * buyer without electronic address (BT-34, BT-49) gets the enterprise number of its Belgian
     * VAT number, unless the setting peppol_endpoints is false (quittio.countries.be).
     */
    public function complete(InvoiceData $invoice, array $settings): InvoiceData
    {
        if (($settings['peppol_endpoints'] ?? true) === false) {
            return $invoice;
        }
        $seller = $invoice->seller;
        $buyer = $invoice->buyer;
        $sellerNumber = $seller->electronicAddress === null ? EnterpriseNumber::fromVatId($seller->vatId) : null;
        $buyerNumber = $buyer->electronicAddress === null ? EnterpriseNumber::fromVatId($buyer->vatId) : null;
        if ($sellerNumber === null && $buyerNumber === null) {
            return $invoice;
        }

        return $invoice->with([
            'seller' => $sellerNumber === null ? $seller : $seller->with(['electronicAddress' => new ElectronicAddress($sellerNumber, ElectronicAddressScheme::BelgianEnterpriseNumber)]),
            'buyer' => $buyerNumber === null ? $buyer : $buyer->with(['electronicAddress' => new ElectronicAddress($buyerNumber, ElectronicAddressScheme::BelgianEnterpriseNumber)]),
        ]);
    }

    /**
     * A sequential number in one or more series (Art. 5 § 1, 2° Royal Decree No. 1).
     */
    public function numberingRules(): NumberingRules
    {
        return new NumberingRules(
            defaultPattern: 'F-{Y}-{seq:4}',
            defaultReset: 'yearly',
            allowedResets: ['never', 'yearly'],
            gapless: true,
            numberConsumedOnRejection: true,
        );
    }

    /**
     * Law of 2 August 2002: the reference rate plus 8 points between businesses and a flat rate
     * of 40 EUR; consumers owe the legal interest rate, 4.5 % in 2026.
     */
    public function dunningLaw(): DunningLaw
    {
        return self::belgianDunningLaw();
    }

    public static function belgianDunningLaw(): DunningLaw
    {
        return new DunningLaw(
            b2bInterestPoints: '8.00',
            b2cInterestPoints: '0.00',
            b2bFlatRate: '40.00',
            dayCount: 365,
            consumerDefaultAfterDays: null,
            referenceRateName: 'Referentie-interestvoet (wet van 2 augustus 2002)',
            b2cFixedRate: '4.50',
        );
    }

    public function referenceRates(): ReferenceRates
    {
        return new ReferenceRateTable;
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
                'cancellation' => ['nl' => 'Creditnota', 'fr' => 'Note de crédit', 'de' => 'Gutschrift', 'en' => 'Credit note'],
                'correction' => ['nl' => 'Verbeterde factuur', 'fr' => 'Facture rectificative', 'de' => 'Berichtigte Rechnung', 'en' => 'Corrected invoice'],
                'self_billed' => ['nl' => 'Zelffacturering', 'fr' => 'Autofacturation', 'de' => 'Gutschrift (Selbstfakturierung)', 'en' => 'Self-billed invoice'],
            ],
        );
    }

    /**
     * Seven years from the end of the calendar year of issue (Art. 60 § 3 VAT Code, as amended by
     * the law of 18 December 2025, for VAT due from 2023).
     */
    public function retention(): RetentionRule
    {
        return new RetentionRule(7, fromEndOfCalendarYear: true);
    }

    public function translationPath(): string
    {
        return dirname(__DIR__).'/resources/lang';
    }

    /**
     * Locales the pack ships translations for, e.g. ["de", "en", "fr", "nl"].
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
     * Makes the pack's translations available to the active translator.
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
