<?php

declare(strict_types=1);

namespace Quittio\Core\Countries;

use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Pdf\Qr\PaymentQrGenerator;
use Quittio\Core\Tax\TaxPresetCollection;
use Quittio\Core\View\AddressFormatter;
use Quittio\Core\View\InternationalAddressFormatter;
use Quittio\Core\View\LetterLayout;

/**
 * Base class with neutral defaults, so that country packs only override what differs.
 */
abstract class AbstractCountryPack implements CountryPack
{
    public function defaults(): CountryDefaults
    {
        return new CountryDefaults;
    }

    public function taxPresets(): TaxPresetCollection
    {
        return new TaxPresetCollection;
    }

    public function rules(): array
    {
        return [];
    }

    public function buyerCountryRules(): array
    {
        return [];
    }

    public function profiles(): array
    {
        return [];
    }

    public function formatRules(): array
    {
        return [];
    }

    public function mandatoryOutput(InvoiceData $invoice): MandatoryFieldList
    {
        return new MandatoryFieldList;
    }

    public function legalTexts(): LegalTextCatalog
    {
        return new LegalTextCatalog;
    }

    public function numberingRules(): NumberingRules
    {
        return new NumberingRules;
    }

    public function retention(): RetentionRule
    {
        return new RetentionRule(10);
    }

    public function dunningLaw(): DunningLaw
    {
        return new DunningLaw;
    }

    public function correctionPolicy(): CorrectionPolicy
    {
        return new CorrectionPolicy;
    }

    public function paymentQr(): ?PaymentQrGenerator
    {
        return null;
    }

    public function addressFormatter(): AddressFormatter
    {
        return new InternationalAddressFormatter;
    }

    public function letterLayouts(): array
    {
        return ['free' => LetterLayout::free()];
    }

    public function exporters(): array
    {
        return [];
    }

    public function readers(): array
    {
        return [];
    }

    public function translationPath(): ?string
    {
        return null;
    }
}
