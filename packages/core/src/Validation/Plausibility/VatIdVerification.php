<?php

declare(strict_types=1);

namespace Quittio\Core\Validation\Plausibility;

/**
 * Result of an online VAT identifier check. $valid is null if no statement is possible: the
 * service was not reachable or answered with an error, or it does not cover the identifier
 * ($supported = false).
 */
final readonly class VatIdVerification
{
    public function __construct(
        public string $vatId,
        public ?bool $valid,
        public ?string $name = null,
        public ?string $address = null,
        public ?string $error = null,
        public bool $supported = true,
    ) {}

    /**
     * The verifier does not cover this identifier (e.g. a Swiss VAT number for VIES).
     */
    public static function notSupported(string $vatId, ?string $reason = null): self
    {
        return new self($vatId, null, error: $reason, supported: false);
    }

    /**
     * @return array{vatId: string, valid: ?bool, name: ?string, address: ?string, error: ?string, supported: bool}
     */
    public function toArray(): array
    {
        return ['vatId' => $this->vatId, 'valid' => $this->valid, 'name' => $this->name, 'address' => $this->address, 'error' => $this->error, 'supported' => $this->supported];
    }
}
