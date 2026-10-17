<?php

declare(strict_types=1);

namespace Quittio\Core\Calculation;

use Quittio\Core\Data\Meta;

/**
 * Whether data carries declared amounts (Meta::FLAG_DECLARED_AMOUNTS) and whether declared amounts
 * had to be recalculated (Meta::FLAG_DECLARED_AMOUNTS_RECALCULATED). The two flags are independent,
 * so there are four states.
 *
 * @internal Read and written through DeclaredAmountsState; the flags stay the stored format.
 */
enum DeclaredAmountsStatus
{
    /** No declared amounts; Quittio calculated the amounts itself. */
    case None;

    /** Declared amounts that the calculator keeps within the tolerances. */
    case Declared;

    /**
     * Declared amounts, of which the calculator kept those within the tolerances and recalculated
     * the others (sticky: calculating the result again finds no deviation, but the document may
     * still not reverse the original exactly).
     */
    case PartlyRecalculated;

    /**
     * No declared amounts any more: they were replaced by recalculated ones (DeclaredAmountsMode::Recalculate,
     * or data continued with InvoiceBuilder::from() after a partial recalculation).
     */
    case Recalculated;

    public static function of(bool $declared, bool $recalculated): self
    {
        return match (true) {
            $declared && $recalculated => self::PartlyRecalculated,
            $declared => self::Declared,
            $recalculated => self::Recalculated,
            default => self::None,
        };
    }

    /** Meta::FLAG_DECLARED_AMOUNTS */
    public function declared(): bool
    {
        return $this === self::Declared || $this === self::PartlyRecalculated;
    }

    /** Meta::FLAG_DECLARED_AMOUNTS_RECALCULATED */
    public function recalculated(): bool
    {
        return $this === self::Recalculated || $this === self::PartlyRecalculated;
    }
}
