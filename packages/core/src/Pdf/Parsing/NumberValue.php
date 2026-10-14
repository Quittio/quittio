<?php

declare(strict_types=1);

namespace Quittio\Core\Pdf\Parsing;

final readonly class NumberValue implements PdfValue
{
    public function __construct(public int|float $value) {}

    public function int(): ?int
    {
        return is_int($this->value) ? $this->value : null;
    }
}
