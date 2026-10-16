<?php

declare(strict_types=1);

namespace Quittio\Core\Countries;

use Quittio\Core\Countries\Capabilities\ProvidesFormats;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Pdf\Qr\PaymentQrGenerator;
use Quittio\Core\Tax\TaxPresetCollection;
use Quittio\Core\View\AddressFormatter;
use Quittio\Core\View\InternationalAddressFormatter;
use Quittio\Core\View\LetterLayout;

/**
 * Base class with neutral defaults, so that country packs only override what differs.
 */
abstract class AbstractCountryPack implements CountryPack, ProvidesFormats
{
    /**
     * Default: neutral CountryDefaults.
     */
    public function defaults(): CountryDefaults
    {
        return new CountryDefaults;
    }

    /**
     * Default: no presets.
     */
    public function taxPresets(): TaxPresetCollection
    {
        return new TaxPresetCollection;
    }

    /**
     * Default: no rules.
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * Default: no rules.
     */
    public function buyerCountryRules(): array
    {
        return [];
    }

    /**
     * Default: no profiles.
     */
    public function profiles(): array
    {
        return [];
    }

    /**
     * Default: no format rules; the generic automatic choice applies.
     */
    public function formatRules(): array
    {
        return [];
    }

    /**
     * Default: nothing beyond EN 16931.
     */
    public function mandatoryOutput(InvoiceData $invoice): MandatoryFieldList
    {
        return new MandatoryFieldList;
    }

    /**
     * Default: an empty catalog.
     */
    public function legalTexts(): LegalTextCatalog
    {
        return new LegalTextCatalog;
    }

    /**
     * Default: pattern INV-{Y}-{seq:4}, reset yearly, see NumberingRules.
     */
    public function numberingRules(): NumberingRules
    {
        return new NumberingRules;
    }

    /**
     * Default: 10 years from the end of the calendar year.
     */
    public function retention(): RetentionRule
    {
        return new RetentionRule(10);
    }

    /**
     * Default: the EU late payment directive (8 points above the reference rate for businesses,
     * 40.00 flat rate), see DunningLaw.
     */
    public function dunningLaw(): DunningLaw
    {
        return new DunningLaw;
    }

    /**
     * Default: CorrectionPolicy with credit notes for cancellations.
     */
    public function correctionPolicy(): CorrectionPolicy
    {
        return new CorrectionPolicy;
    }

    /**
     * Default: none; the EPC GiroCode is used for the type "girocode".
     */
    public function paymentQr(): ?PaymentQrGenerator
    {
        return null;
    }

    /**
     * Default: international address format.
     */
    public function addressFormatter(): AddressFormatter
    {
        return new InternationalAddressFormatter;
    }

    /**
     * Default: the free layout only.
     */
    public function letterLayouts(): array
    {
        return ['free' => LetterLayout::free()];
    }

    /**
     * Default: no exporters.
     */
    public function exporters(): array
    {
        return [];
    }

    /**
     * Default: no readers.
     */
    public function readers(): array
    {
        return [];
    }

    /**
     * Default: no translations.
     */
    public function translationPath(): ?string
    {
        return null;
    }

    /**
     * Default: no own formats.
     */
    public function formats(): array
    {
        return [];
    }

    /**
     * Default: no aliases.
     */
    public function formatAliases(): array
    {
        return [];
    }

    /**
     * Default: no serializers; CII and UBL are built in.
     */
    public function serializers(): array
    {
        return [];
    }
}
