<?php

declare(strict_types=1);

namespace Quittio\Laravel\Rendering;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Http\Request;
use Illuminate\Mail\Attachment;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Download, inline display, mail attachment and storage for rendered files.
 */
trait DeliversFile
{
    abstract public function contents(): string;

    abstract public function filename(?string $extension = null): string;

    abstract public function mimeType(): string;

    public function download(?string $filename = null): Response
    {
        return $this->response('attachment', $filename);
    }

    public function stream(?string $filename = null): Response
    {
        return $this->response('inline', $filename);
    }

    /**
     * @param  Request  $request
     */
    public function toResponse($request): Response
    {
        return $this->download();
    }

    public function toMailAttachment(): Attachment
    {
        $contents = $this->contents();

        return Attachment::fromData(static fn (): string => $contents, $this->filename())->withMime($this->mimeType());
    }

    /**
     * Stores the main output on a filesystem disk (default: quittio.storage.disk).
     *
     * @return string the stored path
     */
    public function save(?string $disk = null, ?string $path = null): string
    {
        $path ??= $this->filename();
        if (str_ends_with($path, '/')) {
            $path .= $this->filename();
        }

        $container = Container::getInstance();
        $configured = $container->make(Repository::class)->get('quittio.storage.disk');
        $disk ??= is_string($configured) && $configured !== '' ? $configured : null;
        $filesystem = $container->make(FilesystemFactory::class)->disk($disk);
        $filesystem->put($path, $this->contents());

        return $path;
    }

    private function response(string $disposition, ?string $filename): Response
    {
        $filename ??= $this->filename();

        return new Response($this->contents(), 200, [
            'Content-Type' => $this->mimeType(),
            'Content-Disposition' => sprintf('%s; filename="%s"; filename*=UTF-8\'\'%s', $disposition, self::asciiFilename($filename), rawurlencode($filename)),
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * ASCII fallback for the "filename" parameter: characters are transliterated ("Ü" → "U",
     * "ß" → "ss"); every character that has no transliteration or is no letter, digit, ".",
     * "_" or "-" becomes one "_" (not one per byte).
     */
    private static function asciiFilename(string $filename): string
    {
        if (! mb_check_encoding($filename, 'UTF-8')) {
            return (string) preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);
        }

        $ascii = '';
        foreach (mb_str_split($filename) as $character) {
            $transliterated = strlen($character) === 1 ? $character : Str::ascii($character);
            $ascii .= $transliterated === '' ? '_' : (string) preg_replace('/[^A-Za-z0-9._-]/', '_', $transliterated);
        }

        return $ascii;
    }
}
