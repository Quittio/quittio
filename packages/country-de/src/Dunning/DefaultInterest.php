<?php

declare(strict_types=1);

namespace Quittio\Country\DE\Dunning;

use Brick\Math\BigDecimal;
use Brick\Money\Money;
use Quittio\Core\Countries\DunningLaw;
use Quittio\Core\Documents\InterestCharge;
use Quittio\Core\Dunning\DefaultInterest as CoreDefaultInterest;
use Quittio\Core\Support\Date;
use Quittio\Country\DE\CountryDE;
use Quittio\Country\DE\Texts\LegalTexts;

/**
 * Default interest according to section 288 BGB: base rate plus 9 points (business)
 * or 5 points (consumer), split at every change of the base rate.
 * Points, flat rate and day count basis come from the dunning law, by default
 * CountryDE::germanDunningLaw(); the calculation is that of Quittio\Core\Dunning\DefaultInterest. The base rates are those shipped with Quittio plus the rates
 * registered with BaseRates (configured or fetched from the Bundesbank), unless a table is given.
 */
final readonly class DefaultInterest
{
    private CoreDefaultInterest $interest;

    /**
     * @param  BaseRateTable|null  $rates  base rates; null = BaseRates::table()
     * @param  DunningLaw|null  $law  null = CountryDE::germanDunningLaw()
     */
    public function __construct(
        ?BaseRateTable $rates = null,
        ?DunningLaw $law = null,
    ) {
        $this->interest = new CoreDefaultInterest(
            $rates ?? BaseRates::table(),
            $law ?? CountryDE::germanDunningLaw(),
            self::description(...),
        );
    }

    /**
     * Interest charges for an open amount from the first day of default until $until.
     *
     * @return list<InterestCharge>
     */
    public function calculate(Money $amount, Date $from, Date $until, bool $consumer = false, string $locale = 'de'): array
    {
        return $this->interest->calculate($amount, $from, $until, $consumer, $locale);
    }

    /**
     * Flat rate for business debtors (section 288 (5) BGB), once per claim.
     */
    public function flatRate(string $currency = 'EUR'): ?Money
    {
        return $this->interest->flatRate($currency);
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
