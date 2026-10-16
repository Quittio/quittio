<?php

declare(strict_types=1);

namespace Quittio\Core\Serialization;

use BackedEnum;
use Brick\Math\BigDecimal;
use Brick\Money\Money;
use Quittio\Core\Codes\RawCode;
use Quittio\Core\Exceptions\HydrationException;
use Quittio\Core\Mapping\Attributes\Binary;
use Quittio\Core\Mapping\Attributes\ListOf;
use Quittio\Core\Mapping\Attributes\MapOf;
use Quittio\Core\Mapping\Attributes\TaxCurrency;
use Quittio\Core\Support\Amount;
use Quittio\Core\Support\Date;
use Quittio\Core\Support\Decimal;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;
use Throwable;

/**
 * Builds DTOs from plain arrays (the inverse of ArrayNormalizer).
 *
 * Monetary amounts are stored without currency; the invoice currency (BT-5) and,
 * for fields marked with #[TaxCurrency], the VAT accounting currency (BT-6) are used.
 */
final class ArrayHydrator
{
    private string $currency = 'EUR';

    private ?string $taxCurrency = null;

    /**
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @param  array<string, mixed>  $data
     * @return T
     */
    public function hydrate(string $class, array $data): object
    {
        if (isset($data['currency']) && is_string($data['currency'])) {
            $this->currency = strtoupper($data['currency']);
        }
        if (isset($data['taxCurrency']) && is_string($data['taxCurrency'])) {
            $this->taxCurrency = strtoupper($data['taxCurrency']);
        }

        return $this->object($class, $data, '');
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @param  array<array-key, mixed>  $data
     * @return T
     */
    private function object(string $class, array $data, string $path): object
    {
        $reflection = new ReflectionClass($class);
        if ($reflection->isAbstract()) {
            throw HydrationException::at($path, sprintf('Cannot instantiate abstract class %s.', $class));
        }

        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return $reflection->newInstance();
        }

        $known = [];
        $arguments = [];
        foreach ($constructor->getParameters() as $parameter) {
            $name = $parameter->getName();
            $known[$name] = true;
            $childPath = $path === '' ? $name : $path.'.'.$name;

            if (! array_key_exists($name, $data)) {
                if ($parameter->isDefaultValueAvailable()) {
                    continue;
                }
                if ($parameter->allowsNull()) {
                    $arguments[$name] = null;

                    continue;
                }
                throw HydrationException::at($childPath, 'Required value is missing.');
            }

            $arguments[$name] = $this->parameter($parameter, $data[$name], $childPath);
        }

        foreach (array_keys($data) as $key) {
            if (! isset($known[$key])) {
                throw HydrationException::at($path === '' ? (string) $key : $path.'.'.$key, sprintf('Unknown field for %s.', $reflection->getShortName()));
            }
        }

        try {
            return $reflection->newInstanceArgs($arguments);
        } catch (HydrationException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw HydrationException::at($path, $e->getMessage(), $e);
        }
    }

    private function parameter(ReflectionParameter $parameter, mixed $value, string $path): mixed
    {
        if ($value === null) {
            if (! $parameter->allowsNull()) {
                throw HydrationException::at($path, 'Value must not be null.');
            }

            return null;
        }

        $type = $parameter->getType();
        $names = match (true) {
            $type instanceof ReflectionNamedType => [$type->getName()],
            $type instanceof ReflectionUnionType => array_map(
                static fn ($t): string => $t instanceof ReflectionNamedType ? $t->getName() : 'mixed',
                $type->getTypes(),
            ),
            default => ['mixed'],
        };
        $names = array_values(array_filter($names, static fn (string $n): bool => $n !== 'null'));

        $listOf = $parameter->getAttributes(ListOf::class)[0] ?? null;
        $mapOf = $parameter->getAttributes(MapOf::class)[0] ?? null;
        $taxCurrency = $parameter->getAttributes(TaxCurrency::class) !== [];

        if ($parameter->getAttributes(Binary::class) !== []) {
            $decoded = is_string($value) ? base64_decode($value, true) : false;
            if ($decoded === false) {
                throw HydrationException::at($path, 'Expected base64 encoded content.');
            }

            return $decoded;
        }

        if ($names === ['array']) {
            if (! is_array($value)) {
                throw HydrationException::at($path, 'Expected a list.');
            }
            if ($mapOf !== null) {
                $elementType = $mapOf->newInstance()->type;
                $result = [];
                foreach ($value as $key => $element) {
                    $result[(string) $key] = $this->convert([$elementType], $element, $path.'['.$key.']', false);
                }

                return $result;
            }
            if ($listOf === null) {
                return $value;
            }
            $elementType = $listOf->newInstance()->type;
            $result = [];
            foreach (array_values($value) as $index => $element) {
                $result[] = $this->convert([$elementType], $element, $path.'['.$index.']', false);
            }

            return $result;
        }

        return $this->convert($names, $value, $path, $taxCurrency);
    }

    /**
     * @param  list<string>  $types
     */
    private function convert(array $types, mixed $value, string $path, bool $taxCurrency): mixed
    {
        // Objects of a matching type are accepted as they are.
        if (is_object($value)) {
            foreach ($types as $type) {
                if ($value instanceof $type) {
                    return $value;
                }
            }
        }

        $hasRawCode = in_array(RawCode::class, $types, true);
        $errors = [];

        foreach ($types as $type) {
            try {
                $converted = $this->convertTo($type, $value, $path, $taxCurrency, $hasRawCode);
                if ($converted !== null) {
                    return $converted;
                }
            } catch (HydrationException $e) {
                $errors[] = $e;
            }
        }

        if ($hasRawCode && (is_string($value) || is_int($value))) {
            return new RawCode((string) $value);
        }

        throw $errors[0] ?? HydrationException::at($path, sprintf('Value does not match type %s.', implode('|', $types)));
    }

    private function convertTo(string $type, mixed $value, string $path, bool $taxCurrency, bool $hasRawCode): mixed
    {
        try {
            return match (true) {
                $type === 'string' => is_scalar($value) ? (string) $value : throw HydrationException::at($path, 'Expected a string.'),
                $type === 'int' => is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1)
                    ? (int) $value
                    : throw HydrationException::at($path, 'Expected an integer.'),
                $type === 'bool' => is_bool($value) ? $value : throw HydrationException::at($path, 'Expected a boolean.'),
                $type === 'mixed' => $value,
                $type === BigDecimal::class => Decimal::of($this->scalar($value, $path)),
                $type === Money::class => Amount::of(
                    $this->scalar($value, $path),
                    $taxCurrency ? ($this->taxCurrency ?? $this->currency) : $this->currency,
                ),
                $type === Date::class => Date::parse((string) $this->scalar($value, $path)),
                $type === RawCode::class => null,
                is_subclass_of($type, BackedEnum::class) => $type::tryFrom((string) $this->scalar($value, $path))
                    ?? ($hasRawCode ? null : throw HydrationException::at($path, sprintf('"%s" is not a valid %s.', (string) $this->scalar($value, $path), (new ReflectionClass($type))->getShortName()))),
                class_exists($type) => is_array($value)
                    ? $this->object($type, $value, $path)
                    : throw HydrationException::at($path, 'Expected an object.'),
                default => throw HydrationException::at($path, sprintf('Unsupported type %s.', $type)),
            };
        } catch (HydrationException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw HydrationException::at($path, $e->getMessage(), $e);
        }
    }

    private function scalar(mixed $value, string $path): string|int
    {
        if (is_string($value) || is_int($value)) {
            return $value;
        }

        throw HydrationException::at($path, is_float($value)
            ? 'Floats are not accepted. Pass monetary values and quantities as strings.'
            : 'Expected a string or an integer.');
    }
}
