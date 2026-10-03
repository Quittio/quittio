<?php

declare(strict_types=1);

namespace Quittio\Core\Pdf\Renderers;

use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;
use Mpdf\MpdfException;
use Mpdf\Output\Destination;
use Quittio\Core\Pdf\PdfException;
use Quittio\Core\Pdf\PdfRenderer;
use Quittio\Core\Pdf\RenderRequest;
use Quittio\Core\Pdf\Xmp\FacturXXmp;

/**
 * Default renderer: HTML/CSS to PDF/A-3b with mPDF, in pure PHP (specification 11.3).
 *
 * PDF/A, the embedded XML and the XMP metadata are written in one pass.
 */
final class MpdfRenderer implements PdfRenderer
{
    /**
     * @param  array<string, array<string, string>>  $fonts  additional fonts: family => [R => file, B => file, I => file, BI => file]
     * @param  list<string>  $fontDirectories
     */
    public function __construct(
        private readonly ?string $tempDir = null,
        private readonly array $fonts = [],
        private readonly array $fontDirectories = [],
        private readonly string $defaultFont = 'inter',
        private readonly bool $pdfaAuto = false,
        private readonly bool $simpleTables = true,
    ) {}

    public function supportsEmbedding(): bool
    {
        return true;
    }

    public function render(RenderRequest $request): string
    {
        try {
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

            $mpdf->WriteHTML($request->html);

            $output = $mpdf->Output('', Destination::STRING_RETURN);
            if (! is_string($output)) {
                throw new PdfException('mPDF returned no PDF.');
            }

            return $output;
        } catch (MpdfException $e) {
            throw new PdfException('PDF rendering failed: '.$e->getMessage(), 0, $e);
        }
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
            'inter' => ['R' => 'Inter-Regular.ttf', 'B' => 'Inter-Bold.ttf', 'I' => 'Inter-Italic.ttf', 'BI' => 'Inter-BoldItalic.ttf'],
            ...$this->fonts,
        ];

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => $request->paper,
            'tempDir' => $tempDir,
            'fontDir' => [...$defaultFontDirs, dirname(__DIR__, 3).'/resources/fonts/inter', ...$this->fontDirectories],
            'fontdata' => [...$defaultFontData, ...$fontData],
            'default_font' => $request->font ?? $this->defaultFont,
            'margin_left' => 20,
            'margin_right' => 15,
            'margin_top' => 20,
            'margin_bottom' => 25,
            'margin_header' => 8,
            'margin_footer' => 8,
            'simpleTables' => $this->simpleTables,
            'packTableData' => true,
            'useSubstitutions' => false,
        ]);

        if ($request->pdfa) {
            $mpdf->PDFA = true;
            $mpdf->PDFAversion = '3-B';
            $mpdf->PDFAauto = $this->pdfaAuto;
        }

        return $mpdf;
    }
}
