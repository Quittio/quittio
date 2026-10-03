<?php

declare(strict_types=1);

use Quittio\Core\Mapping\Attributes\Bt;
use Quittio\Core\Mapping\Attributes\ExtendedOnly;
use Quittio\Core\Mapping\Attributes\InputFor;
use Quittio\Core\Mapping\Attributes\Internal;
use Quittio\Core\Mapping\Attributes\ListOf;
use Quittio\Core\Mapping\Attributes\PdfOnly;
use Quittio\Core\Mapping\Attributes\XRechnungExtensionOnly;

/**
 * @return list<class-string>
 */
function dataClasses(): array
{
    $root = dirname(__DIR__, 3).'/src/Data';
    $classes = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php' || str_contains($file->getPathname(), '/Concerns/')) {
            continue;
        }
        $relative = substr($file->getPathname(), strlen($root) + 1, -4);
        $classes[] = 'Quittio\\Core\\Data\\'.str_replace('/', '\\', $relative);
    }
    sort($classes);

    return $classes;
}

it('annotates every field of classes that carry business terms', function () {
    $markers = [Bt::class, Internal::class, PdfOnly::class, ExtendedOnly::class, XRechnungExtensionOnly::class, InputFor::class];

    foreach (dataClasses() as $class) {
        $reflection = new ReflectionClass($class);
        $constructor = $reflection->getConstructor();
        if ($reflection->isAbstract() || $constructor === null) {
            continue;
        }

        $parameters = $constructor->getParameters();
        $hasBt = array_filter($parameters, fn ($p) => $p->getAttributes(Bt::class) !== []);
        if ($hasBt === []) {
            continue;
        }

        foreach ($parameters as $parameter) {
            $found = array_filter($markers, fn ($m) => $parameter->getAttributes($m) !== []);
            expect($found)->not->toBeEmpty("{$class}::\${$parameter->getName()} has no mapping attribute");
        }
    }
});

it('declares the element type of every array field', function () {
    foreach (dataClasses() as $class) {
        $constructor = (new ReflectionClass($class))->getConstructor();
        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof ReflectionNamedType && $type->getName() === 'array'
                && ! in_array($parameter->getName(), ['custom', 'labels', 'footerColumns'], true)) {
                expect($parameter->getAttributes(ListOf::class))->not->toBeEmpty("{$class}::\${$parameter->getName()} has no #[ListOf]");
            }
        }
    }
});

it('uses each business term identifier only once', function () {
    $seen = [];
    foreach (dataClasses() as $class) {
        $constructor = (new ReflectionClass($class))->getConstructor();
        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            foreach ($parameter->getAttributes(Bt::class) as $attribute) {
                $id = $attribute->newInstance()->id;
                expect($seen)->not->toHaveKey($id, "{$id} is used by {$class} and ".($seen[$id] ?? ''));
                $seen[$id] = $class;
            }
        }
    }
});
