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

    public function legalTexts(): LegalTextCatalog;

    public function numberingRules(): NumberingRules;

    public function retention(): RetentionRule;

    public function dunningLaw(): DunningLaw;

    public function correctionPolicy(): CorrectionPolicy;

    public function paymentQr(): ?PaymentQrGenerator;

    public function addressFormatter(): AddressFormatter;

    /**
     * Letter layouts by name (e.g. din5008a, din5008b); CountryDefaults::$letterLayout selects the default.
     *
     * @return array<string, LetterLayout>
     */
    public function letterLayouts(): array;

    /**
     * @return list<Exporter>
     */
    public function exporters(): array;

    /**
     * Readers for national formats.
     *
     * @return list<FormatReader>
     */
    public function readers(): array;

    /**
     * Directory with translation files ({locale}/{group}.php) of the pack, if any.
     */
    public function translationPath(): ?string;
}
