<?php

declare(strict_types=1);

use Quittio\Core\Pdf\Renderers\CarryOver;
use Quittio\Core\Pdf\Renderers\MpdfAnchors;
use Quittio\Core\Quittio;
use Quittio\Core\Testing\Scenarios;

function carryOverHtml(): string
{
    return '<html><body><p>Intro</p>'
        .'<table class="lines"><thead><tr><th>Pos.</th><th>Amount</th></tr></thead><tbody>'
        .'<!--quittio:rows-->'
        .'<tr class="line" data-carry-over="10,00 €"><td>1</td><td>10,00 €</td></tr>'
        .'<tr class="sub"><td>1.1</td><td>Part</td></tr>'
        .'<tr class="line" data-carry-over="30,00 €"><td>2</td><td><table><tr><td>nested</td></tr></table>20,00 €</td></tr>'
        .'<!--/quittio:rows-->'
        .'</tbody></table>'
        .'<!--quittio:carry-out <tr class="carry-out"><td>Continued on page {page}</td><td>{amount}</td></tr> -->'
        .'<!--quittio:carry-in <tr class="carry-in"><td>Brought forward from page {page}</td><td>{amount}</td></tr> -->'
        .'<p>Totals</p></body></html>';
}

/**
 * Text of each page of a PDF (pdftotext -layout).
 *
 * @return list<string>
 */
function pdfPages(string $pdf): array
{
    $file = tempnam(sys_get_temp_dir(), 'quittio-carry-');
    file_put_contents((string) $file, $pdf);
    exec(sprintf('pdftotext -layout %s -', escapeshellarg((string) $file)), $lines);
    unlink((string) $file);

    return explode("\f", implode("\n", $lines));
}

function germanAmount(string $value): string
{
    return str_replace(['.', ','], ['', '.'], $value);
}

it('finds the marked rows of the line table, also with nested tables', function () {
    $carry = CarryOver::parse(carryOverHtml());
    $anchored = (string) $carry?->anchored();

    expect($carry)->not->toBeNull()
        ->and(substr_count($anchored, '<a name="'.CarryOver::ANCHOR))->toBe(3)
        ->and($anchored)->toContain('<td><a name="quittio-row-0"></a>1</td>')
        ->and($anchored)->toContain('<td><a name="quittio-row-2"></a>2</td><td><table><tr><td>nested</td></tr></table>20,00 €</td></tr>')
        ->and($anchored)->toContain('<p>Intro</p>')->toContain('<p>Totals</p>')
        // The carry rows are templates, not content.
        ->and($anchored)->not->toContain('carry-out')->not->toContain('carry-in')
        ->and(CarryOver::parse('<table><tbody><tr><td>1</td></tr></tbody></table>'))->toBeNull();
});

it('ends a page only after a row with a running total', function () {
    $carry = CarryOver::parse(carryOverHtml());

    expect($carry?->breaks([0 => 1, 1 => 1, 2 => 1]))->toBe([])
        ->and($carry?->breaks([0 => 1, 1 => 2, 2 => 2]))->toBe([0 => 1])
        // A sub line carries the running total of its line.
        ->and($carry?->breaks([0 => 1, 1 => 1, 2 => 2]))->toBe([1 => 1])
        ->and($carry?->breaks([0 => 1, 1 => 1, 2 => 2], from: 2))->toBe([])
        ->and(CarryOver::pages(['quittio-row-1' => 2, 'other' => 1, 'quittio-row-0' => 1]))->toBe([0 => 1, 1 => 2]);
});

it('reserves the carry rows for the layout and inserts them at the page breaks', function () {
    $carry = CarryOver::parse(carryOverHtml());
    $head = '<table class="lines"><thead><tr><th>Pos.</th><th>Amount</th></tr></thead><tbody>';

    // First page: only the carry-out row is reserved, with the longest amount.
    expect((string) $carry?->firstPage())->toContain('</tr></thead><tfoot><tr class="carry-out"><td>Continued on page 999</td><td>10,00 €</td></tr></tfoot><tbody>')
        // Following pages: the first page ends after row 0, then both rows repeat.
        ->and((string) $carry?->followingPages(0, 1))->toContain('<tr class="carry-out"><td>Continued on page 2</td><td>10,00 €</td></tr></tbody></table><pagebreak /><table class="lines"><thead><tr><th>Pos.</th><th>Amount</th></tr><tr class="carry-in"><td>Brought forward from page 999</td>')
        ->and((string) $carry?->paginated([1 => 1]))->toContain(
            '<tr class="sub"><td>1.1</td><td>Part</td></tr><tr class="carry-out"><td>Continued on page 2</td><td>10,00 €</td></tr></tbody></table><pagebreak />'
            .$head.'<tr class="carry-in"><td>Brought forward from page 1</td><td>10,00 €</td></tr><tr class="line" data-carry-over="30,00 €">'
        )
        ->and((string) $carry?->paginated([]))->not->toContain('pagebreak')->not->toContain('quittio-row-');
});

it('carries the sum of the lines over to the next page', function () {
    $pdf = Quittio::create()->make(Scenarios::make('many-lines'))->format('pdf')->locale('de')->withoutValidation()->render()->pdf();
    $pages = pdfPages($pdf);

    expect(count($pages))->toBeGreaterThan(2);
    $sum = '0';
    foreach (explode("\n", $pages[0]) as $line) {
        if (preg_match('/^\s*\d+\s+Artikel .*?([\d.]+,\d{2}) €\s*$/u', $line, $m) === 1) {
            $sum = bcadd($sum, germanAmount($m[1]), 2);
        }
    }

    expect(preg_match('/Fortsetzung auf Seite 2\s+Übertrag\s+([\d.]+,\d{2}) €/u', $pages[0], $out))->toBe(1)
        ->and(preg_match('/Übertrag von Seite 1\s+([\d.]+,\d{2}) €/u', $pages[1], $in))->toBe(1)
        ->and(germanAmount($out[1]))->toBe($sum)
        ->and($in[1])->toBe($out[1])
        // Every following page starts with the table head and the carry-over of the previous one.
        ->and($pages[2])->toContain('Übertrag von Seite 2')
        ->and($pages[1])->toContain('Fortsetzung auf Seite 3')
        // The last page has no carry-over to a next page.
        ->and(end($pages))->not->toContain('Fortsetzung auf Seite');
})->skip(fn () => trim((string) shell_exec('command -v pdftotext')) === '' || ! function_exists('bcadd'), 'pdftotext or bcmath is not available.');

it('renders a single page without carry-over', function () {
    $pdf = Quittio::create()->make(Scenarios::make('simple'))->format('pdf')->locale('de')->withoutValidation()->render()->pdf();
    $pages = array_filter(pdfPages($pdf), static fn (string $page): bool => trim($page) !== '');

    expect($pages)->toHaveCount(1)
        ->and(implode('', $pages))->not->toContain('Übertrag');
})->skip(fn () => trim((string) shell_exec('command -v pdftotext')) === '', 'pdftotext is not available.');

it('reads the pages of named anchors from mPDF', function () {
    $mpdf = new Mpdf\Mpdf(['tempDir' => sys_get_temp_dir().'/quittio-mpdf-anchors']);
    $mpdf->WriteHTML('<a name="first"></a><p>One</p><pagebreak /><a name="second"></a><p>Two</p>');

    expect(MpdfAnchors::pages($mpdf))->toMatchArray(['first' => 1, 'second' => 2]);
});
