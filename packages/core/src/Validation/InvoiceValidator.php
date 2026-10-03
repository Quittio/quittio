<?php

declare(strict_types=1);

namespace Quittio\Core\Validation;

/**
 * Runs all configured validation drivers and merges their results.
 */
final class InvoiceValidator
{
    /** @var list<ValidationDriver> */
    private array $drivers;

    /**
     * @param  iterable<ValidationDriver>  $drivers
     */
    public function __construct(iterable $drivers = [])
    {
        $this->drivers = [];
        foreach ($drivers as $driver) {
            $this->drivers[] = $driver;
        }
    }

    public function withDriver(ValidationDriver $driver): self
    {
        $clone = clone $this;
        $clone->drivers[] = $driver;

        return $clone;
    }

    /**
     * @return list<ValidationDriver>
     */
    public function drivers(): array
    {
        return $this->drivers;
    }

    public function validate(ValidationSubject $subject): ValidationResult
    {
        $result = new ValidationResult;
        foreach ($this->drivers as $driver) {
            if ($driver->supports($subject)) {
                $result = $result->merge($driver->validate($subject));
            }
        }

        return $result;
    }
}
