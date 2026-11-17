<?php

declare(strict_types=1);

namespace Quittio\Core\Pdf\Renderers;

use Fagus\Conformance;
use Fagus\Engine;
use Fagus\Exception\FagusException;
use Fagus\Fagus;
use Fagus\InvoiceProfile;
use Mpdf\Config\FontVariables;
use Quittio\Core\Exceptions\ConfigurationException;
use Quittio\Core\Pdf\PdfException;
use Quittio\Core\Pdf\PdfRenderer;
use Quittio\Core\Pdf\RenderRequest;

/**
 * Optional renderer: HTML/CSS to PDF/A-3b with Fagus (package nforst/fagus), an engine for CSS
 * Paged Media that runs as a binary next to PHP.
 *
 * Templates stay written for mPDF: their mPDF markup (page headers and footers, page breaks,
 * named pages, positioned elements, watermark, carry-over rows) is translated into standard CSS
 * before rendering ({@see MpdfMarkupConverter}). The fonts are the same as for {@see MpdfRenderer}:
 * the mPDF family names (geist, geistmedium, inter, liberationsans and those of $fonts) map to
 * the font files. PDF/A, the embedded XML and its Factur-X metadata are written by Fagus.
 *
 * Like mPDF, Fagus never loads anything over the network. It reads no files by itself either:
 * only images that the HTML names by a local path (the logo prepared by LogoCache) are handed
 * over, and only if they are images.
 */
final class FagusRenderer implements PdfRenderer
{
    /** Largest local image that is handed over (bytes), as for logos. */
    private const int MAX_IMAGE_BYTES = 10 * 1024 * 1024;

    /** @var array<string, ?string> font file => PostScript name */
    private static array $postScriptNames = [];

    private ?Engine $engine = null;

    /**
     * @param  array<string, array<string, string>>  $fonts  additional fonts: family => [R => file, B => file, I => file, BI => file]
     * @param  list<string>  $fontDirectories  searched first, before the bundled Geist, Inter and Liberation Sans;
     *         directories that do not exist are skipped
     * @param  ?string  $binary  path of the fagus binary; null = FAGUS_BINARY, the binary installed with the
     *         package or fagus on the PATH
     * @param  float  $timeout  seconds to wait for each answer of the Fagus worker
     */
    public function __construct(
        private readonly array $fonts = [],
        private readonly array $fontDirectories = [],
        private readonly string $defaultFont = 'geist',
        private readonly ?string $binary = null,
        private readonly float $timeout = 60.0,
    ) {
        if (! class_exists(Fagus::class)) {
            throw new ConfigurationException('The PDF renderer "fagus" needs the package nforst/fagus. Install it with: composer require nforst/fagus');
        }
    }

    public function supportsEmbedding(): bool
    {
        return true;
    }

    /**
     * Renders the HTML with Fagus and returns the PDF; with $request->pdfa as PDF/A-3b, with the
     * embedded XML as ZUGFeRD/Factur-X (PDF/A-3b, the profile from the conformance level).
     * Throws a PdfException when Fagus fails.
     */
    public function render(RenderRequest $request): string
    {
        // As mPDF: no watermark in PDF/A (an embedded XML makes the PDF a PDF/A-3).
        $html = (new MpdfMarkupConverter($request->paper, watermarks: ! $request->pdfa && $request->embedded === null))->convert($request->html);
        $html = self::metadata($html, $request);

        try {
            $document = Fagus::html($html)->stylesheet($this->fontFaces($html, $request->font ?? $this->defaultFont), 'quittio-fonts');
            foreach (self::localImages($html) as $url => $data) {
                $document = $document->resource($url, $data);
            }
            if ($request->embedded !== null) {
                $document = $document->facturX(
                    $request->embedded->content,
                    self::profile($request->embedded->conformanceLevel),
                    version: $request->embedded->version,
                    description: $request->embedded->description,
                );
            } elseif ($request->pdfa) {
                $document = $document->pdfA(Conformance::PdfA3b);
            }

            return $document->render($this->engine())->bytes();
        } catch (FagusException $e) {
            throw new PdfException('PDF rendering failed: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * One engine per renderer: its worker process stays warm between documents.
     */
    private function engine(): Engine
    {
        return $this->engine ??= new Engine(
            binary: $this->binary,
            fontDirectories: $this->directories(),
            timeout: $this->timeout,
        );
    }

    /**
     * The configured font directories that exist, then the bundled ones.
     *
     * @return list<string>
     */
    private function directories(): array
    {
        $bundled = dirname(__DIR__, 3).'/resources/fonts';
        $directories = [];
        foreach ([...$this->fontDirectories, $bundled.'/geist', $bundled.'/inter', $bundled.'/liberation'] as $directory) {
            $real = realpath($directory);
            if ($real !== false && is_dir($real) && ! in_array($real, $directories, true)) {
                $directories[] = $real;
            }
        }

        return $directories;
    }

    /**
     * @font-face rules that give the mPDF family names (as in MpdfRenderer) the faces of their
     * font files, for the families the HTML names; the default font for text without one.
     */
    private function fontFaces(string $html, string $default): string
    {
        $defaults = (new FontVariables)->getDefaults();
        $fontData = [
            ...(is_array($defaults) && is_array($defaults['fontdata'] ?? null) ? $defaults['fontdata'] : []),
            'geist' => ['R' => 'Geist-Regular.ttf', 'B' => 'Geist-Bold.ttf', 'I' => 'Geist-Italic.ttf', 'BI' => 'Geist-BoldItalic.ttf'],
            // mPDF knows only normal and bold: the medium weights of Geist as a family of their own.
            'geistmedium' => ['R' => 'Geist-Medium.ttf', 'B' => 'Geist-SemiBold.ttf'],
            'inter' => ['R' => 'Inter-Regular.ttf', 'B' => 'Inter-Bold.ttf', 'I' => 'Inter-Italic.ttf', 'BI' => 'Inter-BoldItalic.ttf'],
            'liberationsans' => ['R' => 'LiberationSans-Regular.ttf', 'B' => 'LiberationSans-Bold.ttf'],
            ...$this->fonts,
        ];

        preg_match_all('/font-family\s*:\s*([^;}<>]+)/i', $html, $declarations);
        $families = [strtolower($default) => true];
        foreach ($declarations[1] as $declaration) {
            foreach (explode(',', $declaration) as $family) {
                $families[strtolower(trim(html_entity_decode($family, ENT_QUOTES | ENT_HTML5, 'UTF-8'), " \t\n\r\"'"))] = true;
            }
        }

        $directories = $this->directories();
        $css = '';
        foreach ($fontData as $family => $styles) {
            $family = strtolower((string) $family);
            if (! isset($families[$family]) || ! is_array($styles) || preg_match('/^[a-z0-9_-]+$/', $family) !== 1) {
                continue;
            }
            foreach (['R' => [400, 'normal'], 'B' => [700, 'normal'], 'I' => [400, 'italic'], 'BI' => [700, 'italic']] as $style => [$weight, $fontStyle]) {
                $file = is_string($styles[$style] ?? null) ? self::find($styles[$style], $directories) : null;
                $name = $file === null ? null : self::postScriptName($file);
                if ($name !== null) {
                    $css .= sprintf("@font-face { font-family: %s; src: local(\"%s\"); font-weight: %d; font-style: %s; }\n", $family, addcslashes($name, '"\\'), $weight, $fontStyle);
                }
            }
        }

        if (preg_match('/^[a-z0-9_-]+$/i', $default) === 1) {
            // mPDF's default_font: the font of text that names none.
            $css .= 'html { font-family: '.strtolower($default).'; }'."\n";
        }

        return $css;
    }

    /**
     * The first directory with the file wins, as in mPDF.
     *
     * @param  list<string>  $directories
     */
    private static function find(string $file, array $directories): ?string
    {
        foreach ($directories as $directory) {
            if (is_file($directory.'/'.$file)) {
                return $directory.'/'.$file;
            }
        }

        return null;
    }

    /**
     * The PostScript name of a TrueType or OpenType font file (name ID 6), by which @font-face
     * local() finds the face among the engine's font directories.
     */
    private static function postScriptName(string $file): ?string
    {
        if (array_key_exists($file, self::$postScriptNames)) {
            return self::$postScriptNames[$file];
        }

        $data = @file_get_contents($file, false, null, 0, 1024 * 1024);
        $name = null;
        if (is_string($data) && strlen($data) >= 12) {
            $base = str_starts_with($data, 'ttcf') ? self::uint32($data, 12) : 0;
            $tables = self::uint16($data, $base + 4);
            for ($i = 0; $i < $tables; $i++) {
                $record = $base + 12 + $i * 16;
                if (substr($data, $record, 4) === 'name') {
                    $name = self::nameRecord($data, self::uint32($data, $record + 8), 6);
                    break;
                }
            }
        }

        return self::$postScriptNames[$file] = $name;
    }

    private static function nameRecord(string $data, int $table, int $id): ?string
    {
        $count = self::uint16($data, $table + 2);
        $strings = $table + self::uint16($data, $table + 4);
        $found = null;
        for ($i = 0; $i < $count; $i++) {
            $record = $table + 6 + $i * 12;
            if (self::uint16($data, $record + 6) !== $id) {
                continue;
            }
            $platform = self::uint16($data, $record);
            $value = substr($data, $strings + self::uint16($data, $record + 10), self::uint16($data, $record + 8));
            if ($platform === 3 || $platform === 0) {
                $found = mb_convert_encoding($value, 'UTF-8', 'UTF-16BE');
                break;
            }
            $found ??= $value;
        }

        return $found === null || $found === '' ? null : $found;
    }

    private static function uint16(string $data, int $offset): int
    {
        $value = unpack('n', $data, $offset);

        return is_array($value) && is_int($value[1] ?? null) ? $value[1] : 0;
    }

    private static function uint32(string $data, int $offset): int
    {
        $value = unpack('N', $data, $offset);

        return is_array($value) && is_int($value[1] ?? null) ? $value[1] : 0;
    }

    /**
     * Images that the HTML names by a local path (as mPDF reads them): URL as written => data.
     * Nothing else is read, and only files that are images.
     *
     * @return array<string, string>
     */
    private static function localImages(string $html): array
    {
        preg_match_all('/<img\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*?\ssrc\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $html, $matches, PREG_SET_ORDER);
        $images = [];
        foreach ($matches as $m) {
            $url = html_entity_decode(($m[1] ?? '').($m[2] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            // URLs with a scheme (data:, http:) are not files; Fagus fetches nothing over the network.
            if ($url === '' || isset($images[$url]) || preg_match('#^[a-z][a-z0-9+.-]*:#i', $url) === 1 || str_starts_with($url, '//')) {
                continue;
            }
            $file = realpath($url);
            if ($file === false || ! is_file($file) || (int) filesize($file) > self::MAX_IMAGE_BYTES) {
                continue;
            }
            $data = file_get_contents($file);
            if (is_string($data) && (@getimagesizefromstring($data) !== false || preg_match('/^\s*(?:<\?xml[^>]*>\s*)?(?:<!--.*?-->\s*)*<svg\b/is', $data) === 1)) {
                $images[$url] = $data;
            }
        }

        return $images;
    }

    /**
     * The title, author and subject of the request as the document's metadata.
     */
    private static function metadata(string $html, RenderRequest $request): string
    {
        // Only in <head>: an inline SVG may have a <title> of its own.
        $end = stripos($html, '</head>');
        $end = $end === false ? 0 : $end;
        $html = preg_replace('/<title\b[^>]*>.*?<\/title\s*>|<meta\b[^>]*\bname\s*=\s*(["\']?)(?:author|description)\1[^>]*>/is', '', substr($html, 0, $end)).substr($html, $end);
        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $meta = '<title>'.$escape($request->title).'</title>'
            .($request->author !== null ? '<meta name="author" content="'.$escape($request->author).'">' : '')
            .($request->subject !== null ? '<meta name="description" content="'.$escape($request->subject).'">' : '');

        return (string) preg_replace('/<head\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>/i', '$0'.str_replace(['\\', '$'], ['\\\\', '\\$'], $meta), $html, 1);
    }

    /**
     * The Fagus profile of a Factur-X conformance level; null = the profile the XML declares.
     */
    private static function profile(?string $conformanceLevel): ?InvoiceProfile
    {
        return match (strtoupper(trim((string) $conformanceLevel))) {
            'MINIMUM' => InvoiceProfile::Minimum,
            'BASIC WL' => InvoiceProfile::BasicWl,
            'BASIC' => InvoiceProfile::Basic,
            'EN 16931', 'EN16931', 'COMFORT' => InvoiceProfile::En16931,
            'EXTENDED' => InvoiceProfile::Extended,
            'XRECHNUNG' => InvoiceProfile::XRechnung,
            default => null,
        };
    }
}
