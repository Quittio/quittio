<?php

declare(strict_types=1);

namespace Quittio\Core\Countries;

/**
 * Country specific export format (e.g. DATEV for Germany, FEC for France).
 * Used by the Managed layer; country packs only declare which exporters they provide.
 */
interface Exporter
{
    public function key(): string;
}
