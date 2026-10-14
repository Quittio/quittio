<?php

declare(strict_types=1);

namespace Quittio\Core\Pdf\Parsing;

final readonly class Dictionary implements PdfValue
{
    /**
     * @param  array<string, PdfValue>  $entries  keys without the leading slash
     */
    public function __construct(public array $entries) {}

    public function get(string $key): ?PdfValue
    {
        return $this->entries[$key] ?? null;
    }

    public function name(string $key): ?string
    {
        $value = $this->get($key);

        return $value instanceof Name ? $value->value : null;
    }
}
