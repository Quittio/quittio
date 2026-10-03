<?php

declare(strict_types=1);

namespace Quittio\Core\Serialization;

use BackedEnum;
use Brick\Math\BigDecimal;
use Brick\Money\Money;
use Quittio\Core\Codes\RawCode;
use Quittio\Core\Mapping\Attributes\Binary;
use Quittio\Core\Support\Amount;
use Quittio\Core\Support\Date;
use ReflectionClass;
use ReflectionParameter;

/**
 * Converts DTOs into plain arrays (scalars only) for JSON, storage and APIs.
 *
 * Values equal to the constructor default are omitted to keep the output compact;
 * the hydrator restores them from the defaults.
 */
final class ArrayNormalizer
{
    /**
     * @return array<string, mixed>
     */
    public function normalize(object $dto): array
    {
        $result = [];
        $constructor = (new ReflectionClass($dto))->getConstructor();
        if ($constructor === null) {
            return $result;
        }

        $properties = get_object_vars($dto);
        foreach ($constructor->getParameters() as $parameter) {
            $name = $parameter->getName();
            $value = $properties[$name] ?? null;
            $normalized = is_string($value) && $parameter->getAttributes(Binary::class) !== []
                ? base64_encode($value)
                : $this->value($value);

            if ($this->isDefault($parameter, $normalized)) {
                continue;
            }

            $result[$name] = $normalized;
        }

        return $result;
    }

    public function value(mixed $value): mixed
    {
        return match (true) {
            $value === null, is_scalar($value) => $value,
            $value instanceof Money => Amount::format($value),
            $value instanceof BigDecimal => $value->toString(),
            $value instanceof Date => $value->toIsoString(),
            $value instanceof BackedEnum => $value->value,
            $value instanceof RawCode => $value->value,
            is_array($value) => array_map($this->value(...), $value),
            is_object($value) => $this->normalize($value),
            default => null,
        };
    }

    private function isDefault(ReflectionParameter $parameter, mixed $normalized): bool
    {
        if (! $parameter->isDefaultValueAvailable()) {
            return false;
        }

        return $this->value($parameter->getDefaultValue()) === $normalized;
    }
}
