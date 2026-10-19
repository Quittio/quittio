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
    $root = dirname(__DIR__, 2);
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

/**
 * Blocks that mix a fragment with complete code (a config excerpt followed by a call, a trait
 * conflict resolution followed by a call) and parse in no single wrapping; found by a snippet.
 */
const DOCS_MIXED_BLOCKS = [
    'laravel.md' => ["'templates' => ['invoices.*', 'mytheme::*']"],
    'managed.md' => ['class TeamIssuerResolver', 'HasInvoices::invoices as quittioInvoices', 'class ErpNumbers'],
];

/**
 * Ways to read a block: as a file, as a configuration excerpt (array items), as class members
 * and as the continuation of a builder chain (lines that start with "->").
 *
 * @return list<string>
 */
function docsParseCandidates(string $code): array
{
    $code = (string) preg_replace('/^<\?php\s*/', '', $code);
    preg_match('/\A((?:\s*(?:use [^;{]+;|\/\/[^\n]*)\n)*)(.*)\z/s', $code, $parts);
    [$imports, $body] = [$parts[1] ?? '', $parts[2] ?? $code];
    $chain = (string) preg_replace('/^(\s*)->/m', '$1$builder->', $body, 1);

    return [
        "<?php\n".$code,
        "<?php\n".$imports."\$fragment = [\n".$body."\n];",
        "<?php\n".$imports."final class Fragment\n{\n".$body."\n}",
        "<?php\n".$imports.rtrim($chain)."\n;",
    ];
}

it('has only PHP code blocks that parse', function () {
    $invalid = [];
    foreach (docsCodeBlocks() as $block) {
        $mixed = array_filter(DOCS_MIXED_BLOCKS[$block['file']] ?? [], static fn (string $snippet): bool => str_contains($block['code'], $snippet));
        if ($mixed !== []) {
            continue;
        }
        $parses = array_filter(docsParseCandidates($block['code']), static function (string $source): bool {
            try {
                token_get_all($source, TOKEN_PARSE);

                return true;
            } catch (CompileError) {   // ParseError, and on PHP 8.3 e.g. a final property
                return false;
            }
        });
        if ($parses === []) {
            $invalid[] = "{$block['file']}:{$block['line']}";
        }
    }

    expect($invalid)->toBe([]);
});

it('keeps the list of mixed blocks current', function () {
    $code = implode("\n", array_column(docsCodeBlocks(), 'code'));
    foreach (DOCS_MIXED_BLOCKS as $file => $snippets) {
        foreach ($snippets as $snippet) {
            expect(str_contains($code, $snippet))->toBeTrue("{$file}: {$snippet}");
        }
    }
});
