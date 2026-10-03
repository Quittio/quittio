<?php

declare(strict_types=1);

namespace Quittio\Core\Serialization;

use BackedEnum;
use Brick\Math\BigDecimal;
use Brick\Money\Money;
use Quittio\Core\Codes\RawCode;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Mapping\Attributes\Binary;
use Quittio\Core\Mapping\Attributes\ListOf;
use Quittio\Core\Mapping\FieldMatrix;
use Quittio\Core\Support\Date;
use ReflectionClass;
use ReflectionEnum;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;

/**
 * Generates the JSON Schema (draft 2020-12) of InvoiceData::toArray() from the constructor
 * signatures and mapping attributes, so that the schema never drifts from the model.
 */
final class JsonSchemaGenerator
{
    public const string SCHEMA_ID = 'https://quittio.dev/schema/invoice.schema.json';

    private const string DECIMAL_PATTERN = '^-?[0-9]+(\.[0-9]+)?$';

    /** @var array<string, array<string, mixed>> */
    private array $definitions = [];

    public static function defaultPath(): string
    {
        return dirname(__DIR__, 2).'/resources/schema/invoice.schema.json';
    }

    /**
     * @return array<string, mixed>
     */
    public function generate(): array
    {
        $this->definitions = [];
        $root = $this->define(InvoiceData::class);
        ksort($this->definitions);

        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            '$id' => self::SCHEMA_ID,
            'title' => 'Quittio invoice data',
            'description' => 'InvoiceData::toArray() / toJson(). Amounts and decimals are strings; dates use ISO 8601 (YYYY-MM-DD). Values equal to their default may be omitted.',
            '$ref' => '#/$defs/'.$root,
            '$defs' => $this->definitions,
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->generate(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    }

    /**
     * @param  class-string  $class
     */
    private function define(string $class): string
    {
        $name = (new ReflectionClass($class))->getShortName();
        if (isset($this->definitions[$name])) {
            return $name;
        }
        $this->definitions[$name] = [];

        $properties = [];
        $required = [];
        $fields = FieldMatrix::fields($class);
        foreach ((new ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $parameter) {
            $schema = $this->parameter($parameter);
            $field = $fields[$parameter->getName()] ?? null;
            $notes = array_filter([
                $field?->bt,
                $field?->cardinality === null ? null : 'cardinality '.$field->cardinality,
                $field !== null && $field->computed ? 'calculated' : null,
                $field !== null && $field->pdfOnly ? 'PDF only' : null,
                $field !== null && $field->internal ? 'internal' : null,
                $field !== null && $field->extendedOnly ? 'EXTENDED only' : null,
            ], static fn (?string $note): bool => $note !== null);
            if ($notes !== []) {
                $schema['description'] = implode(', ', $notes);
            }

            $properties[$parameter->getName()] = $schema;
            if (! $parameter->isDefaultValueAvailable()) {
                $required[] = $parameter->getName();
            }
        }

        $definition = ['type' => 'object', 'properties' => $properties, 'additionalProperties' => false];
        if ($required !== []) {
            $definition['required'] = $required;
        }
        $this->definitions[$name] = $definition;

        return $name;
    }

    /**
     * @return array<string, mixed>
     */
    private function parameter(ReflectionParameter $parameter): array
    {
        $type = $parameter->getType();
        $names = match (true) {
            $type instanceof ReflectionNamedType => [$type->getName()],
            $type instanceof ReflectionUnionType => array_map(static fn ($t): string => $t instanceof ReflectionNamedType ? $t->getName() : 'mixed', $type->getTypes()),
            default => ['mixed'],
        };
        $nullable = $type?->allowsNull() ?? true;
        $names = array_values(array_filter($names, static fn (string $n): bool => $n !== 'null'));

        $listOf = ($parameter->getAttributes(ListOf::class)[0] ?? null)?->newInstance();
        $schema = $parameter->getAttributes(Binary::class) !== []
            ? ['type' => 'string', 'contentEncoding' => 'base64']
            : $this->types($names, $listOf);

        return $nullable ? ['anyOf' => [$schema, ['type' => 'null']]] : $schema;
    }

    /**
     * @param  list<string>  $names
     * @return array<string, mixed>
     */
    private function types(array $names, ?ListOf $listOf): array
    {
        // Codes: enum values are documented, unknown codes (RawCode) remain allowed.
        $enums = [];
        foreach ($names as $name) {
            if (is_subclass_of($name, BackedEnum::class)) {
                $enums[] = $name;
            }
        }
        if ($enums !== [] || in_array(RawCode::class, $names, true)) {
            $schema = ['type' => 'string'];
            if ($enums !== [] && ! in_array(RawCode::class, $names, true)) {
                $schema['enum'] = $this->enumValues($enums[0]);
            } elseif ($enums !== []) {
                $schema['examples'] = array_slice($this->enumValues($enums[0]), 0, 10);
            }

            return $schema;
        }

        $schemas = [];
        foreach ($names as $name) {
            $schemas[] = $this->single($name, $listOf);
        }

        return count($schemas) === 1 ? $schemas[0] : ['anyOf' => $schemas];
    }

    /**
     * @return array<string, mixed>
     */
    private function single(string $name, ?ListOf $listOf): array
    {
        return match (true) {
            $name === 'string' => ['type' => 'string'],
            $name === 'int' => ['type' => 'integer'],
            $name === 'float' => ['type' => 'number'],
            $name === 'bool' => ['type' => 'boolean'],
            $name === Money::class => ['type' => 'string', 'pattern' => self::DECIMAL_PATTERN],
            $name === BigDecimal::class => ['type' => 'string', 'pattern' => self::DECIMAL_PATTERN],
            $name === Date::class => ['type' => 'string', 'format' => 'date'],
            $name === 'array' => $listOf === null ? ['type' => ['array', 'object']] : ['type' => 'array', 'items' => $this->single($listOf->type, null)],
            class_exists($name) => ['$ref' => '#/$defs/'.$this->define($name)],
            default => [],
        };
    }

    /**
     * @param  class-string<BackedEnum>  $enum
     * @return list<string>
     */
    private function enumValues(string $enum): array
    {
        $values = [];
        foreach ((new ReflectionEnum($enum))->getCases() as $case) {
            $value = $case->getValue();
            if ($value instanceof BackedEnum) {
                $values[] = (string) $value->value;
            }
        }

        return $values;
    }
}
