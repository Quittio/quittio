<?php

declare(strict_types=1);

// Code blocks of the documentation that import classes import all classes they use, so that a
// copied example runs. Classes of the application (InvoiceMail, Order …) are left out.

const DOCS_APPLICATION_CLASSES = [
    'Model', 'Authenticatable', 'Mailable', 'Envelope', 'Content', 'Attachment', 'Mail', 'Storage', 'Route', 'Schema',
    'Event', 'Queue', 'Log', 'Relation', 'TestCase', 'InvoiceMail', 'YourInvoiceMail', 'Order', 'OrderItem', 'Customer',
    'User', 'CountryAT', 'ConsumerPdfRule', 'GotenbergClient', 'MyFormatReader', 'InvalidArgumentException', 'Throwable',
    'Exception', 'DateTimeImmutable', 'Closure',
];

/**
 * @return list<array{file: string, line: int, code: string}>
 */
function docsCodeBlocks(): array
{
    $root = dirname(__DIR__, 4);
    $blocks = [];
    foreach ([...glob($root.'/docs/*.md') ?: [], $root.'/README.md'] as $file) {
        $content = (string) file_get_contents($file);
        preg_match_all('/^```php\n(.*?)^```$/ms', $content, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[1] as [$code, $offset]) {
            $blocks[] = ['file' => basename($file), 'line' => substr_count(substr($content, 0, $offset), "\n") + 1, 'code' => $code];
        }
    }

    return $blocks;
}

it('imports every class a documentation example uses', function () {
    if (! is_dir(dirname(__DIR__, 4).'/docs')) {
        $this->markTestSkipped('The documentation exists only in the monorepo.');
    }

    $missing = [];
    foreach (docsCodeBlocks() as $block) {
        preg_match_all('/^use ([\w\\\\]+)(?: as (\w+))?;$/m', $block['code'], $uses, PREG_SET_ORDER);
        preg_match_all('/^use [\w\\\\]+\\\\\{([^}]*)\};$/m', $block['code'], $groups);
        if ($uses === [] && $groups[1] === []) {
            continue;                                    // a fragment of an earlier example
        }
        $imported = array_map(static fn (array $use): string => $use[2] ?? substr((string) strrchr('\\'.$use[1], '\\'), 1), $uses);
        foreach ($groups[1] as $group) {
            $imported = [...$imported, ...array_map('trim', explode(',', $group))];
        }
        preg_match_all('/\b(?:class|interface|trait|enum) (\w+)/', $block['code'], $defined);
        $code = (string) preg_replace(['/^use .*$/m', '/\'[^\']*\'|"[^"]*"/', '#//.*$#m'], ['', "''", ''], $block['code']);
        preg_match_all('/(?<![\\\\\w$>:])([A-Z]\w+)::|\bnew ([A-Z]\w+)|\bfn \(([A-Z]\w+) \$|\b(?:extends|implements) ([A-Z]\w+)/', $code, $references, PREG_SET_ORDER);
        foreach ($references as $reference) {
            $class = array_values(array_filter(array_slice($reference, 1)))[0];
            if (! in_array($class, [...$imported, ...$defined[1], ...DOCS_APPLICATION_CLASSES, 'self', 'static', 'parent'], true)) {
                $missing["{$block['file']}:{$block['line']} {$class}"] = true;
            }
        }
    }

    expect(array_keys($missing))->toBe([]);
});
