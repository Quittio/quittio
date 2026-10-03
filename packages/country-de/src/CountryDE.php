<?php

declare(strict_types=1);

namespace Quittio\Country\DE;

use Quittio\Core\Countries\AbstractCountryPack;
use Quittio\Core\Countries\CorrectionPolicy;
use Quittio\Core\Countries\CountryDefaults;
use Quittio\Core\Countries\DunningLaw;
use Quittio\Core\Countries\LegalTextCatalog;
use Quittio\Core\Countries\MandatoryField;
use Quittio\Core\Countries\MandatoryFieldList;
use Quittio\Core\Countries\NumberingRules;
use Quittio\Core\Countries\RetentionRule;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Enums\InvoiceTypeCode;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\I18n\Translations;
use Quittio\Core\Tax\TaxPresetCollection;
use Quittio\Core\Validation\Rules\Inspect;
use Quittio\Core\Validation\ValidationMessage;
use Quittio\Core\Validation\ValidationResult;
use Quittio\Country\DE\Builder\GermanInvoiceMacros;
use Quittio\Country\DE\Formats\GermanFormatRule;
use Quittio\Country\DE\Layouts\Din5008;
use Quittio\Country\DE\Profiles\XRechnung\XRechnung30;
use Quittio\Country\DE\Profiles\XRechnung\XRechnung30Extension;
use Quittio\Country\DE\Rules\BmfErrorClass;
use Quittio\Country\DE\Rules\UstgRules;
use Quittio\Country\DE\Support\Flags;
use Quittio\Country\DE\Tax\VatPreset;
use Quittio\Country\DE\Texts\LegalTexts;

/**
 * Country pack for Germany (specification 25.6).
 */
class CountryDE extends AbstractCountryPack
{
    public const string CODE = 'DE';

    public const string TRANSLATION_NAMESPACE = 'quittio-de';

    private static ?int $translationsRegisteredFor = null;

    public function __construct()
    {
        GermanInvoiceMacros::register();
    }

    public function code(): string
    {
        return self::CODE;
    }

    public function defaults(): CountryDefaults
    {
        return new CountryDefaults(
            currency: 'EUR',
            timezone: 'Europe/Berlin',
            locale: 'de',
            paper: 'A4',
            letterLayout: 'din5008b',
            profile: 'en16931',
            paymentQr: 'girocode',
        );
    }

    public function profiles(): array
    {
        return [new XRechnung30, new XRechnung30Extension];
    }

    public function taxPresets(): TaxPresetCollection
    {
        return new TaxPresetCollection(VatPreset::cases());
    }

    /**
     * Mandatory information according to section 14 (4) and 14a UStG (applies to every format).
     */
    public function rules(): array
    {
        return [...UstgRules::all(), ...UstgRules::plausibility()];
    }

    public function formatRules(): array
    {
        return [new GermanFormatRule];
    }

    /**
     * Values the PDF must show in addition to the base fields (specification 11.8).
     */
    public function mandatoryOutput(InvoiceData $invoice): MandatoryFieldList
    {
        $fields = [];
        $small = $invoice->meta->hasFlag(Flags::SMALL_AMOUNT);

        if (! $small && (Inspect::filled($invoice->seller->vatId) || Inspect::filled($invoice->seller->taxNumber))) {
            $fields[] = new MandatoryField('seller.vatId|seller.taxNumber', 'Tax number or VAT identifier of the seller', '§ 14 Abs. 4 Nr. 2 UStG');
        }
        if (! $small && ! UstgRules::isSmallBusinessInvoice($invoice) && UstgRules::hasServiceDate($invoice)) {
            $fields[] = new MandatoryField('servicePeriod|lineGroups', 'Date of the supply or service', '§ 14 Abs. 4 Nr. 6 UStG');
        }
        if ($invoice->typeCode === InvoiceTypeCode::SelfBilledInvoice) {
            $fields[] = new MandatoryField('title', 'Label "Gutschrift"', '§ 14 Abs. 4 Nr. 10 UStG');
            $fields[] = new MandatoryField('notes', 'Note "Gutschrift"', '§ 14 Abs. 4 Nr. 10 UStG');
        }
        if ($invoice->meta->hasFlag(Flags::PROPERTY_RELATED_SERVICE)) {
            $fields[] = new MandatoryField('notes', 'Retention notice for property related services', '§ 14 Abs. 4 Nr. 9 UStG');
        }
        foreach ([VatCategory::ReverseCharge, VatCategory::IntraCommunitySupply] as $category) {
            if (Inspect::uses($invoice, $category)) {
                $fields[] = new MandatoryField('buyer.vatId', 'VAT identifier of the buyer', '§ 14a Abs. 1 und 3 UStG');
                $fields[] = new MandatoryField('legalNotes', 'Reverse charge or exemption note', '§ 14a UStG');
            }
        }

        return new MandatoryFieldList($fields);
    }

    public function legalTexts(): LegalTextCatalog
    {
        return LegalTexts::catalog();
    }

    /**
     * Invoice numbers must be unique and consecutive; gaps must be explainable.
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
     * Section 288 BGB: base rate plus 9 points (business) or 5 points (consumer),
     * flat rate of 40 EUR for business debtors, consumer default after 30 days with notice.
     */
    public function dunningLaw(): DunningLaw
    {
        return new DunningLaw(
            b2bInterestPoints: '9.00',
            b2cInterestPoints: '5.00',
            b2bFlatRate: '40.00',
            dayCount: 365,
            consumerDefaultAfterDays: 30,
            referenceRateName: 'Basiszinssatz (§ 247 BGB)',
        );
    }

    /**
     * Cancellation by credit note, correction by cancel and reissue. To be confirmed with
     * a tax adviser (open decision in README).
     */
    public function correctionPolicy(): CorrectionPolicy
    {
        return new CorrectionPolicy(
            cancellationStrategy: CorrectionPolicy::CANCELLATION_CREDIT_NOTE,
            correctionStrategy: CorrectionPolicy::CORRECTION_CANCEL_AND_REISSUE,
            titles: [
                'cancellation' => ['de' => 'Stornorechnung', 'en' => 'Cancellation invoice'],
                'correction' => ['de' => 'Rechnungskorrektur', 'en' => 'Corrected invoice'],
                'self_billed' => ['de' => 'Gutschrift', 'en' => 'Self-billed invoice (Gutschrift)'],
            ],
        );
    }

    public function letterLayouts(): array
    {
        return Din5008::all();
    }

    /**
     * Groups findings by the error classes of the BMF letter of 15 October 2025.
     *
     * @return array<string, list<ValidationMessage>>
     */
    public function errorClassification(ValidationResult $result): array
    {
        $classes = [];
        foreach ([...$result->errors(), ...$result->warnings()] as $message) {
            $classes[BmfErrorClass::of($message)->value][] = $message;
        }

        return $classes;
    }

    /**
     * Invoices are business records: 8 years from the end of the calendar year of issue
     * (section 14b UStG, section 147 AO as amended in 2025).
     */
    public function retention(): RetentionRule
    {
        return new RetentionRule(8, fromEndOfCalendarYear: true);
    }

    public function translationPath(): string
    {
        return dirname(__DIR__).'/resources/lang';
    }

    /**
     * Makes the pack's translations available to the active translator. Called lazily,
     * so presets work even without a registered Quittio instance.
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
