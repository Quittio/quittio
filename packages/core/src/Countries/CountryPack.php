<?php

declare(strict_types=1);

namespace Quittio\Core\Countries;

use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Formats\FormatRule;
use Quittio\Core\Pdf\Qr\PaymentQrGenerator;
use Quittio\Core\Profiles\Profile;
use Quittio\Core\Reading\FormatReader;
use Quittio\Core\Tax\TaxPresetCollection;
use Quittio\Core\Validation\Rule;
use Quittio\Core\View\AddressFormatter;
use Quittio\Core\View\LetterLayout;

/**
 * Everything that differs between countries (specification 25.3).
 *
 * A country pack may only add or tighten rules; it never relaxes EN 16931 rules.
 */
interface CountryPack
{
    /**
     * ISO 3166-1 alpha-2 code, e.g. "DE".
     */
    public function code(): string;

    public function defaults(): CountryDefaults;

    /**
     * Tax presets of the country by key (e.g. "de.small_business"). Informational metadata for
     * applications, e.g. to offer them in a form or to resolve a stored LineVat::$presetKey; the
     * core does not apply it: lines take a preset with LineBuilder::vat().
     */
    public function taxPresets(): TaxPresetCollection;

    /**
     * Additional validation rules (L3) applied when the pack is the leading pack.
     *
     * @return list<Rule>
     */
    public function rules(): array;

    /**
     * Rules applied when the pack's country is the buyer's country of a cross-border invoice.
     *
     * @return list<Rule>
     */
    public function buyerCountryRules(): array;

    /**
     * National profiles, e.g. XRechnung.
     *
     * @return list<Profile>
     */
    public function profiles(): array;

    /**
     * Format choice for domestic invoices (specification 9.3).
     *
     * @return list<FormatRule>
     */
    public function formatRules(): array;

    public function mandatoryOutput(InvoiceData $invoice): MandatoryFieldList;

    /**
     * Legal and informational texts of the country by key and locale. Informational metadata
     * for applications and the pack's own presets and rules; the core adds none of them to an
     * invoice by itself.
     */
    public function legalTexts(): LegalTextCatalog;

    /**
     * Numbering requirements; the Managed layer applies pattern and resets (see NumberingRules).
     */
    public function numberingRules(): NumberingRules;

    /**
     * Retention period of issued invoices; the Managed layer stores the end of it.
     */
    public function retention(): RetentionRule;

    /**
     * Statutory late payment rules. Informational metadata for applications, e.g. to compute
     * fees and default interest for DocumentBuilder::fee() and interest(); the core does not
     * apply them.
     */
    public function dunningLaw(): DunningLaw;

    /**
     * Cancellation and correction strategies and titles; see CorrectionPolicy for who uses what.
     */
    public function correctionPolicy(): CorrectionPolicy;

    /**
     * Payment QR code generator of the country, used for the type "auto" unless
     * CountryDefaults::$paymentQr is "none" (an application's generator registered with
     * Quittio::withPaymentQrGenerator() comes first); null = the EPC GiroCode for "girocode".
     */
    public function paymentQr(): ?PaymentQrGenerator;

    public function addressFormatter(): AddressFormatter;

    /**
     * Letter layouts by name (e.g. din5008a, din5008b); CountryDefaults::$letterLayout selects the default.
     *
     * @return array<string, LetterLayout>
     */
    public function letterLayouts(): array;

    /**
     * Export formats the pack declares (e.g. DATEV). Informational metadata for applications;
     * Quittio runs no exporter.
     *
     * @return list<Exporter>
     */
    public function exporters(): array;

    /**
     * Readers for national formats. Quittio::read() and validateFile() try them after the
     * CII and UBL readers, for every registered pack; return new instances on every call
     * (readers keep the state of one document).
     *
     * @return list<FormatReader>
     */
    public function readers(): array;

    /**
     * Directory with translation files ({locale}/{group}.php) of the pack, if any.
     */
    public function translationPath(): ?string;
}
