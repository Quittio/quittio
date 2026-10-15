<?php

declare(strict_types=1);

namespace Quittio\Country\DE\Dunning;

use Brick\Math\BigDecimal;
use Brick\Money\Money;
use Quittio\Core\Countries\DunningLaw;
use Quittio\Core\Documents\InterestCharge;
use Quittio\Core\Support\Amount;
use Quittio\Core\Support\Date;
use Quittio\Country\DE\CountryDE;
use Quittio\Country\DE\Texts\LegalTexts;

/**
 * Default interest according to section 288 BGB: base rate plus 9 points (business)
 * or 5 points (consumer), split at every change of the base rate.
 * Points, flat rate and day count basis come from the dunning law, by default
 * CountryDE::dunningLaw(). The base rates are those shipped with Quittio plus the rates
 * registered with BaseRates (configured or fetched from the Bundesbank), unless a table is given.
 */
final readonly class DefaultInterest
{
    private DunningLaw $law;

    private BaseRateTable $rates;

    public function __construct(
        ?BaseRateTable $rates = null,
        ?DunningLaw $law = null,
    ) {
        $this->rates = $rates ?? BaseRates::table();
        $this->law = $law ?? (new CountryDE)->dunningLaw();
    }

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
            // Periods end exclusively; the text names the last day with interest.
            $lastDay = $period['until']->addDays(-1);
            $charges[] = new InterestCharge(
                base: $amount,
                ratePercent: $rate,
                from: $period['from'],
                until: $period['until'],
                dayCount: $this->law->dayCount,
                description: self::description($rate, $period['rate'], $points, $period['from'], $lastDay, $locale),
            );
        }

        return $charges;
    }

    /**
     * Flat rate for business debtors (section 288 (5) BGB), once per claim.
     */
    public function flatRate(string $currency = 'EUR'): ?Money
    {
        return $this->law->b2bFlatRate === null ? null : Amount::of($this->law->b2bFlatRate, $currency);
    }

    /**
     * The text of LegalTexts::DEFAULT_INTEREST; German texts (also for "de_AT") with German
     * numbers and dates.
     */
    private static function description(BigDecimal $rate, BigDecimal $baseRate, string $points, Date $from, Date $lastDay, string $locale): string
    {
        $german = LegalTexts::localeOf(LegalTexts::DEFAULT_INTEREST, $locale) === 'de';
        $number = static fn (string $value): string => $german ? str_replace('.', ',', $value) : $value;

        return LegalTexts::text(LegalTexts::DEFAULT_INTEREST, $locale, [
            'rate' => $number($rate->toString()),
            'base_rate' => $number($baseRate->toString()),
            'points' => $number($points),
            'from' => $german ? $from->format('d.m.Y') : $from->toIsoString(),
            'until' => $german ? $lastDay->format('d.m.Y') : $lastDay->toIsoString(),
        ]);
    }
}
