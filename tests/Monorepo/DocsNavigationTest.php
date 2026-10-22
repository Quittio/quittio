<?php

declare(strict_types=1);

// The documentation on quittio.dev/docs: the website reads titles and order of the pages from
// docs/nav.yml with a pattern for exactly this line format, and renders the links between the
// pages with the heading anchors of kramdown.

/**
 * The entries of docs/nav.yml.
 *
 * @return list<array{page: string, title: string, fields: array<string, string>}>
 */
function docsNavigation(): array
{
    $source = (string) file_get_contents(dirname(__DIR__, 2).'/docs/nav.yml');
    $entries = [];
    foreach (explode("\n", $source) as $line) {
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        expect($line)->toMatch('/^- \{ page: ([\w-]+), title: ([^,}]+)((?:, \w+: [^,}]+)*) \}$/');
        preg_match('/^- \{ page: ([\w-]+), title: ([^,}]+)((?:, \w+: [^,}]+)*) \}$/', $line, $match);
        preg_match_all('/, (\w+): ([^,}]+)/', $match[3], $fields, PREG_SET_ORDER);
        $entries[] = [
            'page' => $match[1],
            'title' => trim($match[2]),
            'fields' => array_column(array_map(fn (array $field): array => [$field[1], trim($field[2])], $fields), 1, 0),
        ];
    }

    return $entries;
}

/**
 * The anchors of the headings of a page as kramdown generates them, and the explicit ones ({#id}).
 *
 * @return list<string>
 */
function docsAnchors(string $markdown): array
{
    $markdown = (string) preg_replace('/^```.*?^```$/ms', '', $markdown);
    preg_match_all('/^#{1,6} (.+)$/m', $markdown, $headings);
    $anchors = [];
    foreach ($headings[1] as $heading) {
        if (preg_match('/\{:?\s*#([\w-]+)\s*\}\s*$/', $heading, $explicit) === 1) {
            $anchors[] = $explicit[1];

            continue;
        }
        $text = (string) preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $heading);
        $id = strtolower(str_replace(' ', '-', (string) preg_replace('/[^a-zA-Z0-9 -]/', '', (string) preg_replace('/^[^a-zA-Z]+/', '', $text))));
        $base = $id === '' ? 'section' : $id;
        $id = $base;
        for ($i = 1; in_array($id, $anchors, true); $i++) {
            $id = $base.'-'.$i;
        }
        $anchors[] = $id;
    }
    preg_match_all('/^\{:\s*#([\w-]+)\s*\}$/m', $markdown, $attributes);

    return [...$anchors, ...$attributes[1]];
}

it('lists every page of the documentation in docs/nav.yml', function () {
    $root = dirname(__DIR__, 2);
    $pages = array_map(fn (string $file): string => basename($file, '.md'), glob($root.'/docs/*.md') ?: []);
    $listed = array_column(docsNavigation(), 'page');

    expect(array_values(array_diff($pages, $listed)))->toBe([], 'Pages without an entry in docs/nav.yml')
        ->and(array_values(array_diff($listed, $pages)))->toBe([], 'Entries in docs/nav.yml without a page')
        ->and($listed)->toBe(array_values(array_unique($listed)))
        ->and($listed[0])->toBe('index');
});

it('uses only known fields and existing parents in docs/nav.yml', function () {
    $entries = docsNavigation();
    $titles = array_column($entries, 'title');
    foreach ($entries as $entry) {
        expect(array_keys($entry['fields']))->each->toBeIn(['parent', 'preview', 'optional']);
        if (isset($entry['fields']['parent'])) {
            expect($titles)->toContain($entry['fields']['parent']);
        }
        foreach (['preview', 'optional'] as $flag) {
            if (isset($entry['fields'][$flag])) {
                expect($entry['fields'][$flag])->toBe('true');
            }
        }
    }
});

it('links only to existing pages and anchors of the documentation', function () {
    $root = dirname(__DIR__, 2);
    $anchors = [];
    foreach (glob($root.'/docs/*.md') ?: [] as $file) {
        $anchors[basename($file, '.md')] = docsAnchors((string) file_get_contents($file));
    }
    $broken = [];
    foreach (glob($root.'/docs/*.md') ?: [] as $file) {
        $page = basename($file, '.md');
        $markdown = (string) preg_replace('/^```.*?^```$/ms', '', (string) file_get_contents($file));
        preg_match_all('/\]\((?:([\w-]+)\.md)?(?:#([\w-]+))?\)/', $markdown, $links, PREG_SET_ORDER);
        foreach ($links as $link) {
            $target = ($link[1] ?? '') !== '' ? $link[1] : $page;
            $anchor = $link[2] ?? '';
            if (! isset($anchors[$target]) || ($anchor !== '' && ! in_array($anchor, $anchors[$target], true))) {
                $broken[] = "{$page}.md: {$link[0]}";
            }
        }
    }

    expect($broken)->toBe([]);
});
