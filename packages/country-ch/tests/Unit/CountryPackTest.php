<?php

declare(strict_types=1);

use Brick\Math\BigDecimal;
use Brick\Money\Money;
use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Countries\CountryRegistry;
use Quittio\Core\Countries\DefaultCountryResolver;
use Quittio\Core\Dunning\DefaultInterest;
use Quittio\Core\Enums\UnitCode;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Support\Date;
use Quittio\Core\View\ViewContext;
use Quittio\Core\View\ViewModelFactory;
use Quittio\Country\CH\CountryCH;
use Quittio\Country\CH\Rounding\CashRounding;
use Quittio\Country\CH\Tax\VatPreset;
use Quittio\Country\CH\Tests\Support\Swiss;

it('has Swiss defaults: CHF, Swiss formats and the QR bill', function () {
    $defaults = (new CountryCH)->defaults();

    expect([$defaults->currency, $defaults->locale, $defaults->timezone, $defaults->paymentQr, $defaults->vatPreset])
        ->toBe(['CHF', 'de_CH', 'Europe/Zurich', 'swiss-qr-bill', 'ch.standard'])
        ->and((new CountryCH)->taxPresets()->count())->toBe(count(VatPreset::cases()));
});

it('takes the VAT rate of the supply date', function (string $date, string $standard, string $reduced, string $accommodation) {
    $rates = fn (VatPreset $preset) => (string) (new En16931Calculator)->calculate(Swiss::base()->deliveryDate($date)
        ->line(fn ($l) => $l->name('A')->quantity(1, UnitCode::Piece)->netPrice('100.00')->vat($preset))->build())->lines[0]->vat->rate;

    expect([$rates(VatPreset::Standard), $rates(VatPreset::Reduced), $rates(VatPreset::Accommodation)])->toBe([$standard, $reduced, $accommodation]);
})->with([
    '2017' => ['2017-12-31', '8.0', '2.5', '3.8'],
    '2018 to 2023' => ['2023-12-31', '7.7', '2.5', '3.7'],
    'since 2024' => ['2024-01-01', '8.1', '2.6', '3.8'],
]);

it('states the exemption reasons in the four languages', function (string $locale, string $text) {
    expect(VatPreset::Export->exemptionReason($locale))->toBe($text);
})->with([
    ['de', 'Von der Steuer befreite Ausfuhr (Art. 23 MWSTG)'],
    ['fr', 'Exportation exonérée de l\'impôt (art. 23 LTVA)'],
    ['it', 'Esportazione esente dall\'imposta (art. 23 LIVA)'],
    ['en', 'Export exempt from VAT (Art. 23 MWSTG)'],
]);

it('marks excluded supplies with their legal reference', function () {
    expect(VatPreset::excluded('Art. 21 Abs. 2 Ziff. 3 MWSTG')->exemptionReason())->toBe('Von der Steuer ausgenommen nach Art. 21 Abs. 2 Ziff. 3 MWSTG')
        ->and(VatPreset::excluded('Art. 21 Abs. 2 Ziff. 3 MWSTG')->category())->toBe(VatCategory::Exempt)
        ->and(VatPreset::Standard->exemptionReason())->toBeNull();
});

it('rounds the amount due to 5 Rappen with the rounding amount', function (string $net, string $due, ?string $rounding) {
    $builder = Swiss::base()->flag(CountryCH::FLAG_CASH_ROUNDING)
        ->line(fn ($l) => $l->name('A')->quantity(1, UnitCode::Piece)->netPrice($net)->vat(VatPreset::Standard));
    $invoice = (new En16931Calculator)->calculate((new CountryCH)->complete($builder->build(), []));

    expect((string) $invoice->totals?->amountDue->getAmount())->toBe($due)
        ->and($invoice->totals?->roundingAmount === null ? null : (string) $invoice->totals->roundingAmount->getAmount())->toBe($rounding);
})->with([
    // 100.00 + 8.10 VAT = 108.10: already a multiple of 5 Rappen.
    'no rounding' => ['100.00', '108.10', null],
    // 100.02 + 8.10 = 108.12 → 108.10
    'down' => ['100.02', '108.10', '-0.02'],
    // 100.04 + 8.10 = 108.14 → 108.15
    'up' => ['100.04', '108.15', '0.01'],
]);

it('rounds only CHF invoices that ask for it', function () {
    $invoice = Swiss::base()->line(fn ($l) => $l->name('A')->quantity(1, UnitCode::Piece)->netPrice('100.02')->vat(VatPreset::Standard))->build();

    expect((new CountryCH)->complete($invoice, [])->roundingAmount)->toBeNull()
        ->and((new CountryCH)->complete($invoice, ['cash_rounding' => true])->roundingAmount?->getAmount()->toString())->toBe('-0.02')
        ->and(CashRounding::apply($invoice->with(['currency' => 'EUR']))->roundingAmount)->toBeNull()
        ->and((string) CashRounding::round(BigDecimal::of('10.025')))->toBe('10.05');
});

it('charges 5 % default interest under Art. 104 OR without a flat rate', function () {
    $interest = DefaultInterest::forCountry(new CountryCH);
    $charges = $interest->calculate(Money::of('1000.00', 'CHF'), Date::of(2026, 1, 1), Date::of(2026, 3, 1));

    expect(array_map(fn ($c) => (string) $c->ratePercent, $charges))->toBe(['5.00'])
        ->and(array_map(fn ($c) => (string) $c->ratePercent, $interest->calculate(Money::of('1000.00', 'CHF'), Date::of(2026, 1, 1), Date::of(2026, 3, 1), consumer: true)))->toBe(['5.00'])
        ->and($interest->flatRate('CHF'))->toBeNull();
});

it('keeps invoices for ten years and uses Swiss titles', function () {
    $pack = new CountryCH;

    expect($pack->retention()->until(Date::of(2026, 10, 5))->toIsoString())->toBe('2036-12-31')
        ->and($pack->correctionPolicy()->title('cancellation', 'fr'))->toBe('Note de crédit')
        ->and($pack->correctionPolicy()->title('cancellation', 'de_CH'))->toBe('Gutschrift')
        ->and($pack->numberingRules()->gapless)->toBeFalse();
});

it('keeps the space before the note of the VAT registration of a UID number', function () {
    $invoice = Swiss::invoice()->seller(fn ($s) => $s->vatId('CHE-123.456.788 MWST'))->buyer(fn ($b) => $b->vatId('che 109.322.551tva'))->build();

    expect([$invoice->seller->vatId, $invoice->buyer->vatId])->toBe(['CHE-123.456.788 MWST', 'CHE109.322.551 TVA']);
});

it('leads the invoices of sellers in Liechtenstein', function () {
    $invoice = Swiss::invoice()->seller(fn ($s) => $s->address('Städtle 1', '9490', 'Vaduz', 'LI'))->build();

    expect((new DefaultCountryResolver(new CountryRegistry([new CountryCH])))->resolve($invoice)->leading)->toBeInstanceOf(CountryCH::class);
});

it('labels VAT as MWST in Swiss German', function () {
    CountryCH::registerTranslations(); // as Quittio::create() does for a registered pack
    $model = (new ViewModelFactory)->build(Swiss::calculated(Swiss::invoice()), new ViewContext(locale: 'de_CH', country: new CountryCH));

    expect($model->labels->vat_rate)->toBe('MWST')
        ->and($model->labels->vat_id)->toBe('MWST-Nr.')
        ->and($model->labels->invoice_number ?? null)->not->toBe('Invoice number');
});
