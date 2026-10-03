<?php

declare(strict_types=1);

namespace Quittio\Core\Validation\Plausibility;

/**
 * Online check of VAT identifiers (e.g. EU VIES). Implementations must never throw
 * because a service is unavailable; they return a result with $valid = null instead.
 */
interface VatIdVerifier
{
    public function verify(string $vatId): VatIdVerification;
}
