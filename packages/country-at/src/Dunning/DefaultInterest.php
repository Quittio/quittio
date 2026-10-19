<?php

declare(strict_types=1);

namespace Quittio\Country\AT\Dunning;

use Brick\Math\BigDecimal;
use Brick\Money\Money;
use Quittio\Core\Countries\DunningLaw;
use Quittio\Core\Documents\InterestCharge;
use Quittio\Core\Dunning\DefaultInterest as CoreDefaultInterest;
use Quittio\Core\Support\Date;
use Quittio\Country\AT\CountryAT;
use Quittio\Country\AT\Texts\LegalTexts;

/**
 * Austrian default interest: the base rate of the OeNB plus 9.2 points between businesses
 * (section 456 UGB), split at every change of the base rate, and 4 % for consumers (section 1000
 * (1) ABGB). The calculation is that of Quittio\Core\Dunning\DefaultInterest.
 */
final readonly class DefaultInterest
{
    private CoreDefaultInterest $interest;

    /**
     * @param  BaseRateTable|null  $rates  base rates; null = the published table
     * @param  DunningLaw|null  $law  null = CountryAT::austrianDunningLaw()
     */
    public function __construct(
        ?BaseRateTable $rates = null,
        ?DunningLaw $law = null,
    ) {
        $this->interest = new CoreDefaultInterest(
            $rates ?? new BaseRateTable,
            $law ?? CountryAT::austrianDunningLaw(),
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
     * Flat rate for business debtors (section 458 UGB), once per claim.
     */
    public function flatRate(string $currency = 'EUR'): ?Money
    {
        return $this->interest->flatRate($currency);
    }

    /**
     * The text of LegalTexts::DEFAULT_INTEREST (or DEFAULT_INTEREST_CONSUMER for the fixed rate);
     * German texts with Austrian numbers and dates.
     */
    private static function description(BigDecimal $rate, BigDecimal $baseRate, string $points, Date $from, Date $lastDay, string $locale): string
    {
        $key = $points === '0.00' && $rate->isEqualTo($baseRate) ? LegalTexts::DEFAULT_INTEREST_CONSUMER : LegalTexts::DEFAULT_INTEREST;
        $german = LegalTexts::localeOf($key, $locale) === 'de';
        $number = static fn (string $value): string => $german ? str_replace('.', ',', $value) : $value;

        return LegalTexts::text($key, $locale, [
            'rate' => $number($rate->toString()),
            'base_rate' => $number($baseRate->toString()),
            'points' => $number($points),
            'from' => $german ? $from->format('d.m.Y') : $from->toIsoString(),
            'until' => $german ? $lastDay->format('d.m.Y') : $lastDay->toIsoString(),
        ]);
    }
}
