<?php

declare(strict_types=1);

namespace Quittio\Core\Pdf\Parsing;

final readonly class ArrayValue implements PdfValue
{
    /**
     * @param  list<PdfValue>  $items
     */
    public function __construct(public array $items) {}
}
