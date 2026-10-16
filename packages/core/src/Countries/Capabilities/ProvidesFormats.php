<?php

declare(strict_types=1);

namespace Quittio\Core\Countries\Capabilities;

use Quittio\Core\Formats\OutputFormat;
use Quittio\Core\Xml\XmlSerializer;

/**
 * A country pack that brings output formats, e.g. a national e-invoice format, with their
 * aliases and the serializers of their syntaxes. Quittio::registerCountry() registers them.
 * AbstractCountryPack implements it with empty defaults.
 */
interface ProvidesFormats
{
    /**
     * Formats of the pack; a format with the key of a registered one replaces it.
     *
     * @return list<OutputFormat>
     */
    public function formats(): array;

    /**
     * Further names of formats: alias => format key, e.g. ['xrechnung' => 'xrechnung-cii'].
     *
     * @return array<string, string>
     */
    public function formatAliases(): array;

    /**
     * Serializers for the syntaxes of the pack's formats; a serializer replaces the one of its syntax.
     *
     * @return list<XmlSerializer>
     */
    public function serializers(): array;
}
