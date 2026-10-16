<?php

declare(strict_types=1);

use Brick\Math\BigDecimal;
use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Codes\RawCode;
use Quittio\Core\Data\Delivery\Period;
use Quittio\Core\Data\Lines\InvoiceLine;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Enums\VatExemptionReasonCode;
use Quittio\Core\Support\Date;
use Quittio\Core\Support\DatedValues;
use Quittio\Core\Tax\CustomTaxPreset;
use Quittio\Core\Tax\DatedTaxPreset;
use Quittio\Core\Tax\SupplyDate;
use Quittio\Core\Tax\TaxPreset;
use Quittio\Core\Tax\TaxPresetCollection;
use Quittio\Core\Tests\Support\Invoices;

/**
 * A preset whose rate was 16 % from 2020-07-01 to 2020-12-31 and 19 % otherwise.
 */
function datedPreset(): DatedTaxPreset
{
    return new class implements DatedTaxPreset
    {
        public function key(): string
        {
            return 'xx.standard';
        }

        public function category(): VatCategory
        {
            return VatCategory::StandardRate;
        }

        public function rate(): ?BigDecimal
        {
            return BigDecimal::of('19');
        }

        public function exemptionReasonCode(): VatExemptionReasonCode|RawCode|null
        {
            return null;
        }

        public function exemptionReason(?string $locale = null): ?string
        {
            return null;
        }

        public function on(Date $date): TaxPreset
        {
            $rate = (new DatedValues(['2007-01-01' => '19', '2020-07-01' => '16', '2021-01-01' => '19']))->on($date);

            return CustomTaxPreset::make($this->key(), VatCategory::StandardRate, $rate);
        }
    };
}

/**
 * @return list<string> rates of the built lines, sub lines included
 */
function datedRates(InvoiceBuilder $builder): array
{
    $rates = [];
    $walk = function (array $lines) use (&$walk, &$rates): void {
        foreach ($lines as $line) {
            /** @var InvoiceLine $line */
            $rates[] = (string) $line->vat->rate;
            $walk($line->subLines);
        }
    };
    $walk($builder->build()->lines);

    return $rates;
}

it('finds the value of a date and the periods between two dates', function () {
    $values = new DatedValues(['2021-01-01' => '19', '2020-07-01' => '16', '2007-01-01' => '19']);

    expect($values->on(Date::fromIsoDate('2006-12-31')))->toBeNull()
        ->and($values->on(Date::fromIsoDate('2020-06-30')))->toBe('19')
        ->and($values->on(Date::fromIsoDate('2020-07-01')))->toBe('16')
        ->and($values->on(Date::fromIsoDate('2020-12-31')))->toBe('16')
        ->and($values->on(Date::fromIsoDate('2021-01-01')))->toBe('19')
        ->and(array_keys($values->changes()))->toBe(['2007-01-01', '2020-07-01', '2021-01-01'])
        ->and((string) $values->lastChange())->toBe('2021-01-01')
        ->and(array_map(fn (array $p) => $p['from'].' '.$p['until'].' '.$p['value'], $values->periods(Date::fromIsoDate('2020-06-15'), Date::fromIsoDate('2021-01-10'))))
        ->toBe(['2020-06-15 2020-06-30 19', '2020-07-01 2020-12-31 16', '2021-01-01 2021-01-10 19'])
        ->and($values->withValue('2023-01-01', '20')->on(Date::fromIsoDate('2024-01-01')))->toBe('20');
});

it('takes the supply date from the line period, then the document dates', function () {
    $date = fn (?string $value): ?Date => $value === null ? null : Date::fromIsoDate($value);
    $resolve = fn (?Period $line, ?string $taxPoint, ?string $delivery, ?Period $invoicing, ?string $issue) => (string) SupplyDate::resolve($line, $date($taxPoint), $date($delivery), $invoicing, $date($issue));

    expect($resolve(Period::between('2020-08-01', '2020-08-31'), '2020-05-01', null, null, '2021-02-01'))->toBe('2020-08-31')
        ->and($resolve(Period::between('2020-08-01', null), null, null, null, '2021-02-01'))->toBe('2020-08-01')
        ->and($resolve(null, '2020-05-01', '2020-06-01', null, '2021-02-01'))->toBe('2020-05-01')
        ->and($resolve(null, null, '2020-06-01', Period::between('2020-01-01', '2020-12-31'), '2021-02-01'))->toBe('2020-06-01')
        ->and($resolve(null, null, null, Period::between('2020-01-01', '2020-12-31'), '2021-02-01'))->toBe('2020-12-31')
        ->and($resolve(null, null, null, null, '2021-02-01'))->toBe('2021-02-01');
});

it('resolves dated presets with the supply date of each line', function () {
    $builder = Invoices::base()->issueDate('2021-01-15')->deliveryDate('2020-12-20')
        ->line(fn ($l) => $l->name('December')->quantity(1)->netPrice('100.00')->vat(datedPreset()))
        ->line(fn ($l) => $l->name('January')->quantity(1)->netPrice('100.00')->vat(datedPreset())->period('2021-01-01', '2021-01-10'))
        ->line(fn ($l) => $l->name('Kit')->quantity(1)->vat(datedPreset())->period('2020-06-01', '2020-06-30')
            ->subLine(fn ($s) => $s->name('Part')->quantity(1)->netPrice('10.00')->vat(datedPreset())));

    expect(datedRates($builder))->toBe(['16', '19', '19', '19'])
        ->and($builder->build()->lines[0]->vat->presetKey)->toBe('xx.standard');
});

it('resolves a dated default VAT with the date of the line', function () {
    $builder = Invoices::base()->issueDate('2020-08-15')->deliveryDate('2020-08-14')->defaultVat(datedPreset())
        ->line(fn ($l) => $l->name('August')->quantity(1)->netPrice('100.00'))
        ->line(fn ($l) => $l->name('Later')->quantity(1)->netPrice('100.00')->period('2021-02-01', '2021-02-28'));

    expect(datedRates($builder))->toBe(['16', '19']);
});

it('finds presets under an alias without listing them twice', function () {
    $preset = CustomTaxPreset::make('xx.standard', VatCategory::StandardRate, '19');
    $presets = (new TaxPresetCollection([$preset]))->alias('xx.standard_19', $preset);

    expect($presets->has('xx.standard_19'))->toBeTrue()
        ->and($presets->get('xx.standard_19'))->toBe($preset)
        ->and($presets)->toHaveCount(1)
        ->and(array_keys(iterator_to_array($presets)))->toBe(['xx.standard']);
});
