<?php

declare(strict_types=1);

namespace Quittio\Laravel\Rendering;

use Illuminate\Container\Container;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Http\Request;
use Illuminate\Mail\Attachment;
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
     * Stores the main output on a filesystem disk.
     *
     * @return string the stored path
     */
    public function save(?string $disk = null, ?string $path = null): string
    {
        $path ??= $this->filename();
        if (str_ends_with($path, '/')) {
            $path .= $this->filename();
        }

        $filesystem = Container::getInstance()->make(FilesystemFactory::class)->disk($disk);
        $filesystem->put($path, $this->contents());

        return $path;
    }

    private function response(string $disposition, ?string $filename): Response
    {
        $filename ??= $this->filename();
        $fallback = (string) preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);

        return new Response($this->contents(), 200, [
            'Content-Type' => $this->mimeType(),
            'Content-Disposition' => sprintf('%s; filename="%s"; filename*=UTF-8\'\'%s', $disposition, $fallback, rawurlencode($filename)),
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
