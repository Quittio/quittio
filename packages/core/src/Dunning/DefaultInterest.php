<?php

declare(strict_types=1);

namespace Quittio\Core\Dunning;

use Brick\Math\BigDecimal;
use Brick\Money\Money;
use Closure;
use Quittio\Core\Countries\Capabilities\ProvidesReferenceRates;
use Quittio\Core\Countries\CountryPack;
use Quittio\Core\Countries\DunningLaw;
use Quittio\Core\Documents\InterestCharge;
use Quittio\Core\Exceptions\ConfigurationException;
use Quittio\Core\Support\Amount;
use Quittio\Core\Support\Date;

/**
 * Default interest of a late payment law: the reference rate plus the points of the DunningLaw
 * (business or consumer debtors), split at every change of the reference rate, with the day
 * count basis of the law; for consumers a fixed rate of the law instead, if it has one. The result goes into DocumentBuilder::interestCharge().
 */
final readonly class DefaultInterest
{
    /**
     * @param  (Closure(BigDecimal $rate, BigDecimal $referenceRate, string $points, Date $from, Date $lastDay, string $locale): ?string)|null  $describe
     *         text of a charge, e.g. "Verzugszinsen 9 Prozentpunkte über dem Basiszinssatz …"; null = no text
     */
    public function __construct(
        private ReferenceRates $rates,
        private DunningLaw $law,
        private ?Closure $describe = null,
    ) {}

    /**
     * Default interest with the reference rates and the dunning law of a country pack.
     *
     * @throws ConfigurationException for a pack without reference rates
     */
    public static function forCountry(CountryPack $pack): self
    {
        if (! $pack instanceof ProvidesReferenceRates) {
            throw new ConfigurationException(sprintf('The country pack %s has no reference interest rate (ProvidesReferenceRates).', $pack->code()));
        }

        return new self($pack->referenceRates(), $pack->dunningLaw());
    }

    /**
     * Interest charges for an open amount from the first day of default until $until (exclusive).
     *
     * @return list<InterestCharge>
     */
    public function calculate(Money $amount, Date $from, Date $until, bool $consumer = false, string $locale = 'en'): array
    {
        if (! $amount->isPositive() || ! $until->isAfter($from)) {
            return [];
        }

        if ($consumer && $this->law->b2cFixedRate !== null) {
            // A fixed rate does not follow the reference rate: one charge for the whole time.
            $rate = BigDecimal::of($this->law->b2cFixedRate);

            return [new InterestCharge(
                base: $amount,
                ratePercent: $rate,
                from: $from,
                until: $until,
                description: $this->describe === null ? null : ($this->describe)($rate, $rate, '0.00', $from, $until->addDays(-1), $locale),
                dayCount: $this->law->dayCount,
            )];
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
                // Periods end exclusively; the text names the last day with interest.
                description: $this->describe === null ? null : ($this->describe)($rate, $period['rate'], $points, $period['from'], $period['until']->addDays(-1), $locale),
                dayCount: $this->law->dayCount,
            );
        }

        return $charges;
    }

    /**
     * Flat rate for business debtors (e.g. 40 EUR, EU late payment directive), once per claim.
     */
    public function flatRate(string $currency = 'EUR'): ?Money
    {
        return $this->law->b2bFlatRate === null ? null : Amount::of($this->law->b2bFlatRate, $currency);
    }
}
