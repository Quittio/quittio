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
 * - <pagebreak> becomes a page break, with page-selector a block on that named page, whose
 *   first page gets the rules of @page :first through a page group;
 * - absolutely (and fixed) positioned children of <body>, which mPDF places relative to the
 *   sheet, are placed in the page area of the page they are on where they lie in it, else
 *   drawn in a layer over the sheet of that page;
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

    /** Class of the blocks that place positioned elements in the page area. */
    public const string AREA = 'mpdf-area';

    /** Properties that place a positioned element. */
    private const array POSITION_PROPERTIES = ['position', 'top', 'left', 'right', 'bottom', 'width', 'height'];

    /** Elements that make no box in the flow of the page. */
    private const array BOXLESS = [
        'script', 'style', 'link', 'meta', 'title', 'template', 'watermarktext', 'watermarkimage', 'bookmark', 'tocentry',
        'indexentry', 'annotation', 'sethtmlpageheader', 'sethtmlpagefooter', 'setpageheader', 'setpagefooter',
    ];

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
        [$html, $running] = self::runningElements($html);
        $blocks = self::pageBlocks($html);
        $css = self::namedFirstPages($html, $blocks);
        $html = self::body($html, $running, self::positionRules($html), $blocks, $width, $height);
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
     * @return array{string, string} the HTML without them, and the running elements
     */
    private static function runningElements(string $html): array
    {
        $running = '';
        $html = (string) preg_replace_callback(
            '/<htmlpage(header|footer)\b((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>(.*?)<\/htmlpage\1\s*>/is',
            static function (array $m) use (&$running): string {
                $kind = strtolower($m[1]);
                $name = self::attributes($m[2])['name'] ?? '';
                $running .= '<div class="mpdf-'.$kind.'" style="position: running('.self::runningName($kind, $name).');">'.$m[3].'</div>'."\n";

                return '';
            },
            $html,
        );

        return [$html, $running];
    }

    /**
     * The blocks of the top-level @page rules of the document's stylesheets by selector: '' for
     *
     * @page, ':first', or the page name in lower case (mPDF ignores the case of page names).
     *
     * @return array<string, string>
     */
    private static function pageBlocks(string $html): array
    {
        $blocks = [];
        foreach (self::styleBlocks($html) as [$css]) {
            foreach (self::statements(self::withoutComments($css)) as [$prelude, $block]) {
                if ($block !== null && preg_match('/^\s*@page\b(.*)$/is', $prelude, $m) === 1) {
                    $selector = strtolower(trim($m[1]));
                    $blocks[$selector] = ($blocks[$selector] ?? '').$block.';';
                }
            }
        }

        return $blocks;
    }

    /**
     * mPDF applies the rules of @page :first also to the first page after a <pagebreak> with a
     * page selector, before those of the named page. Each such page break starts a page group
     * (see {@see body()}), so @page name:nth(1 of name) selects that page: it gets the rules of
     * :first, then those of the named page again, which the more specific rule would override.
     *
     * @param  array<string, string>  $blocks  the @page blocks by selector
     * @return string the @page rules of the first pages of the named pages
     */
    private static function namedFirstPages(string $html, array $blocks): string
    {
        $first = self::firstPageBlock($blocks[':first'] ?? '');
        if ($first === '') {
            return '';
        }

        preg_match_all('/<(?:pagebreak|formfeed|newpage)\b[^>]*\spage-selector\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $html, $selectors, PREG_SET_ORDER);
        $pages = [];
        $css = '';
        foreach ($selectors as $m) {
            $page = trim(html_entity_decode(($m[1] ?? '').($m[2] ?? '').($m[3] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $ident = self::ident($page);
            if ($page === '' || isset($pages[$ident])) {
                continue;
            }
            $pages[$ident] = true;
            $css .= '@page '.$ident.':nth(1 of '.$ident.') {'.self::pageRule($first.($blocks[strtolower($page)] ?? '')).'}'."\n";
        }

        return $css;
    }

    /**
     * The rules of @page :first that mPDF applies to the first page of a named page: all but the
     * size and the left and right margins.
     */
    private static function firstPageBlock(string $block): string
    {
        $kept = '';
        foreach (self::statements($block) as [$text, $nested]) {
            if ($nested !== null) {
                $kept .= trim($text).' {'.$nested.'} ';
            }
        }
        foreach (self::declarations($block) as [$name, $value]) {
            if ($name === 'margin') {
                [$top, , $bottom] = self::sides($value);
                $kept .= 'margin-top: '.$top.'; margin-bottom: '.$bottom.'; ';
            } elseif (! in_array($name, ['size', 'sheet-size', 'margin-left', 'margin-right'], true)) {
                $kept .= $name.': '.$value.'; ';
            }
        }

        return $kept;
    }

    /**
     * The four sides (top, right, bottom, left) of a shorthand value such as that of margin.
     *
     * @return array{string, string, string, string}
     */
    private static function sides(string $value): array
    {
        $parts = preg_split('/\s+/', trim($value), -1, PREG_SPLIT_NO_EMPTY);
        $parts = is_array($parts) && $parts !== [] ? $parts : [''];
        $top = $parts[0];
        $right = $parts[1] ?? $top;
        $bottom = $parts[2] ?? $top;

        return [$top, $right, $bottom, $parts[3] ?? $right];
    }

    private static function runningName(string $kind, string $name): string
    {
        return 'mpdf-'.$kind.'-'.preg_replace('/[^A-Za-z0-9_-]/', '_', $name);
    }

    /**
     * The children of <body>: the running elements first, positioned elements in the page area or
     * in layers over the sheet, and a page break for every top-level <pagebreak>. A page break
     * with a page selector starts a block on that named page, a page group, up to the next one;
     * the pages after a page break without page selector stay in it.
     *
     * mPDF places positioned elements from the edges of the sheet of the page they are on. Before
     * the first content of a page, that page and its margins are known: a positioned element
     * that lies in its page area is placed there, in the flow, so that a tagged PDF keeps it as
     * content. Others, such as fold marks in the page margin, which the page area would cut off,
     * are drawn in a layer over the sheet in a margin box, where they are artifacts.
     *
     * @param  array<string, list<array{list<string>, string, array{int, int, int}, bool}>>  $rules
     * @param  array<string, string>  $blocks  the @page blocks by selector
     */
    private static function body(string $html, string $running, array $rules, array $blocks, float $width, float $height): string
    {
        [$start, $end] = self::bodyRange($html);
        $body = substr($html, $start, $end - $start);

        $out = $running;
        // Positioned elements waiting for their block (sheet or area), and what follows them until the next one.
        $group = '';
        $into = self::SHEET;
        $pending = '';
        $open = false;
        $named = '';
        // The page of the positioned elements, until content of the page comes before them.
        $page = self::pageBox([$blocks[''] ?? '', $blocks[':first'] ?? ''], $width, $height);

        foreach (self::topLevel($body) as [$kind, $chunk, $tag, $attributes]) {
            if ($kind !== 'element') {
                // Whitespace and comments stay where they are; a group of positioned elements spans them.
                if ($group !== '' && ($kind === 'comment' || trim($chunk) === '')) {
                    $pending .= $chunk;
                } else {
                    $out .= self::group($into, $group, $width, $height).$pending.$chunk;
                    $group = $pending = '';
                }
                $page = $kind === 'text' && trim($chunk) !== '' ? null : $page;

                continue;
            }
            if (in_array($tag, self::BREAKS, true)) {
                $out .= self::group($into, $group, $width, $height).$pending;
                $group = $pending = '';
                $selector = trim($attributes['page-selector'] ?? '');
                if ($selector === '') {
                    // The page selector before applies to the following pages until another one is given.
                    $out .= '<div class="mpdf-page" style="break-before: page;"></div>';
                    $page = self::pageBox([$blocks[''] ?? '', $named === '' ? '' : $blocks[strtolower($named)] ?? ''], $width, $height);

                    continue;
                }
                $out .= ($open ? '</div>' : '').'<div class="mpdf-page" style="break-before: page; page: '.self::ident($selector).';">';
                $open = true;
                $named = $selector;
                // The first page of a named page, as namedFirstPages() styles it.
                $page = self::pageBox([$blocks[''] ?? '', self::firstPageBlock($blocks[':first'] ?? ''), $blocks[strtolower($selector)] ?? ''], $width, $height);

                continue;
            }
            $position = self::property('position', $tag, $attributes, $rules);
            if ($position !== 'absolute' && $position !== 'fixed') {
                $out .= self::group($into, $group, $width, $height).$pending.$chunk;
                $group = $pending = '';
                $page = in_array($tag, self::BOXLESS, true) ? $page : null;

                continue;
            }
            $area = $page === null ? null : self::inPageArea($tag, $attributes, $rules, $page);
            $target = $area === null ? self::SHEET : self::AREA;
            if ($group !== '' && $target !== $into) {
                $out .= self::group($into, $group, $width, $height).$pending;
                $group = $pending = '';
            }
            $into = $target;
            // mPDF places fixed elements like absolute ones, on the page they are on.
            $declarations = ($position === 'fixed' ? 'position: absolute; ' : '')
                .($area === null ? '' : 'top: '.self::number($area[0]).'mm !important; left: '.self::number($area[1]).'mm !important;');
            $group .= $pending.($declarations === '' ? $chunk : self::withStyle($chunk, trim($declarations)));
            $pending = '';
        }
        $out .= self::group($into, $group, $width, $height).$pending.($open ? '</div>' : '');
        // A page break inside another element only breaks the page.
        $out = (string) preg_replace('/<(?:pagebreak|formfeed|newpage)\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>(?:\s*<\/(?:pagebreak|formfeed|newpage)\s*>)?/i', '<div class="mpdf-page" style="break-before: page;"></div>', $out);

        return substr($html, 0, $start).$out.substr($html, $end);
    }

    /**
     * Positioned elements in the page area ({@see AREA}) or in a layer over the sheet ({@see SHEET}).
     */
    private static function group(string $into, string $elements, float $width, float $height): string
    {
        if ($elements === '') {
            return '';
        }

        // A block of no height at the top of the page area (a block formatting context, which
        // the margins of the content after it do not collapse through) holds them.
        return $into === self::AREA
            ? '<div class="'.self::AREA.'" style="position: relative; display: flow-root; height: 0;">'.$elements.'</div>'
            : self::layer($elements, $width, $height);
    }

    /**
     * Positioned elements in a layer the size of the sheet, shown in the page's top left corner
     * box: on the page where the layer is, and (by the empty element after it) on no other.
     */
    private static function layer(string $elements, float $width, float $height): string
    {
        return '<div class="'.self::SHEET.'" style="position: running('.self::SHEET.');"><div style="position: relative; width: '.$width.'mm; height: '.$height.'mm;">'
            .$elements.'</div></div><div style="position: running('.self::SHEET.');"></div>';
    }

    private static function number(float $value): string
    {
        $number = rtrim(rtrim(sprintf('%.3F', $value), '0'), '.');

        return $number === '-0' ? '0' : $number;
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
     * The value of a property of a top-level element (in lower case), from its style attribute
     * or the rules of the document's stylesheets; null where none sets it.
     *
     * @param  array<string, string>  $attributes
     * @param  array<string, list<array{list<string>, string, array{int, int, int}, bool}>>  $rules
     */
    private static function property(string $property, string $tag, array $attributes, array $rules): ?string
    {
        $value = null;
        if (preg_match_all('/(?:^|;)\s*'.$property.'\s*:\s*([^;]*?)\s*(?:!\s*important\s*)?(?=;|$)/i', $attributes['style'] ?? '', $inline, PREG_SET_ORDER) > 0) {
            $value = strtolower($inline[count($inline) - 1][1]);
        }

        $classes = preg_split('/\s+/', trim($attributes['class'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        $classes = is_array($classes) ? $classes : [];
        $winner = null;
        foreach ($rules[$property] ?? [] as $order => [$compound, $ruleValue, $specificity, $important]) {
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

        return $value;
    }

    /**
     * Top and left of a positioned element from the edges of the page area of its page, where
     * its top and left are absolute lengths and its box lies in the page area (as far as its
     * width and height are absolute lengths, and without right and bottom); null otherwise.
     *
     * @param  array<string, string>  $attributes
     * @param  array<string, list<array{list<string>, string, array{int, int, int}, bool}>>  $rules
     * @param  array{?float, ?float, ?float, ?float, ?float, ?float}  $page  see {@see pageBox()}
     * @return array{float, float}|null
     */
    private static function inPageArea(string $tag, array $attributes, array $rules, array $page): ?array
    {
        [$width, $height, $marginTop, $marginRight, $marginBottom, $marginLeft] = $page;
        $value = static fn (string $property): ?string => self::property($property, $tag, $attributes, $rules);
        // No width or height: the box fits its content, which is not measured here.
        $size = static function (string $property) use ($value): ?float {
            $length = $value($property);

            return $length === null || $length === 'auto' ? 0.0 : self::millimetres($length);
        };
        $top = self::millimetres((string) $value('top'));
        $left = self::millimetres((string) $value('left'));
        $boxWidth = $size('width');
        $boxHeight = $size('height');
        if ($top === null || $left === null || $boxWidth === null || $boxHeight === null || ! in_array($value('right'), [null, 'auto'], true) || ! in_array($value('bottom'), [null, 'auto'], true)
            || $width === null || $height === null || $marginTop === null || $marginRight === null || $marginBottom === null || $marginLeft === null) {
            return null;
        }

        // Rounding of the lengths: a box that ends at the edge of the page area is in it.
        $tolerance = 0.01;
        $inside = $top >= $marginTop - $tolerance && $left >= $marginLeft - $tolerance
            && $left + $boxWidth <= $width - $marginRight + $tolerance
            && $top + $boxHeight <= $height - $marginBottom + $tolerance
            && $top < $height - $marginBottom;

        return $inside ? [$top - $marginTop, $left - $marginLeft] : null;
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
     * The rules of the document's stylesheets that set position or the offsets and size of a
     * positioned element, by property: for each selector the parts of its last compound
     * selector, the value, the specificity and whether it is important. Only what mPDF applies:
     * top-level rules and those of @media print and all.
     *
     * @return array<string, list<array{list<string>, string, array{int, int, int}, bool}>>
     */
    private static function positionRules(string $html): array
    {
        $rules = [];
        foreach (self::styleBlocks($html) as [$css]) {
            foreach (self::rules(self::withoutComments($css)) as [$selectors, $declarations]) {
                foreach (self::declarations($declarations) as [$name, $value]) {
                    if (! in_array($name, self::POSITION_PROPERTIES, true)) {
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
                        $rules[$name][] = [$parts[0], $value, [
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

    /**
     * Size and margins in millimetres of a page that the @page blocks style, in cascade order
     * after mPDF's page defaults (see {@see baseStyles()}); null for a value that is not an
     * absolute length.
     *
     * @param  list<string>  $blocks
     * @return array{?float, ?float, ?float, ?float, ?float, ?float} width, height, and the margins top, right, bottom and left
     */
    private static function pageBox(array $blocks, float $width, float $height): array
    {
        $box = [$width, $height, 20.0, 15.0, 25.0, 20.0];
        $sides = ['margin-top' => 2, 'margin-right' => 3, 'margin-bottom' => 4, 'margin-left' => 5];
        foreach ($blocks as $block) {
            foreach (self::declarations($block) as [$name, $value]) {
                $value = trim((string) preg_replace('/!\s*important\s*$/i', '', $value), " \t\n\r\0\x0B\"'");
                if ($name === 'margin') {
                    [$box[2], $box[3], $box[4], $box[5]] = array_map(self::millimetres(...), self::sides($value));
                } elseif (isset($sides[$name])) {
                    $box[$sides[$name]] = self::millimetres($value);
                } elseif ($name === 'size' || $name === 'sheet-size') {
                    [$box[0], $box[1]] = self::size($value);
                }
            }
        }

        return $box;
    }

    /**
     * Width and height in millimetres of a value of size or sheet-size; nulls for one that is
     * neither two absolute lengths nor an mPDF page format (with landscape or portrait).
     *
     * @return array{?float, ?float}
     */
    private static function size(string $value): array
    {
        $parts = preg_split('/\s+/', strtolower(trim($value)), -1, PREG_SPLIT_NO_EMPTY);
        $parts = is_array($parts) ? $parts : [];
        if (count($parts) === 2 && self::millimetres($parts[0]) !== null && self::millimetres($parts[1]) !== null) {
            return [self::millimetres($parts[0]), self::millimetres($parts[1])];
        }
        $orientation = array_values(array_intersect($parts, ['landscape', 'portrait']));
        $name = implode(' ', array_diff($parts, ['landscape', 'portrait']));
        try {
            [$width, $height] = self::paperSize($name);
        } catch (PdfException) {
            return [null, null];
        }
        $landscape = ($orientation[0] ?? null) === 'landscape';

        return ($orientation === [] || $landscape === ($width > $height)) ? [$width, $height] : [$height, $width];
    }

    /**
     * An absolute length (mm, cm, Q, in, pt, pc, px or 0) in millimetres; null for anything else.
     */
    private static function millimetres(string $value): ?float
    {
        if (preg_match('/^(-?(?:\d+\.?\d*|\.\d+))(mm|cm|q|in|pt|pc|px)?$/i', trim($value), $m) !== 1 || (($m[2] ?? '') === '' && (float) $m[1] !== 0.0)) {
            return null;
        }

        return (float) $m[1] * match (strtolower($m[2] ?? '')) {
            'cm' => 10.0,
            'q' => 0.25,
            'in' => 25.4,
            'pt' => 25.4 / 72,
            'pc' => 25.4 / 6,
            'px' => 25.4 / 96,
            default => 1.0,
        };
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
