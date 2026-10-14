<?php

declare(strict_types=1);

namespace Quittio\Core\Pdf\Parsing;

use Quittio\Core\Pdf\PdfException;

/**
 * Decodes stream data: FlateDecode with optional PNG or TIFF predictors (ISO 32000-1, 7.4.4).
 *
 * Decompression runs chunk by chunk and stops at the size limit (protection against zip bombs).
 */
final class StreamDecoder
{
    private const int CHUNK = 8192;

    /**
     * @param  list<array{0: string, 1: ?Dictionary}>  $filters  filter names with their decode parameters
     */
    public static function decode(string $data, array $filters, int $limit): string
    {
        foreach ($filters as [$filter, $parameters]) {
            $data = match ($filter) {
                'FlateDecode', 'Fl' => self::predict(self::inflate($data, $limit), $parameters),
                default => throw new PdfException(sprintf('Unsupported stream filter "%s".', $filter)),
            };
        }

        if (strlen($data) > $limit) {
            throw new PdfException(sprintf('A stream exceeds the decompression limit of %d bytes.', $limit));
        }

        return $data;
    }

    private static function inflate(string $data, int $limit): string
    {
        $context = inflate_init(ZLIB_ENCODING_DEFLATE);
        if ($context === false) {
            throw new PdfException('Unable to initialise decompression.');
        }

        $output = '';
        foreach (str_split($data, self::CHUNK) as $chunk) {
            $part = @inflate_add($context, $chunk, ZLIB_SYNC_FLUSH);
            if ($part === false) {
                if ($output !== '') {
                    // Trailing garbage after a complete stream is common and harmless.
                    break;
                }

                throw new PdfException('A stream could not be decompressed.');
            }
            $output .= $part;
            if (strlen($output) > $limit) {
                throw new PdfException(sprintf('A stream exceeds the decompression limit of %d bytes.', $limit));
            }
        }

        $rest = @inflate_add($context, '', ZLIB_FINISH);
        if (is_string($rest)) {
            $output .= $rest;
        }

        return $output;
    }

    private static function predict(string $data, ?Dictionary $parameters): string
    {
        $predictor = self::int($parameters, 'Predictor', 1);
        if ($predictor <= 1) {
            return $data;
        }

        $colors = max(1, self::int($parameters, 'Colors', 1));
        $bits = max(1, self::int($parameters, 'BitsPerComponent', 8));
        $columns = max(1, self::int($parameters, 'Columns', 1));
        if ($colors > 32 || $bits > 16 || $columns > 1_000_000) {
            throw new PdfException('Unsupported predictor parameters.');
        }
        $bytesPerPixel = max(1, intdiv($colors * $bits + 7, 8));
        $rowLength = intdiv($colors * $bits * $columns + 7, 8);

        if ($predictor === 2) {
            return self::tiff($data, $bits, $bytesPerPixel, $rowLength);
        }
        if ($predictor < 10) {
            throw new PdfException(sprintf('Unsupported predictor %d.', $predictor));
        }

        return self::png($data, $bytesPerPixel, $rowLength);
    }

    private static function png(string $data, int $bytesPerPixel, int $rowLength): string
    {
        $output = '';
        $previous = '';
        $previousLength = 0;
        $length = strlen($data);

        for ($offset = 0; $offset < $length; $offset += $rowLength + 1) {
            $type = ord($data[$offset]);
            // Only the bytes actually present are processed: a declared row length far larger
            // than the data must not expand the work (and memory) beyond the input size.
            $row = substr($data, $offset + 1, $rowLength);
            $width = strlen($row);
            $decoded = '';
            for ($i = 0; $i < $width; $i++) {
                $raw = ord($row[$i]);
                $left = $i >= $bytesPerPixel ? ord($decoded[$i - $bytesPerPixel]) : 0;
                $up = $i < $previousLength ? ord($previous[$i]) : 0;
                $upLeft = $i >= $bytesPerPixel && $i - $bytesPerPixel < $previousLength ? ord($previous[$i - $bytesPerPixel]) : 0;
                $value = match ($type) {
                    0 => $raw,
                    1 => $raw + $left,
                    2 => $raw + $up,
                    3 => $raw + intdiv($left + $up, 2),
                    4 => $raw + self::paeth($left, $up, $upLeft),
                    default => throw new PdfException(sprintf('Unsupported PNG predictor type %d.', $type)),
                };
                $decoded .= chr($value & 0xFF);
            }
            $output .= $decoded;
            $previous = $decoded;
            $previousLength = $width;
        }

        return $output;
    }

    private static function tiff(string $data, int $bits, int $bytesPerPixel, int $rowLength): string
    {
        if ($bits !== 8) {
            throw new PdfException('Unsupported TIFF predictor parameters.');
        }

        $output = '';
        foreach (str_split($data, max(1, $rowLength)) as $row) {
            for ($i = $bytesPerPixel, $n = strlen($row); $i < $n; $i++) {
                $row[$i] = chr((ord($row[$i]) + ord($row[$i - $bytesPerPixel])) & 0xFF);
            }
            $output .= $row;
        }

        return $output;
    }

    private static function paeth(int $left, int $up, int $upLeft): int
    {
        $p = $left + $up - $upLeft;
        $pa = abs($p - $left);
        $pb = abs($p - $up);
        $pc = abs($p - $upLeft);

        return match (true) {
            $pa <= $pb && $pa <= $pc => $left,
            $pb <= $pc => $up,
            default => $upLeft,
        };
    }

    private static function int(?Dictionary $parameters, string $key, int $default): int
    {
        $value = $parameters?->get($key);

        return $value instanceof NumberValue ? (int) $value->value : $default;
    }
}
