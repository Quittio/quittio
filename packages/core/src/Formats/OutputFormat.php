<?php

declare(strict_types=1);

namespace Quittio\Core\Formats;

use Quittio\Core\Profiles\Syntax;

/**
 * Definition of an output format.
 */
final readonly class OutputFormat
{
    /**
     * @param  Syntax|null  $syntax  XML syntax, null for formats without XML
     * @param  bool  $pdf  whether a PDF is produced
     * @param  bool  $embedsXml  whether the XML is embedded in the PDF (ZUGFeRD/Factur-X)
     * @param  string|null  $defaultProfile  profile used when none is given
     * @param  list<string>  $profiles  allowed profile keys; empty = every profile that supports the syntax
     */
    public function __construct(
        public string $key,
        public ?Syntax $syntax = null,
        public bool $pdf = false,
        public bool $embedsXml = false,
        public ?string $defaultProfile = null,
        public array $profiles = [],
        public bool $html = false,
        public bool $json = false,
    ) {}

    /**
     * Whether the format produces XML, i.e. has a syntax (CII or UBL).
     */
    public function hasXml(): bool
    {
        return $this->syntax !== null;
    }

    /**
     * Whether the format accepts the profile $key; a format without a profile list accepts every
     * profile.
     */
    public function allowsProfile(string $key): bool
    {
        return $this->profiles === [] || in_array($key, $this->profiles, true);
    }

    /**
     * Whether the format is an e-invoice in the sense of EN 16931 (structured XML).
     */
    public function isEInvoice(): bool
    {
        return $this->syntax !== null;
    }
}
