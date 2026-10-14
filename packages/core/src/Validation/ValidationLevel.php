<?php

declare(strict_types=1);

namespace Quittio\Core\Validation;

/**
 * Validation levels.
 */
enum ValidationLevel: string
{
    /** Structure and data types of the DTOs. */
    case L1 = 'L1';
    /** Mandatory and allowed fields of the profile. */
    case L2 = 'L2';
    /** Business rules (EN 16931, country packs, networks). */
    case L3 = 'L3';
    /** XML against XSD. */
    case L4 = 'L4';
    /** XML against Schematron (KoSIT, Peppol). */
    case L5 = 'L5';
    /** PDF/A conformance (veraPDF). */
    case L6 = 'L6';
    /** Plausibility (IBAN, VAT identifiers, Leitweg-ID, dates). */
    case L7 = 'L7';
}
