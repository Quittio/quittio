<?php

declare(strict_types=1);

namespace Quittio\Core\Profiles\Capabilities;

/**
 * Marker for profiles that expect cash discounts as structured lines in BT-20,
 * e.g. XRechnung: "#SKONTO#TAGE=14#PROZENT=2.00#".
 */
interface StructuredCashDiscountText {}
