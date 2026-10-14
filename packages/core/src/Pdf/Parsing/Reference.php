<?php

declare(strict_types=1);

namespace Quittio\Core\Pdf\Parsing;

final readonly class Reference implements PdfValue
{
    public function __construct(public int $number, public int $generation = 0) {}
}
