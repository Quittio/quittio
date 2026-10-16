<?php

declare(strict_types=1);

namespace Quittio\Core\Pdf\Renderers;

/**
 * Carry-over ("Übertrag") for a line table that continues on the next page.
 *
 * The template marks the rows of its line table with <!--quittio:rows--> and <!--/quittio:rows-->,
 * gives every line row the running total as data-carry-over="…" and provides the rows to insert
 * as comments <!--quittio:carry-out <tr>…</tr>--> (end of a page) and <!--quittio:carry-in
 * <tr>…</tr>--> (start of the next page) with the placeholders {page} and {amount}. Browsers and
 * other renderers ignore these comments.
 *
 * The renderer lays the HTML out with an anchor in every row ({@see self::anchored()}). If the
 * rows land on more than one page, it finds the page breaks with the carry rows reserved: on the
 * first page the carry-out row ({@see self::firstPage()}), on the following pages also the
 * carry-in row ({@see self::followingPages()}). Then it splits the table there ({@see self::paginated()}).
 *
 * @internal
 */
final readonly class CarryOver
{
    /** Name prefix of the row anchors, followed by the row index. */
    public const string ANCHOR = 'quittio-row-';

    /**
     * @param  list<string>  $rows  the table rows (<tr>…</tr>)
     * @param  list<?string>  $carries  the running total after each row, if the row has one
     */
    private function __construct(
        private string $before,
        private string $head,
        private array $rows,
        private array $carries,
        private string $tail,
        private string $after,
        private string $carryOut,
        private string $carryIn,
    ) {}

    /**
     * The marked line table of the HTML, or null if the HTML has none (or no carry rows).
     */
    public static function parse(string $html): ?self
    {
        $out = self::template($html, 'carry-out');
        $in = self::template($html, 'carry-in');
        $start = strpos($html, '<!--quittio:rows-->');
        $end = strpos($html, '<!--/quittio:rows-->');
        if ($out === null || $in === null || $start === false || $end === false || $end < $start) {
            return null;
        }

        $tableStart = strrpos(substr($html, 0, $start), '<table');
        $tableEnd = stripos($html, '</table>', $end);
        $head = $tableStart === false ? '' : substr($html, $tableStart, $start - $tableStart);
        if ($tableStart === false || $tableEnd === false || stripos($head, '</thead>') === false) {
            return null;
        }

        $rows = self::rows(substr($html, $start + strlen('<!--quittio:rows-->'), $end - $start - strlen('<!--quittio:rows-->')));
        if ($rows === []) {
            return null;
        }

        $carries = [];
        $carry = null;
        foreach ($rows as $row) {
            if (preg_match('/^<tr\b[^>]*\sdata-carry-over="([^"]*)"/i', $row, $m) === 1) {
                $carry = $m[1];
            }
            $carries[] = $carry;
        }

        $tableEnd += strlen('</table>');
        $remove = static fn (string $part): string => (string) preg_replace('/<!--quittio:carry-(?:out|in)\s.*?-->/s', '', $part);

        return new self(
            before: $remove(substr($html, 0, $tableStart)),
            head: $head,
            rows: $rows,
            carries: $carries,
            tail: substr($html, $end + strlen('<!--/quittio:rows-->'), $tableEnd - $end - strlen('<!--/quittio:rows-->')),
            after: $remove(substr($html, $tableEnd)),
            carryOut: $out,
            carryIn: $in,
        );
    }

    /**
     * The HTML without carry rows, every row with an anchor named ANCHOR.index.
     */
    public function anchored(): string
    {
        return $this->before.$this->head.implode('', $this->anchoredRows()).$this->tail.$this->after;
    }

    /**
     * Layout of the page the table starts on: the carry-out row reserved in the table foot.
     */
    public function firstPage(): string
    {
        return $this->before.$this->reserve(false).implode('', $this->anchoredRows()).$this->tail.$this->after;
    }

    /**
     * Layout of the following pages, the first page ending after row $break (on page $page): the
     * carry-in row repeats with the table head, the carry-out row with the table foot.
     */
    public function followingPages(int $break, int $page): string
    {
        $rows = $this->anchoredRows();

        $carryOut = self::fill($this->carryOut, (string) ($page + 1), $this->carries[$break] ?? '');

        return $this->before.$this->head.implode('', array_slice($rows, 0, $break + 1)).$carryOut.$this->tail.'<pagebreak />'
            .$this->reserve(true).implode('', array_slice($rows, $break + 1)).$this->tail.$this->after;
    }

    /**
     * The HTML with the table split after the given rows, with the carry rows.
     *
     * @param  array<int, int>  $breaks  index of the last row of a page => that page
     */
    public function paginated(array $breaks): string
    {
        $html = $this->before.$this->head;
        foreach ($this->rows as $i => $row) {
            $html .= $row;
            if (isset($breaks[$i])) {
                $html .= $this->pageBreak($i, $breaks[$i]);
            }
        }

        return $html.$this->tail.$this->after;
    }

    /**
     * The rows after which the table continues on the next page (index => page of the row), from
     * row $from on. A row without running total (before the first line) ends no page with a carry-over.
     *
     * @param  array<int, int>  $pages  page of each row
     * @return array<int, int>
     */
    public function breaks(array $pages, int $from = 0): array
    {
        $breaks = [];
        foreach ($pages as $i => $page) {
            $next = $pages[$i + 1] ?? null;
            if ($i >= $from && $next !== null && $next > $page && ($this->carries[$i] ?? null) !== null) {
                $breaks[$i] = $page;
            }
        }

        return $breaks;
    }

    /**
     * Carry-out row, end of the table, page break, the table head and the carry-in row.
     */
    private function pageBreak(int $row, int $page): string
    {
        $carry = $this->carries[$row] ?? '';

        return self::fill($this->carryOut, (string) ($page + 1), $carry).$this->tail.'<pagebreak />'.$this->head.self::fill($this->carryIn, (string) $page, $carry);
    }

    /**
     * The table head with the carry-out row as table foot (repeated on every page) and, with
     * $carryIn, the carry-in row in the table head. Filled with the longest amount, so that the
     * reserved rows are at least as high as the real ones.
     */
    private function reserve(bool $carryIn): string
    {
        $amount = '';
        foreach ($this->carries as $carry) {
            if ($carry !== null && mb_strlen($carry) > mb_strlen($amount)) {
                $amount = $carry;
            }
        }
        $position = (int) strripos($this->head, '</thead>');

        return substr($this->head, 0, $position).($carryIn ? self::fill($this->carryIn, '999', $amount) : '')
            .'</thead><tfoot>'.self::fill($this->carryOut, '999', $amount).'</tfoot>'.substr($this->head, $position + strlen('</thead>'));
    }

    /**
     * Page of each row from the pages of the anchors (MpdfAnchors::pages()).
     *
     * @param  array<string, int>  $anchorPages  anchor name => page
     * @return array<int, int>
     */
    public static function pages(array $anchorPages): array
    {
        $pages = [];
        foreach ($anchorPages as $name => $page) {
            if (str_starts_with($name, self::ANCHOR)) {
                $pages[(int) substr($name, strlen(self::ANCHOR))] = $page;
            }
        }
        ksort($pages);

        return $pages;
    }

    /**
     * @return list<string>
     */
    private function anchoredRows(): array
    {
        $rows = [];
        foreach ($this->rows as $i => $row) {
            $rows[] = (string) preg_replace('/<td\b[^>]*>/i', '$0<a name="'.self::ANCHOR.$i.'"></a>', $row, 1);
        }

        return $rows;
    }

    private static function template(string $html, string $name): ?string
    {
        return preg_match('/<!--quittio:'.$name.'\s(.*?)-->/s', $html, $m) === 1 ? trim($m[1]) : null;
    }

    private static function fill(string $row, string $page, string $amount): string
    {
        return str_replace(['{page}', '{amount}'], [$page, $amount], $row);
    }

    /**
     * The top-level <tr> elements of a table body (rows of nested tables stay inside their cell).
     *
     * @return list<string>
     */
    private static function rows(string $body): array
    {
        preg_match_all('/<tr\b|<\/tr\s*>/i', $body, $tags, PREG_OFFSET_CAPTURE);
        $rows = [];
        $depth = 0;
        $start = 0;
        foreach ($tags[0] as [$tag, $offset]) {
            if (stripos($tag, '</') !== 0) {
                if ($depth === 0) {
                    $start = $offset;
                }
                $depth++;

                continue;
            }
            $depth--;
            if ($depth === 0) {
                $rows[] = substr($body, $start, $offset + strlen($tag) - $start);
            }
        }

        return $rows;
    }
}
