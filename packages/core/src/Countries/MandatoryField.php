<?php

declare(strict_types=1);

namespace Quittio\Core\Countries;

/**
 * A value that must appear in the PDF (checked by the mandatory field guard).
 *
 * $accessKey is the view model path whose access is recorded, e.g. "seller.vatId".
 */
final readonly class MandatoryField
{
    public function __construct(
        public string $accessKey,
        public string $description,
        public ?string $legalBasis = null,
    ) {}
}
