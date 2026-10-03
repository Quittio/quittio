<?php

declare(strict_types=1);

namespace Quittio\Country\DE\Dunning;

use Brick\Money\Money;
use Quittio\Core\Countries\DunningLaw;
use Quittio\Core\Documents\InterestCharge;
use Quittio\Core\Support\Date;

/**
 * Default interest according to section 288 BGB: base rate plus 9 points (business)
 * or 5 points (consumer), split at every change of the base rate (specification 18.4).
 */
final readonly class DefaultInterest
{
    public function __construct(
        private BaseRateTable $rates = new BaseRateTable,
        private DunningLaw $law = new DunningLaw('9.00', '5.00', '40.00', 365, 30, 'Basiszinssatz (§ 247 BGB)'),
    ) {}

    /**
     * Interest charges for an open amount from the first day of default until $until.
     *
     * @return list<InterestCharge>
     */
    public function calculate(Money $amount, Date $from, Date $until, bool $consumer = false, string $locale = 'de'): array
    {
        if (! $amount->isPositive() || ! $until->isAfter($from)) {
            return [];
        }

        $points = $consumer ? $this->law->b2cInterestPoints : $this->law->b2bInterestPoints;
        $charges = [];
        foreach ($this->rates->periods($from, $until) as $period) {
            $rate = $period['rate']->plus($points);
            $charges[] = new InterestCharge(
                base: $amount,
                ratePercent: $rate,
                from: $period['from'],
                until: $period['until'],
                description: $locale === 'de'
                    ? sprintf('Verzugszinsen %s %% p. a. (Basiszinssatz %s %% + %s Prozentpunkte) vom %s bis %s', self::number($rate->toString()), self::number($period['rate']->toString()), self::number($points), $period['from']->format('d.m.Y'), $period['until']->format('d.m.Y'))
                    : sprintf('Default interest %s %% p.a. (base rate %s %% + %s points) from %s to %s', $rate->toString(), $period['rate']->toString(), $points, $period['from']->toIsoString(), $period['until']->toIsoString()),
            );
        }

        return $charges;
    }

    /**
     * Flat rate for business debtors (section 288 (5) BGB), once per claim.
     */
    public function flatRate(string $currency = 'EUR'): ?Money
    {
        return $this->law->b2bFlatRate === null ? null : Money::of($this->law->b2bFlatRate, $currency);
    }

    private static function number(string $value): string
    {
        return str_replace('.', ',', $value);
    }
}
