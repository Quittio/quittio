<?php

declare(strict_types=1);

namespace Quittio\Core\Pdf;

use GdImage;
use Imagick;
use ImagickException;
use ImagickPixel;
use Quittio\Core\Exceptions\ConfigurationException;

/**
 * Prepares logos for the PDF (specification 5.15, 11.11) and caches the result.
 *
 * mPDF never fetches a logo itself: remote images (http and https only) are downloaded by
 * {@see RemoteImageFetcher} from public hosts and cached as local files; other stream
 * wrappers are refused. Logos may be local files (configuration), data URIs or such URLs.
 *
 * Without PDF/A, large raster files are scaled once to the size used in the PDF; SVG must be
 * self-contained. For PDF/A (ZUGFeRD/Factur-X), which allows neither the alpha channel mPDF
 * would write nor mPDF's SVG output (ISO 19005-3, 6.2.2), every logo is re-encoded as RGB PNG
 * on white: raster images are flattened, SVG is rasterised with Imagick.
 */
final readonly class LogoCache
{
    /** Largest logo file that is read (bytes). */
    private const int MAX_BYTES = 10 * 1024 * 1024;

    /** Largest raster image that is decoded (pixels): a small PNG may declare a huge canvas. */
    private const int MAX_PIXELS = 25_000_000;

    public function __construct(
        private string $directory,
        private int $maxWidth = 600,
        private int $maxHeight = 200,
        private bool $pdfa = false,
        private ?RemoteImageFetcher $fetcher = null,
    ) {}

    /**
     * A value for the renderer that can never trigger a server-side request: a local file
     * ({@see realpath()}) for existing files, or a canonical data URI built from bytes this
     * class has decoded and checked itself (for http(s) URLs and data URIs). Null if a remote
     * logo cannot be downloaded. Everything else is refused.
     */
    public function resolve(?string $logo): ?string
    {
        if ($logo === null || $logo === '') {
            return $logo;
        }
        // No surrounding whitespace, backslashes (Path::relativeToAbsolutePath turns them into
        // "/") or a protocol-relative "//host" (mPDF would prefix it with "http:").
        if (trim($logo) !== $logo || str_contains($logo, '\\') || str_starts_with($logo, '//')) {
            throw new ConfigurationException(sprintf('Invalid logo "%s": remove surrounding whitespace, backslashes and a leading "//".', $logo));
        }
        if (self::isRemote($logo)) {
            $data = ($this->fetcher ?? new RemoteImageFetcher(self::MAX_BYTES))->fetch($logo);

            return $data === null ? null : $this->inline($data);
        }
        // "data:" only at the very start, so that no other string reaches mPDF as a data URI.
        if (stripos($logo, 'data:') === 0) {
            return $this->inline($this->decodeDataUri($logo));
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $logo) === 1 || stripos($logo, 'data:') !== false) {
            throw new ConfigurationException(sprintf('Logos must be local files, data URIs or http(s) URLs, "%s" is not allowed.', $logo));
        }

        $real = realpath($logo);
        if ($real === false || ! is_file($real)) {
            throw new ConfigurationException(sprintf('The logo file "%s" does not exist.', $logo));
        }

        return $this->pdfa ? $this->forPdfa($real) : $this->scaled($real);
    }

    /**
     * Decodes a data URI strictly (no lenient base64 like mPDF does), size limited. Only the
     * bytes decoded here are checked and re-encoded, so the lenient decoder never sees an
     * unchecked payload.
     */
    private function decodeDataUri(string $logo): string
    {
        $comma = strpos($logo, ',');
        if ($comma === false) {
            throw new ConfigurationException('The data URI logo is malformed (no comma before the payload).');
        }
        $meta = substr($logo, 5, $comma - 5);
        $payload = substr($logo, $comma + 1);
        $data = stripos($meta, ';base64') !== false ? base64_decode($payload, true) : rawurldecode($payload);
        if (! is_string($data) || $data === '') {
            throw new ConfigurationException('The data URI logo could not be decoded.');
        }
        if (strlen($data) > self::MAX_BYTES) {
            throw new ConfigurationException(sprintf('The logo is larger than %d MB.', self::MAX_BYTES / 1048576));
        }

        return $data;
    }

    /**
     * Downloaded or decoded bytes as a canonical data URI after the checks: SVG is validated
     * (self-contained, no entities) and, for PDF/A, rasterised; raster images are size checked
     * and, for PDF/A, flattened to RGB PNG. Null when the bytes are no usable image.
     */
    private function inline(string $data): ?string
    {
        if (self::isSvg('', $data)) {
            self::assertSelfContained($data);
            if (! $this->pdfa) {
                // The checked SVG, re-encoded so mPDF decodes exactly these bytes.
                return 'data:image/svg+xml;base64,'.base64_encode($data);
            }
            $png = $this->process($this->rasterise($data), flatten: true);

            return $png === null ? null : 'data:image/png;base64,'.base64_encode($png);
        }

        $size = self::imageSize($data);
        if ($size === null) {
            return null;
        }
        self::assertDecodable($size[0], $size[1]);
        if ($this->pdfa) {
            $png = $this->process($data, flatten: true);

            return $png === null ? null : 'data:image/png;base64,'.base64_encode($png);
        }
        if ($size[0] > $this->maxWidth || $size[1] > $this->maxHeight) {
            $png = $this->process($data, flatten: false);
            if ($png !== null) {
                return 'data:image/png;base64,'.base64_encode($png);
            }
        }

        return 'data:'.image_type_to_mime_type($size[2]).';base64,'.base64_encode($data);
    }

    /**
     * Large local raster files scaled to the target size (transparency is kept); a local SVG
     * is checked and its path passed on (mPDF reads it locally, never over the network).
     */
    private function scaled(string $logo): string
    {
        if (self::isSvg($logo, (string) @file_get_contents($logo, false, null, 0, 4096))) {
            self::assertSelfContained((string) @file_get_contents($logo, false, null, 0, self::MAX_BYTES));

            return $logo;
        }
        if (! extension_loaded('gd')) {
            return $logo;
        }

        $size = @getimagesize($logo);
        if ($size === false || ($size[0] <= $this->maxWidth && $size[1] <= $this->maxHeight)) {
            return $logo;
        }
        self::assertDecodable($size[0], $size[1]);

        $target = $this->target($logo.'|'.filemtime($logo).'|'.filesize($logo), 'scaled');
        if (is_file($target)) {
            return $target;
        }

        $data = file_get_contents($logo);
        $source = $data === false ? false : @imagecreatefromstring($data);
        if ($source === false) {
            return $logo;
        }

        $scaled = $this->resample($source, transparent: true);
        if ($scaled === null) {
            return $logo;
        }

        return $this->write($scaled, $target) ? $target : $logo;
    }

    /**
     * A local logo as RGB PNG without alpha channel (SVG rasterised), cached as a file.
     */
    private function forPdfa(string $logo): string
    {
        $data = $this->read($logo);
        if ($data === null) {
            // Unreadable: mPDF reports the missing image as before.
            return $logo;
        }

        $target = $this->target(hash('xxh128', $data), 'pdfa');
        if (is_file($target)) {
            return $target;
        }

        if (self::isSvg($logo, $data)) {
            $data = $this->rasterise($data);
        }
        if (! extension_loaded('gd')) {
            return $logo;
        }

        $size = self::imageSize($data);
        if ($size === null) {
            return $logo;
        }
        self::assertDecodable($size[0], $size[1]);
        $source = @imagecreatefromstring($data);
        if ($source === false) {
            return $logo;
        }
        $flat = $this->resample($source, transparent: false);

        return $flat !== null && $this->write($flat, $target) ? $target : $logo;
    }

    /**
     * Decodes image bytes with GD and returns the scaled PNG bytes (flattened on white for
     * PDF/A, transparency kept otherwise); null if GD is missing or the bytes do not decode.
     */
    private function process(string $data, bool $flatten): ?string
    {
        if (! extension_loaded('gd')) {
            return null;
        }
        $source = @imagecreatefromstring($data);
        if ($source === false) {
            return null;
        }
        $image = $this->resample($source, transparent: ! $flatten);
        if ($image === null) {
            return null;
        }
        ob_start();
        $ok = imagepng($image, null, 9);
        $png = (string) ob_get_clean();

        return $ok ? $png : null;
    }

    /**
     * Rasterises an SVG logo with Imagick (PDF/A forbids mPDF's SVG output).
     */
    private function rasterise(string $svg): string
    {
        if (! extension_loaded('imagick')) {
            throw new ConfigurationException('SVG logos cannot be embedded into a PDF/A (ZUGFeRD/Factur-X) document without the PHP extension imagick. Install imagick or use a PNG or JPEG logo.');
        }
        self::assertSelfContained($svg);

        try {
            // A few bytes of SVG may declare a huge canvas: 300 dpi, but at most twice the target size.
            $probe = new Imagick;
            $probe->setFormat('SVG');
            $probe->pingImageBlob($svg);
            $scale = min(2 * $this->maxWidth / max(1, $probe->getImageWidth()), 2 * $this->maxHeight / max(1, $probe->getImageHeight()));
            $density = max(1.0, min(300.0, 96.0 * $scale));

            $image = new Imagick;
            // Only the SVG coder: the content must not select another one (PostScript, MVG, …).
            $image->setFormat('SVG');
            $image->setBackgroundColor(new ImagickPixel('white'));
            $image->setResolution($density, $density);
            $image->readImageBlob($svg);
            $image->setImageBackgroundColor(new ImagickPixel('white'));
            $flat = $image->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
            $flat->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
            // The format of the reader (SVG) would otherwise also apply to the output blob.
            $flat->setFormat('png24');
            $flat->setImageFormat('png24');

            return $flat->getImageBlob();
        } catch (ImagickException $e) {
            throw new ConfigurationException('The SVG logo cannot be rasterised: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Copy scaled down to the maximum size; without transparency on a white background.
     */
    private function resample(GdImage $source, bool $transparent): ?GdImage
    {
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $ratio = min(1.0, $this->maxWidth / $sourceWidth, $this->maxHeight / $sourceHeight);
        $width = max(1, (int) round($sourceWidth * $ratio));
        $height = max(1, (int) round($sourceHeight * $ratio));

        $target = imagecreatetruecolor($width, $height);
        if ($target === false) {
            return null;
        }
        if ($transparent) {
            // Keep transparency of PNG logos.
            imagealphablending($target, false);
            imagesavealpha($target, true);
        } else {
            $white = imagecolorallocate($target, 255, 255, 255);
            imagefilledrectangle($target, 0, 0, $width, $height, $white === false ? 0 : $white);
            imagealphablending($target, true);
            imagesavealpha($target, false);
        }
        imagecopyresampled($target, $source, 0, 0, 0, 0, $width, $height, $sourceWidth, $sourceHeight);

        return $target;
    }

    /**
     * No external references (other files, URLs, nested SVG) that the renderer would load,
     * and no entity declarations. mPDF substitutes entities itself before parsing, so an
     * external href could appear after substitution; the declaration is refused in both modes.
     */
    private static function assertSelfContained(string $svg): void
    {
        $external = '/\b(?:xlink:)?href\s*=\s*["\'](?!#|data:image\/(?:png|jpe?g|gif|webp)[;,])|url\(\s*["\']?(?!#)/i';
        if (preg_match($external, $svg) === 1 || preg_match('/<!ENTITY|<!DOCTYPE[^>]*\[/i', $svg) === 1) {
            throw new ConfigurationException('The SVG logo references external resources or declares entities; use a self-contained SVG or a PNG logo.');
        }
    }

    /**
     * Contents of a local file (data URIs are decoded in {@see self::decodeDataUri()}).
     */
    private function read(string $logo): ?string
    {
        $data = is_file($logo) && filesize($logo) <= self::MAX_BYTES ? @file_get_contents($logo) : false;

        return $data === false || $data === '' ? null : $data;
    }

    /**
     * Width, height and type of an image, null if it is none (without PHP notices).
     *
     * @return array{int, int, int}|null
     */
    private static function imageSize(string $data): ?array
    {
        set_error_handler(static fn (): bool => true);
        try {
            $size = getimagesizefromstring($data);
        } finally {
            restore_error_handler();
        }

        return $size === false ? null : [$size[0], $size[1], $size[2]];
    }

    /**
     * Refuses images whose decoded pixels would exhaust memory (decompression bombs).
     */
    private static function assertDecodable(int $width, int $height): void
    {
        if ($width * $height > self::MAX_PIXELS) {
            throw new ConfigurationException(sprintf('The logo has %d × %d pixels; use an image of at most %d megapixels.', $width, $height, self::MAX_PIXELS / 1_000_000));
        }
    }

    private function target(string $key, string $kind): string
    {
        return sprintf('%s/logo-%s-%s-%dx%d.png', rtrim($this->directory, '/'), $kind, hash('xxh128', $key), $this->maxWidth, $this->maxHeight);
    }

    private function write(GdImage $image, string $target): bool
    {
        if (! is_dir($this->directory)) {
            @mkdir($this->directory, 0775, true);
        }
        // Write to a temporary file first, so that parallel renders never read half a file.
        $temporary = $target.'.'.bin2hex(random_bytes(4));
        if (! imagepng($image, $temporary, 9) || ! rename($temporary, $target)) {
            @unlink($temporary);

            return false;
        }

        return true;
    }

    private static function isRemote(string $logo): bool
    {
        return preg_match('#^https?://#i', $logo) === 1;
    }

    private static function isSvg(string $logo, string $data): bool
    {
        return str_starts_with(strtolower($logo), 'data:image/svg')
            || preg_match('/\.svgz?(?:$|\?)/i', $logo) === 1
            || preg_match('/<svg\b/i', substr($data, 0, 4096)) === 1;
    }
}
