<?php

declare(strict_types=1);

namespace Quittio\Core\Validation\Plausibility;

/**
 * Result of an online VAT identifier check. $valid is null if the service was not reachable.
 */
final readonly class VatIdVerification
{
    public function __construct(
        public string $vatId,
        public ?bool $valid,
        public ?string $name = null,
        public ?string $address = null,
        public ?string $error = null,
    ) {}

    /**
     * @return array{vatId: string, valid: ?bool, name: ?string, address: ?string, error: ?string}
     */
    public function toArray(): array
    {
        return ['vatId' => $this->vatId, 'valid' => $this->valid, 'name' => $this->name, 'address' => $this->address, 'error' => $this->error];
    }
}
