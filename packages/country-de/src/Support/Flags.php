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

    /**
     * Prepayment invoice whose date of the service is not agreed yet (section 14.8 (4) sentence 5
     * UStAE): no DE-USTG-03 warning.
     */
    public const string SERVICE_DATE_OPEN = 'de.service_date_open';

    /**
     * Prepayment invoice that names the expected date or month of the service in its note (set
     * by advance(), section 14.8 (4) sentence 3 UStAE): no DE-USTG-03 warning.
     */
    public const string EXPECTED_SERVICE_DATE = 'de.expected_service_date';

    /**
     * deductAdvance() deducted an advance in full without the amount received being confirmed:
     * DE-USTG-20 asks to confirm it.
     */
    public const string ADVANCE_RECEIPT_UNCONFIRMED = 'de.advance_receipt_unconfirmed';

    /**
     * A final invoice deducted an advance that was settled by self-billing (389), set by
     * deductAdvance(): DE-USTG-19 notes the change of the way of invoicing.
     */
    public const string SELF_BILLED_ADVANCE = 'de.self_billed_advance';

    /** The seller is a small business (section 19 UStG), e.g. from config quittio.seller.small_business. */
    public const string SMALL_BUSINESS = Meta::FLAG_SMALL_BUSINESS;

    /**
     * Gross limit of a small amount invoice in EUR.
     *
     * @deprecated the limit depends on the supply date, use TaxTables::smallAmountLimitOn()
     */
    public const string SMALL_AMOUNT_LIMIT = '250.00';
}
