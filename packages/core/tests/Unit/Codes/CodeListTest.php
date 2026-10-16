<?php

declare(strict_types=1);

use Quittio\Core\Codes\CodeList;
use Quittio\Core\Enums\ElectronicAddressScheme;
use Quittio\Core\Enums\InvoiceTypeCode;
use Quittio\Core\Enums\UnitCode;
use Quittio\Core\Enums\VatCategory;

it('knows the official code lists', function () {
    expect(CodeList::contains(CodeList::UNITS, 'HUR'))->toBeTrue()
        ->and(CodeList::contains(CodeList::UNITS, 'XYZ123'))->toBeFalse()
        ->and(CodeList::contains(CodeList::INVOICE_TYPES, '380'))->toBeTrue()
        ->and(CodeList::contains(CodeList::EAS, '0204'))->toBeTrue()
        ->and(CodeList::contains(CodeList::CURRENCIES, 'EUR'))->toBeTrue();
});

it('keeps the Peppol electronic address schemes a subset of the EAS list without letter codes', function () {
    $peppol = CodeList::codes(CodeList::PEPPOL_EAS);

    expect(array_diff($peppol, CodeList::codes(CodeList::EAS)))->toBe([])
        ->and(CodeList::contains(CodeList::PEPPOL_EAS, '9930'))->toBeTrue()
        ->and(CodeList::contains(CodeList::PEPPOL_EAS, '0088'))->toBeTrue()
        ->and(array_filter($peppol, fn (string $code) => preg_match('/^[0-9]{4}$/', $code) !== 1))->toBe([]);
});

it('only contains enum cases that are valid codes', function (string $enum, string $list) {
    foreach ($enum::cases() as $case) {
        expect(CodeList::contains($list, $case->value))->toBeTrue("{$enum}::{$case->name} ({$case->value}) is not in {$list}");
    }
})->with([
    [InvoiceTypeCode::class, CodeList::INVOICE_TYPES],
    [UnitCode::class, CodeList::UNITS],
    [VatCategory::class, CodeList::VAT_CATEGORIES],
    [ElectronicAddressScheme::class, CodeList::EAS],
    [Quittio\Core\Enums\IdentifierScheme::class, CodeList::ICD],
    [Quittio\Core\Enums\PaymentMeansCode::class, CodeList::PAYMENT_MEANS],
    [Quittio\Core\Enums\AllowanceReasonCode::class, CodeList::ALLOWANCE_REASONS],
    [Quittio\Core\Enums\ChargeReasonCode::class, CodeList::CHARGE_REASONS],
    [Quittio\Core\Enums\NoteSubjectCode::class, CodeList::NOTE_SUBJECTS],
    [Quittio\Core\Enums\VatExemptionReasonCode::class, CodeList::VATEX],
    [Quittio\Core\Enums\MimeType::class, CodeList::MIME_TYPES],
    [Quittio\Core\Enums\ItemClassificationScheme::class, CodeList::ITEM_CLASSIFICATIONS],
]);

it('provides labels in German, English and French', function () {
    expect(UnitCode::Hour->label('de'))->toBe('Std.')
        ->and(UnitCode::Hour->label('en'))->toBe('h')
        ->and(InvoiceTypeCode::CommercialInvoice->label('de'))->toBe('Rechnung')
        ->and(InvoiceTypeCode::CommercialInvoice->label('fr'))->toBe('Facture commerciale')
        ->and(VatCategory::ReverseCharge->label('de'))->toBe('Umkehrung der Steuerschuldnerschaft');
});

it('has a label for every enum case in every shipped language', function () {
    $enums = glob(dirname(__DIR__, 3).'/src/Enums/*.php') ?: [];
    foreach ($enums as $file) {
        $class = 'Quittio\\Core\\Enums\\'.basename($file, '.php');
        if (! method_exists($class, 'labelGroup')) {
            continue;
        }
        foreach ($class::cases() as $case) {
            foreach (['de', 'en', 'fr'] as $locale) {
                $key = 'quittio::codes.'.$class::labelGroup().'.'.$case->value;
                expect(Quittio\Core\I18n\Translations::translator()->has($key, $locale))
                    ->toBeTrue("Missing {$locale} label for {$class}::{$case->name}");
            }
        }
    }
});

it('identifies credit note type codes', function () {
    expect(InvoiceTypeCode::CreditNote->isCreditNote())->toBeTrue()
        ->and(InvoiceTypeCode::CommercialInvoice->isCreditNote())->toBeFalse()
        ->and(InvoiceTypeCode::SelfBilledInvoice->isSelfBilled())->toBeTrue();
});
