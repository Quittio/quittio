<?php

declare(strict_types=1);

namespace Quittio\Core\Rendering;

use Quittio\Core\Countries\CountryPack;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Profiles\Profile;
use Quittio\Core\Validation\ValidationResult;

/**
 * Calculated and validated invoice data plus the decisions a render run would take
 * (format, profile, country pack, locale), without rendering anything. The XML of every
 * format (also the one "pdf" embeds with b2cEmbedXml) is generated for the validation only.
 * Used by the Managed layer to freeze an invoice before its documents are rendered; the
 * validation always runs, whatever the validation mode.
 */
final readonly class PreparedInvoice
{
    /**
     * @param  array<string, Profile|null>  $formats  format key => profile (null for formats that write or embed no XML)
     */
    public function __construct(
        public InvoiceData $data,
        public array $formats,
        public CountryPack $country,
        public ?CountryPack $buyerCountry,
        public string $locale,
        public ValidationResult $validation,
    ) {}

    /**
     * Key of the main format, the first of $formats as in a render run.
     */
    public function format(): string
    {
        return (string) array_key_first($this->formats);
    }

    /**
     * Profile of the main format; null for formats that write or embed no XML.
     */
    public function profile(): ?Profile
    {
        return $this->formats[$this->format()] ?? null;
    }
}
