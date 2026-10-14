<?php

declare(strict_types=1);

use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Profiles\ProfileRegistry;
use Quittio\Core\Reading\InvoiceReader;
use Quittio\Core\Support\Amount;
use Quittio\Core\Tests\Support\Invoices;
use Quittio\Core\Xml\Cii\CiiWriter;
use Quittio\Core\Xml\Ubl\UblWriter;

function exemptForeignCurrencyInvoice(): Quittio\Core\Data\InvoiceData
{
    return (new En16931Calculator)->calculate(Invoices::base()
        ->currency('USD')
        ->taxCurrency('EUR', '0.9')
        ->line(fn ($l) => $l->name('Consulting')->quantity(1)->netPrice('100')->vat(VatCategory::StandardRate, '19'))
        ->line(fn ($l) => $l->name('Medical service')->quantity(1)->netPrice('50')->vat(VatCategory::Exempt, '0', 'Exempt according to section 4 no. 14 UStG', 'VATEX-EU-132-1C'))
        ->charge(VatCategory::Exempt, '0', amount: '5.00', reason: 'Travel')
        ->build());
}

it('keeps exemption reasons and the VAT total in tax currency when read data is recalculated', function (string $syntax) {
    $invoice = exemptForeignCurrencyInvoice();
    $profile = ProfileRegistry::withDefaults()->get('en16931');
    $xml = $syntax === 'cii' ? (new CiiWriter)->serialize($invoice, $profile) : (new UblWriter)->serialize($invoice, $profile);

    $read = (new InvoiceReader)->read($xml)->data;
    $recalculated = (new En16931Calculator)->calculate($read);
    $exempt = $recalculated->vatBreakdown[1];

    expect($read->lines[1]->vat->exemptionReason)->toBe('Exempt according to section 4 no. 14 UStG')
        ->and($read->lines[0]->vat->exemptionReason)->toBeNull()
        ->and($read->charges[0]->exemptionReasonCode?->value)->toBe('VATEX-EU-132-1C')
        ->and($exempt->exemptionReason)->toBe('Exempt according to section 4 no. 14 UStG')
        ->and($exempt->exemptionReasonCode?->value)->toBe('VATEX-EU-132-1C')
        ->and(Amount::format($recalculated->totals?->vatTotalInTaxCurrency ?? Amount::zero('EUR')))->toBe('17.10');
})->with(['cii', 'ubl']);

it('accepts codes of the official code lists without an enum case', function () {
    $xml = (new CiiWriter)->serialize(exemptForeignCurrencyInvoice(), ProfileRegistry::withDefaults()->get('en16931'));

    $result = (new InvoiceReader)->read($xml);

    expect($result->data->vatBreakdown[1]->exemptionReasonCode)->toBeInstanceOf(Quittio\Core\Codes\RawCode::class)
        ->and(array_map(fn ($f) => $f->code, $result->findings))->not->toContain('QUITTIO-READER-UNKNOWN-CODE');
});
