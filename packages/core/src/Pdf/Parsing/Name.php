<?php

declare(strict_types=1);

namespace Quittio\Core\Pdf\Parsing;

final readonly class Name implements PdfValue
{
    public function __construct(public string $value) {}
}
