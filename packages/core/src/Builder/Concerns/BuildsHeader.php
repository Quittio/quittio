<?php

declare(strict_types=1);

namespace Quittio\Core\Builder\Concerns;

use Brick\Math\BigDecimal;
use DateTimeInterface;
use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Codes\RawCode;
use Quittio\Core\Data\Delivery\Delivery;
use Quittio\Core\Data\Identifier;
use Quittio\Core\Data\Note;
use Quittio\Core\Enums\InvoiceTypeCode;
use Quittio\Core\Enums\TaxPointDateCode;
use Quittio\Core\Exceptions\InvalidArgumentException;
use Quittio\Core\Standards\StandardVersion;
use Quittio\Core\Support\Date;
use Quittio\Core\Support\Decimal;

/**
 * Invoice number, dates, type, currencies, business process and standard version.
 *
 * Part of {@see InvoiceBuilder}, which holds the state; use the builder, not the trait.
 */
trait BuildsHeader
{
    /**
     * Invoice number (BT-1). Not checked here; the validation requires it.
     */
    public function number(string $number): static
    {
        $this->number = $number;

        return $this;
    }

    /**
     * Issue date (BT-2); default today. A DateTimeInterface is converted in the time zone of
     * timezone(), so call timezone() first.
     */
    public function issueDate(Date|DateTimeInterface|string $date): static
    {
        $this->issueDate = Date::parse($date, $this->meta->timezone);

        return $this;
    }

    /**
     * Type code (BT-3) from UNTDID 1001, e.g. 381 for a credit note; default 380. Unknown
     * codes are kept as RawCode and reported by the validation.
     */
    public function type(InvoiceTypeCode|RawCode|string $type): static
    {
        $this->typeCode = is_string($type) ? (InvoiceTypeCode::tryFrom($type) ?? new RawCode($type)) : $type;

        return $this;
    }

    /**
     * Invoice currency (BT-5), ISO 4217; stored in upper case. Default EUR.
     */
    public function currency(string $currency): static
    {
        $this->currency = strtoupper($currency);

        return $this;
    }

    /**
     * VAT accounting currency (BT-6). With an exchange rate, BT-111 is calculated.
     */
    public function taxCurrency(string $currency, BigDecimal|int|float|string|null $exchangeRate = null, Date|DateTimeInterface|string|null $rateDate = null): static
    {
        $this->taxCurrency = strtoupper($currency);
        if ($exchangeRate !== null) {
            $this->exchangeRate($exchangeRate, $rateDate);
        }

        return $this;
    }

    /**
     * Exchange rate from the invoice currency to the VAT accounting currency (BT-6), used to
     * calculate the VAT total in that currency (BT-111), with the date of the rate. Replaces a rate
     * implied by declared amounts (see from()).
     *
     * @throws InvalidArgumentException for a float
     */
    public function exchangeRate(BigDecimal|int|float|string $rate, Date|DateTimeInterface|string|null $date = null): static
    {
        $this->impliedExchangeRate = false;
        $this->meta = $this->meta->with([
            'exchangeRate' => Decimal::of($rate),
            'exchangeRateDate' => $date === null ? null : Date::parse($date),
        ]);

        return $this;
    }

    /**
     * Date when VAT becomes chargeable (BT-7), if it differs from the issue date. EN 16931
     * allows BT-7 or BT-8, not both (BR-CO-03).
     */
    public function taxPointDate(Date|DateTimeInterface|string $date): static
    {
        $this->taxPointDate = Date::parse($date, $this->meta->timezone);

        return $this;
    }

    /**
     * Code of the date when VAT becomes chargeable (BT-8): issue, delivery or payment date.
     * EN 16931 allows BT-7 or BT-8, not both (BR-CO-03).
     */
    public function taxPointDateCode(TaxPointDateCode $code): static
    {
        $this->taxPointDateCode = $code;

        return $this;
    }

    /**
     * Due date (BT-9). Without it the calculator derives it from dueInDays(); otherwise the
     * configured due_days apply when rendering, never for credit notes.
     */
    public function dueDate(Date|DateTimeInterface|string $date): static
    {
        $this->dueDate = Date::parse($date, $this->meta->timezone);

        return $this;
    }

    /**
     * Business process identifier (BT-23); Peppol and XRechnung write their process when it is
     * not set. null removes it.
     */
    public function businessProcess(?string $process): static
    {
        $this->businessProcess = $process;

        return $this;
    }

    /**
     * Version of EN 16931 (BT-24 of the profile): 2017 (default) or 2026, which only the profile
     * EN 16931 supports.
     */
    public function standard(StandardVersion $version): static
    {
        $this->standardVersion = $version;

        return $this;
    }
}
