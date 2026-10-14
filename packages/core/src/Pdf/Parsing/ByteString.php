<?php

declare(strict_types=1);

namespace Quittio\Core\Pdf\Parsing;

/**
 * A literal or hexadecimal string with its decoded bytes.
 */
final readonly class ByteString implements PdfValue
{
    public function __construct(public string $bytes) {}

    /**
     * The text of a text string (ISO 32000-1, 7.9.2.2): UTF-16 with byte order mark,
     * UTF-8 with byte order mark (PDF 2.0) or PDFDocEncoding, which is kept as is.
     */
    public function text(): string
    {
        $bytes = $this->bytes;

        return match (true) {
            str_starts_with($bytes, "\xFE\xFF") => (string) mb_convert_encoding(substr($bytes, 2), 'UTF-8', 'UTF-16BE'),
            str_starts_with($bytes, "\xFF\xFE") => (string) mb_convert_encoding(substr($bytes, 2), 'UTF-8', 'UTF-16LE'),
            str_starts_with($bytes, "\xEF\xBB\xBF") => substr($bytes, 3),
            default => $bytes,
        };
    }
}
