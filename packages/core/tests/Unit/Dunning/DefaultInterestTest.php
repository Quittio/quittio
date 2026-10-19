<?php

declare(strict_types=1);

use Brick\Math\BigDecimal;
use Quittio\Core\Countries\AbstractCountryPack;
use Quittio\Core\Countries\DunningLaw;
use Quittio\Core\Documents\DocumentBuilder;
use Quittio\Core\Dunning\DefaultInterest;
use Quittio\Core\Dunning\ReferenceRates;
use Quittio\Core\Exceptions\ConfigurationException;
use Quittio\Core\Support\Amount;
use Quittio\Core\Support\Date;
use Quittio\Core\Support\DatedValues;

/**
 * Reference rates of 2.00 % until 2026-06-30 and 3.00 % from 2026-07-01.
 */
function fakeReferenceRates(): ReferenceRates
{
    return new class implements ReferenceRates
    {
        private DatedValues $rates;

        public function __construct()
        {
            $this->rates = new DatedValues(['2026-01-01' => '2.00', '2026-07-01' => '3.00']);
        }

        public function rateOn(Date $date): BigDecimal
        {
            return BigDecimal::of($this->rates->on($date) ?? '0');
        }

        public function periods(Date $from, Date $until): array
        {
            return array_map(fn (array $p): array => ['from' => $p['from'], 'until' => $p['until']->addDays(1), 'rate' => BigDecimal::of($p['value'])], $this->rates->periods($from, $until->addDays(-1)));
        }
    };
}

it('adds the points of the law to the reference rate, split at its changes', function () {
    $interest = new DefaultInterest(fakeReferenceRates(), new DunningLaw(b2bInterestPoints: '8.00', b2cInterestPoints: '5.00', dayCount: 360),
        fn (BigDecimal $rate, BigDecimal $reference, string $points, Date $from, Date $lastDay, string $locale) => "{$rate} % ({$reference} + {$points}) {$from}–{$lastDay}");
    $charges = $interest->calculate(Amount::of('1000.00', 'EUR'), Date::fromIsoDate('2026-06-16'), Date::fromIsoDate('2026-07-16'));

    expect(array_map(fn ($c) => $c->description, $charges))->toBe(['10.00 % (2.00 + 8.00) 2026-06-16–2026-06-30', '11.00 % (3.00 + 8.00) 2026-07-01–2026-07-15'])
        ->and($charges[0]->dayCount)->toBe(360)
        ->and((string) $interest->calculate(Amount::of('1000.00', 'EUR'), Date::fromIsoDate('2026-02-01'), Date::fromIsoDate('2026-03-01'), consumer: true)[0]->ratePercent)->toBe('7.00')
        ->and($interest->calculate(Amount::of('0.00', 'EUR'), Date::fromIsoDate('2026-02-01'), Date::fromIsoDate('2026-03-01')))->toBe([])
        ->and((string) $interest->flatRate('EUR'))->toBe('EUR 40.00');
});

it('charges consumers a fixed rate of the law for the whole time', function () {
    $interest = new DefaultInterest(fakeReferenceRates(), new DunningLaw(b2bInterestPoints: '9.20', b2cFixedRate: '4.00'),
        fn (BigDecimal $rate, BigDecimal $reference, string $points, Date $from, Date $lastDay, string $locale) => "{$rate} % ({$reference} + {$points}) {$from}–{$lastDay}");
    $consumer = $interest->calculate(Amount::of('1000.00', 'EUR'), Date::fromIsoDate('2026-06-16'), Date::fromIsoDate('2026-07-16'), consumer: true);

    expect(array_map(fn ($c) => $c->description, $consumer))->toBe(['4.00 % (4.00 + 0.00) 2026-06-16–2026-07-15'])
        ->and($consumer[0]->dayCount)->toBe(365)
        ->and(array_map(fn ($c) => (string) $c->ratePercent, $interest->calculate(Amount::of('1000.00', 'EUR'), Date::fromIsoDate('2026-06-16'), Date::fromIsoDate('2026-07-16'))))->toBe(['11.20', '12.20'])
        ->and($interest->calculate(Amount::of('1000.00', 'EUR'), Date::fromIsoDate('2026-07-16'), Date::fromIsoDate('2026-07-16'), consumer: true))->toBe([]);
});

it('charges the fixed rates of a law without reference rates', function () {
    $pack = new class extends AbstractCountryPack
    {
        public function code(): string
        {
            return 'XX';
        }

        public function dunningLaw(): DunningLaw
        {
            return new DunningLaw(b2bFlatRate: null, b2cFixedRate: '5.00', b2bFixedRate: '5.00');
        }
    };
    $interest = DefaultInterest::forCountry($pack);

    expect(array_map(fn ($c) => (string) $c->ratePercent, $interest->calculate(Amount::of('1000.00', 'EUR'), Date::fromIsoDate('2026-06-16'), Date::fromIsoDate('2026-07-16'))))->toBe(['5.00'])
        ->and(array_map(fn ($c) => (string) $c->ratePercent, $interest->calculate(Amount::of('1000.00', 'EUR'), Date::fromIsoDate('2026-06-16'), Date::fromIsoDate('2026-07-16'), consumer: true)))->toBe(['5.00'])
        ->and($interest->flatRate('EUR'))->toBeNull()
        ->and(fn () => (new DefaultInterest(null, new DunningLaw(b2cFixedRate: '4.00')))->calculate(Amount::of('1000.00', 'EUR'), Date::fromIsoDate('2026-06-16'), Date::fromIsoDate('2026-07-16')))->toThrow(ConfigurationException::class, 'reference rates');
});

it('needs a country pack with reference rates', function () {
    $pack = new class extends AbstractCountryPack
    {
        public function code(): string
        {
            return 'XX';
        }
    };

    expect(fn () => DefaultInterest::forCountry($pack))->toThrow(ConfigurationException::class, 'reference interest rate');
});

it('adds calculated charges to a reminder with their day count', function () {
    $charges = (new DefaultInterest(fakeReferenceRates(), new DunningLaw(dayCount: 360)))
        ->calculate(Amount::of('1000.00', 'EUR'), Date::fromIsoDate('2026-06-16'), Date::fromIsoDate('2026-07-16'));
    $reminder = DocumentBuilder::paymentReminder(1)->number('M-1')->issueDate('2026-07-20')
        ->seller(fn ($p) => $p->name('Seller Ltd')->address('Main Street 1', '12345', 'Sample City', 'DE'))
        ->buyer(fn ($p) => $p->name('Buyer Inc')->address('Station Road 5', '54321', 'Example Town', 'DE'))
        ->interestCharge(...$charges)
        ->build();

    expect($reminder->dunning?->interest)->toHaveCount(2)
        ->and($reminder->dunning?->interest[1]->dayCount)->toBe(360)
        ->and((string) $reminder->dunning?->interest[1]->ratePercent)->toBe('11.00');
});
