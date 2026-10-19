<?php

declare(strict_types=1);

namespace Quittio\Country\AT\EbInterface;

use DOMDocument;
use Quittio\Core\Reading\FormatReader;
use Quittio\Core\Reading\ReadResult;

/**
 * Reads ebInterface 6.0 and 6.1 invoices into the data model; CountryAT::readers() registers it,
 * so that Quittio::read() and validateFile() accept them. The mapping is that of
 * EbInterfaceWriter in reverse; the result has the syntax "ebinterface" and no profile.
 */
final class EbInterfaceReader implements FormatReader
{
    /** The namespaces of the versions read. */
    public const array NAMESPACES = [
        'http://www.ebinterface.at/schema/6p1/',
        'http://www.ebinterface.at/schema/6p0/',
    ];

    public const string SYNTAX = 'ebinterface';

    public function canRead(DOMDocument $document): bool
    {
        $root = $document->documentElement;

        return $root !== null && $root->localName === 'Invoice' && in_array($root->namespaceURI, self::NAMESPACES, true);
    }

    public function read(DOMDocument $document, string $xml): ReadResult
    {
        return (new EbInterfaceDocumentReader)->read($document, $xml);
    }
}
