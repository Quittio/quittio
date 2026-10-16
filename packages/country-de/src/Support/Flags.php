<?php

declare(strict_types=1);

namespace Quittio\Country\DE\Support;

use Quittio\Core\Data\Meta;

/**
 * Meta flags (InvoiceData::$meta->flags) understood by the German country pack.
 */
final class Flags
{
    /** Small amount invoice up to 250 EUR gross (section 33 UStDV). */
    public const string SMALL_AMOUNT = 'de.small_amount';

    /** Service in connection with a property to a non-business buyer (section 14 (4) no. 9 UStG). */
    public const string PROPERTY_RELATED_SERVICE = 'de.property_related_service';

    /** Intra-community supply of a new vehicle (section 14a (4) UStG). */
    public const string NEW_VEHICLE = 'de.new_vehicle';

    /** The seller is a small business (section 19 UStG), e.g. from config quittio.seller.small_business. */
    public const string SMALL_BUSINESS = Meta::FLAG_SMALL_BUSINESS;

    /**
     * Gross limit of a small amount invoice in EUR.
     *
     * @deprecated the limit depends on the issue date, use TaxTables::smallAmountLimitOn()
     */
    public const string SMALL_AMOUNT_LIMIT = '250.00';
}
