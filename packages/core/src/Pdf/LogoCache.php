<?php

declare(strict_types=1);

namespace Quittio\Core\Pdf;

/**
 * Scales raster logos once to the size used in the PDF and caches the result
 * (specification 5.15, 11.11). SVG, remote URLs and data URIs are passed through.
 */
final readonly class LogoCache
{
    public function __construct(
        private string $directory,
        private int $maxWidth = 600,
        private int $maxHeight = 200,
    ) {}

    public function resolve(?string $logo): ?string
    {
        if ($logo === null || $logo === '' || str_starts_with($logo, 'data:') || preg_match('#^https?://#i', $logo) === 1) {
            return $logo;
        }
        if (! is_file($logo) || ! extension_loaded('gd') || str_ends_with(strtolower($logo), '.svg')) {
            return $logo;
        }

        $size = @getimagesize($logo);
        if ($size === false || ($size[0] <= $this->maxWidth && $size[1] <= $this->maxHeight)) {
            return $logo;
        }

        $target = sprintf('%s/logo-%s-%dx%d.png', rtrim($this->directory, '/'), hash('xxh128', $logo.'|'.filemtime($logo).'|'.filesize($logo)), $this->maxWidth, $this->maxHeight);
        if (is_file($target)) {
            return $target;
        }

        $data = file_get_contents($logo);
        $source = $data === false ? false : @imagecreatefromstring($data);
        if ($source === false) {
            return $logo;
        }

        $ratio = min($this->maxWidth / $size[0], $this->maxHeight / $size[1]);
        $width = max(1, (int) round($size[0] * $ratio));
        $height = max(1, (int) round($size[1] * $ratio));
        $scaled = imagecreatetruecolor($width, $height);
        if ($scaled === false) {
            return $logo;
        }
        // Keep transparency of PNG logos.
        imagealphablending($scaled, false);
        imagesavealpha($scaled, true);
        imagecopyresampled($scaled, $source, 0, 0, 0, 0, $width, $height, $size[0], $size[1]);

        if (! is_dir($this->directory)) {
            @mkdir($this->directory, 0775, true);
        }
        // Write to a temporary file first, so that parallel renders never read half a file.
        $temporary = $target.'.'.bin2hex(random_bytes(4));
        if (! imagepng($scaled, $temporary) || ! rename($temporary, $target)) {
            @unlink($temporary);

            return $logo;
        }

        return $target;
    }
}
