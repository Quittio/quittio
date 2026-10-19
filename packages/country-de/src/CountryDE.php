<?php

declare(strict_types=1);

namespace Quittio\Country\DE;

use Quittio\Core\Countries\AbstractCountryPack;
use Quittio\Core\Countries\Capabilities\CompletesInvoices;
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
use Quittio\Core\Data\Note;
use Quittio\Core\Dunning\ReferenceRates;
use Quittio\Core\Enums\InvoiceTypeCode;
use Quittio\Core\Enums\NoteSubjectCode;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\I18n\Translations;
use Quittio\Core\Tax\TaxPresetCollection;
use Quittio\Core\Validation\Rules\Inspect;
use Quittio\Core\Validation\ValidationMessage;
use Quittio\Core\Validation\ValidationResult;
use Quittio\Core\View\MandatoryFieldGuard;
use Quittio\Core\View\ViewModelFactory;
use Quittio\Country\DE\Builder\GermanInvoiceMacros;
use Quittio\Country\DE\Dunning\BaseRates;
use Quittio\Country\DE\Formats\GermanFormatRule;
use Quittio\Country\DE\Formats\XRechnungFormats;
use Quittio\Country\DE\Layouts\Din5008;
use Quittio\Country\DE\Profiles\XRechnung\XRechnung30;
use Quittio\Country\DE\Profiles\XRechnung\XRechnung30Cvd;
use Quittio\Country\DE\Profiles\XRechnung\XRechnung30Extension;
use Quittio\Country\DE\Rules\BmfErrorClass;
use Quittio\Country\DE\Rules\UstgRules;
use Quittio\Country\DE\Support\Flags;
use Quittio\Country\DE\Support\LeitwegId;
use Quittio\Country\DE\Tax\VatPreset;
use Quittio\Country\DE\Texts\LegalTexts;

/**
 * Country pack for Germany.
 */
class CountryDE extends AbstractCountryPack implements CompletesInvoices, ProvidesReferenceRates
{
    public const string CODE = 'DE';

    public const string TRANSLATION_NAMESPACE = 'quittio-de';

    private static ?int $translationsRegisteredFor = null;

    /** @var list<string>|null */
    private static ?array $locales = null;

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
            vatPreset: VatPreset::Standard->value,
            smallBusinessVatPreset: VatPreset::SmallBusiness->value,
        );
    }

    public function profiles(): array
    {
        return [new XRechnung30, new XRechnung30Extension, new XRechnung30Cvd];
    }

    /**
     * XRechnung in CII and UBL; "xrechnung" names the CII variant.
     */
    public function formats(): array
    {
        return XRechnungFormats::all();
    }

    public function formatAliases(): array
    {
        return [XRechnungFormats::ALIAS => XRechnungFormats::CII];
    }

    public function taxPresets(): TaxPresetCollection
    {
        $presets = array_filter(VatPreset::cases(), static fn (VatPreset $preset): bool => $preset->canonical() === $preset);

        // Keys of earlier versions, e.g. in stored invoices or quittio.defaults.vat.
        return (new TaxPresetCollection($presets))
            ->alias('de.standard_19', VatPreset::Standard)
            ->alias('de.reduced_7', VatPreset::Reduced);
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
     * Values the PDF must show in addition to the base fields. Paths
     * with "[*]" apply to every index; paths without a value in the view model are skipped.
     */
    public function mandatoryOutput(InvoiceData $invoice): MandatoryFieldList
    {
        $fields = [];
        $small = UstgRules::isSmallAmountInvoice($invoice);

        $representative = Inspect::filled($invoice->sellerTaxRepresentative?->vatId);
        if (! $small && (Inspect::filled($invoice->seller->vatId) || Inspect::filled($invoice->seller->taxNumber) || $representative)) {
            $fields[] = new MandatoryField('seller.vatId|seller.taxNumber|taxRepresentative.vatId', 'Tax number or VAT identifier of the seller', '§ 14 Abs. 4 Nr. 2 UStG');
        }
        if (! $small && ! UstgRules::isSmallBusinessInvoice($invoice)) {
            // BT-72 or BG-14 are printed as servicePeriod (BT-72 also as deliveryDate or delivery.date);
            // otherwise the period of every line that has one (servicePeriod then only summarises them).
            $fields[] = $invoice->invoicingPeriod !== null || $invoice->delivery?->actualDeliveryDate !== null
                ? new MandatoryField('servicePeriod|deliveryDate|delivery.date', 'Date of the supply or service', '§ 14 Abs. 4 Nr. 6 UStG')
                : new MandatoryField('servicePeriod|lineGroups[*].lines[*].period|lines[*].period', 'Date of the supply or service', '§ 14 Abs. 4 Nr. 6 UStG');
        }
        if ($invoice->typeCode instanceof InvoiceTypeCode && $invoice->typeCode->isSelfBilled()) {
            // The title "Gutschrift" is the required label; a "Gutschrift" note must be printed too.
            $fields[] = new MandatoryField('title', 'Label "Gutschrift"', '§ 14 Abs. 4 Nr. 10 UStG');
            $note = MandatoryFieldGuard::notePath($invoice, static fn (Note $n): bool => stripos($n->content, 'gutschrift') !== false);
            if ($note !== null) {
                $fields[] = new MandatoryField($note, 'Note "Gutschrift"', '§ 14 Abs. 4 Nr. 10 UStG');
            }
        }
        if ($invoice->meta->hasFlag(Flags::PROPERTY_RELATED_SERVICE)) {
            $texts = array_filter([LegalTexts::catalog()->get(LegalTexts::PROPERTY_RETENTION, 'de'), LegalTexts::catalog()->get(LegalTexts::PROPERTY_RETENTION, 'en')], static fn (?string $text): bool => $text !== null);
            $note = MandatoryFieldGuard::notePath($invoice, static fn (Note $n): bool => in_array(trim($n->content), $texts, true) || str_contains($n->content, '14b'));
            if ($note !== null) {
                $fields[] = new MandatoryField($note, 'Retention notice for property related services', '§ 14 Abs. 4 Nr. 9 UStG');
            }
        }

        $legalNotes = ViewModelFactory::legalNoteTexts($invoice);
        $rowIndexes = ViewModelFactory::vatRowIndexes($invoice);
        foreach ($invoice->vatBreakdown as $index => $row) {
            if (! in_array($row->category, [VatCategory::ReverseCharge, VatCategory::IntraCommunitySupply], true)) {
                continue;
            }
            // The exemption text (BT-120), or the VAT row that names reverse charge or the exemption.
            $note = $row->exemptionReason === null ? false : array_search($row->exemptionReason, $legalNotes, true);
            $fields[] = new MandatoryField('vatRows['.$rowIndexes[$index].'].label'.($note === false ? '' : '|legalNotes['.$note.']'), 'Reverse charge or exemption note', '§ 14a UStG');
        }
        $reverseCharge = Inspect::uses($invoice, VatCategory::ReverseCharge);
        $intraCommunity = Inspect::uses($invoice, VatCategory::IntraCommunitySupply);
        if ($reverseCharge || $intraCommunity) {
            $fields[] = new MandatoryField('buyer.vatId', 'VAT identifier of the buyer', '§ 14a Abs. 1 und 3 UStG');
        }
        if ($intraCommunity || ($reverseCharge && $invoice->buyer->address->countryCode !== $invoice->seller->address->countryCode)) {
            // Cross-border reverse charge and intra-community supplies need the USt-IdNr., not the tax number.
            $fields[] = new MandatoryField('seller.vatId|taxRepresentative.vatId', 'VAT identifier of the seller', '§ 14a Abs. 1 und 3 UStG');
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
     * Invoices to consumers get the notice that the consumer is in default 30 days after the due
     * date and receipt of the invoice (LegalTexts::CONSUMER_DEFAULT, subject PMT): without it the
     * 30 days do not apply to consumers (section 286 (3) sentence 1 BGB). Not for credit notes,
     * not twice, and not with the setting consumer_default_notice = false (quittio.countries.de).
     */
    public function complete(InvoiceData $invoice, array $settings): InvoiceData
    {
        if (($settings['omit_vat_ids_for_category_o'] ?? true) !== false && self::canOmitVatIds($invoice)) {
            $invoice = $invoice->with([
                'seller' => $invoice->seller->with(['vatId' => null]),
                'buyer' => $invoice->buyer->with(['vatId' => null]),
                'meta' => $invoice->meta->withFlag(Flags::VAT_IDS_OMITTED),
            ]);
        }
        if (($settings['consumer_default_notice'] ?? true) === false || ! $invoice->buyer->isConsumer || $invoice->isCreditNote()) {
            return $invoice;
        }
        $texts = array_values(LegalTexts::catalog()->texts[LegalTexts::CONSUMER_DEFAULT] ?? []);
        foreach ($invoice->notes as $note) {
            if (str_contains($note->content, '286') || in_array(trim($note->content), $texts, true)) {
                return $invoice;
            }
        }

        return $invoice->with(['notes' => [
            ...$invoice->notes,
            new Note(LegalTexts::text(LegalTexts::CONSUMER_DEFAULT, $invoice->meta->locale ?? LegalTexts::FALLBACK_LOCALE), NoteSubjectCode::PaymentInformation),
        ]]);
    }

    /**
     * Whether only supplies not subject to VAT (category O) are invoiced, so that EN 16931 forbids
     * the VAT identifiers of seller and buyer (BR-O-02).
     */
    public static function onlyCategoryO(InvoiceData $invoice): bool
    {
        $lines = $invoice->allLines();

        return $lines !== [] && array_filter($lines, static fn ($line): bool => $line->vat->category !== VatCategory::NotSubjectToVat) === [];
    }

    /**
     * An invoice of category O only with a VAT identifier that complete() may leave out: the
     * seller states its tax number instead (section 14 (4) sentence 1 no. 2 UStG), and there is no
     * tax representative, whose VAT identifier EN 16931 requires (BR-56) and forbids (BR-O-02).
     */
    public static function canOmitVatIds(InvoiceData $invoice): bool
    {
        return self::onlyCategoryO($invoice) && $invoice->sellerTaxRepresentative === null
            && Inspect::filled($invoice->seller->taxNumber)
            && (Inspect::filled($invoice->seller->vatId) || Inspect::filled($invoice->buyer->vatId));
    }

    /**
     * Section 288 BGB: base rate plus 9 points (business) or 5 points (consumer),
     * flat rate of 40 EUR for business debtors, consumer default after 30 days with notice.
     */
    public function dunningLaw(): DunningLaw
    {
        return self::germanDunningLaw();
    }

    /**
     * The dunning law of section 288 BGB without an instance of the pack (whose constructor
     * registers the builder macros).
     */
    public static function germanDunningLaw(): DunningLaw
    {
        return new DunningLaw(
            b2bInterestPoints: '9.00',
            b2cInterestPoints: '5.00',
            b2bFlatRate: '40.00',
            // Actual/actual: 366 days in a leap year, as the statutory day count conventions do
            // (annex to section 16 PAngV); 365 would overstate the interest of a leap year.
            dayCount: null,
            consumerDefaultAfterDays: 30,
            referenceRateName: 'Basiszinssatz (§ 247 BGB)',
        );
    }

    /**
     * The Basiszinssatz (section 247 BGB): the shipped rates plus those registered with BaseRates.
     */
    public function referenceRates(): ReferenceRates
    {
        return BaseRates::table();
    }

    /**
     * Cancellation by credit note, correction by cancel and reissue. A self-billed invoice sent
     * as XRechnung (with a Leitweg-ID) is cancelled by a negative self-billed invoice (389): the
     * self-billed credit note (261) is not among the type codes of XRechnung (warning BR-DE-17).
     * Confirmed by a tax adviser in October 2026 (legal review in the README).
     */
    public function correctionPolicy(): CorrectionPolicy
    {
        return new CorrectionPolicy(
            cancellationStrategy: CorrectionPolicy::CANCELLATION_CREDIT_NOTE,
            correctionStrategy: CorrectionPolicy::CORRECTION_CANCEL_AND_REISSUE,
            titles: [
                'cancellation' => ['de' => 'Stornorechnung', 'en' => 'Cancellation invoice'],
                // A cancellation of a self-billed invoice is issued by the buyer as well (section 14 (4) no. 10 UStG).
                'cancellation_self_billed' => ['de' => 'Storno-Gutschrift', 'en' => 'Cancellation of the self-billed invoice (Storno-Gutschrift)'],
                'correction' => ['de' => 'Rechnungskorrektur', 'en' => 'Corrected invoice'],
                // Not "Rechnungskorrektur" nor "Gutschrift": the invoice stays correct (section 14.11 (4) UStAE).
                'price_reduction' => ['de' => 'Entgeltminderung', 'en' => 'Price reduction'],
                'self_billed' => ['de' => 'Gutschrift', 'en' => 'Self-billed invoice (Gutschrift)'],
            ],
            strategyResolver: static fn (InvoiceData $original): ?string => $original->typeCode instanceof InvoiceTypeCode
                && $original->typeCode->isSelfBilled() && ! $original->isCreditNote() && LeitwegId::isValid($original->buyerReference)
                    ? CorrectionPolicy::CANCELLATION_NEGATIVE_INVOICE
                    : null,
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
