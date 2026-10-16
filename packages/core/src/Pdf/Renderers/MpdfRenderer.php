<?php

declare(strict_types=1);

namespace Quittio\Core\Pdf\Renderers;

use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Container\SimpleContainer;
use Mpdf\Mpdf;
use Mpdf\MpdfException;
use Mpdf\Output\Destination;
use Quittio\Core\Pdf\PdfException;
use Quittio\Core\Pdf\PdfRenderer;
use Quittio\Core\Pdf\RenderRequest;
use Quittio\Core\Pdf\Xmp\FacturXXmp;

/**
 * Default renderer: HTML/CSS to PDF/A-3b with mPDF, in pure PHP.
 *
 * PDF/A, the embedded XML and the XMP metadata are written in one pass.
 */
final class MpdfRenderer implements PdfRenderer
{
    /**
     * @param  array<string, array<string, string>>  $fonts  additional fonts: family => [R => file, B => file, I => file, BI => file]
     * @param  list<string>  $fontDirectories  searched first, before the bundled Geist and Inter and the fonts
     *         of mPDF, so that files with the same name (e.g. a published and edited Inter) replace them
     */
    public function __construct(
        private readonly ?string $tempDir = null,
        private readonly array $fonts = [],
        private readonly array $fontDirectories = [],
        private readonly string $defaultFont = 'geist',
        private readonly bool $pdfaAuto = false,
        // simpleTables drops the borders of table cells (row lines, totals line) and is not faster.
        private readonly bool $simpleTables = false,
    ) {}

    public function supportsEmbedding(): bool
    {
        return true;
    }

    public function render(RenderRequest $request): string
    {
        try {
            // A line table across pages gets a carry-over at each page break: laid out again with
            // the carry rows reserved, then split at the resulting page breaks (see CarryOver).
            $carryOver = CarryOver::parse($request->html);
            $mpdf = $this->write($request, $carryOver?->anchored() ?? $request->html);
            if ($carryOver !== null && $carryOver->breaks(CarryOver::pages(MpdfAnchors::pages($mpdf))) !== []) {
                $first = $carryOver->breaks($this->layout($request, $carryOver->firstPage()));
                $break = array_key_first($first);
                if ($break !== null) {
                    $following = $carryOver->breaks($this->layout($request, $carryOver->followingPages($break, $first[$break])), $break + 1);
                    $mpdf = $this->write($request, $carryOver->paginated([$break => $first[$break]] + $following));
                }
            }

            $output = $mpdf->Output('', Destination::STRING_RETURN);
            if (! is_string($output)) {
                throw new PdfException('mPDF returned no PDF.');
            }

            return $output;
        } catch (MpdfException $e) {
            throw new PdfException('PDF rendering failed: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * A configured mPDF instance with the HTML written, ready for output.
     */
    private function write(RenderRequest $request, string $html): Mpdf
    {
        $mpdf = $this->create($request);

        $mpdf->SetTitle($request->title);
        if ($request->author !== null) {
            $mpdf->SetAuthor($request->author);
        }
        if ($request->subject !== null) {
            $mpdf->SetSubject($request->subject);
        }
        $mpdf->SetCreator('Quittio');

        if ($request->embedded !== null) {
            $mpdf->SetAssociatedFiles([[
                'name' => $request->embedded->filename,
                'mime' => 'text/xml',
                'description' => $request->embedded->description,
                'AFRelationship' => $request->embedded->relationship,
                'content' => $request->embedded->content,
            ]]);
            $mpdf->SetAdditionalXmpRdf(FacturXXmp::forEmbedded($request->embedded));
        }

        $mpdf->WriteHTML($html);

        return $mpdf;
    }

    /**
     * Page of each table row (see CarryOver) when the HTML is laid out.
     *
     * @return array<int, int>
     */
    private function layout(RenderRequest $request, string $html): array
    {
        $mpdf = $this->create($request);
        $mpdf->WriteHTML($html);

        return CarryOver::pages(MpdfAnchors::pages($mpdf));
    }

    private function create(RenderRequest $request): Mpdf
    {
        $defaultConfig = (new ConfigVariables)->getDefaults();
        $defaultFontConfig = (new FontVariables)->getDefaults();
        $defaultFontDirs = is_array($defaultConfig) && is_array($defaultConfig['fontDir'] ?? null) ? array_values(array_filter($defaultConfig['fontDir'], is_string(...))) : [];
        $defaultFontData = is_array($defaultFontConfig) && is_array($defaultFontConfig['fontdata'] ?? null) ? $defaultFontConfig['fontdata'] : [];

        $tempDir = $this->tempDir ?? sys_get_temp_dir().'/quittio-mpdf';
        if (! is_dir($tempDir)) {
            @mkdir($tempDir, 0775, true);
        }

        $fontData = [
            'geist' => ['R' => 'Geist-Regular.ttf', 'B' => 'Geist-Bold.ttf', 'I' => 'Geist-Italic.ttf', 'BI' => 'Geist-BoldItalic.ttf'],
            // mPDF knows only normal and bold: the medium weights of Geist as a family of their own.
            'geistmedium' => ['R' => 'Geist-Medium.ttf', 'B' => 'Geist-SemiBold.ttf'],
            'inter' => ['R' => 'Inter-Regular.ttf', 'B' => 'Inter-Bold.ttf', 'I' => 'Inter-Italic.ttf', 'BI' => 'Inter-BoldItalic.ttf'],
            ...$this->fonts,
        ];

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => $request->paper,
            'tempDir' => $tempDir,
            // The first directory with the file wins: configured directories before the bundled ones.
            'fontDir' => [...$this->fontDirectories, dirname(__DIR__, 3).'/resources/fonts/geist', dirname(__DIR__, 3).'/resources/fonts/inter', ...$defaultFontDirs],
            'fontdata' => [...$defaultFontData, ...$fontData],
            'default_font' => $request->font ?? $this->defaultFont,
            'margin_left' => 20,
            'margin_right' => 15,
            'margin_top' => 20,
            'margin_bottom' => 25,
            'margin_header' => 8,
            'margin_footer' => 8,
            'simpleTables' => $this->simpleTables,
            // packTableData raises "Trying to access array offset on int" warnings for a row
            // above a cell with colspan (line group titles, subtotals), which fail the render
            // where warnings are exceptions (Laravel). Unpacked tables need slightly more memory.
            'packTableData' => false,
            'useSubstitutions' => false,
            // Logos are prepared as local files (LogoCache); remote assets of templates are not redirected.
            'curlFollowLocation' => false,
            // <watermarktext> only sets the text; without this flag mPDF never prints it.
            // PDF/A does not allow the transparency of watermarks.
            'showWatermarkText' => ! $request->pdfa,
            // Defence in depth: mPDF cannot fetch anything over HTTP (see DenyingHttpClient),
            // so no bypass of LogoCache can turn a reference into a server-side request.
        ], new SimpleContainer(['httpClient' => new DenyingHttpClient]));

        // No remote base path: a relative reference must not become a URL from $_SERVER['HTTP_HOST'].
        $mpdf->basepath = '';
        $mpdf->basepathIsLocal = true;

        if ($request->pdfa) {
            $mpdf->PDFA = true;
            $mpdf->PDFAversion = '3-B';
            $mpdf->PDFAauto = $this->pdfaAuto;
        }

        return $mpdf;
    }
}
