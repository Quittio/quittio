<?php

declare(strict_types=1);

use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Tax\CustomTaxPreset;

it('takes the exemption text of the language of a regional locale', function () {
    $preset = CustomTaxPreset::make('xx.exempt', VatCategory::Exempt, '0', reason: ['en' => 'VAT exempt', 'fr' => 'Exonéré de TVA', 'nl' => 'Vrijgesteld van btw']);

    expect($preset->exemptionReason('fr_BE'))->toBe('Exonéré de TVA')
        ->and($preset->exemptionReason('nl-BE'))->toBe('Vrijgesteld van btw')
        ->and($preset->exemptionReason('de_CH'))->toBe('VAT exempt')
        ->and($preset->exemptionReason())->toBe('VAT exempt');
});
