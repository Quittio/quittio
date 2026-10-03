<?php

declare(strict_types=1);

namespace Quittio\Core\Pdf\Qr;

use Mpdf\QrCode\Output\Png;
use Mpdf\QrCode\Output\Svg;
use Mpdf\QrCode\QrCode;

/**
 * Renders QR codes as SVG data URIs for templates (no further dependency besides mpdf/qrcode).
 */
final class QrImage
{
    /**
     * @param  'L'|'M'|'Q'|'H'  $errorCorrection
     */
    public static function svg(string $payload, int $size = 200, string $errorCorrection = 'M'): string
    {
        $code = new QrCode($payload, $errorCorrection);

        return (new Svg)->output($code, $size);
    }

    /**
     * @param  'L'|'M'|'Q'|'H'  $errorCorrection
     */
    public static function png(string $payload, int $size = 400, string $errorCorrection = 'M'): string
    {
        return (new Png)->output(new QrCode($payload, $errorCorrection), $size);
    }

    /**
     * Data URI for templates. PNG is preferred: mPDF turns SVG into form XObjects
     * without resource dictionary, which violates PDF/A (clause 6.2.2).
     *
     * @param  'L'|'M'|'Q'|'H'  $errorCorrection
     */
    public static function dataUri(string $payload, int $size = 400, string $errorCorrection = 'M'): string
    {
        if (function_exists('imagecreatetruecolor')) {
            return 'data:image/png;base64,'.base64_encode(self::png($payload, $size, $errorCorrection));
        }

        return 'data:image/svg+xml;base64,'.base64_encode(self::svg($payload, $size, $errorCorrection));
    }
}
