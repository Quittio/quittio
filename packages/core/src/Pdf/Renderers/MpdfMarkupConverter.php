<?php

declare(strict_types=1);

namespace Quittio\Core\Pdf\Renderers;

use Mpdf\MpdfException;
use Mpdf\PageFormat;
use Quittio\Core\Pdf\PdfException;

/**
 * Translates the mPDF-specific markup of a template into standard HTML and CSS (CSS Paged Media
 * with the extensions of Fagus), so that one template serves mPDF and {@see FagusRenderer}:
 *
 * - <htmlpageheader name> and <htmlpagefooter name> become running elements, and the @page
 *   properties header, footer, margin-header and margin-footer the top and bottom center margin
 *   boxes that show them; {PAGENO} and {nbpg} become counter(page) and counter(pages);
 * - <pagebreak> becomes a page break, with page-selector a block on that named page;
 * - absolutely (and fixed) positioned children of <body>, which mPDF places relative to the
 *   sheet, are drawn in a layer over the sheet of the page they are on;
 * - <watermarktext> becomes a watermark on every page (unless watermarks are off, as mPDF
 *   prints none in PDF/A), and replaces a browser watermark of the template (body::before);
 * - the carry-over rows of the line table (see {@see CarryOver}) become rows of its table head
 *   and foot that show only where the table breaks (-fagus-repeat: at-break);
 * - mPDF's default stylesheet and page margins, and the paper size, apply before the template's
 *   own styles.
 *
 * @internal
 */
final readonly class MpdfMarkupConverter
{
    /** Running name of the layers with the positioned elements of a page. */
    public const string SHEET = 'mpdf-sheet';

    /** Elements without content (or written as <tag />) that never open a scope. */
    private const array VOID = [
        'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr',
        'pagebreak', 'formfeed', 'newpage', 'tocpagebreak', 'watermarktext', 'watermarkimage', 'barcode', 'bookmark',
        'indexentry', 'tocentry', 'annotation', 'dottab', 'columnbreak', 'newcolumn', 'columns', 'sethtmlpageheader',
        'sethtmlpagefooter', 'setpageheader', 'setpagefooter',
    ];

    /** Elements that close an open <p>, as in HTML. */
    private const array BLOCKS = [
        'address', 'article', 'aside', 'blockquote', 'details', 'div', 'dl', 'fieldset', 'figcaption', 'figure', 'footer',
        'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hr', 'main', 'nav', 'ol', 'p', 'pre', 'section', 'table', 'ul',
    ];

    /** Tags that break the page in mPDF. */
    private const array BREAKS = ['pagebreak', 'formfeed', 'newpage'];

    /**
     * @param  string  $paper  mPDF page format: a name such as "A4" or "Letter", "-L" for landscape
     * @param  bool  $watermarks  whether <watermarktext> is printed (mPDF prints none in PDF/A)
     */
    public function __construct(
        private string $paper = 'A4',
        private bool $watermarks = true,
    ) {}

    /**
     * The HTML with the mPDF constructs in standard CSS. Throws a PdfException for an unknown paper size.
     */
    public function convert(string $html): string
    {
        [$width, $height] = self::paperSize($this->paper);
        $watermark = preg_match('/<watermarktext\b/i', $html) === 1;

        $html = self::carryOver($html);
        [$html, $running, $contents] = self::runningElements($html);
        [$sections, $css] = self::namedFirstPages($html, $contents);
        $html = self::body($html, $running, $sections, self::positionRules($html), $width, $height);
        $html = $this->watermark($html, min($width, $height));
        $html = self::placeholders($html);
        $html = self::styles($html);

        return self::head($html, self::baseStyles($width, $height, $watermark), $css);
    }

    /**
     * Width and height in millimetres of an mPDF page format ("A4", "Letter", "A4-L").
     *
     * @return array{float, float}
     */
    public static function paperSize(string $paper): array
    {
        $name = trim($paper);
        $landscape = (bool) preg_match('/-L$/i', $name);
        if ($landscape) {
            $name = substr($name, 0, -2);
        }
        try {
            $size = PageFormat::getSizeFromName($name);
        } catch (MpdfException $e) {
            throw new PdfException(sprintf('Unknown paper size "%s".', $paper), 0, $e);
        }
        $width = round((float) $size[0] * 25.4 / 72, 2);
        $height = round((float) $size[1] * 25.4 / 72, 2);

        return $landscape ? [$height, $width] : [$width, $height];
    }

    /**
     * The carry-over rows of the marked line table as rows of its head (carry-in) and foot
     * (carry-out) that show only where the table breaks; {page} the previous or next page,
     * {amount} the running total of the last row before the break.
     */
    private static function carryOver(string $html): string
    {
        $out = self::comment($html, 'carry-out');
        $in = self::comment($html, 'carry-in');
        $start = strpos($html, '<!--quittio:rows-->');
        if ($out === null || $in === null || $start === false) {
            return $html;
        }
        $tableStart = strrpos(substr($html, 0, $start), '<table');
        $headEnd = $tableStart === false ? false : strripos(substr($html, 0, $start), '</thead>');
        if ($tableStart === false || $headEnd === false || $headEnd < $tableStart) {
            return $html;
        }

        $amount = '<span class="mpdf-carry-amount"></span>';
        $carryIn = self::carryRow(str_replace(['{page}', '{amount}'], ['<span class="mpdf-previous-page"></span>', $amount], $in));
        $carryOut = self::carryRow(str_replace(['{page}', '{amount}'], ['<span class="mpdf-next-page"></span>', $amount], $out));
        $html = substr($html, 0, $headEnd).$carryIn.'</thead><tfoot>'.$carryOut.'</tfoot>'.substr($html, $headEnd + strlen('</thead>'));
        // Separate borders: with collapsed borders, Fagus 0.1 draws the bottom border of the hidden
        // carry-in row instead of the border below the column heads on the first page of the table.
        $tableEnd = (int) strpos($html, '>', $tableStart) + 1;
        $html = substr($html, 0, $tableStart).self::withStyle(substr($html, $tableStart, $tableEnd - $tableStart), 'border-collapse: separate; border-spacing: 0;').substr($html, $tableEnd);

        return (string) preg_replace('/<!--quittio:carry-(?:out|in)\s.*?-->/s', '', $html);
    }

    private static function comment(string $html, string $name): ?string
    {
        return preg_match('/<!--quittio:'.$name.'\s(.*?)-->/s', $html, $m) === 1 ? trim($m[1]) : null;
    }

    private static function carryRow(string $row): string
    {
        return (string) preg_replace('/^<tr\b/i', '<tr data-mpdf-carry', $row, 1);
    }

    /**
     * <htmlpageheader> and <htmlpagefooter> as running elements, taken out of the document.
     *
     * @return array{string, string, array<string, array<string, string>>} the HTML without them,
     *         the running elements, and their content by kind (header, footer) and name
     */
    private static function runningElements(string $html): array
    {
        $running = '';
        $contents = ['header' => [], 'footer' => []];
        $html = (string) preg_replace_callback(
            '/<htmlpage(header|footer)\b((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>(.*?)<\/htmlpage\1\s*>/is',
            static function (array $m) use (&$running, &$contents): string {
                $kind = strtolower($m[1]);
                $name = self::attributes($m[2])['name'] ?? '';
                $running .= '<div class="mpdf-'.$kind.'" style="position: running('.self::runningName($kind, $name).');">'.$m[3].'</div>'."\n";
                $contents[$kind][$name] = $m[3];

                return '';
            },
            $html,
        );

        return [$html, $running, $contents];
    }

    /**
     * mPDF applies the rules of @page :first also to the first page after a <pagebreak> with a
     * page selector (before those of the named page). CSS has no selector for the first page of
     * a named page, so where this gives that page another header or footer than the following
     * pages of the same name, the named page shows them as running elements set at the start of
     * the section: the one for its first page, then the one for the pages after it.
     *
     * @param  array<string, array<string, string>>  $contents  header and footer content by name
     * @return array{array<string, string>, string} running elements for the start of a section of
     *         each page name, and the margin boxes that show them
     */
    private static function namedFirstPages(string $html, array $contents): array
    {
        $rules = [];
        foreach (self::styleBlocks($html) as [$css]) {
            foreach (self::statements(self::withoutComments($css)) as [$prelude, $block]) {
                if ($block === null || preg_match('/^\s*@page\b(.*)$/is', $prelude, $m) !== 1) {
                    continue;
                }
                $selector = strtolower(trim($m[1]));
                foreach (self::declarations($block) as [$name, $value]) {
                    $name = ['odd-header-name' => 'header', 'odd-footer-name' => 'footer'][$name] ?? $name;
                    if ($name === 'header' || $name === 'footer') {
                        $rules[$selector][$name] = trim($value, " \t\n\r\0\x0B\"'");
                    }
                }
            }
        }

        preg_match_all('/<(?:pagebreak|formfeed|newpage)\b[^>]*\spage-selector\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $html, $selectors, PREG_SET_ORDER);
        $sections = [];
        $css = '';
        foreach ($selectors as $m) {
            $page = trim(html_entity_decode(($m[1] ?? '').($m[2] ?? '').($m[3] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $key = strtolower($page);
            if ($page === '' || isset($sections[$page])) {
                continue;
            }
            $first = [...$rules[''] ?? [], ...$rules[':first'] ?? [], ...$rules[$key] ?? []];
            $following = [...$rules[''] ?? [], ...$rules[$key] ?? []];
            $sections[$page] = '';
            foreach (['header' => 'top-center', 'footer' => 'bottom-center'] as $kind => $box) {
                if (($first[$kind] ?? null) === ($following[$kind] ?? null)) {
                    continue;
                }
                $name = 'mpdf-page-'.self::ident($page).'-'.$kind;
                $content = static fn (?string $value): string => preg_match('/^html_(.+)$/i', (string) $value, $h) === 1 ? ($contents[$kind][$h[1]] ?? '') : '';
                $sections[$page] .= '<div class="mpdf-'.$kind.'" style="position: running('.$name.');">'.$content($first[$kind] ?? null).'</div>'
                    .'<div class="mpdf-'.$kind.'" style="position: running('.$name.');">'.$content($following[$kind] ?? null).'</div>';
                $css .= '@page '.self::ident($page).' { @'.$box.' { content: element('.$name.'); } }'."\n";
            }
        }

        return [$sections, $css];
    }

    private static function runningName(string $kind, string $name): string
    {
        return 'mpdf-'.$kind.'-'.preg_replace('/[^A-Za-z0-9_-]/', '_', $name);
    }

    /**
     * The children of <body>: the running elements first, positioned elements in layers over the
     * sheet, and a page break (or a block on a named page) for every top-level <pagebreak>.
     *
     * @param  array<string, string>  $sections  running elements for the start of a section by page name
     * @param  list<array{list<string>, string, array{int, int, int}, bool}>  $rules
     */
    private static function body(string $html, string $running, array $sections, array $rules, float $width, float $height): string
    {
        [$start, $end] = self::bodyRange($html);
        $body = substr($html, $start, $end - $start);

        $out = $running;
        // Positioned elements waiting for their layer, and what follows them until the next one.
        $layer = '';
        $pending = '';
        $page = null;
        $open = false;

        foreach (self::topLevel($body) as [$kind, $chunk, $tag, $attributes]) {
            if ($kind !== 'element') {
                // Whitespace and comments stay where they are; a group of positioned elements spans them.
                if ($layer !== '' && ($kind === 'comment' || trim($chunk) === '')) {
                    $pending .= $chunk;
                } else {
                    $out .= self::layer($layer, $width, $height).$pending.$chunk;
                    $layer = $pending = '';
                }

                continue;
            }
            if (in_array($tag, self::BREAKS, true)) {
                $out .= self::layer($layer, $width, $height).$pending;
                $layer = $pending = '';
                $selector = trim($attributes['page-selector'] ?? '');
                // A page selector applies to the following pages until another one is given.
                $page = $selector !== '' ? $selector : $page;
                $out .= $open ? '</div>' : '';
                $open = $page !== null;
                $out .= $open
                    ? '<div class="mpdf-page" style="break-before: page; page: '.self::ident($page).';">'.($selector !== '' ? $sections[$selector] ?? '' : '')
                    : '<div class="mpdf-page" style="break-before: page;"></div>';

                continue;
            }
            $position = self::position($tag, $attributes, $rules);
            if ($position === null) {
                $out .= self::layer($layer, $width, $height).$pending.$chunk;
                $layer = $pending = '';

                continue;
            }
            // mPDF places fixed elements like absolute ones, on the page they are on.
            $layer .= $pending.($position === 'fixed' ? self::withStyle($chunk, 'position: absolute;') : $chunk);
            $pending = '';
        }
        $out .= self::layer($layer, $width, $height).$pending.($open ? '</div>' : '');
        // A page break inside another element only breaks the page.
        $out = (string) preg_replace('/<(?:pagebreak|formfeed|newpage)\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>(?:\s*<\/(?:pagebreak|formfeed|newpage)\s*>)?/i', '<div class="mpdf-page" style="break-before: page;"></div>', $out);

        return substr($html, 0, $start).$out.substr($html, $end);
    }

    /**
     * Positioned elements in a layer the size of the sheet, shown in the page's top left corner
     * box: on the page where the layer is, and (by the empty element after it) on no other.
     */
    private static function layer(string $elements, float $width, float $height): string
    {
        if ($elements === '') {
            return '';
        }

        return '<div class="'.self::SHEET.'" style="position: running('.self::SHEET.');"><div style="position: relative; width: '.$width.'mm; height: '.$height.'mm;">'
            .$elements.'</div></div><div style="position: running('.self::SHEET.');"></div>';
    }

    /**
     * Start and end of the content of <body>; without <body>, everything after <head>.
     *
     * @return array{int, int}
     */
    private static function bodyRange(string $html): array
    {
        if (preg_match('/<body\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>/i', $html, $m, PREG_OFFSET_CAPTURE) === 1) {
            $start = $m[0][1] + strlen($m[0][0]);
        } elseif (($head = stripos($html, '</head>')) !== false) {
            $start = $head + strlen('</head>');
        } elseif (preg_match('/<html\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>/i', $html, $m, PREG_OFFSET_CAPTURE) === 1) {
            $start = $m[0][1] + strlen($m[0][0]);
        } else {
            $start = 0;
        }
        $end = stripos($html, '</body>', $start);
        if ($end === false) {
            $end = stripos($html, '</html>', $start);
        }

        return [$start, $end === false ? strlen($html) : $end];
    }

    /**
     * The top-level nodes of the body: elements with their content, comments and text.
     *
     * @return list<array{string, string, string, array<string, string>}> kind (element, comment, text), markup, tag, attributes
     */
    private static function topLevel(string $body): array
    {
        preg_match_all('/<!--.*?-->|<(\/?)([a-zA-Z][a-zA-Z0-9:_-]*)((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>/s', $body, $tokens, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL);

        $nodes = [];
        $stack = [];
        $position = 0;
        $start = 0;
        $tag = '';
        $attributes = [];
        $skipUntil = 0;
        foreach ($tokens as $token) {
            $markup = (string) $token[0][0];
            $offset = $token[0][1];
            if ($offset < $skipUntil) {
                continue;
            }
            if ($stack === [] && $offset > $position) {
                $nodes[] = ['text', substr($body, $position, $offset - $position), '', []];
                $position = $offset;
            }
            if (str_starts_with($markup, '<!--')) {
                if ($stack === []) {
                    $nodes[] = ['comment', $markup, '', []];
                    $position = $offset + strlen($markup);
                }

                continue;
            }
            $name = strtolower((string) $token[2][0]);
            $closing = $token[1][0] === '/';
            $attributeMarkup = (string) $token[3][0];
            $end = $offset + strlen($markup);
            if (! $closing && in_array($name, ['script', 'style', 'textarea', 'title'], true)) {
                // Raw text: no tags inside.
                $close = stripos($body, '</'.$name, $end);
                $closeEnd = $close === false ? false : strpos($body, '>', $close);
                $skipUntil = $end = $closeEnd === false ? strlen($body) : $closeEnd + 1;
                if ($stack === []) {
                    $nodes[] = ['element', substr($body, $offset, $end - $offset), $name, self::attributes($attributeMarkup)];
                    $position = $end;
                }

                continue;
            }
            if ($closing) {
                // The innermost open element of that name; end tags without one are ignored.
                $index = array_search($name, array_reverse($stack, true), true);
                if ($index === false) {
                    continue;
                }
                $stack = array_slice($stack, 0, (int) $index);
                if ($stack === []) {
                    $nodes[] = ['element', substr($body, $start, $end - $start), $tag, $attributes];
                    $position = $end;
                }

                continue;
            }
            if (in_array($name, self::BLOCKS, true) && end($stack) === 'p') {
                // A block closes the paragraph it starts in.
                array_pop($stack);
                if ($stack === []) {
                    $nodes[] = ['element', substr($body, $start, $offset - $start), $tag, $attributes];
                    $position = $offset;
                }
            }
            $void = in_array($name, self::VOID, true) || str_ends_with(rtrim($attributeMarkup), '/');
            if ($stack === []) {
                $start = $offset;
                $tag = $name;
                $attributes = self::attributes($attributeMarkup);
                if ($void) {
                    $nodes[] = ['element', $markup, $tag, $attributes];
                    $position = $end;

                    continue;
                }
            }
            if (! $void) {
                $stack[] = $name;
            }
        }
        if ($stack !== []) {
            $nodes[] = ['element', substr($body, $start), $tag, $attributes];
        } elseif ($position < strlen($body)) {
            $nodes[] = ['text', substr($body, $position), '', []];
        }

        return array_values(array_filter($nodes, static fn (array $node): bool => $node[1] !== ''));
    }

    /**
     * @return array<string, string> lower-case name => decoded value
     */
    private static function attributes(string $markup): array
    {
        preg_match_all('/([^\s"\'>\/=]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?/', $markup, $matches, PREG_SET_ORDER);
        $attributes = [];
        foreach ($matches as $m) {
            $name = strtolower($m[1]);
            if (! isset($attributes[$name])) {
                $attributes[$name] = html_entity_decode(($m[2] ?? '').($m[3] ?? '').($m[4] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }

        return $attributes;
    }

    /**
     * The element's start tag with the declaration added at the end of its style attribute.
     */
    private static function withStyle(string $element, string $declaration): string
    {
        return (string) preg_replace_callback('/^<([a-zA-Z][a-zA-Z0-9:_-]*)((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>/', static function (array $m) use ($declaration): string {
            if (preg_match('/\sstyle\s*=\s*(["\'])(.*?)\1/is', $m[2], $style, PREG_OFFSET_CAPTURE) === 1) {
                $value = rtrim($style[2][0]);
                $value .= ($value === '' || str_ends_with($value, ';') ? ' ' : '; ').$declaration;

                return '<'.$m[1].substr($m[2], 0, $style[2][1]).$value.substr($m[2], $style[2][1] + strlen($style[2][0])).'>';
            }
            $attributes = rtrim($m[2]);
            $selfClosing = str_ends_with($attributes, '/');

            return '<'.$m[1].($selfClosing ? rtrim(substr($attributes, 0, -1)) : $attributes).' style="'.$declaration.'"'.($selfClosing ? ' />' : '>');
        }, $element, 1);
    }

    /**
     * The value of position (absolute or fixed) of a top-level element, from its style attribute
     * or the rules of the document's stylesheets; null for any other value.
     *
     * @param  array<string, string>  $attributes
     * @param  list<array{list<string>, string, array{int, int, int}, bool}>  $rules
     */
    private static function position(string $tag, array $attributes, array $rules): ?string
    {
        $value = null;
        if (preg_match_all('/(?:^|;)\s*position\s*:\s*([a-z-]+)\s*(!\s*important)?/i', $attributes['style'] ?? '', $inline, PREG_SET_ORDER) > 0) {
            $value = strtolower($inline[count($inline) - 1][1]);
        }

        $classes = preg_split('/\s+/', trim($attributes['class'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        $classes = is_array($classes) ? $classes : [];
        $winner = null;
        foreach ($rules as $order => [$compound, $ruleValue, $specificity, $important]) {
            if (! self::matches($compound, $tag, $attributes['id'] ?? null, $classes)) {
                continue;
            }
            $rank = [$important ? 1 : 0, ...$specificity, $order];
            if ($winner === null || $rank >= $winner[0]) {
                $winner = [$rank, $ruleValue];
            }
        }
        if ($winner !== null && ($value === null || $winner[0][0] === 1)) {
            $value = $winner[1];
        }

        return in_array($value, ['absolute', 'fixed'], true) ? $value : null;
    }

    /**
     * @param  list<string>  $compound  tag, #id and .class parts of the last compound selector
     * @param  list<string>  $classes
     */
    private static function matches(array $compound, string $tag, ?string $id, array $classes): bool
    {
        foreach ($compound as $part) {
            $matches = match ($part[0]) {
                '#' => $id === substr($part, 1),
                '.' => in_array(substr($part, 1), $classes, true),
                '*' => true,
                default => strtolower($part) === $tag,
            };
            if (! $matches) {
                return false;
            }
        }

        return $compound !== [];
    }

    /**
     * The rules of the document's stylesheets that set position: for each selector the parts
     * of its last compound selector, the value, the specificity and whether it is important.
     * Only what mPDF applies: top-level rules and those of @media print and all.
     *
     * @return list<array{list<string>, string, array{int, int, int}, bool}>
     */
    private static function positionRules(string $html): array
    {
        $rules = [];
        foreach (self::styleBlocks($html) as [$css]) {
            foreach (self::rules(self::withoutComments($css)) as [$selectors, $declarations]) {
                foreach (self::declarations($declarations) as [$name, $value]) {
                    if ($name !== 'position') {
                        continue;
                    }
                    $important = (bool) preg_match('/!\s*important\s*$/i', $value);
                    $value = strtolower(trim((string) preg_replace('/!\s*important\s*$/i', '', $value)));
                    foreach (explode(',', $selectors) as $selector) {
                        $selector = trim($selector);
                        if ($selector === '' || str_contains($selector, '::') || preg_match('/:(?:before|after)\b/i', $selector) === 1) {
                            continue;
                        }
                        $compounds = preg_split('/\s*[>+~]\s*|\s+/', $selector, -1, PREG_SPLIT_NO_EMPTY);
                        $last = is_array($compounds) && $compounds !== [] ? $compounds[count($compounds) - 1] : '';
                        preg_match_all('/[#.]?[A-Za-z_\-*][\w-]*|\*/', (string) preg_replace('/\[[^\]]*\]|:[\w-]+(?:\([^)]*\))?/', '', $last), $parts);
                        $rules[] = [$parts[0], $value, [
                            substr_count($selector, '#'),
                            substr_count($selector, '.') + substr_count($selector, '[') + self::occurrences('/:[\w-]/', $selector),
                            self::occurrences('/(?:^|[\s>+~])[a-zA-Z]/', $selector),
                        ], $important];
                    }
                }
            }
        }

        return $rules;
    }

    private static function occurrences(string $pattern, string $subject): int
    {
        $count = preg_match_all($pattern, $subject);

        return $count === false ? 0 : $count;
    }

    /**
     * Rules of a stylesheet (selectors, declarations): top-level and inside @media print or all.
     *
     * @return list<array{string, string}>
     */
    private static function rules(string $css): array
    {
        $rules = [];
        foreach (self::statements($css) as [$prelude, $block]) {
            $prelude = trim(self::withoutComments($prelude));
            if ($block === null) {
                continue;
            }
            if (str_starts_with($prelude, '@')) {
                if (preg_match('/^@media\b(.*)$/is', $prelude, $m) === 1 && preg_match('/\b(?:print|all)\b/i', $m[1]) === 1) {
                    $rules = [...$rules, ...self::rules($block)];
                }

                continue;
            }
            $rules[] = [$prelude, $block];
        }

        return $rules;
    }

    /**
     * The statements of a stylesheet: the prelude and the content of its block, or null for a
     * statement without block (@import …;), with their offsets.
     *
     * @return list<array{string, ?string, int, int}> prelude, block, start, end
     */
    private static function statements(string $css): array
    {
        $statements = [];
        $length = strlen($css);
        $start = 0;
        $i = 0;
        while ($i < $length) {
            $char = $css[$i];
            if ($char === '"' || $char === "'") {
                $i = self::skipString($css, $i);

                continue;
            }
            if ($char === '/' && ($css[$i + 1] ?? '') === '*') {
                $close = strpos($css, '*/', $i + 2);
                $i = $close === false ? $length : $close + 2;

                continue;
            }
            if ($char === ';') {
                $statements[] = [substr($css, $start, $i - $start), null, $start, $i + 1];
                $start = ++$i;

                continue;
            }
            if ($char === '{') {
                $close = self::matchingBrace($css, $i);
                $statements[] = [substr($css, $start, $i - $start), substr($css, $i + 1, $close - $i - 1), $start, $close + 1];
                $start = $i = $close + 1;

                continue;
            }
            if ($char === '}') {
                $start = ++$i;

                continue;
            }
            $i++;
        }

        return $statements;
    }

    private static function skipString(string $css, int $i): int
    {
        $quote = $css[$i];
        $length = strlen($css);
        for ($i++; $i < $length; $i++) {
            if ($css[$i] === '\\') {
                $i++;
            } elseif ($css[$i] === $quote) {
                return $i + 1;
            }
        }

        return $length;
    }

    private static function matchingBrace(string $css, int $open): int
    {
        $depth = 0;
        $length = strlen($css);
        for ($i = $open; $i < $length; $i++) {
            $char = $css[$i];
            if ($char === '"' || $char === "'") {
                $i = self::skipString($css, $i) - 1;
            } elseif ($char === '/' && ($css[$i + 1] ?? '') === '*') {
                $close = strpos($css, '*/', $i + 2);
                $i = $close === false ? $length : $close + 1;
            } elseif ($char === '{') {
                $depth++;
            } elseif ($char === '}' && --$depth === 0) {
                return $i;
            }
        }

        return $length;
    }

    /**
     * The declarations of a block (name in lower case, value), without nested blocks.
     *
     * @return list<array{string, string}>
     */
    private static function declarations(string $block): array
    {
        $declarations = [];
        foreach (self::statements($block) as [$text, $nested]) {
            if ($nested !== null) {
                continue;
            }
            $colon = strpos($text, ':');
            if ($colon !== false) {
                $declarations[] = [strtolower(trim(substr($text, 0, $colon))), trim(substr($text, $colon + 1))];
            }
        }
        // The last declaration has no semicolon: statements() leaves it out.
        $last = self::lastDeclaration($block);
        if ($last !== null) {
            $declarations[] = $last;
        }

        return $declarations;
    }

    /**
     * The declaration after the last semicolon or block of $block, if any.
     *
     * @return array{string, string}|null
     */
    private static function lastDeclaration(string $block): ?array
    {
        $statements = self::statements($block);
        $end = $statements === [] ? 0 : end($statements)[3];
        $text = trim(substr($block, $end));
        $colon = strpos($text, ':');

        return $text === '' || $colon === false ? null : [strtolower(trim(substr($text, 0, $colon))), trim(substr($text, $colon + 1))];
    }

    private static function withoutComments(string $css): string
    {
        return (string) preg_replace('/\/\*.*?\*\//s', '', $css);
    }

    /**
     * The <style> elements: their content and its offsets.
     *
     * @return list<array{string, int}>
     */
    private static function styleBlocks(string $html): array
    {
        preg_match_all('/<style\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>(.*?)<\/style\s*>/is', $html, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        return array_map(static fn (array $m): array => [$m[1][0], $m[1][1]], $matches);
    }

    /**
     * The @page rules of every <style> element with mPDF's page properties as margin boxes.
     */
    private static function styles(string $html): string
    {
        foreach (array_reverse(self::styleBlocks($html)) as [$css, $offset]) {
            $html = substr($html, 0, $offset).self::pageRules($css).substr($html, $offset + strlen($css));
        }

        return $html;
    }

    private static function pageRules(string $css): string
    {
        foreach (array_reverse(self::statements($css)) as [$prelude, $block, $start, $end]) {
            if ($block === null || preg_match('/^\s*@page\b/i', self::withoutComments($prelude)) !== 1) {
                continue;
            }
            $css = substr($css, 0, $start).$prelude.'{'.self::pageRule($block).'}'.substr($css, $end);
        }

        return $css;
    }

    /**
     * The body of an @page rule with header, footer, margin-header, margin-footer and sheet-size
     * as standard CSS.
     */
    private static function pageRule(string $block): string
    {
        $kept = [];
        $nested = [];
        $boxes = ['top-center' => [], 'bottom-center' => []];
        foreach (self::statements($block) as [$text, $inner]) {
            if ($inner !== null) {
                $nested[] = trim($text).' {'.$inner.'}';
            }
        }
        foreach (self::declarations($block) as [$name, $value]) {
            $value = trim($value, " \t\n\r\0\x0B\"'");
            match ($name) {
                'header', 'odd-header-name' => $boxes['top-center']['content'] = self::runningContent('header', $value),
                'footer', 'odd-footer-name' => $boxes['bottom-center']['content'] = self::runningContent('footer', $value),
                'margin-header' => $boxes['top-center']['padding-top'] = $value,
                'margin-footer' => $boxes['bottom-center']['padding-bottom'] = $value,
                'sheet-size' => $kept[] = 'size: '.self::sheetSize($value),
                // Mirrored margins are off in MpdfRenderer: mPDF prints the odd headers on every page.
                'even-header-name', 'even-footer-name', 'odd-header-value', 'even-header-value', 'odd-footer-value',
                'even-footer-value', 'resetpagenum', 'pagenumstyle', 'suppress' => null,
                default => $kept[] = $name.': '.$value,
            };
        }
        foreach ($boxes as $box => $declarations) {
            if ($declarations !== []) {
                $nested[] = '@'.$box.' { '.implode(' ', array_map(static fn (string $name, string $value): string => $name.': '.$value.';', array_keys($declarations), $declarations)).' }';
            }
        }

        return ' '.implode('; ', $kept).($kept === [] ? '' : ';').($nested === [] ? '' : "\n      ".implode("\n      ", $nested)).' ';
    }

    private static function runningContent(string $kind, string $value): string
    {
        return preg_match('/^html_(.+)$/i', $value, $m) === 1 ? 'element('.self::runningName($kind, $m[1]).')' : 'none';
    }

    private static function sheetSize(string $value): string
    {
        try {
            [$width, $height] = self::paperSize($value);
        } catch (PdfException) {
            // A size in CSS units ("210mm 297mm") or a keyword.
            return $value;
        }

        return $width.'mm '.$height.'mm';
    }

    /**
     * <watermarktext> as a watermark on every page, as mPDF draws it: bold, at 45 degrees, across
     * the page (relative to the page area rather than the sheet), with its alpha.
     */
    private function watermark(string $html, float $side): string
    {
        return (string) preg_replace_callback('/<watermarktext\b((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>(?:\s*<\/watermarktext\s*>)?/i', function (array $m) use ($side): string {
            $attributes = self::attributes($m[1]);
            $text = $attributes['content'] ?? '';
            if (! $this->watermarks || $text === '') {
                return '';
            }
            $alpha = is_numeric($attributes['alpha'] ?? null) ? max(0.0, min(1.0, (float) $attributes['alpha'])) : 0.2;
            // mPDF shrinks the text from 96 pt until it fits the shorter side of the page.
            $size = round(min(96.0, $side * 72 / 25.4 / max(1, mb_strlen($text)) / 0.62), 1);

            return '<div class="mpdf-watermark" style="position: fixed; top: 50%; left: 0; right: 0; height: 0; text-align: center; line-height: 0; white-space: nowrap; transform: rotate(-45deg); font-weight: bold; font-size: '.$size.'pt; color: #000; opacity: '.$alpha.'; z-index: 1000;">'
                .htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8').'</div>';
        }, $html);
    }

    /**
     * {PAGENO}, {nbpg} and {nb} in the text (not in tags or comments) as page counters.
     */
    private static function placeholders(string $html): string
    {
        $parts = preg_split('/(<!--.*?-->|<(?:[^>"\']|"[^"]*"|\'[^\']*\')*>)/s', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (! is_array($parts)) {
            return $html;
        }
        foreach ($parts as $i => $part) {
            if ($i % 2 === 0 && str_contains($part, '{')) {
                $parts[$i] = str_replace(['{PAGENO}', '{nbpg}', '{nb}'], ['<span class="mpdf-pageno"></span>', '<span class="mpdf-nbpg"></span>', '<span class="mpdf-nbpg"></span>'], $part);
            }
        }

        return implode('', $parts);
    }

    /**
     * The document with a doctype (no quirks mode), the charset and the base stylesheet before
     * the template's own styles, $late after them.
     */
    private static function head(string $html, string $css, string $late): string
    {
        $html = ltrim((string) preg_replace('/^\s*<!doctype\b[^>]*>/i', '', $html));
        if ($late !== '') {
            // After the template's styles: rules that must win over its own.
            $at = stripos($html, '</head>');
            $late = '<style>'."\n".$late.'</style>'."\n";
            $html = $at === false ? $html.$late : substr($html, 0, $at).$late.substr($html, $at);
        }
        $insert = '<meta charset="utf-8">'."\n".'<style>'."\n".$css.'</style>'."\n";
        if (preg_match('/<head\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>/i', $html, $m, PREG_OFFSET_CAPTURE) === 1) {
            $at = $m[0][1] + strlen($m[0][0]);
            $html = substr($html, 0, $at)."\n".$insert.substr($html, $at);
        } elseif (preg_match('/<html\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>/i', $html, $m, PREG_OFFSET_CAPTURE) === 1) {
            $at = $m[0][1] + strlen($m[0][0]);
            $html = substr($html, 0, $at)."\n<head>\n".$insert.'</head>'.substr($html, $at);
        } else {
            $html = "<head>\n".$insert.'</head>'."\n".$html;
        }

        return "<!DOCTYPE html>\n".$html;
    }

    /**
     * mPDF's page defaults (MpdfRenderer), its default stylesheet where it differs from a
     * browser's, and the classes the conversion uses.
     */
    private static function baseStyles(float $width, float $height, bool $watermark): string
    {
        $sheet = self::SHEET;
        $css = <<<CSS
            @page { size: {$width}mm {$height}mm; margin: 20mm 15mm 25mm 20mm;
              @top-left-corner { content: element({$sheet}); vertical-align: top; text-align: left; padding: 0; }
              @top-center { vertical-align: top; padding-top: 8mm; }
              @bottom-center { vertical-align: bottom; padding-bottom: 8mm; } }
            body { margin: 0; font-size: 11pt; line-height: 1.33; }
            p { margin: 1.12em 0; }
            h1 { font-size: 2em; margin: 0.67em 0; break-after: avoid; }
            h2 { font-size: 1.5em; margin: 0.75em 0; break-after: avoid; }
            h3 { font-size: 1.17em; margin: 0.83em 0; break-after: avoid; }
            h4 { margin: 1.12em 0; break-after: avoid; }
            h5 { font-size: 0.83em; margin: 1.5em 0; break-after: avoid; }
            h6 { font-size: 0.75em; margin: 1.67em 0; break-after: avoid; }
            hr { border: 0; border-top: 0.2mm solid #888; margin: 0.83em 0; }
            pre { margin: 0.83em 0; }
            ul, ol { margin: 0.83em 0; }
            ul ul, ul ol, ol ul, ol ol { margin: 0; }
            dl { margin: 1.67em 0; }
            dd { margin-left: 0; padding-left: 40px; }
            blockquote { margin: 1.12em 40px; }
            a { color: #00f; }
            sub, sup { font-size: 55%; }
            small { font-size: 83%; }
            big { font-size: 117%; }
            acronym { font-size: 77%; font-weight: bold; }
            q { font-style: italic; }
            table { line-height: 1.2; }
            td, th { padding: 0.1em; }
            .mpdf-pageno::after { content: counter(page); }
            .mpdf-nbpg::after { content: counter(pages); }
            tr[data-carry-over] { string-set: mpdf-carry-over attr(data-carry-over); }
            tr[data-mpdf-carry] { -fagus-repeat: at-break; }
            .mpdf-carry-amount::after { content: string(mpdf-carry-over); }
            .mpdf-next-page::after { content: counter(-fagus-next-page); }
            .mpdf-previous-page::after { content: counter(-fagus-previous-page); }

            CSS;
        if ($watermark) {
            // The template's watermark for browsers (mPDF has no generated content) gives way to <watermarktext>.
            $css .= "body::before, body::after { content: none !important; }\n";
        }

        return $css;
    }

    private static function ident(string $name): string
    {
        $ident = (string) preg_replace('/[^A-Za-z0-9_-]/', '_', $name);

        return preg_match('/^(?:-?[A-Za-z_]|--)/', $ident) === 1 ? $ident : '_'.$ident;
    }
}
