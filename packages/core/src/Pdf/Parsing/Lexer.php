<?php

declare(strict_types=1);

namespace Quittio\Core\Pdf\Parsing;

use Quittio\Core\Pdf\PdfException;

/**
 * Reads PDF objects (ISO 32000-1, 7.2 and 7.3) from a byte string.
 *
 * Streams are not handled here; the document reads the stream data after a dictionary.
 *
 * @internal
 */
final class Lexer
{
    public const string WHITESPACE = "\x00\x09\x0A\x0C\x0D\x20";

    private const string REGULAR = '[^\x00\x09\x0A\x0C\x0D\x20()<>\[\]{}\/%]';

    private const int MAX_NESTING = 64;

    private int $position;

    private readonly int $length;

    public function __construct(private readonly string $data, int $offset = 0)
    {
        $this->length = strlen($data);
        $this->position = max(0, min($offset, $this->length));
    }

    public function position(): int
    {
        return $this->position;
    }

    public function seek(int $position): void
    {
        $this->position = max(0, min($position, $this->length));
    }

    /**
     * Skips white space and comments.
     */
    public function skipWhitespace(): void
    {
        while ($this->position < $this->length) {
            $this->position += strspn($this->data, self::WHITESPACE, $this->position);
            if ($this->position < $this->length && $this->data[$this->position] === '%') {
                $end = strcspn($this->data, "\r\n", $this->position);
                $this->position += $end;

                continue;
            }

            break;
        }
    }

    /**
     * Reads a keyword or other regular token (e.g. "obj", "stream", "xref") without consuming it.
     */
    public function peekKeyword(): ?string
    {
        $position = $this->position;
        $keyword = $this->readKeyword();
        $this->position = $position;

        return $keyword;
    }

    public function readKeyword(): ?string
    {
        $this->skipWhitespace();
        if (preg_match('/\G'.self::REGULAR.'+/', $this->data, $match, 0, $this->position) !== 1) {
            return null;
        }
        $this->position += strlen($match[0]);

        return $match[0];
    }

    public function readInteger(): ?int
    {
        $this->skipWhitespace();
        if (preg_match('/\G\d{1,15}(?!'.self::REGULAR.')/', $this->data, $match, 0, $this->position) !== 1) {
            return null;
        }
        $this->position += strlen($match[0]);

        return (int) $match[0];
    }

    public function readValue(int $depth = 0): PdfValue
    {
        if ($depth > self::MAX_NESTING) {
            throw new PdfException('Malformed PDF: objects are nested too deeply.');
        }

        $this->skipWhitespace();
        if ($this->position >= $this->length) {
            throw new PdfException('Malformed PDF: unexpected end of data.');
        }

        $char = $this->data[$this->position];

        return match (true) {
            $char === '/' => $this->readName(),
            $char === '(' => $this->readLiteralString(),
            $char === '<' && ($this->data[$this->position + 1] ?? '') === '<' => $this->readDictionary($depth),
            $char === '<' => $this->readHexString(),
            $char === '[' => $this->readArray($depth),
            str_contains('0123456789+-.', $char) => $this->readNumberOrReference(),
            default => $this->readKeywordValue(),
        };
    }

    private function readKeywordValue(): PdfValue
    {
        $position = $this->position;
        $keyword = $this->readKeyword();

        return match ($keyword) {
            'true' => new BooleanValue(true),
            'false' => new BooleanValue(false),
            'null' => new NullValue,
            default => throw new PdfException(sprintf('Malformed PDF: unexpected token at offset %d.', $position)),
        };
    }

    private function readName(): Name
    {
        $this->position++;
        $name = '';
        if (preg_match('/\G'.self::REGULAR.'*/', $this->data, $match, 0, $this->position) === 1) {
            $name = $match[0];
            $this->position += strlen($name);
        }

        $decoded = preg_replace_callback('/#([0-9A-Fa-f]{2})/', static fn (array $m): string => chr(((int) hexdec($m[1])) & 0xFF), $name);

        return new Name($decoded ?? $name);
    }

    private function readLiteralString(): ByteString
    {
        $this->position++;
        $result = '';
        $depth = 1;

        while ($this->position < $this->length) {
            $char = $this->data[$this->position++];
            if ($char === '\\') {
                $result .= $this->readEscape();

                continue;
            }
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
                if ($depth === 0) {
                    return new ByteString($result);
                }
            } elseif ($char === "\r") {
                // End-of-line markers in strings are read as a single line feed.
                if (($this->data[$this->position] ?? '') === "\n") {
                    $this->position++;
                }
                $char = "\n";
            }
            $result .= $char;
        }

        throw new PdfException('Malformed PDF: unterminated string.');
    }

    private function readEscape(): string
    {
        $char = $this->data[$this->position] ?? '';
        $this->position++;

        if ($char !== '' && $char >= '0' && $char <= '7') {
            $octal = $char;
            while (strlen($octal) < 3 && ($next = $this->data[$this->position] ?? '') !== '' && $next >= '0' && $next <= '7') {
                $octal .= $next;
                $this->position++;
            }

            return chr(((int) octdec($octal)) & 0xFF);
        }

        return match ($char) {
            'n' => "\n",
            'r' => "\r",
            't' => "\t",
            'b' => "\x08",
            'f' => "\x0C",
            "\r" => ($this->data[$this->position] ?? '') === "\n" ? $this->skip(1, '') : '',
            "\n" => '',
            default => $char,
        };
    }

    private function skip(int $bytes, string $result): string
    {
        $this->position += $bytes;

        return $result;
    }

    /**
     * Hexadecimal strings (7.3.4.3): white space is ignored and a missing last digit is 0.
     */
    private function readHexString(): ByteString
    {
        $this->position++;
        $end = strpos($this->data, '>', $this->position);
        if ($end === false) {
            throw new PdfException('Malformed PDF: unterminated hexadecimal string.');
        }

        $hex = (string) preg_replace('/[^0-9A-Fa-f]/', '', substr($this->data, $this->position, $end - $this->position));
        $this->position = $end + 1;
        if (strlen($hex) % 2 === 1) {
            $hex .= '0';
        }

        return new ByteString((string) hex2bin($hex));
    }

    private function readArray(int $depth): ArrayValue
    {
        $this->position++;
        $items = [];

        while (true) {
            $this->skipWhitespace();
            if ($this->position >= $this->length) {
                throw new PdfException('Malformed PDF: unterminated array.');
            }
            if ($this->data[$this->position] === ']') {
                $this->position++;

                return new ArrayValue($items);
            }
            $items[] = $this->readValue($depth + 1);
        }
    }

    private function readDictionary(int $depth): Dictionary
    {
        $this->position += 2;
        $entries = [];

        while (true) {
            $this->skipWhitespace();
            if ($this->position >= $this->length) {
                throw new PdfException('Malformed PDF: unterminated dictionary.');
            }
            if ($this->data[$this->position] === '>' && ($this->data[$this->position + 1] ?? '') === '>') {
                $this->position += 2;

                return new Dictionary($entries);
            }
            $key = $this->readValue($depth + 1);
            if (! $key instanceof Name) {
                throw new PdfException(sprintf('Malformed PDF: dictionary key expected at offset %d.', $this->position));
            }
            $entries[$key->value] = $this->readValue($depth + 1);
        }
    }

    private function readNumberOrReference(): PdfValue
    {
        if (preg_match('/\G[+-]?(?:\d+\.?\d*|\.\d+)/', $this->data, $match, 0, $this->position) !== 1) {
            throw new PdfException(sprintf('Malformed PDF: number expected at offset %d.', $this->position));
        }
        $this->position += strlen($match[0]);
        $token = $match[0];

        if (ctype_digit($token) && strlen($token) <= 10) {
            $position = $this->position;
            $generation = $this->readInteger();
            if ($generation !== null && $this->readKeyword() === 'R') {
                return new Reference((int) $token, $generation);
            }
            $this->position = $position;

            return new NumberValue((int) $token);
        }

        return new NumberValue(str_contains($token, '.') ? (float) $token : (int) $token);
    }
}
