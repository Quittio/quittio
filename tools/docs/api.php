<?php

declare(strict_types=1);

/**
 * Writes the API reference of the public classes as JSON for the documentation on quittio.dev
 * (the website renders it under /docs/api). The sources are parsed, not loaded, so the script
 * also reads the packages of an older release (git archive of a tag) without installing them.
 * Classes and members marked @internal and private members are left out.
 *
 * Usage: php tools/docs/api.php [--packages <dir>] [--version <name>] [--output <file>]
 *   --packages  directory with the packages (default: packages/ of this checkout)
 *   --version   name stored in the file, e.g. v0.7.1 or next (default: next)
 *   --output    target file (default: standard output)
 */

use PhpParser\Comment\Doc;
use PhpParser\Modifiers;
use PhpParser\NameContext;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

// The parser comes from Composer; the collector below extends one of its classes.
$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';

/**
 * Collects the public API of the classes, interfaces, traits and enums of one file.
 *
 * @phpstan-type Text array{summary: string, description: string, deprecated: ?string, see: list<string>}
 * @phpstan-type Tagged array{type: string, description: string}
 * @phpstan-type DocBlock array{summary: string, description: string, internal: bool, deprecated: ?string, see: list<string>, var: ?string, return: ?Tagged, params: array<string, array{type: ?string, description: string}>, throws: list<Tagged>}
 * @phpstan-type Property array{name: string, visibility: string, static: bool, readonly: bool, type: ?string, default: ?string, summary: string, description: string, deprecated: ?string, see: list<string>}
 */
final class ApiCollector extends NodeVisitorAbstract
{
    /** @var list<array{name: string}&array<string, mixed>> */
    public array $classes = [];

    private const BUILTIN = ['int', 'integer', 'float', 'double', 'string', 'bool', 'boolean', 'true', 'false', 'null', 'void', 'never',
        'mixed', 'object', 'array', 'iterable', 'callable', 'resource', 'self', 'static', 'parent', 'list', 'non-empty-list',
        'non-empty-array', 'non-empty-string', 'non-falsy-string', 'numeric-string', 'class-string', 'positive-int', 'negative-int',
        'non-negative-int', 'non-positive-int', 'int-mask', 'int-mask-of', 'key-of', 'value-of', 'array-key', 'scalar', 'numeric',
        'callable-string', 'lowercase-string', 'literal-string', 'closed-resource', 'max', 'min'];

    public function __construct(
        private readonly NameContext $context,
        private readonly Standard $printer,
        private readonly string $package,
        private readonly string $path,
    ) {}

    // On leaving, so that the name resolver has already resolved the names inside the class.
    public function leaveNode(Node $node): null
    {
        // Anonymous classes have no name and are no API.
        if (! $node instanceof Node\Stmt\ClassLike || $node->namespacedName === null) {
            return null;
        }
        $doc = $this->doc($node->getDocComment());
        if ($doc['internal']) {
            return null;
        }

        $modifiers = $node instanceof Node\Stmt\Class_ ? $this->classModifiers($node) : [];
        $readonly = in_array('readonly', $modifiers, true);
        $traits = [];
        $constants = [];
        $cases = [];
        /** @var list<Property> $properties */
        $properties = [];
        $methods = [];

        foreach ($node->stmts as $statement) {
            if ($statement instanceof Node\Stmt\TraitUse) {
                foreach ($statement->traits as $trait) {
                    $traits[] = $trait->toString();
                }
            } elseif ($statement instanceof Node\Stmt\ClassConst) {
                $member = $this->doc($statement->getDocComment());
                if ($statement->isPrivate() || $member['internal']) {
                    continue;
                }
                foreach ($statement->consts as $constant) {
                    $constants[] = [
                        'name' => $constant->name->toString(),
                        'visibility' => $statement->isProtected() ? 'protected' : 'public',
                        'final' => $statement->isFinal(),
                        'type' => $statement->type !== null ? $this->type($statement->type) : $this->docType($member['var']),
                        'value' => $this->printer->prettyPrintExpr($constant->value),
                        ...$this->text($member),
                    ];
                }
            } elseif ($statement instanceof Node\Stmt\EnumCase) {
                $cases[] = [
                    'name' => $statement->name->toString(),
                    'value' => $statement->expr !== null ? $this->printer->prettyPrintExpr($statement->expr) : null,
                    ...$this->text($this->doc($statement->getDocComment())),
                ];
            } elseif ($statement instanceof Node\Stmt\Property) {
                $member = $this->doc($statement->getDocComment());
                if ($statement->isPrivate() || $member['internal']) {
                    continue;
                }
                foreach ($statement->props as $property) {
                    $properties[] = [
                        'name' => $property->name->toString(),
                        'visibility' => $statement->isProtected() ? 'protected' : 'public',
                        'static' => $statement->isStatic(),
                        'readonly' => $statement->isReadonly() || $readonly,
                        'type' => $this->docType($member['var']) ?? ($statement->type !== null ? $this->type($statement->type) : null),
                        'default' => $property->default !== null ? $this->printer->prettyPrintExpr($property->default) : null,
                        ...$this->text($member),
                    ];
                }
            } elseif ($statement instanceof Node\Stmt\ClassMethod) {
                $member = $this->doc($statement->getDocComment());
                if ($statement->isPrivate() || $member['internal']) {
                    continue;
                }
                $methods[] = $this->method($statement, $member);
                // Promoted constructor parameters are properties as well.
                foreach ($statement->params as $param) {
                    if ($param->flags === 0 || ($param->flags & Modifiers::PRIVATE) !== 0) {
                        continue;
                    }
                    $name = $this->paramName($param);
                    $properties[] = [
                        'name' => $name,
                        'visibility' => ($param->flags & Modifiers::PROTECTED) !== 0 ? 'protected' : 'public',
                        'static' => false,
                        'readonly' => ($param->flags & Modifiers::READONLY) !== 0 || $readonly,
                        'type' => $this->docType($member['params'][$name]['type'] ?? null) ?? ($param->type !== null ? $this->type($param->type) : null),
                        'default' => null,
                        'summary' => $member['params'][$name]['description'] ?? '',
                        'description' => '',
                        'deprecated' => null,
                        'see' => [],
                    ];
                }
            }
        }

        $this->classes[] = [
            'name' => $node->namespacedName->toString(),
            'kind' => match (true) {
                $node instanceof Node\Stmt\Interface_ => 'interface',
                $node instanceof Node\Stmt\Trait_ => 'trait',
                $node instanceof Node\Stmt\Enum_ => 'enum',
                default => 'class',
            },
            'package' => $this->package,
            'file' => $this->path,
            'line' => $node->getStartLine(),
            'modifiers' => $modifiers,
            'extends' => $node instanceof Node\Stmt\Class_ && $node->extends !== null ? $node->extends->toString() : null,
            'implements' => array_map(fn (Node\Name $n): string => $n->toString(), match (true) {
                $node instanceof Node\Stmt\Class_, $node instanceof Node\Stmt\Enum_ => $node->implements,
                $node instanceof Node\Stmt\Interface_ => $node->extends,
                default => [],
            }),
            'traits' => $traits,
            'backing' => $node instanceof Node\Stmt\Enum_ && $node->scalarType !== null ? $this->type($node->scalarType) : null,
            ...$this->text($doc),
            'constants' => $constants,
            'cases' => $cases,
            'properties' => $properties,
            'methods' => $methods,
        ];

        return null;
    }

    /**
     * @param  DocBlock  $doc
     * @return array<string, mixed>
     */
    private function method(Node\Stmt\ClassMethod $method, array $doc): array
    {
        $params = [];
        foreach ($method->params as $param) {
            $name = $this->paramName($param);
            $params[] = [
                'name' => $name,
                'type' => $this->docType($doc['params'][$name]['type'] ?? null) ?? ($param->type !== null ? $this->type($param->type) : null),
                'default' => $param->default !== null ? $this->printer->prettyPrintExpr($param->default) : null,
                'variadic' => $param->variadic,
                'byRef' => $param->byRef,
                'description' => $doc['params'][$name]['description'] ?? '',
            ];
        }

        return [
            'name' => $method->name->toString(),
            'visibility' => $method->isProtected() ? 'protected' : 'public',
            'static' => $method->isStatic(),
            'abstract' => $method->isAbstract(),
            'final' => $method->isFinal(),
            'line' => $method->getStartLine(),
            'params' => $params,
            'returns' => [
                'type' => $this->docType($doc['return']['type'] ?? null) ?? ($method->returnType !== null ? $this->type($method->returnType) : null),
                'description' => $doc['return']['description'] ?? '',
            ],
            'throws' => array_map(fn (array $t): array => ['type' => $this->docType($t['type']) ?? $t['type'], 'description' => $t['description']], $doc['throws']),
            ...$this->text($doc),
        ];
    }

    private function paramName(Node\Param $param): string
    {
        return $param->var instanceof Node\Expr\Variable && is_string($param->var->name) ? $param->var->name : '?';
    }

    /**
     * @return list<string>
     */
    private function classModifiers(Node\Stmt\Class_ $class): array
    {
        return array_values(array_filter([
            $class->isAbstract() ? 'abstract' : null,
            $class->isFinal() ? 'final' : null,
            $class->isReadonly() ? 'readonly' : null,
        ]));
    }

    private function type(Node $type): string
    {
        return ltrim(str_replace(['|\\', '&\\', '?\\', '(\\'], ['|', '&', '?', '('], $this->printer->prettyPrint([$type])), '\\');
    }

    /**
     * A PHPDoc type with the class names resolved against the imports of the file.
     */
    private function docType(?string $type): ?string
    {
        if ($type === null || trim($type) === '') {
            return null;
        }

        return (string) preg_replace_callback('/(?<![\w\\\\$-])\\\\?[A-Za-z_][\w\\\\]*(?![\w\\\\(:-])/', function (array $match): string {
            $name = $match[0];
            if (str_starts_with($name, '\\')) {
                return substr($name, 1);
            }
            if (in_array(strtolower($name), self::BUILTIN, true)) {
                return $name;
            }

            return $this->context->getResolvedClassName(new Node\Name($name))->toString();
        }, trim($type));
    }

    /**
     * The parts of a doc comment the reference shows.
     *
     * @return DocBlock
     */
    private function doc(?Doc $comment): array
    {
        $doc = ['summary' => '', 'description' => '', 'internal' => false, 'deprecated' => null, 'see' => [], 'var' => null, 'return' => null, 'params' => [], 'throws' => []];
        if ($comment === null) {
            return $doc;
        }

        $body = (string) preg_replace(['#^/\*\*#', '#\*/$#'], '', trim($comment->getText()));
        $lines = array_map(fn (string $line): string => (string) preg_replace('/^\s*\* ?/', '', $line), explode("\n", str_replace("\r\n", "\n", $body)));

        // The text before the first tag, then one entry per tag with its continuation lines.
        $text = [];
        $tags = [];
        $fence = false;
        foreach ($lines as $line) {
            if (str_starts_with(ltrim($line), '```')) {
                $fence = ! $fence;
            }
            if (! $fence && preg_match('/^@([\w-]+)\s*(.*)$/', trim($line), $match) === 1) {
                $tags[] = [$match[1], $match[2]];
            } elseif ($tags !== []) {
                $tags[array_key_last($tags)][1] .= "\n".$line;
            } else {
                $text[] = $line;
            }
        }

        $parts = explode("\n\n", (string) preg_replace('/\n[ \t]+\n/', "\n\n", trim(implode("\n", $text))), 2);
        $doc['summary'] = $this->flatten($parts[0]);
        $doc['description'] = trim($parts[1] ?? '');

        foreach ($tags as [$tag, $value]) {
            $value = trim($value);
            [$type, $rest] = $this->splitType($value);
            switch ($tag) {
                case 'internal':
                    $doc['internal'] = true;
                    break;
                case 'deprecated':
                    $doc['deprecated'] = $value;
                    break;
                case 'see':
                    $doc['see'][] = $value;
                    break;
                case 'var':
                    $doc['var'] = $type;
                    break;
                case 'return':
                    $doc['return'] = ['type' => $type, 'description' => $this->flatten($rest)];
                    break;
                case 'throws':
                    $doc['throws'][] = ['type' => $type, 'description' => $this->flatten($rest)];
                    break;
                case 'param':
                    if (preg_match('/^(?:\.\.\.)?&?\$(\w+)\s*(.*)$/s', $rest, $match) === 1) {
                        $doc['params'][$match[1]] = ['type' => $type === '' ? null : $type, 'description' => $this->flatten($match[2])];
                    } elseif (preg_match('/^(?:\.\.\.)?&?\$(\w+)\s*(.*)$/s', $value, $match) === 1) {
                        // "@param $name description" without a type
                        $doc['params'][$match[1]] = ['type' => null, 'description' => $this->flatten($match[2])];
                    }
                    break;
            }
        }

        return $doc;
    }

    /**
     * A PHPDoc type at the start of a tag body, which may contain spaces inside <…>, {…} and (…).
     *
     * @return array{0: string, 1: string}
     */
    private function splitType(string $body): array
    {
        $depth = 0;
        $length = strlen($body);
        for ($i = 0; $i < $length; $i++) {
            $char = $body[$i];
            if (in_array($char, ['<', '{', '('], true)) {
                $depth++;
            } elseif (in_array($char, ['>', '}', ')'], true)) {
                $depth--;
            } elseif ($depth === 0 && ctype_space($char)) {
                // "array<int, string>|null" may also be written with spaces around "|".
                $rest = ltrim(substr($body, $i));
                if (str_starts_with($rest, '|') || str_ends_with(substr($body, 0, $i), '|')) {
                    continue;
                }

                return [substr($body, 0, $i), $rest];
            }
        }

        return [$body, ''];
    }

    private function flatten(string $text): string
    {
        return trim((string) preg_replace('/\s*\n\s*/', ' ', $text));
    }

    /**
     * @param  DocBlock  $doc
     * @return Text
     */
    private function text(array $doc): array
    {
        return ['summary' => $doc['summary'], 'description' => $doc['description'], 'deprecated' => $doc['deprecated'], 'see' => $doc['see']];
    }
}

/** @var array{packages?: string, version?: string, output?: string} $options */
$options = getopt('', ['packages:', 'version:', 'output:']);
$packages = rtrim($options['packages'] ?? $root.'/packages', '/');
$version = $options['version'] ?? 'next';

if (! is_dir($packages)) {
    fwrite(STDERR, "No packages directory: {$packages}\n");
    exit(1);
}

$parser = (new ParserFactory)->createForNewestSupportedVersion();
$printer = new Standard;
/** @var list<array{name: string}&array<string, mixed>> $classes */
$classes = [];

$sources = glob($packages.'/*/src', GLOB_ONLYDIR);
foreach ($sources === false ? [] : $sources as $source) {
    $package = basename(dirname($source));
    /** @var iterable<SplFileInfo> $files */
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $path = 'packages/'.$package.'/'.substr($file->getPathname(), strlen($source) - 3);
        $resolver = new NameResolver;
        $collector = new ApiCollector($resolver->getNameContext(), $printer, $package, $path);
        (new NodeTraverser($resolver, $collector))->traverse($parser->parse((string) file_get_contents($file->getPathname())) ?? []);
        array_push($classes, ...$collector->classes);
    }
}

usort($classes, fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

// Binary string constants (e.g. a byte order mark) are not valid UTF-8.
$json = json_encode(['schema' => 1, 'version' => $version, 'classes' => $classes], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)."\n";
if (isset($options['output'])) {
    $target = $options['output'];
    if (! is_dir(dirname($target))) {
        mkdir(dirname($target), 0777, true);
    }
    file_put_contents($target, $json);
    fwrite(STDERR, sprintf("%d classes → %s\n", count($classes), $target));
} else {
    echo $json;
}
