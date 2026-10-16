<?php

declare(strict_types=1);

namespace Quittio\Core\Profiles\Capabilities;

/**
 * Profiles that transmit sub lines (BG-DEX-01 of the XRechnung extension, the line hierarchy of
 * EXTENDED). Invoices with sub lines are not upgraded to EXTENDED when they use such a profile.
 */
interface SupportsSubLines {}
