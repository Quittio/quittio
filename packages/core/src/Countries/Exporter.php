<?php

declare(strict_types=1);

namespace Quittio\Core\Countries;

/**
 * Country specific export format (e.g. DATEV for Germany, FEC for France). Country packs only
 * declare which exporters they provide (CountryPack::exporters()); Quittio runs none of them,
 * applications may.
 */
interface Exporter
{
    /**
     * Key of the export format, e.g. "datev".
     */
    public function key(): string;
}
