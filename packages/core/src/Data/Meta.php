<?php

declare(strict_types=1);

namespace Quittio\Core\Data;

use Brick\Math\BigDecimal;
use Quittio\Core\Data\Concerns\Immutable;
use Quittio\Core\Mapping\Attributes as Q;
use Quittio\Core\Support\Date;
use Quittio\Core\Support\Decimal;

/**
 * Package-internal metadata: locale, time zone, exchange rate, free data for templates and flags for country packs.
 *
 * The flags of the declared amounts (FLAG_DECLARED_AMOUNTS, FLAG_DECLARED_AMOUNTS_RECALCULATED,
 * FLAG_CANCELLATION_VAT_ALIGNED, amountsFingerprint(), declaredVatInTaxCurrency()) are their stored
 * format; Quittio reads and writes them together as Calculation\DeclaredAmountsState.
 */
final readonly class Meta
{
    use Immutable;

    /** The seller applies a small business scheme and must not charge VAT (DE: section 19 UStG). */
    public const string FLAG_SMALL_BUSINESS = 'small_business';

    /**
     * The line net amounts (BT-131) and the VAT breakdown (BT-116, BT-117) of the data are the
     * declared amounts of an existing document that another system rounded differently, e.g. of
     * a cancellation or of data started with InvoiceBuilder::from() whose amounts were not
     * changed. Set by the builder only, and only if the recalculation does not reproduce the
     * declared amounts, together with the fingerprint of the amount inputs (amountsFingerprint()).
     * The calculator keeps declared BT-131 within 0.02 of quantity × price ± allowances and
     * charges (PEPPOL-EN16931-R120) and declared BT-117 within less than one currency unit
     * (BR-CO-17), so that foreign rounding is reversed exactly; once the amount inputs no longer
     * match the fingerprint (e.g. changed with with() or in stored JSON), everything is recalculated.
     */
    public const string FLAG_DECLARED_AMOUNTS = 'declared_amounts';

    /** Set by the calculator when declared amounts could not be kept (QUITTIO-DECLARED-AMOUNTS). */
    public const string FLAG_DECLARED_AMOUNTS_RECALCULATED = 'declared_amounts_recalculated';

    /**
     * A partial cancellation whose VAT (BT-117, BT-111) is aligned with the earlier partial
     * cancellations of the original (Corrections\CancellationHistory), in gross price mode also its
     * taxable amounts (BT-116): a VAT amount may differ from round2(taxable amount × rate) by a cent,
     * reported as QUITTIO-CANCELLATION-VAT-ALIGNED instead of QUITTIO-VAT-ROUNDING (which still
     * reports larger differences and a sign other than that of the taxable amount). Dropped once
     * amounts change.
     */
    public const string FLAG_CANCELLATION_VAT_ALIGNED = 'cancellation_vat_aligned';

    /**
     * A document on the reduction of the consideration of an invoice (Corrections\PriceReduction,
     * section 17 UStG), not a cancellation or correction of it.
     */
    public const string FLAG_PRICE_REDUCTION = 'price_reduction';

    /** Prefix of the flag that carries the fingerprint of the amount inputs. */
    private const string FINGERPRINT_PREFIX = 'amounts_fingerprint:';

    /** Prefix of the flag that carries a declared VAT total in accounting currency (BT-111). */
    private const string VAT_IN_TAX_CURRENCY_PREFIX = 'declared_vat_in_tax_currency:';

    /**
     * @param  array<string, mixed>  $custom
     * @param  list<string>  $flags
     */
    public function __construct(
        #[Q\Internal]
        public ?string $locale = null,
        #[Q\Internal]
        public ?string $timezone = null,
        #[Q\Internal]
        public ?BigDecimal $exchangeRate = null,
        #[Q\Internal]
        public ?Date $exchangeRateDate = null,
        #[Q\Internal]
        public array $custom = [],
        #[Q\Internal]
        public ?string $source = null,
        #[Q\Internal, Q\ListOf('string')]
        public array $flags = [],
    ) {}

    public function hasFlag(string $flag): bool
    {
        return in_array($flag, $this->flags, true);
    }

    /**
     * Fingerprint of the amount inputs (Calculation\DeclaredAmounts) for which the line net amounts
     * and the VAT breakdown of the data were calculated or declared; set by the calculator and the
     * builder. Data whose inputs no longer match was changed afterwards (e.g. with with() or in
     * stored JSON): its amounts are stale. Stored as a flag, so that it survives toArray().
     */
    public function amountsFingerprint(): ?string
    {
        return $this->prefixed(self::FINGERPRINT_PREFIX);
    }

    public function withAmountsFingerprint(?string $fingerprint): self
    {
        return $this->withPrefixed(self::FINGERPRINT_PREFIX, $fingerprint);
    }

    /**
     * Declared VAT total in accounting currency (BT-111) that belongs to the declared amounts
     * (FLAG_DECLARED_AMOUNTS), e.g. of a partial cancellation whose VAT is aligned with the earlier
     * ones: the calculator keeps it within 0.01 of round2(BT-110 × exchange rate). Data built with
     * InvoiceBuilder has no totals, so it is stored as a flag, like the fingerprint.
     */
    public function declaredVatInTaxCurrency(): ?BigDecimal
    {
        $value = $this->prefixed(self::VAT_IN_TAX_CURRENCY_PREFIX);

        return $value === null || ! Decimal::isPlain($value) ? null : BigDecimal::of($value);
    }

    public function withDeclaredVatInTaxCurrency(?BigDecimal $amount): self
    {
        return $this->withPrefixed(self::VAT_IN_TAX_CURRENCY_PREFIX, $amount === null ? null : (string) $amount);
    }

    public function withFlag(string $flag, bool $enabled = true): self
    {
        $flags = array_values(array_filter($this->flags, static fn (string $f): bool => $f !== $flag));
        if ($enabled) {
            $flags[] = $flag;
        }

        return $this->with(['flags' => $flags]);
    }

    private function prefixed(string $prefix): ?string
    {
        foreach ($this->flags as $flag) {
            if (str_starts_with($flag, $prefix)) {
                return substr($flag, strlen($prefix));
            }
        }

        return null;
    }

    private function withPrefixed(string $prefix, ?string $value): self
    {
        $flags = array_values(array_filter($this->flags, static fn (string $f): bool => ! str_starts_with($f, $prefix)));
        if ($value !== null) {
            $flags[] = $prefix.$value;
        }

        return $this->with(['flags' => $flags]);
    }
}
