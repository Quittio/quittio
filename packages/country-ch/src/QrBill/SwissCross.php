<?php

declare(strict_types=1);

namespace Quittio\Country\CH\QrBill;

/**
 * The Swiss cross printed in the middle of the Swiss QR Code (7 × 7 mm, guidelines 6.4.2): a black
 * square with a white border and the white cross, as PNG (PDF/A allows no SVG of mPDF).
 */
final class SwissCross
{
    /** Pixels of the image: 7 mm at about 600 dpi. */
    public const int SIZE = 168;

    private static ?string $dataUri = null;

    /**
     * The cross as PNG data URI; null without GD.
     */
    public static function dataUri(): ?string
    {
        if (self::$dataUri !== null) {
            return self::$dataUri;
        }
        if (! function_exists('imagecreatetruecolor')) {
            return null;
        }

        $size = self::SIZE;
        $image = imagecreatetruecolor($size, $size);
        $white = (int) imagecolorallocate($image, 255, 255, 255);
        $black = (int) imagecolorallocate($image, 0, 0, 0);
        imagefilledrectangle($image, 0, 0, $size - 1, $size - 1, $white);
        // Black square inside a white border of 0.5 mm.
        $border = (int) round($size / 14);
        imagefilledrectangle($image, $border, $border, $size - 1 - $border, $size - 1 - $border, $black);
        // The cross: its arms are one sixth longer than wide, length 10/3 of the width.
        $inner = $size - 2 * $border;
        $length = (int) round($inner * 0.6);
        $width = (int) round($length * 0.3);
        $center = intdiv($size, 2);
        imagefilledrectangle($image, $center - intdiv($width, 2), $center - intdiv($length, 2), $center + intdiv($width, 2) - 1, $center + intdiv($length, 2) - 1, $white);
        imagefilledrectangle($image, $center - intdiv($length, 2), $center - intdiv($width, 2), $center + intdiv($length, 2) - 1, $center + intdiv($width, 2) - 1, $white);

        ob_start();
        imagepng($image, null, 9);
        $png = (string) ob_get_clean();

        return self::$dataUri = 'data:image/png;base64,'.base64_encode($png);
    }
}
