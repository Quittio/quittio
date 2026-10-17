<?php

declare(strict_types=1);

namespace Quittio\Core\Calculation;

use Brick\Math\BigDecimal;
use Quittio\Core\Data\Meta;

/**
 * The declared amounts state of invoice data, typed over the meta flags that store it: the status
 * (Meta::FLAG_DECLARED_AMOUNTS, Meta::FLAG_DECLARED_AMOUNTS_RECALCULATED), the fingerprint of the
 * amount inputs (Meta::amountsFingerprint()), the VAT aligned with earlier partial cancellations
 * (Meta::FLAG_CANCELLATION_VAT_ALIGNED) and the declared VAT total in accounting currency
 * (Meta::declaredVatInTaxCurrency()). The calculator, the builder, the reader and the cancellations
 * read it with fromMeta() and write it with applyTo().
 *
 * The flags stay the stored format: fromMeta() reads data of every earlier version, and applyTo()
 * writes the same flag strings. applyTo() keeps the other flags in their order and appends the
 * flags of the state after them in a fixed order (declared amounts, recalculated, VAT aligned,
 * VAT in accounting currency, fingerprint last), so that the same state always gives the same flags.
 *
 * A declared status without fingerprint marks declared amounts whose inputs are not known
 * (DeclaredAmounts::isStale()), e.g. a cancellation of an original changed after its amounts were
 * declared: InvoiceBuilder::from() then recalculates everything.
 *
 * @internal The meta flags are package-internal metadata; applications check the outcome with the
 *           validation (QUITTIO-DECLARED-AMOUNTS) and the DeclaredAmounts helpers.
 */
final readonly class DeclaredAmountsState
{
    private const array FLAGS = [Meta::FLAG_DECLARED_AMOUNTS, Meta::FLAG_DECLARED_AMOUNTS_RECALCULATED, Meta::FLAG_CANCELLATION_VAT_ALIGNED];

    public function __construct(
        public DeclaredAmountsStatus $status = DeclaredAmountsStatus::None,
        public ?string $fingerprint = null,
        public bool $vatAligned = false,
        public ?BigDecimal $vatInTaxCurrency = null,
    ) {}

    public static function none(): self
    {
        return new self;
    }

    public static function fromMeta(Meta $meta): self
    {
        return new self(
            DeclaredAmountsStatus::of($meta->hasFlag(Meta::FLAG_DECLARED_AMOUNTS), $meta->hasFlag(Meta::FLAG_DECLARED_AMOUNTS_RECALCULATED)),
            $meta->amountsFingerprint(),
            $meta->hasFlag(Meta::FLAG_CANCELLATION_VAT_ALIGNED),
            $meta->declaredVatInTaxCurrency(),
        );
    }

    /**
     * The meta data with the flags of this state instead of those of its previous state.
     */
    public function applyTo(Meta $meta): Meta
    {
        $flags = array_values(array_filter(
            $meta->withAmountsFingerprint(null)->withDeclaredVatInTaxCurrency(null)->flags,
            static fn (string $flag): bool => ! in_array($flag, self::FLAGS, true),
        ));
        if ($this->status->declared()) {
            $flags[] = Meta::FLAG_DECLARED_AMOUNTS;
        }
        if ($this->status->recalculated()) {
            $flags[] = Meta::FLAG_DECLARED_AMOUNTS_RECALCULATED;
        }
        if ($this->vatAligned) {
            $flags[] = Meta::FLAG_CANCELLATION_VAT_ALIGNED;
        }

        return $meta->with(['flags' => $flags])->withDeclaredVatInTaxCurrency($this->vatInTaxCurrency)->withAmountsFingerprint($this->fingerprint);
    }

    /** The data carries declared amounts (Meta::FLAG_DECLARED_AMOUNTS). */
    public function isDeclared(): bool
    {
        return $this->status->declared();
    }

    /** Declared amounts had to be recalculated (Meta::FLAG_DECLARED_AMOUNTS_RECALCULATED). */
    public function isRecalculated(): bool
    {
        return $this->status->recalculated();
    }

    public function withDeclared(bool $declared = true): self
    {
        return $this->withStatus(DeclaredAmountsStatus::of($declared, $this->isRecalculated()));
    }

    public function withRecalculated(bool $recalculated = true): self
    {
        return $this->withStatus(DeclaredAmountsStatus::of($this->isDeclared(), $recalculated));
    }

    public function withStatus(DeclaredAmountsStatus $status): self
    {
        return new self($status, $this->fingerprint, $this->vatAligned, $this->vatInTaxCurrency);
    }

    public function withFingerprint(?string $fingerprint): self
    {
        return new self($this->status, $fingerprint, $this->vatAligned, $this->vatInTaxCurrency);
    }

    public function withVatAligned(bool $aligned = true): self
    {
        return new self($this->status, $this->fingerprint, $aligned, $this->vatInTaxCurrency);
    }

    public function withVatInTaxCurrency(?BigDecimal $amount): self
    {
        return new self($this->status, $this->fingerprint, $this->vatAligned, $amount);
    }
}
