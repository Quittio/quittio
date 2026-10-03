<?php

declare(strict_types=1);

namespace Quittio\Core\Profiles\Capabilities;

/**
 * Marker for profiles that require the VAT category rate (BT-119) in every VAT breakdown,
 * including category "O" (written as 0). Example: XRechnung (BR-DE-14).
 */
interface RequiresVatBreakdownRate {}
