<?php

declare(strict_types=1);

use Quittio\Core\Pdf\PdfException;
use Quittio\Core\Pdf\Renderers\MpdfMarkupConverter;

function mpdfMarkup(string $body, string $css = ''): string
{
    return '<html><head><style>'.$css.'</style></head><body>'.$body.'</body></html>';
}

/**
 * The content of <body> of converted HTML.
 */
function convertedBody(string $html, string $paper = 'A4', bool $watermarks = true): string
{
    $converted = (new MpdfMarkupConverter($paper, $watermarks))->convert($html);
    preg_match('/<body>(.*)<\/body>/s', $converted, $m);

    return $m[1] ?? '';
}

it('starts the document in standards mode with the charset and mPDF defaults before the template styles', function () {
    $html = (new MpdfMarkupConverter)->convert('<html><head><style>body { color: red; }</style></head><body><p>Text</p></body></html>');

    expect($html)->toStartWith("<!DOCTYPE html>\n<html><head>\n<meta charset=\"utf-8\">")
        ->and(strpos($html, '@page { size: 210mm 297mm; margin: 20mm 15mm 25mm 20mm;'))->toBeLessThan(strpos($html, 'body { color: red; }'))
        ->and($html)->toContain('p { margin: 1.12em 0; }')
        ->and($html)->toContain('<body><p>Text</p></body>');
});

it('replaces a doctype of the template and adds a head to fragments', function () {
    expect((new MpdfMarkupConverter)->convert('<!DOCTYPE html PUBLIC "-//W3C//DTD HTML 4.01//EN"><html><body>x</body></html>'))
        ->toStartWith("<!DOCTYPE html>\n<html>\n<head>\n<meta charset=\"utf-8\">")
        ->and((new MpdfMarkupConverter)->convert('<p>Fragment</p>'))->toStartWith("<!DOCTYPE html>\n<head>\n")
        ->toEndWith("</head>\n<p>Fragment</p>");
});

it('sets the page size of the mPDF format', function (string $paper, string $size) {
    expect((new MpdfMarkupConverter($paper))->convert('<p>x</p>'))->toContain('@page { size: '.$size.';');
})->with([
    'A4' => ['A4', '210mm 297mm'],
    'Letter' => ['Letter', '215.9mm 279.4mm'],
    'A4 landscape' => ['A4-L', '297mm 210mm'],
    'A5, lower case' => ['a5', '148mm 210mm'],
]);

it('refuses an unknown paper size', function () {
    (new MpdfMarkupConverter('Napkin'))->convert('<p>x</p>');
})->throws(PdfException::class, 'Unknown paper size "Napkin".');

it('turns page headers and footers into running elements at the start of the body', function () {
    $body = convertedBody(mpdfMarkup(
        '<p>Content</p><htmlpageheader name="main"><b>Head</b></htmlpageheader><htmlpagefooter name="foot">Page {PAGENO} of {nbpg}</htmlpagefooter>',
    ));

    expect($body)->toStartWith('<div class="mpdf-header" style="position: running(mpdf-header-main);"><b>Head</b></div>'."\n"
        .'<div class="mpdf-footer" style="position: running(mpdf-footer-foot);">Page <span class="mpdf-pageno"></span> of <span class="mpdf-nbpg"></span></div>')
        ->and($body)->toEndWith('<p>Content</p>')
        ->and($body)->not->toContain('htmlpage');
});

it('turns the page properties of mPDF into margin boxes', function () {
    $html = (new MpdfMarkupConverter)->convert(mpdfMarkup('', '/* Pages */ @page { margin: 40mm 19mm; margin-header: 14.6mm; margin-footer: 5.5mm; header: html_main; footer: html_foot; } '
        .'@page :first { header: html_first; margin-top: 15mm } @page slip { footer: _blank; } @page wide { sheet-size: A4-L; odd-footer-name: html_wide; even-footer-name: html_even }'));

    expect($html)->toContain('@page { margin: 40mm 19mm;'."\n".'      @top-center { padding-top: 14.6mm; content: element(mpdf-header-main); }'
        ."\n".'      @bottom-center { padding-bottom: 5.5mm; content: element(mpdf-footer-foot); } }')
        ->and($html)->toContain('@page :first { margin-top: 15mm;'."\n".'      @top-center { content: element(mpdf-header-first); } }')
        ->and($html)->toContain('@page slip { '."\n".'      @bottom-center { content: none; } }')
        ->and($html)->toContain('@page wide { size: 297mm 210mm;'."\n".'      @bottom-center { content: element(mpdf-footer-wide); } }')
        ->and($html)->not->toContain('margin-header')
        ->and($html)->not->toContain('html_');
});

it('keeps the margin boxes of the template and its other page properties', function () {
    $html = (new MpdfMarkupConverter)->convert(mpdfMarkup('', '@page { size: A5; background: #eee; @bottom-right { content: counter(page); } }'));

    expect($html)->toContain('@page { size: A5; background: #eee;'."\n".'      @bottom-right { content: counter(page); } }');
});

it('replaces the page number placeholders only in the text', function () {
    $body = convertedBody(mpdfMarkup('<p title="{PAGENO}">{PAGENO}/{nb}</p><!-- {nbpg} -->'));

    expect($body)->toBe('<p title="{PAGENO}"><span class="mpdf-pageno"></span>/<span class="mpdf-nbpg"></span></p><!-- {nbpg} -->');
});

it('turns page breaks into breaks and page selectors into blocks on named pages', function () {
    $body = convertedBody(mpdfMarkup('<p>One</p><pagebreak /><p>Two</p><pagebreak page-selector="slip" /><p>Three</p><pagebreak /><p>Four</p><pagebreak page-selector="other"><p>Five</p>'));

    expect($body)->toBe('<p>One</p><div class="mpdf-page" style="break-before: page;"></div><p>Two</p>'
        // The page selector applies until another one is given: a page break without one stays in its page group.
        .'<div class="mpdf-page" style="break-before: page; page: slip;"><p>Three</p><div class="mpdf-page" style="break-before: page;"></div><p>Four</p></div>'
        .'<div class="mpdf-page" style="break-before: page; page: other;"><p>Five</p></div>');
});

it('breaks the page for a page break inside another element', function () {
    expect(convertedBody(mpdfMarkup('<div><p>One</p><pagebreak page-selector="slip" /><p>Two</p></div>')))
        ->toBe('<div><p>One</p><div class="mpdf-page" style="break-before: page;"></div><p>Two</p></div>');
});

it('gives the first page of a page selector the rules of the first page, as mPDF does', function () {
    $html = (new MpdfMarkupConverter)->convert(mpdfMarkup(
        '<htmlpageheader name="first"></htmlpageheader><htmlpageheader name="main">Head</htmlpageheader><htmlpagefooter name="foot">Foot</htmlpagefooter>'
        .'<p>One</p><pagebreak page-selector="slip" /><p>Slip</p><pagebreak page-selector="slip" /><p>Second slip</p>',
        '@page { header: html_main; footer: html_foot; } @page :first { margin: 15mm 5mm 30mm; size: A5; header: html_first; @top-left { content: "x"; } } @page slip { footer: none; margin-bottom: 10mm; }',
    ));

    // The rules of :first without size and the left and right margins, then those of the named
    // page again; one rule for every page group of that name.
    expect($html)->toContain("<style>\n@page slip:nth(1 of slip) { margin-top: 15mm; margin-bottom: 30mm; margin-bottom: 10mm;\n"
        ."      @top-left { content: \"x\"; }\n      @top-center { content: element(mpdf-header-first); }\n      @bottom-center { content: none; } }\n</style>\n</head>")
        ->and($html)->toContain('<div class="mpdf-page" style="break-before: page; page: slip;"><p>Slip</p></div><div class="mpdf-page" style="break-before: page; page: slip;"><p>Second slip</p></div>')
        ->and(substr_count($html, ':nth('))->toBe(1)
        ->and($html)->not->toContain('mpdf-page-slip');
});

it('adds no rules for named pages without rules of the first page', function () {
    $html = (new MpdfMarkupConverter)->convert(mpdfMarkup('<p>One</p><pagebreak page-selector="slip" /><p>Slip</p>', '@page slip { footer: none; }'));

    expect($html)->not->toContain(':nth(');
});

it('draws positioned children of the body in a layer over the sheet of their page', function () {
    $body = convertedBody(mpdfMarkup(
        '<div class="mark" style="top: 105mm"></div> <!-- marks --> <div id="address">Address</div><p>Flow</p><div style="position: fixed; top: 5mm">Stamp</div><div class="box"><div class="mark"></div></div>',
        '.mark { position: absolute; left: 0; } #address { position: absolute; top: 45mm; } .box { position: relative; }',
    ));

    $layer = static fn (string $elements): string => '<div class="mpdf-sheet" style="position: running(mpdf-sheet);"><div style="position: relative; width: 210mm; height: 297mm;">'
        .$elements.'</div></div><div style="position: running(mpdf-sheet);"></div>';

    expect($body)->toBe($layer('<div class="mark" style="top: 105mm"></div> <!-- marks --> <div id="address">Address</div>')
        .'<p>Flow</p>'
        // mPDF places fixed elements like absolute ones; nested ones stay where they are.
        .$layer('<div style="position: fixed; top: 5mm; position: absolute;">Stamp</div>')
        .'<div class="box"><div class="mark"></div></div>');
});

it('places positioned elements before the content of a page in its page area, with the margins of that page', function () {
    $body = convertedBody(mpdfMarkup(
        '<watermarktext content="X" /><div class="mark" style="top: 105mm"></div> <div class="address">Address</div><p>Flow</p>'
        .'<pagebreak page-selector="slip" /><div class="slip" style="top: 192mm; left: 5mm; width: 200mm; height: 105mm">Slip</div>'
        .'<pagebreak /><div class="slip" style="top: 50mm; left: 30mm; position: fixed">Next</div>',
        '@page { margin: 40mm 19mm 37mm 20mm; } @page :first { margin-top: 15mm; margin-left: 30mm; } @page slip { margin: 0; margin-top: 1cm; } '
        .'.mark { position: absolute; left: 0; width: 4.2mm; } .address { position: absolute; top: 45mm; left: 30mm; width: 85mm; height: 45mm; } .slip { position: absolute; }',
    ));

    $area = static fn (string $elements): string => '<div class="mpdf-area" style="position: relative; display: flow-root; height: 0;">'.$elements.'</div>';

    // The fold mark lies in the page margin: in the layer over the sheet. The address from the
    // edges of the page area of the first page (its top and left margins come from :first).
    expect($body)->toContain('<div class="mpdf-sheet" style="position: running(mpdf-sheet);"><div style="position: relative; width: 210mm; height: 297mm;"><div class="mark" style="top: 105mm"></div></div></div><div style="position: running(mpdf-sheet);"></div> '
        .$area('<div class="address" style="top: 30mm !important; left: 0mm !important;">Address</div>').'<p>Flow</p>')
        // The first page of a named page: :first without its left margin, then the named page.
        ->and($body)->toContain('page: slip;">'.$area('<div class="slip" style="top: 192mm; left: 5mm; width: 200mm; height: 105mm; top: 182mm !important; left: 5mm !important;">Slip</div>'))
        // The following pages of the named page; fixed like absolute.
        ->and($body)->toContain('<div class="mpdf-page" style="break-before: page;"></div>'
            .$area('<div class="slip" style="top: 50mm; left: 30mm; position: fixed; position: absolute; top: 40mm !important; left: 30mm !important;">Next</div>'));
});

it('draws positioned elements in the layer over the sheet unless they lie in the page area of a known page', function (string $style, string $before = '') {
    $body = convertedBody(mpdfMarkup($before.'<div style="position: absolute; '.$style.'">X</div>', '@page { margin: 20mm; }'));

    expect($body)->toContain('mpdf-sheet')
        ->and($body)->not->toContain('mpdf-area');
})->with([
    'in the left margin' => ['top: 30mm; left: 10mm;'],
    'in the top margin' => ['top: 10mm; left: 30mm;'],
    'reaching into the right margin' => ['top: 30mm; left: 30mm; width: 170mm;'],
    'reaching into the bottom margin' => ['top: 250mm; left: 30mm; height: 40mm;'],
    'below the page area' => ['top: 280mm; left: 30mm;'],
    'without left' => ['top: 30mm;'],
    'with right' => ['top: 30mm; left: 30mm; right: 30mm;'],
    'with bottom' => ['top: 30mm; left: 30mm; bottom: 30mm;'],
    'in relative units' => ['top: 10%; left: 30mm;'],
    'with a width in relative units' => ['top: 30mm; left: 30mm; width: 50%;'],
    'after content of the page' => ['top: 30mm; left: 30mm;', '<p>Flow</p>'],
    'on a page of unknown size' => ['top: 30mm; left: 30mm;', '<pagebreak page-selector="wide" /><style>@page wide { size: auto; }</style>'],
]);

it('reads the page size and margins of the page in absolute units', function () {
    $body = convertedBody(mpdfMarkup(
        '<pagebreak page-selector="wide" /><div style="position: absolute; top: 1in; left: 72pt; width: 240mm">Wide</div>',
        '@page wide { sheet-size: A4-L; margin: 0.5in 96px 2cm 6pc; }',
    ));

    expect($body)->toContain('top: 12.7mm !important; left: 0mm !important;');
});

it('takes the position from the winning rule', function () {
    $body = convertedBody(mpdfMarkup(
        '<div class="a b">1</div><div class="a" style="position: static">2</div><div class="c">3</div><span class="d">4</span><div class="e">5</div>',
        '.a { position: absolute } .a.b { position: relative } div.c { position: absolute } .c { position: static } span.d { position: fixed } '
        .'@media screen { .e { position: absolute } } .e::before { position: absolute }',
    ));

    expect($body)->toBe('<div class="a b">1</div><div class="a" style="position: static">2</div>'
        .'<div class="mpdf-sheet" style="position: running(mpdf-sheet);"><div style="position: relative; width: 210mm; height: 297mm;"><div class="c">3</div><span class="d" style="position: absolute;">4</span></div></div><div style="position: running(mpdf-sheet);"></div>'
        .'<div class="e">5</div>');
});

it('finds the top-level elements also with unclosed paragraphs, raw text and nested tables', function () {
    $body = convertedBody(mpdfMarkup(
        '<p>Open paragraph<div class="abs">A</div><script>var x = "</div><div class=abs>";</script><table><tr><td><table><tr><td>x</td></tr></table></td></tr></table><div class="abs">B</div>',
        '.abs { position: absolute }',
    ));

    expect($body)->toBe('<p>Open paragraph'
        .'<div class="mpdf-sheet" style="position: running(mpdf-sheet);"><div style="position: relative; width: 210mm; height: 297mm;"><div class="abs">A</div></div></div><div style="position: running(mpdf-sheet);"></div>'
        .'<script>var x = "</div><div class=abs>";</script><table><tr><td><table><tr><td>x</td></tr></table></td></tr></table>'
        .'<div class="mpdf-sheet" style="position: running(mpdf-sheet);"><div style="position: relative; width: 210mm; height: 297mm;"><div class="abs">B</div></div></div><div style="position: running(mpdf-sheet);"></div>');
});

it('sizes the layer as the sheet', function () {
    expect(convertedBody(mpdfMarkup('<div style="position:absolute">x</div>'), 'Letter'))->toContain('width: 215.9mm; height: 279.4mm;');
});

it('prints the watermark of mPDF instead of the browser watermark of the template', function () {
    $html = mpdfMarkup('<watermarktext content="ENTWURF &amp; Co" alpha="0.1" /><p>Text</p>', 'body::before { content: "ENTWURF"; position: fixed; }');
    $converted = (new MpdfMarkupConverter)->convert($html);

    expect($converted)->toContain('<div class="mpdf-watermark" style="position: fixed; top: 50%;')
        ->and($converted)->toContain('opacity: 0.1; z-index: 1000;">ENTWURF &amp; Co</div><p>Text</p>')
        ->and($converted)->toContain('body::before, body::after { content: none !important; }')
        ->and($converted)->not->toContain('<watermarktext');
});

it('prints no watermark when watermarks are off, as mPDF in PDF/A', function () {
    $converted = (new MpdfMarkupConverter(watermarks: false))->convert(mpdfMarkup('<watermarktext content="ENTWURF" /><p>Text</p>'));

    expect($converted)->not->toContain('mpdf-watermark')
        ->and($converted)->not->toContain('ENTWURF')
        ->and($converted)->toContain('body::before, body::after { content: none !important; }');
});

it('keeps the browser watermark of a template without watermarktext', function () {
    expect((new MpdfMarkupConverter)->convert(mpdfMarkup('<p>Text</p>', 'body::before { content: "X"; }')))->not->toContain('content: none !important');
});

it('puts the carry-over rows into the head and foot of the line table', function () {
    $html = '<html><body><table class="lines" style="width: 100%"><thead><tr><th>Pos.</th><th>Amount</th></tr></thead><tbody>'
        .'<!--quittio:rows--><tr class="line" data-carry-over="10,00 €"><td>1</td><td>10,00 €</td></tr><!--/quittio:rows-->'
        .'</tbody></table>'
        .'<!--quittio:carry-out <tr class="carry carry-out"><td>Continued on page {page}</td><td>{amount}</td></tr> -->'
        .'<!--quittio:carry-in <tr class="carry carry-in"><td>Brought forward from page {page}</td><td>{amount}</td></tr> -->'
        .'<p>Totals</p></body></html>';

    $body = convertedBody($html);

    expect($body)->toBe('<table class="lines" style="width: 100%"><thead><tr><th>Pos.</th><th>Amount</th></tr>'
        .'<tr data-mpdf-carry class="carry carry-in"><td>Brought forward from page <span class="mpdf-previous-page"></span></td><td><span class="mpdf-carry-amount"></span></td></tr></thead>'
        .'<tfoot><tr data-mpdf-carry class="carry carry-out"><td>Continued on page <span class="mpdf-next-page"></span></td><td><span class="mpdf-carry-amount"></span></td></tr></tfoot><tbody>'
        .'<!--quittio:rows--><tr class="line" data-carry-over="10,00 €"><td>1</td><td>10,00 €</td></tr><!--/quittio:rows-->'
        .'</tbody></table><p>Totals</p>')
        ->and((new MpdfMarkupConverter)->convert($html))->toContain('tr[data-carry-over] { string-set: mpdf-carry-over attr(data-carry-over); }')
        ->toContain('tr[data-mpdf-carry] { -fagus-repeat: at-break; }');
});

it('leaves a line table without carry-over rows alone', function () {
    $html = '<html><body><table><thead><tr><th>A</th></tr></thead><tbody><!--quittio:rows--><tr><td>1</td></tr><!--/quittio:rows--></tbody></table></body></html>';

    expect(convertedBody($html))->toBe('<table><thead><tr><th>A</th></tr></thead><tbody><!--quittio:rows--><tr><td>1</td></tr><!--/quittio:rows--></tbody></table>');
});
