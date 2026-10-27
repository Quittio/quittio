<?php

declare(strict_types=1);

namespace Quittio\Core\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use DateTimeZone;
use JsonSerializable;
use Quittio\Core\Exceptions\InvalidArgumentException;
use Stringable;
use Throwable;

/**
 * Calendar date without time of day.
 *
 * Invoice dates are legal dates in the issuer's time zone. Storing them as
 * year/month/day makes it impossible for a UTC conversion to shift them to
 * the previous or next day.
 */
final readonly class Date implements JsonSerializable, Stringable
{
    private function __construct(
        public int $year,
        public int $month,
        public int $day,
    ) {
        if (! checkdate($month, $day, $year)) {
            throw new InvalidArgumentException(sprintf('Invalid date %04d-%02d-%02d.', $year, $month, $day));
        }
    }

    /**
     * The date of $year, $month and $day. Throws an InvalidArgumentException for a date that does
     * not exist, e.g. February 30.
     */
    public static function of(int $year, int $month, int $day): self
    {
        return new self($year, $month, $day);
    }

    /**
     * Accepts ISO dates (2026-10-03), the CII format 102 (20261003),
     * date-time strings and DateTimeInterface instances.
     *
     * A DateTimeInterface is first converted into $timezone (if given), so that
     * the calendar date matches the issuer's local date.
     */
    public static function parse(self|DateTimeInterface|string $value, DateTimeZone|string|null $timezone = null): self
    {
        if ($value instanceof self) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            $carbon = CarbonImmutable::instance($value);
            if ($timezone !== null) {
                $carbon = $carbon->setTimezone($timezone);
            }

            return new self((int) $carbon->format('Y'), (int) $carbon->format('n'), (int) $carbon->format('j'));
        }

        $value = trim($value);

        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $value, $m) === 1
            || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) === 1) {
            return new self((int) $m[1], (int) $m[2], (int) $m[3]);
        }

        // Free-form strings must name a calendar date (year, month and day). Expressions
        // relative to today ("tomorrow", "+2000 days", "next monday 2026") and digit strings
        // such as "202610", which Carbon would read as a time of today, are rejected.
        $parts = date_parse($value);
        if ($parts['year'] === false || $parts['month'] === false || $parts['day'] === false
            || $parts['error_count'] > 0 || preg_match('/^\d+$/', $value) === 1) {
            throw new InvalidArgumentException(sprintf('Unable to parse date "%s".', $value));
        }

        try {
            $carbon = CarbonImmutable::parse($value, $timezone);
        } catch (Throwable $e) {
            throw new InvalidArgumentException(sprintf('Unable to parse date "%s".', $value), 0, $e);
        }

        return new self((int) $carbon->format('Y'), (int) $carbon->format('n'), (int) $carbon->format('j'));
    }

    /**
     * Strict CII date in format 102 (YYYYMMDD), as used for incoming documents.
     *
     * @throws InvalidArgumentException
     */
    public static function fromFormat102(string $value): self
    {
        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $value, $m) !== 1) {
            throw new InvalidArgumentException(sprintf('"%s" is not a date in format 102 (YYYYMMDD).', $value));
        }

        return new self((int) $m[1], (int) $m[2], (int) $m[3]);
    }

    /**
     * Strict ISO 8601 calendar date (YYYY-MM-DD) with an optional time zone (Z, +01:00),
     * as used by UBL (xs:date). The time zone does not shift the calendar date.
     *
     * @throws InvalidArgumentException
     */
    public static function fromIsoDate(string $value): self
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:Z|[+-](?:0\d|1[0-4]):[0-5]\d)?$/', $value, $m) !== 1) {
            throw new InvalidArgumentException(sprintf('"%s" is not an ISO date (YYYY-MM-DD).', $value));
        }

        return new self((int) $m[1], (int) $m[2], (int) $m[3]);
    }

    /**
     * Today's date in $timezone, by default the default time zone of PHP.
     */
    public static function today(DateTimeZone|string|null $timezone = null): self
    {
        return self::parse(CarbonImmutable::now($timezone ?? date_default_timezone_get()));
    }

    /**
     * Midnight of this date in $timezone, by default the default time zone of PHP.
     */
    public function toCarbon(DateTimeZone|string|null $timezone = null): CarbonImmutable
    {
        return CarbonImmutable::create($this->year, $this->month, $this->day, 0, 0, 0, $timezone)
            ?? throw new InvalidArgumentException('Unable to create date.');
    }

    /**
     * A new date $days later, or earlier for a negative number.
     */
    public function addDays(int $days): self
    {
        return self::parse($this->toCarbon('UTC')->addDays($days));
    }

    /**
     * The date formatted with a format of date(), e.g. "d.m.Y"; time parts are midnight.
     */
    public function format(string $format): string
    {
        return $this->toCarbon('UTC')->format($format);
    }

    /**
     * Date in UN/CEFACT format 102 (CCYYMMDD).
     */
    public function toFormat102(): string
    {
        return sprintf('%04d%02d%02d', $this->year, $this->month, $this->day);
    }

    /**
     * ISO 8601 date (YYYY-MM-DD), as used for JSON and storage.
     */
    public function toIsoString(): string
    {
        return sprintf('%04d-%02d-%02d', $this->year, $this->month, $this->day);
    }

    /**
     * Negative, zero or positive when this date is before, the same as or after $other, e.g. for
     * usort().
     */
    public function compareTo(self $other): int
    {
        return $this->toIsoString() <=> $other->toIsoString();
    }

    /**
     * Whether both are the same calendar date.
     */
    public function equals(self $other): bool
    {
        return $this->compareTo($other) === 0;
    }

    /**
     * Whether this date is earlier than $other.
     */
    public function isBefore(self $other): bool
    {
        return $this->compareTo($other) < 0;
    }

    /**
     * Whether this date is later than $other.
     */
    public function isAfter(self $other): bool
    {
        return $this->compareTo($other) > 0;
    }

    /**
     * Days from this date to $other: positive when $other is later, negative when it is earlier.
     */
    public function diffInDays(self $other): int
    {
        return (int) $this->toCarbon('UTC')->diffInDays($other->toCarbon('UTC'), false);
    }

    public function __toString(): string
    {
        return $this->toIsoString();
    }

    /**
     * The ISO date (YYYY-MM-DD) for json_encode().
     */
    public function jsonSerialize(): string
    {
        return $this->toIsoString();
    }
}
