<?php

declare(strict_types=1);

namespace Quittio\Country\FR;

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
use Quittio\Core\Data\AllowanceCharge\DocumentAllowance;
use Quittio\Core\Data\AllowanceCharge\DocumentCharge;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Data\Lines\InvoiceLine;
use Quittio\Core\Data\Note;
use Quittio\Core\Data\Totals\VatBreakdown;
use Quittio\Core\Dunning\ReferenceRates;
use Quittio\Core\Enums\NoteSubjectCode;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\I18n\Translations;
use Quittio\Core\Support\Date;
use Quittio\Core\Tax\TaxPresetCollection;
use Quittio\Core\Validation\Rules\Inspect;
use Quittio\Core\View\ViewModelFactory;
use Quittio\Country\FR\Dunning\EcbRateTable;
use Quittio\Country\FR\Formats\FrenchFormatRule;
use Quittio\Country\FR\Rules\FrenchRules;
use Quittio\Country\FR\Tax\VatPreset;
use Quittio\Country\FR\Texts\LegalTexts;

/**
 * Country pack for France (metropolitan France and Monaco).
 *
 * Not reviewed by a tax adviser yet (see the README of the pack).
 */
class CountryFR extends AbstractCountryPack implements CompletesInvoices, ProvidesReferenceRates
{
    public const string CODE = 'FR';

    public const string TRANSLATION_NAMESPACE = 'quittio-fr';

    /** Meta flag: the seller opted to pay VAT on debits; the invoice states it (from 1 September 2026). */
    public const string FLAG_VAT_ON_DEBITS = 'fr.vat_on_debits';

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
            timezone: 'Europe/Paris',
            locale: 'fr',
            paper: 'A4',
            letterLayout: 'free',
            profile: 'en16931',
            paymentQr: 'girocode',
            vatPreset: VatPreset::Standard->value,
            smallBusinessVatPreset: VatPreset::SmallBusiness->value,
        );
    }

    public function taxPresets(): TaxPresetCollection
    {
        return new TaxPresetCollection(VatPreset::cases());
    }

    public function rules(): array
    {
        return FrenchRules::all();
    }

    public function formatRules(): array
    {
        return [new FrenchFormatRule];
    }

    public function mandatoryOutput(InvoiceData $invoice): MandatoryFieldList
    {
        $fields = [];
        if (Inspect::filled($invoice->seller->legalRegistration?->value)) {
            $fields[] = new MandatoryField('seller.legalRegistration|issuer.legalRegistration', 'SIREN of the seller', 'art. R123-237 Code de commerce');
        }
        if (Inspect::filled($invoice->seller->vatId)) {
            $fields[] = new MandatoryField('seller.vatId|taxRepresentative.vatId', 'VAT number of the seller', 'art. 242 nonies A annexe II CGI');
        }
        if (! $invoice->buyer->isConsumer && $invoice->dueDate !== null) {
            $fields[] = new MandatoryField('dueDate|paymentTerms.lines[*]', 'Due date', 'art. L441-9 Code de commerce');
        }

        $legalNotes = ViewModelFactory::legalNoteTexts($invoice);
        $rowIndexes = ViewModelFactory::vatRowIndexes($invoice);
        foreach ($invoice->vatBreakdown as $index => $row) {
            if (! in_array($row->category, [VatCategory::ReverseCharge, VatCategory::IntraCommunitySupply, VatCategory::Export, VatCategory::Exempt], true)) {
                continue;
            }
            $note = $row->exemptionReason === null ? false : array_search($row->exemptionReason, $legalNotes, true);
            $fields[] = new MandatoryField('vatRows['.$rowIndexes[$index].'].label'.($note === false ? '' : '|legalNotes['.$note.']'), 'Reverse charge or exemption mention', 'art. 242 nonies A annexe II CGI');
        }

        return new MandatoryFieldList($fields);
    }

    /**
     * Adds to invoices between businesses the mention of late payment penalties, the fixed
     * indemnity of 40 EUR and the discount (art. L441-9 Code de commerce), unless the invoice
     * mentions penalties already or the setting payment_mentions is false (quittio.countries.fr);
     * with the flag FLAG_VAT_ON_DEBITS the option to pay VAT on debits. An invoice issued from
     * 1 January 2027 cites the CIBS instead of the CGI in the texts of the presets
     * (VatPreset::exemptionReasonOn()).
     */
    public function complete(InvoiceData $invoice, array $settings): InvoiceData
    {
        $invoice = self::withCibsReferences($invoice);
        $notes = $invoice->notes;
        $texts = mb_strtolower(implode("\n", array_map(static fn (Note $note): string => $note->content, $notes)));
        $locale = $invoice->meta->locale ?? 'fr';
        if (($settings['payment_mentions'] ?? true) !== false && ! $invoice->buyer->isConsumer && ! $invoice->isCreditNote()
            && ! str_contains($texts, 'pénalit') && ! str_contains($texts, 'penalt')) {
            $notes[] = new Note(LegalTexts::text(LegalTexts::LATE_PAYMENT, $locale), NoteSubjectCode::PaymentInformation);
        }
        if ($invoice->meta->hasFlag(self::FLAG_VAT_ON_DEBITS) && ! str_contains($texts, 'débits') && ! str_contains($texts, 'debits')) {
            $notes[] = new Note(LegalTexts::text(LegalTexts::VAT_ON_DEBITS, $locale), NoteSubjectCode::TaxDeclaration);
        }

        return $notes === $invoice->notes ? $invoice : $invoice->with(['notes' => $notes]);
    }

    /**
     * Replaces the texts of the presets that cite the CGI by those that cite the CIBS on an
     * invoice issued from 1 January 2027, in lines, document allowances and charges and the VAT
     * breakdown. Texts that differ from those of the presets stay as they are.
     */
    private static function withCibsReferences(InvoiceData $invoice): InvoiceData
    {
        if ($invoice->issueDate->isBefore(Date::parse(VatPreset::CIBS_FROM))) {
            return $invoice;
        }
        $texts = [];
        foreach ([VatPreset::SmallBusiness, VatPreset::IntraCommunitySupply, VatPreset::Export] as $preset) {
            foreach (self::locales() as $locale) {
                $texts[(string) $preset->exemptionReason($locale)] = (string) $preset->exemptionReasonOn($invoice->issueDate, $locale);
            }
        }

        return $invoice->with([
            'lines' => self::withCibsLines($invoice->lines, $texts),
            'allowances' => array_map(static fn (DocumentAllowance $a): DocumentAllowance => $a->with(['exemptionReason' => self::replaced($a->exemptionReason, $texts)]), $invoice->allowances),
            'charges' => array_map(static fn (DocumentCharge $c): DocumentCharge => $c->with(['exemptionReason' => self::replaced($c->exemptionReason, $texts)]), $invoice->charges),
            'vatBreakdown' => array_map(static fn (VatBreakdown $row): VatBreakdown => $row->with(['exemptionReason' => self::replaced($row->exemptionReason, $texts)]), $invoice->vatBreakdown),
        ]);
    }

    /**
     * @param  list<InvoiceLine>  $lines
     * @param  array<string, string>  $texts  CGI text => CIBS text
     * @return list<InvoiceLine>
     */
    private static function withCibsLines(array $lines, array $texts): array
    {
        return array_map(static fn (InvoiceLine $line): InvoiceLine => $line->with([
            'vat' => $line->vat->with(['exemptionReason' => self::replaced($line->vat->exemptionReason, $texts)]),
            'subLines' => self::withCibsLines($line->subLines, $texts),
        ]), $lines);
    }

    /**
     * @param  array<string, string>  $texts  CGI text => CIBS text
     */
    private static function replaced(?string $text, array $texts): ?string
    {
        return $text === null ? null : $texts[$text] ?? $text;
    }

    /**
     * A unique number of a continuous chronological series (art. 242 nonies A annex II CGI).
     */
    public function numberingRules(): NumberingRules
    {
        return new NumberingRules(
            defaultPattern: 'F-{Y}-{seq:5}',
            defaultReset: 'yearly',
            allowedResets: ['never', 'yearly'],
            gapless: true,
            numberConsumedOnRejection: true,
        );
    }

    /**
     * Art. L441-10 Code de commerce: the ECB rate plus 10 points between businesses and a fixed
     * indemnity of 40 EUR; consumers owe the legal interest rate (art. 1231-6 Code civil). The
     * rate depends on the creditor (art. L313-2 Code monétaire et financier): a business claims
     * the rate "for all other cases", 2.75 % in the second half of 2026 (arrêté of 26 June 2026),
     * not the higher rate for creditors who are individuals.
     */
    public function dunningLaw(): DunningLaw
    {
        return self::frenchDunningLaw();
    }

    public static function frenchDunningLaw(): DunningLaw
    {
        return new DunningLaw(
            b2bInterestPoints: '10.00',
            b2cInterestPoints: '0.00',
            b2bFlatRate: '40.00',
            dayCount: 365,
            consumerDefaultAfterDays: null,
            referenceRateName: 'Taux de refinancement de la BCE (art. L441-10 Code de commerce)',
            b2cFixedRate: '2.75',
        );
    }

    public function referenceRates(): ReferenceRates
    {
        return new EcbRateTable;
    }

    /**
     * Cancellation by credit note (avoir), correction by cancel and reissue.
     */
    public function correctionPolicy(): CorrectionPolicy
    {
        return new CorrectionPolicy(
            cancellationStrategy: CorrectionPolicy::CANCELLATION_CREDIT_NOTE,
            correctionStrategy: CorrectionPolicy::CORRECTION_CANCEL_AND_REISSUE,
            titles: [
                'cancellation' => ['fr' => 'Avoir', 'en' => 'Credit note'],
                'correction' => ['fr' => 'Facture rectificative', 'en' => 'Corrected invoice'],
                'self_billed' => ['fr' => 'Autofacturation', 'en' => 'Self-billed invoice'],
            ],
        );
    }

    /**
     * Ten years from the end of the financial year (art. L123-22 Code de commerce); the tax
     * administration may check six years.
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
     * Locales the pack ships translations for, e.g. ["en", "fr"].
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
