<?php

declare(strict_types=1);

namespace Quittio\Core\Pdf\Parsing;

/**
 * A stream object: its dictionary and the still encoded data.
 *
 * @internal
 */
final readonly class Stream implements PdfValue
{
    public function __construct(public Dictionary $dictionary, public string $data) {}
}
