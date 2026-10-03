<?php

declare(strict_types=1);

namespace Quittio\Core\Profiles;

/**
 * XML syntax of an e-invoice.
 */
enum Syntax: string
{
    /** UN/CEFACT Cross Industry Invoice (D16B). */
    case Cii = 'cii';
    /** OASIS UBL 2.1. */
    case Ubl = 'ubl';
}
