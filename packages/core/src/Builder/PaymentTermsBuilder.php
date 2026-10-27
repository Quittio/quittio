<?php

declare(strict_types=1);

namespace Quittio\Core\Builder;

use Quittio\Core\Data\Payment\CashDiscount;
use Quittio\Core\Data\Payment\PaymentTerms;
use Quittio\Core\Data\Payment\PenaltyTerms;
use Quittio\Core\Support\Amount;
use Quittio\Core\Support\Decimal;

/**
 * Fluent builder for structured payment terms (BT-20, BT-9).
 */
final class PaymentTermsBuilder
{
    private ?string $text = null;

    private ?int $dueDays = null;

    /** @var list<array{days: int, percent: string, base: string|null}> */
    private array $discounts = [];

    private ?PenaltyTerms $lateFee = null;

    /**
     * Continues existing terms (InvoiceBuilder::paymentTerms() with a callable after from()).
     */
    public static function fromTerms(PaymentTerms $terms): self
    {
        $builder = new self;
        $builder->text = $terms->text;
        $builder->dueDays = $terms->dueDays;
        $builder->discounts = array_map(static fn (CashDiscount $d): array => [
            'days' => $d->days,
            'percent' => (string) $d->percent,
            'base' => $d->baseAmount === null ? null : Amount::format($d->baseAmount),
        ], $terms->discounts);
        $builder->lateFee = $terms->lateFeeTerms;

        return $builder;
    }

    /**
     * Free text of the payment terms (BT-20); null removes it. Without text the due days are
     * written as a translated sentence, and cash discounts and the late fee text are added in any
     * case.
     */
    public function text(?string $text): self
    {
        $this->text = $text;

        return $this;
    }

    /**
     * Sets the payment target. The due date (BT-9) is derived from the issue date if not given explicitly.
     */
    public function dueInDays(int $days): self
    {
        $this->dueDays = $days;

        return $this;
    }

    /**
     * Cash discount ("Skonto") for payment within $days days.
     */
    public function cashDiscount(int $days, string $percent, ?string $baseAmount = null): self
    {
        $this->discounts[] = ['days' => $days, 'percent' => $percent, 'base' => $baseAmount];

        return $this;
    }

    /**
     * Late payment terms: $days and $percent are written as structured elements in profile EXTENDED
     * only, $text appears in the payment terms text (BT-20). Replaces earlier late payment terms.
     */
    public function lateFee(?int $days = null, ?string $percent = null, ?string $text = null): self
    {
        $this->lateFee = new PenaltyTerms($days, Decimal::ofNullable($percent), $text);

        return $this;
    }

    /**
     * The PaymentTerms with cash discount base amounts in $currency; percentages are rounded to two
     * decimals.
     */
    public function build(string $currency): PaymentTerms
    {
        return new PaymentTerms(
            text: $this->text,
            dueDays: $this->dueDays,
            discounts: array_map(
                static fn (array $d): CashDiscount => new CashDiscount(
                    $d['days'],
                    Decimal::round(Decimal::of($d['percent']), 2),
                    Amount::ofNullable($d['base'], $currency),
                ),
                $this->discounts,
            ),
            lateFeeTerms: $this->lateFee,
        );
    }
}
