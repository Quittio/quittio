<?php

declare(strict_types=1);

namespace Quittio\Country\AT;

use Quittio\Core\Countries\AbstractCountryPack;
use Quittio\Core\Countries\Capabilities\ProvidesReferenceRates;
use Quittio\Core\Countries\CorrectionPolicy;
use Quittio\Core\Countries\CountryDefaults;
use Quittio\Core\Countries\DunningLaw;
use Quittio\Core\Countries\LegalTextCatalog;
use Quittio\Core\Countries\MandatoryField;
use Quittio\Core\Countries\MandatoryFieldList;
use Quittio\Core\Countries\NumberingRules;
use Quittio\Core\Countries\RetentionRule;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Dunning\ReferenceRates;
use Quittio\Core\Enums\InvoiceTypeCode;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\I18n\Translations;
use Quittio\Core\Tax\TaxPresetCollection;
use Quittio\Core\Validation\Rules\Inspect;
use Quittio\Core\View\ViewModelFactory;
use Quittio\Country\AT\Dunning\BaseRateTable;
use Quittio\Country\AT\EbInterface\EbInterfaceReader;
use Quittio\Country\AT\Formats\AustrianFormatRule;
use Quittio\Country\AT\Profiles\CiusAtGov;
use Quittio\Country\AT\Profiles\CiusAtNat;
use Quittio\Country\AT\Rules\UstgRules;
use Quittio\Country\AT\Tax\VatPreset;
use Quittio\Country\AT\Texts\LegalTexts;

/**
 * Country pack for Austria.
 *
 * Not reviewed by a tax adviser yet (see the README of the pack).
 */
class CountryAT extends AbstractCountryPack implements ProvidesReferenceRates
{
    public const string CODE = 'AT';

    public const string TRANSLATION_NAMESPACE = 'quittio-at';

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
            timezone: 'Europe/Vienna',
            locale: 'de',
            paper: 'A4',
            letterLayout: 'free',
            profile: 'en16931',
            paymentQr: 'girocode',
            vatPreset: VatPreset::Standard->value,
            smallBusinessVatPreset: VatPreset::SmallBusiness->value,
        );
    }

    public function profiles(): array
    {
        return [new CiusAtNat, new CiusAtGov];
    }

    public function taxPresets(): TaxPresetCollection
    {
        return new TaxPresetCollection(VatPreset::cases());
    }

    /**
     * Mandatory information according to section 11 UStG 1994 (applies to every format).
     */
    public function rules(): array
    {
        return UstgRules::all();
    }

    public function formatRules(): array
    {
        return [new AustrianFormatRule];
    }

    /**
     * Values the PDF must show in addition to the base fields. Paths with "[*]" apply to every
     * index; paths without a value in the view model are skipped.
     */
    public function mandatoryOutput(InvoiceData $invoice): MandatoryFieldList
    {
        $fields = [];
        $simplified = UstgRules::isSimplifiedInvoice($invoice);

        if (! $simplified && (Inspect::filled($invoice->seller->vatId) || Inspect::filled($invoice->sellerTaxRepresentative?->vatId))) {
            $fields[] = new MandatoryField('seller.vatId|taxRepresentative.vatId', 'VAT identifier (UID) of the seller', '§ 11 Abs. 1 Z 3 lit. i UStG');
        }
        $fields[] = $invoice->invoicingPeriod !== null || $invoice->delivery?->actualDeliveryDate !== null
            ? new MandatoryField('servicePeriod|deliveryDate|delivery.date', 'Date of the supply or service', '§ 11 Abs. 1 Z 3 lit. d UStG')
            : new MandatoryField('servicePeriod|lineGroups[*].lines[*].period|lines[*].period', 'Date of the supply or service', '§ 11 Abs. 1 Z 3 lit. d UStG');
        if (! $simplified && Inspect::filled($invoice->buyer->vatId) && UstgRules::needsBuyerVatId($invoice)) {
            $fields[] = new MandatoryField('buyer.vatId', 'VAT identifier (UID) of the buyer', '§ 11 Abs. 1 Z 3 lit. b UStG');
        }
        if ($invoice->typeCode instanceof InvoiceTypeCode && $invoice->typeCode->isSelfBilled()) {
            $fields[] = new MandatoryField('title', 'Label "Gutschrift"', '§ 11 Abs. 8 Z 3 UStG');
        }

        $legalNotes = ViewModelFactory::legalNoteTexts($invoice);
        $rowIndexes = ViewModelFactory::vatRowIndexes($invoice);
        foreach ($invoice->vatBreakdown as $index => $row) {
            if (! in_array($row->category, [VatCategory::ReverseCharge, VatCategory::IntraCommunitySupply, VatCategory::Export, VatCategory::Exempt], true)) {
                continue;
            }
            // The exemption text (BT-120), or the VAT row that names reverse charge or the exemption.
            $note = $row->exemptionReason === null ? false : array_search($row->exemptionReason, $legalNotes, true);
            $fields[] = new MandatoryField('vatRows['.$rowIndexes[$index].'].label'.($note === false ? '' : '|legalNotes['.$note.']'), 'Reverse charge or exemption note', '§ 11 Abs. 1 Z 3 lit. e und Abs. 1a UStG');
        }
        if (Inspect::uses($invoice, VatCategory::ReverseCharge) || Inspect::uses($invoice, VatCategory::IntraCommunitySupply)) {
            $fields[] = new MandatoryField('buyer.vatId', 'VAT identifier (UID) of the buyer', '§ 11 Abs. 1a UStG, Art. 11 UStG');
        }

        return new MandatoryFieldList($fields);
    }

    public function legalTexts(): LegalTextCatalog
    {
        return LegalTexts::catalog();
    }

    /**
     * A consecutive number with one or more series that identifies the invoice once (section 11
     * (1) no. 3 lit. h UStG).
     */
    public function numberingRules(): NumberingRules
    {
        return new NumberingRules(
            defaultPattern: 'RE-{Y}-{seq:4}',
            defaultReset: 'yearly',
            allowedResets: ['never', 'yearly', 'monthly'],
            gapless: true,
            numberConsumedOnRejection: true,
        );
    }

    /**
     * Section 456 UGB: base rate plus 9.2 points between businesses, flat rate of 40 EUR (section
     * 458 UGB); consumers owe 4 % (section 1000 (1) ABGB).
     */
    public function dunningLaw(): DunningLaw
    {
        return self::austrianDunningLaw();
    }

    /**
     * The dunning law of the UGB and the ABGB without an instance of the pack.
     */
    public static function austrianDunningLaw(): DunningLaw
    {
        return new DunningLaw(
            b2bInterestPoints: '9.20',
            b2cInterestPoints: '0.00',
            b2bFlatRate: '40.00',
            dayCount: 365,
            consumerDefaultAfterDays: null,
            referenceRateName: 'Basiszinssatz (OeNB, § 456 UGB)',
            b2cFixedRate: '4.00',
        );
    }

    /**
     * The Basiszinssatz of the OeNB as published at the release of the pack.
     */
    public function referenceRates(): ReferenceRates
    {
        return new BaseRateTable;
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
                'cancellation' => ['de' => 'Stornorechnung', 'en' => 'Cancellation invoice'],
                'cancellation_self_billed' => ['de' => 'Storno-Gutschrift', 'en' => 'Cancellation of the self-billed invoice (Storno-Gutschrift)'],
                'correction' => ['de' => 'Rechnungsberichtigung', 'en' => 'Corrected invoice'],
                'self_billed' => ['de' => 'Gutschrift', 'en' => 'Self-billed invoice (Gutschrift)'],
            ],
        );
    }

    /**
     * Copies of invoices are kept for seven years from the end of the calendar year (section 11 (2)
     * UStG, section 132 BAO). Invoices for property related supplies have longer periods (section
     * 18 (10) UStG), which the pack does not model.
     */
    public function retention(): RetentionRule
    {
        return new RetentionRule(7, fromEndOfCalendarYear: true);
    }

    /**
     * Reads ebInterface 6.0 and 6.1 invoices.
     */
    public function readers(): array
    {
        return [new EbInterfaceReader];
    }

    public function translationPath(): string
    {
        return dirname(__DIR__).'/resources/lang';
    }

    /**
     * Locales the pack ships translations for (the directories of translationPath()), e.g. ["de", "en"].
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
