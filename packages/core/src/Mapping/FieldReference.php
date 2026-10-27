<?php

declare(strict_types=1);

namespace Quittio\Core\Mapping;

use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Mapping\Attributes\ListOf;
use Quittio\Core\Profiles\ProfileLevel;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionUnionType;

/**
 * Generates docs/field-reference.md from the mapping attributes of the data model.
 */
final class FieldReference
{
    /** @var array<string, true> class@path combinations already documented */
    private array $seen = [];

    /**
     * The Markdown of docs/field-reference.md: one table row per field path of InvoiceData with
     * business term, cardinality, lowest profile level, CII and UBL path and notes.
     */
    public function markdown(): string
    {
        $this->seen = [];
        $rows = [];
        $this->walk(InvoiceData::class, '', null, null, $rows);

        $lines = [
            '# Field reference',
            '',
            'Generated from the mapping attributes of the data model (`php tools/docs/generate.php`). Do not edit by hand.',
            '',
            'Paths use the property names of `InvoiceData`; `[]` marks lists. "Level" is the lowest Factur-X/ZUGFeRD profile that carries the field at this path (a part is never written without its parent, e.g. the buyer address starts with BASIC WL).',
            '',
            '| Path | BT | Card. | Level | CII | UBL | Notes |',
            '|---|---|---|---|---|---|---|',
        ];
        foreach ($rows as $row) {
            $lines[] = '| '.implode(' | ', array_map(static fn (string $cell): string => str_replace('|', '\\|', $cell), $row)).' |';
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  class-string  $class
     * @param  list<list<string>>  $rows
     */
    private function walk(string $class, string $prefix, ?string $parentTerm, ?ProfileLevel $parentLevel, array &$rows): void
    {
        foreach (FieldMatrix::fields($class) as $name => $field) {
            $path = $prefix.$name;
            $level = FieldMatrix::level($class, $name, $parentLevel);
            $bt = FieldMatrix::businessTerm($class, $name, $parentTerm);
            $notes = array_filter([
                $field->computed ? 'calculated' : null,
                $field->pdfOnly ? 'PDF only' : null,
                $field->internal ? 'internal' : null,
                $field->extendedOnly ? 'EXTENDED only' : null,
                $field->xrechnungExtensionOnly ? 'XRechnung extension only' : null,
                $field->requiredIn === [] ? null : 'required in '.implode(', ', $field->requiredIn),
            ], static fn (?string $note): bool => $note !== null);

            $child = $this->childClass($class, $name);
            $isList = $this->isList($class, $name);
            $rows[] = [
                '`'.$path.($isList ? '[]' : '').'`',
                $bt ?? '',
                $field->cardinality ?? '',
                $field->bt === null ? '' : $level->label(),
                $field->cii === null ? '' : '`'.$field->cii.'`',
                $field->ubl === null ? '' : '`'.$field->ubl.'`',
                implode(', ', $notes),
            ];

            if ($child !== null && ! isset($this->seen[$child.'@'.$path])) {
                $this->seen[$child.'@'.$path] = true;
                $this->walk($child, $path.($isList ? '[].' : '.'), $bt, $level, $rows);
            }
        }
    }

    /**
     * @param  class-string  $class
     * @return class-string|null
     */
    private function childClass(string $class, string $property): ?string
    {
        foreach ((new ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $parameter) {
            if ($parameter->getName() !== $property) {
                continue;
            }
            $listOf = ($parameter->getAttributes(ListOf::class)[0] ?? null)?->newInstance();
            if ($listOf !== null) {
                // Self references (sub lines) are documented once.
                return class_exists($listOf->type) && $listOf->type !== $class && ! enum_exists($listOf->type) && FieldMatrix::fields($listOf->type) !== [] ? $listOf->type : null;
            }
            $type = $parameter->getType();
            $types = $type instanceof ReflectionUnionType ? $type->getTypes() : [$type];
            foreach ($types as $t) {
                if ($t instanceof ReflectionNamedType && ! $t->isBuiltin() && str_starts_with($t->getName(), 'Quittio\\Core\\Data\\') && class_exists($t->getName())) {
                    // Self references (sub lines) are documented once.
                    return $t->getName() === $class ? null : $t->getName();
                }
            }
        }

        return null;
    }

    /**
     * @param  class-string  $class
     */
    private function isList(string $class, string $property): bool
    {
        foreach ((new ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $parameter) {
            if ($parameter->getName() === $property) {
                $type = $parameter->getType();

                return $type instanceof ReflectionNamedType && $type->getName() === 'array';
            }
        }

        return false;
    }
}
