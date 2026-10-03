<?php

declare(strict_types=1);

namespace Quittio\Laravel\Rendering;

use Illuminate\Contracts\Mail\Attachable;
use Illuminate\Contracts\Support\Responsable;
use Quittio\Core\Documents\DocumentData;
use Quittio\Core\Rendering\RenderedDocument as CoreRenderedDocument;

/**
 * Rendered quote, delivery note, reminder or statement for Laravel.
 */
final class RenderedDocument implements Attachable, Responsable
{
    use DeliversFile;

    public function __construct(
        private readonly CoreRenderedDocument $result,
    ) {}

    public function core(): CoreRenderedDocument
    {
        return $this->result;
    }

    public function data(): DocumentData
    {
        return $this->result->data();
    }

    public function pdf(): string
    {
        return $this->result->pdf();
    }

    public function html(): string
    {
        return $this->result->html();
    }

    public function contents(): string
    {
        return $this->result->contents();
    }

    public function mimeType(): string
    {
        return $this->result->mimeType();
    }

    public function filename(?string $extension = null): string
    {
        return $this->result->filename($extension);
    }
}
