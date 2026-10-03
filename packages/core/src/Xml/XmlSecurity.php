<?php

declare(strict_types=1);

namespace Quittio\Core\Xml;

use DOMDocument;

/**
 * Safe XML loading for untrusted input (specification 10.4).
 *
 * - Documents with a DOCTYPE are rejected (no entity expansion, no XXE).
 * - No network access (LIBXML_NONET), no entity substitution (no LIBXML_NOENT).
 * - Size limit for the input.
 */
final class XmlSecurity
{
    public const int DEFAULT_MAX_BYTES = 10 * 1024 * 1024;

    public static function load(string $xml, int $maxBytes = self::DEFAULT_MAX_BYTES): DOMDocument
    {
        if (strlen($xml) > $maxBytes) {
            throw new UnsafeXmlException(sprintf('The XML document exceeds the size limit of %d bytes.', $maxBytes));
        }

        // Skip a UTF-8 BOM before checking for a DOCTYPE declaration.
        $head = ltrim(substr($xml, 0, 4096), "\xEF\xBB\xBF \t\r\n");
        if (stripos($xml, '<!DOCTYPE') !== false || stripos($head, '<!ENTITY') !== false) {
            throw new UnsafeXmlException('XML documents with a DOCTYPE declaration are not accepted.');
        }

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

        if (! $loaded || $document->documentElement === null) {
            $message = isset($errors[0]) ? trim($errors[0]->message) : 'unknown error';

            throw new UnsafeXmlException('The document is not well-formed XML: '.$message);
        }

        if ($document->doctype !== null) {
            throw new UnsafeXmlException('XML documents with a DOCTYPE declaration are not accepted.');
        }

        return $document;
    }
}
