<?php

declare(strict_types=1);

namespace Quittio\Core\Profiles;

use Quittio\Core\Standards\StandardVersion;
use Quittio\Core\Validation\Rule;

/**
 * An e-invoice profile (CIUS or extension): which fields are allowed or mandatory,
 * which identifier goes into BT-24 and which additional rules apply.
 */
interface Profile
{
    /**
     * Stable key, e.g. "en16931", "extended", "xrechnung", "peppol".
     */
    public function key(): string;

    /**
     * Display name, e.g. "EN 16931" or "XRechnung 3.0".
     */
    public function name(): string;

    /**
     * Factur-X/ZUGFeRD level used for the field matrix.
     */
    public function level(): ProfileLevel;

    /**
     * Specification identifier written to BT-24.
     */
    public function specificationId(StandardVersion $version = StandardVersion::En16931_2017): string;

    /**
     * @return list<Syntax>
     */
    public function syntaxes(): array;

    /**
     * Whether the profile can carry invoices of this EN 16931 version. The rule
     * QUITTIO-STANDARD-VERSION reports an invoice whose version the profile does not support.
     */
    public function supportsStandard(StandardVersion $version): bool;

    /**
     * File name of the embedded XML in a hybrid PDF, null if the profile is XML only.
     */
    public function embeddedFilename(): ?string;

    /**
     * AFRelationship of the embedded XML (Alternative or Data), null if the profile is XML only.
     */
    public function afRelationship(): ?string;

    /**
     * ConformanceLevel in the Factur-X XMP metadata, null if the profile is XML only.
     */
    public function xmpConformanceLevel(): ?string;

    /**
     * Whether the profile produces an invoice compliant with EN 16931 (MINIMUM and BASIC WL do not).
     */
    public function isEn16931Compliant(): bool;

    /**
     * Additional business terms that are mandatory in this profile (on top of EN 16931).
     *
     * @return list<string>
     */
    public function requiredBusinessTerms(): array;

    /**
     * Additional validation rules of the profile.
     *
     * @return list<Rule>
     */
    public function rules(): array;

    /**
     * Business process identifier (BT-23) set by default, if any.
     */
    public function defaultBusinessProcess(): ?string;
}
