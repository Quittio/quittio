<?php

declare(strict_types=1);

namespace Quittio\Core\Xml;

use DOMDocument;

/**
 * Safe XML loading for untrusted input.
 *
 * - Documents with a DOCTYPE are rejected (no entity expansion, no XXE).
 * - No network access (LIBXML_NONET), no entity substitution (no LIBXML_NOENT).
 * - Size limit for the input.
 * - Start tags are limited in length and number of attributes: libxml2 before 2.12
 *   checks attributes for duplicates in quadratic time.
 * - The number of namespace declarations in scope is limited: libxml2 before 2.12 looks
 *   up every prefix by a linear search through all declarations in scope.
 * - Only encodings in which the markup is ASCII (UTF-8, ISO-8859-*, windows-125*) and
 *   UTF-16 are accepted; UTF-7 or EBCDIC would hide the markup from these checks.
 */
final class XmlSecurity
{
    public const int DEFAULT_MAX_BYTES = 10 * 1024 * 1024;

    /** Maximum length of a single start tag including its attributes. */
    public const int MAX_TAG_BYTES = 64 * 1024;

    /** Maximum number of attributes (including namespace declarations) of a single element. */
    public const int MAX_ATTRIBUTES = 256;

    /** Maximum number of namespace declarations in scope at any element. */
    public const int MAX_NAMESPACES_IN_SCOPE = 256;

    public static function load(string $xml, int $maxBytes = self::DEFAULT_MAX_BYTES): DOMDocument
    {
        if (strlen($xml) > $maxBytes) {
            throw new UnsafeXmlException(sprintf('The XML document exceeds the size limit of %d bytes.', $maxBytes));
        }

        self::assertReasonableTags(self::scannable($xml));

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $document = new DOMDocument;
            $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT);
            $errors = libxml_get_errors();
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        // Checked after parsing: libxml neither loads external entities nor substitutes
        // entities without LIBXML_NOENT/LIBXML_DTDLOAD, and a textual check would also
        // reject "<!DOCTYPE" inside CDATA sections or comments.
        if ($loaded && $document->doctype !== null) {
            throw new UnsafeXmlException('XML documents with a DOCTYPE declaration are not accepted.');
        }

        if (! $loaded || $document->documentElement === null) {
            $message = isset($errors[0]) ? trim($errors[0]->message) : 'unknown error';
            if (preg_match('/<!DOCTYPE|<!ENTITY/i', substr($xml, 0, 65536)) === 1) {
                throw new UnsafeXmlException('XML documents with a DOCTYPE declaration are not accepted ('.$message.').');
            }

            throw new UnsafeXmlException('The document is not well-formed XML: '.$message);
        }

        return $document;
    }

    /**
     * The document as UTF-8 (or another ASCII-compatible encoding) for the byte-wise pre-scan.
     */
    private static function scannable(string $xml): string
    {
        $head = substr($xml, 0, 2);
        if (substr($xml, 2, 2) === "\0\0") {
            throw new UnsafeXmlException('The document is not well-formed XML: UTF-32 is not supported.');
        }
        if ($head === "\xFF\xFE" || $head === "<\0" || $head === "\xFE\xFF" || $head === "\0<") {
            $xml = (string) mb_convert_encoding($xml, 'UTF-8', $head === "\xFF\xFE" || $head === "<\0" ? 'UTF-16LE' : 'UTF-16BE');
        }

        if (preg_match('/^(?:\xEF\xBB\xBF)?[\x20\x09\x0D\x0A]*</', $xml) !== 1) {
            throw new UnsafeXmlException('The document is not well-formed XML: it does not start with "<" in UTF-8 or UTF-16.');
        }
        if (preg_match('/^(?:\xEF\xBB\xBF)?<\?xml[^>]*?\sencoding\s*=\s*(["\'])(.*?)\1/', $xml, $m) === 1
            && preg_match('/^(?:UTF-?8|UTF-?16(?:LE|BE)?|US-ASCII|ASCII|ISO[-_]?8859-\d{1,2}|(?:ISO-?)?LATIN-?\d|WINDOWS-125\d|CP125\d)$/i', $m[2]) !== 1) {
            throw new UnsafeXmlException(sprintf('The XML encoding "%s" is not accepted; use UTF-8.', substr($m[2], 0, 40)));
        }

        return $xml;
    }

    /**
     * Rejects overlong start tags, elements with many attributes, many namespace declarations
     * in scope and DOCTYPE declarations in linear time, before libxml parses them.
     */
    private static function assertReasonableTags(string $xml): void
    {
        $length = strlen($xml);
        $offset = 0;
        $open = [];
        $inScope = 0;
        while (($start = strpos($xml, '<', $offset)) !== false) {
            if (! in_array($xml[$start + 1] ?? '', ['!', '?', '/'], true)) {
                [$offset, $namespaces, $selfClosing] = self::scanStartTag($xml, $start, $length);
                if ($inScope + $namespaces > self::MAX_NAMESPACES_IN_SCOPE) {
                    throw new UnsafeXmlException(sprintf('The XML document has more than %d namespace declarations in scope.', self::MAX_NAMESPACES_IN_SCOPE));
                }
                if (! $selfClosing) {
                    $open[] = $namespaces;
                    $inScope += $namespaces;
                }
            } elseif ($xml[$start + 1] === '/') {
                $inScope -= array_pop($open) ?? 0;
                $offset = $start + 2;
            } else {
                $offset = match (true) {
                    substr_compare($xml, '<!--', $start, 4) === 0 => self::after($xml, '-->', $start + 4),
                    substr_compare($xml, '<![CDATA[', $start, 9) === 0 => self::after($xml, ']]>', $start + 9),
                    substr_compare($xml, '<?', $start, 2) === 0 => self::after($xml, '?>', $start + 2),
                    // A DOCTYPE outside comments and CDATA: its internal subset (default attributes,
                    // entities) would be processed by libxml before the check after parsing.
                    substr_compare($xml, '<!DOCTYPE', $start, 9) === 0 => throw new UnsafeXmlException('XML documents with a DOCTYPE declaration are not accepted.'),
                    default => $start + 2,
                };
            }
            if ($offset >= $length) {
                return;
            }
        }
    }

    /**
     * Scans one start tag, honouring quoted attribute values.
     *
     * @return array{0: int, 1: int, 2: bool} offset after the tag, namespace declarations, self-closing
     */
    private static function scanStartTag(string $xml, int $start, int $length): array
    {
        $position = $start + 1;
        $segment = $position;
        $attributes = 0;
        $namespaces = 0;
        while ($position < $length) {
            $position += strcspn($xml, '"\'=>', $position);
            if ($position - $start > self::MAX_TAG_BYTES) {
                throw new UnsafeXmlException(sprintf('The XML document contains a start tag longer than %d bytes.', self::MAX_TAG_BYTES));
            }
            if ($position >= $length) {
                break;
            }

            $char = $xml[$position];
            if ($char === '>') {
                return [$position + 1, $namespaces, $xml[$position - 1] === '/'];
            }
            if ($char === '=') {
                if (++$attributes > self::MAX_ATTRIBUTES) {
                    throw new UnsafeXmlException(sprintf('An element of the XML document has more than %d attributes.', self::MAX_ATTRIBUTES));
                }
                // Attribute name: the last word before "=" since the previous value.
                $name = (string) preg_replace('/^.*\s/s', '', rtrim(substr($xml, $segment, $position - $segment)));
                if ($name === 'xmlns' || str_starts_with($name, 'xmlns:')) {
                    $namespaces++;
                }
                $position++;

                continue;
            }

            // Quoted attribute value: skip to the closing quote.
            $close = strpos($xml, $char, $position + 1);
            $position = $close === false ? $length : $close + 1;
            $segment = $position;
        }

        if ($position - $start > self::MAX_TAG_BYTES) {
            throw new UnsafeXmlException(sprintf('The XML document contains a start tag longer than %d bytes.', self::MAX_TAG_BYTES));
        }

        return [$position, $namespaces, false];
    }

    private static function after(string $xml, string $terminator, int $offset): int
    {
        $end = strpos($xml, $terminator, $offset);

        return $end === false ? strlen($xml) : $end + strlen($terminator);
    }
}
