<?php

declare(strict_types=1);

/*
 * The public surface of the three packages as a snapshot: every class, interface, trait and
 * enum that is not marked @internal, with its public (and, where it can be extended, protected)
 * methods, properties, constants and enum cases. A refactor must leave it unchanged; a deliberate
 * addition or deprecation is accepted with --update-snapshots and shows up in the diff.
 *
 * Every public method of that surface has a doc comment, unless it implements or overrides a
 * documented method. The methods listed in undocumented-methods.txt are the remaining exceptions;
 * the list may only shrink.
 */

const PUBLIC_API_SOURCES = [
    'Quittio\\Core\\' => 'packages/core/src',
    'Quittio\\Laravel\\' => 'packages/laravel/src/Laravel',
    'Quittio\\Country\\DE\\' => 'packages/country-de/src',
];

/**
 * @return list<class-string>
 */
function publicApiClasses(): array
{
    $root = dirname(__DIR__, 2);
    $classes = [];
    foreach (PUBLIC_API_SOURCES as $prefix => $directory) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($root.'/'.$directory) + 1, -4);
            $class = $prefix.str_replace('/', '\\', $relative);
            if (class_exists($class) || interface_exists($class) || trait_exists($class) || enum_exists($class)) {
                $classes[] = $class;
            }
        }
    }
    sort($classes);

    return $classes;
}

function publicApiIsInternal(ReflectionClass|ReflectionMethod|ReflectionProperty|ReflectionClassConstant $reflection): bool
{
    return str_contains((string) $reflection->getDocComment(), '@internal');
}

function publicApiOwned(string $class): bool
{
    foreach (array_keys(PUBLIC_API_SOURCES) as $prefix) {
        if (str_starts_with($class, $prefix)) {
            return true;
        }
    }

    return false;
}

function publicApiDefault(ReflectionParameter $parameter): string
{
    if (! $parameter->isDefaultValueAvailable()) {
        return '';
    }
    if ($parameter->isDefaultValueConstant()) {
        return ' = '.$parameter->getDefaultValueConstantName();
    }
    $value = $parameter->getDefaultValue();

    return ' = '.match (true) {
        $value instanceof UnitEnum => $value::class.'::'.$value->name,
        is_object($value) => 'new '.$value::class,
        default => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    };
}

function publicApiMethod(ReflectionMethod $method): string
{
    $parameters = array_map(
        fn (ReflectionParameter $p) => trim(($p->getType() ?? '').' '.($p->isPassedByReference() ? '&' : '').($p->isVariadic() ? '...' : '').'$'.$p->getName().publicApiDefault($p)),
        $method->getParameters(),
    );
    $modifiers = implode(' ', Reflection::getModifierNames($method->getModifiers()));
    $return = $method->hasReturnType() ? ': '.$method->getReturnType() : '';

    return $modifiers.' '.$method->getName().'('.implode(', ', $parameters).')'.$return;
}

/**
 * @param  class-string  $class
 * @return array<string, mixed>
 */
function publicApiOf(string $class): array
{
    $reflection = new ReflectionClass($class);
    $extensible = ! $reflection->isFinal() && ! $reflection->isEnum();
    $kind = match (true) {
        $reflection->isEnum() => 'enum',
        $reflection->isInterface() => 'interface',
        $reflection->isTrait() => 'trait',
        default => trim(implode(' ', Reflection::getModifierNames($reflection->getModifiers())).' class'),
    };

    $methods = [];
    foreach ($reflection->getMethods() as $method) {
        $visible = $method->isPublic() || ($extensible && $method->isProtected());
        if (! $visible || publicApiIsInternal($method) || ! publicApiOwned($method->getDeclaringClass()->getName())) {
            continue;
        }
        $methods[] = publicApiMethod($method);
    }
    sort($methods);

    $properties = [];
    foreach ($reflection->getProperties() as $property) {
        $visible = $property->isPublic() || ($extensible && $property->isProtected());
        if (! $visible || publicApiIsInternal($property) || ! publicApiOwned($property->getDeclaringClass()->getName())) {
            continue;
        }
        $properties[] = implode(' ', array_filter([
            ...Reflection::getModifierNames($property->getModifiers()),
            (string) $property->getType(),
            '$'.$property->getName(),
        ]));
    }
    sort($properties);

    $constants = [];
    $cases = [];
    foreach ($reflection->getReflectionConstants() as $constant) {
        if ($constant->isEnumCase()) {
            $value = $constant->getValue();
            $cases[] = $constant->getName().($value instanceof BackedEnum ? ' = '.json_encode($value->value) : '');

            continue;
        }
        $visible = $constant->isPublic() || ($extensible && $constant->isProtected());
        if (! $visible || publicApiIsInternal($constant) || ! publicApiOwned($constant->getDeclaringClass()->getName())) {
            continue;
        }
        $constants[] = $constant->getName();
    }
    sort($constants);

    $parent = $reflection->getParentClass();
    $interfaces = $reflection->getInterfaceNames();
    sort($interfaces);

    return array_filter([
        'kind' => $kind,
        'extends' => $parent !== false ? $parent->getName() : null,
        'implements' => $interfaces,
        'cases' => $cases,
        'constants' => $constants,
        'properties' => $properties,
        'methods' => $methods,
    ], fn (mixed $value) => $value !== null && $value !== []);
}

it('keeps the public API surface', function () {
    $api = [];
    foreach (publicApiClasses() as $class) {
        if (! publicApiIsInternal(new ReflectionClass($class))) {
            $api[$class] = publicApiOf($class);
        }
    }

    expect(json_encode($api, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n")->toMatchSnapshot();
});

/**
 * @return list<string>  "Class::method" of public methods without a doc comment
 */
function publicApiUndocumented(): array
{
    $missing = [];
    foreach (publicApiClasses() as $class) {
        $reflection = new ReflectionClass($class);
        if (publicApiIsInternal($reflection)) {
            continue;
        }
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isInternal() || str_starts_with($method->getName(), '__') || $method->getDeclaringClass()->getName() !== $class
                || $method->getDocComment() !== false || publicApiIsInternal($method)) {
                continue;
            }
            try {
                $documented = $method->getPrototype()->getDocComment() !== false;
            } catch (ReflectionException) {
                $documented = false;
            }
            if (! $documented) {
                $missing[] = $class.'::'.$method->getName();
            }
        }
    }
    sort($missing);

    return $missing;
}

it('documents every public method', function () {
    $baseline = array_values(array_filter(array_map('trim', file(__DIR__.'/undocumented-methods.txt') ?: [])));
    $missing = publicApiUndocumented();

    expect(array_values(array_diff($missing, $baseline)))->toBe([], 'Add a doc comment to these new public methods.')
        ->and(array_values(array_diff($baseline, $missing)))->toBe([], 'Remove these documented or deleted methods from undocumented-methods.txt.');
});
