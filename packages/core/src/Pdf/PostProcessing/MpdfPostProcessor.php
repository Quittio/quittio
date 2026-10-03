<?php

declare(strict_types=1);

namespace Quittio\Core\Pdf\PostProcessing;

use Mpdf\Mpdf;
use Mpdf\MpdfException;
use Mpdf\Output\Destination;
use Quittio\Core\Pdf\EmbeddedXml;
use Quittio\Core\Pdf\PdfException;
use Quittio\Core\Pdf\Xmp\FacturXXmp;
use setasign\Fpdi\PdfParser\StreamReader;
use Throwable;

/**
 * Imports the pages of an existing PDF (FPDI via mPDF), embeds the XML and writes
 * PDF/A-3 metadata and output intent (specification 11.13).
 *
 * The result is PDF/A compliant only if the source PDF is: all fonts embedded, no
 * encryption, no JavaScript. Check the result with veraPDF, ideally in CI.
 */
final class MpdfPostProcessor implements PdfPostProcessor
{
    public function __construct(
        private readonly ?string $tempDir = null,
    ) {}

    public function process(string $pdf, ?EmbeddedXml $embedded, string $title, ?string $author = null): string
    {
        try {
            $mpdf = new Mpdf([
                'mode' => 'utf-8',
                'tempDir' => $this->tempDir ?? sys_get_temp_dir().'/quittio-mpdf',
                'margin_left' => 0,
                'margin_right' => 0,
                'margin_top' => 0,
                'margin_bottom' => 0,
            ]);
            $mpdf->PDFA = true;
            $mpdf->PDFAversion = '3-B';
            $mpdf->PDFAauto = false;
            $mpdf->SetTitle($title);
            $mpdf->SetCreator('Quittio');
            if ($author !== null) {
                $mpdf->SetAuthor($author);
            }

            $pages = $mpdf->setSourceFile(StreamReader::createByString($pdf));
            for ($page = 1; $page <= $pages; $page++) {
                $template = $mpdf->importPage($page);
                $size = $mpdf->getTemplateSize($template);
                $width = is_array($size) && is_numeric($size['width'] ?? null) ? (float) $size['width'] : 210.0;
                $height = is_array($size) && is_numeric($size['height'] ?? null) ? (float) $size['height'] : 297.0;
                $mpdf->AddPageByArray([
                    'orientation' => $width > $height ? 'L' : 'P',
                    'sheet-size' => [$width, $height],
                ]);
                $mpdf->useTemplate($template);
            }

            if ($embedded !== null) {
                $mpdf->SetAssociatedFiles([[
                    'name' => $embedded->filename,
                    'mime' => 'text/xml',
                    'description' => $embedded->description,
                    'AFRelationship' => $embedded->relationship,
                    'content' => $embedded->content,
                ]]);
                $mpdf->SetAdditionalXmpRdf(FacturXXmp::forEmbedded($embedded));
            }

            $output = $mpdf->Output('', Destination::STRING_RETURN);
            if (! is_string($output)) {
                throw new PdfException('mPDF returned no PDF.');
            }

            return $output;
        } catch (MpdfException $e) {
            throw new PdfException('PDF post processing failed: '.$e->getMessage(), 0, $e);
        } catch (Throwable $e) {
            throw new PdfException('The PDF could not be imported: '.$e->getMessage(), 0, $e);
        }
    }
}
