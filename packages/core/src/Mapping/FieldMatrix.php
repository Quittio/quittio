<?php

declare(strict_types=1);

namespace Quittio\Core\Mapping;

use Quittio\Core\Mapping\Attributes\Bt;
use Quittio\Core\Mapping\Attributes\Cardinality;
use Quittio\Core\Mapping\Attributes\Cii;
use Quittio\Core\Mapping\Attributes\Computed;
use Quittio\Core\Mapping\Attributes\ExtendedOnly;
use Quittio\Core\Mapping\Attributes\Internal;
use Quittio\Core\Mapping\Attributes\Level;
use Quittio\Core\Mapping\Attributes\PdfOnly;
use Quittio\Core\Mapping\Attributes\RequiredIn;
use Quittio\Core\Mapping\Attributes\Ubl;
use Quittio\Core\Mapping\Attributes\XRechnungExtensionOnly;
use Quittio\Core\Profiles\ProfileLevel;
use ReflectionClass;

/**
 * Field metadata of all DTOs, derived from the mapping attributes (single source for
 * profile validation, documentation and coverage reports).
 */
final class FieldMatrix
{
    /**
     * Business terms of shared structures depend on where they are used: keyed by the
     * business group of the parent field.
     *
     * @var array<string, array<string, array<string, string>>>
     */
    private const array CONTEXT_BT = [
        'Address' => [
            'BG-5' => ['countryCode' => 'BT-40', 'line1' => 'BT-35', 'line2' => 'BT-36', 'line3' => 'BT-162', 'city' => 'BT-37', 'postcode' => 'BT-38', 'subdivision' => 'BT-39'],
            'BG-8' => ['countryCode' => 'BT-55', 'line1' => 'BT-50', 'line2' => 'BT-51', 'line3' => 'BT-163', 'city' => 'BT-52', 'postcode' => 'BT-53', 'subdivision' => 'BT-54'],
            'BG-12' => ['countryCode' => 'BT-69', 'line1' => 'BT-64', 'line2' => 'BT-65', 'line3' => 'BT-164', 'city' => 'BT-66', 'postcode' => 'BT-67', 'subdivision' => 'BT-68'],
            'BG-15' => ['countryCode' => 'BT-80', 'line1' => 'BT-75', 'line2' => 'BT-76', 'line3' => 'BT-165', 'city' => 'BT-77', 'postcode' => 'BT-78', 'subdivision' => 'BT-79'],
        ],
        'Contact' => [
            'BG-6' => ['name' => 'BT-41', 'phone' => 'BT-42', 'email' => 'BT-43'],
            'BG-9' => ['name' => 'BT-56', 'phone' => 'BT-57', 'email' => 'BT-58'],
        ],
        'Period' => [
            'BG-14' => ['start' => 'BT-73', 'end' => 'BT-74'],
            'BG-26' => ['start' => 'BT-134', 'end' => 'BT-135'],
        ],
    ];

    /** @var array<class-string, array<string, FieldDefinition>> */
    private static array $cache = [];

    /**
     * @param  class-string  $class
     * @return array<string, FieldDefinition>
     */
    public static function fields(string $class): array
    {
        if (isset(self::$cache[$class])) {
            return self::$cache[$class];
        }

        $fields = [];
        $constructor = (new ReflectionClass($class))->getConstructor();
        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            $attr = static fn (string $attribute): ?object => ($parameter->getAttributes($attribute)[0] ?? null)?->newInstance();

            /** @var Bt|null $bt */
            $bt = $attr(Bt::class);
            /** @var Cardinality|null $cardinality */
            $cardinality = $attr(Cardinality::class);
            /** @var Level|null $level */
            $level = $attr(Level::class);
            /** @var RequiredIn|null $requiredIn */
            $requiredIn = $attr(RequiredIn::class);
            /** @var Cii|null $cii */
            $cii = $attr(Cii::class);
            /** @var Ubl|null $ubl */
            $ubl = $attr(Ubl::class);

            $fields[$parameter->getName()] = new FieldDefinition(
                class: $class,
                property: $parameter->getName(),
                bt: $bt?->id,
                cardinality: $cardinality?->value,
                level: $level === null ? ProfileLevel::En16931 : $level->level,
                requiredIn: $requiredIn === null ? [] : $requiredIn->profiles,
                extendedOnly: $attr(ExtendedOnly::class) !== null,
                xrechnungExtensionOnly: $attr(XRechnungExtensionOnly::class) !== null,
                computed: $attr(Computed::class) !== null,
                pdfOnly: $attr(PdfOnly::class) !== null,
                internal: $attr(Internal::class) !== null,
                cii: $cii?->path,
                ubl: $ubl?->path,
            );
        }

        return self::$cache[$class] = $fields;
    }

    /**
     * Lowest profile level that carries a field where it is used: its own level (EN 16931
     * without a Level attribute, EXTENDED for fields of EXTENDED only), raised to the level of
     * the parent field, because a part is written only together with its parent. E.g. the
     * address parts of BASIC WL inside the seller address (BG-5, MINIMUM) stay BASIC WL, the
     * country code (BT-40, MINIMUM) stays MINIMUM; inside the buyer address (BG-8, BASIC WL)
     * the country code (BT-55) is BASIC WL.
     *
     * @param  class-string  $class
     * @param  ProfileLevel|null  $parentLevel  effective level of the parent field, null at the top level
     */
    public static function level(string $class, string $property, ?ProfileLevel $parentLevel = null): ProfileLevel
    {
        $field = self::fields($class)[$property] ?? null;
        $own = match (true) {
            $field === null => ProfileLevel::En16931,
            $field->extendedOnly => ProfileLevel::Extended,
            default => $field->level,
        };

        return $parentLevel !== null && ! $own->includes($parentLevel) ? $parentLevel : $own;
    }

    /**
     * Business term of a field, resolved for shared structures (Address, Contact, Period)
     * from the business term of the parent field, e.g. BT-55 for the country code of the
     * buyer address (BG-8).
     *
     * @param  class-string  $class
     * @param  string|null  $parentTerm  business term of the parent field (e.g. BG-8), null at the top level
     */
    public static function businessTerm(string $class, string $property, ?string $parentTerm): ?string
    {
        $short = substr($class, (int) strrpos($class, '\\') + 1);
        if ($parentTerm !== null && isset(self::CONTEXT_BT[$short][$parentTerm][$property])) {
            return self::CONTEXT_BT[$short][$parentTerm][$property];
        }

        return self::fields($class)[$property]->bt ?? null;
    }
}
