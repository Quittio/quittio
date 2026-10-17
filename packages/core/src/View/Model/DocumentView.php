<?php

declare(strict_types=1);

namespace Quittio\Core\View\Model;

use Quittio\Core\View\ViewNode;

/**
 * The "document" node: type and kind of the invoice or document.
 *
 * A typed, read-only node of the view model: object syntax and array syntax read the same
 * value, and every read is recorded for the mandatory field guard (see ViewNode).
 *
 * @property-read ?string $typeCode Type code (BT-3); null for documents
 * @property-read ?string $type Name of the type code or of the document type
 * @property-read bool $isCreditNote Credit note type code
 * @property-read bool $isSelfBilled Self-billed invoice ("Gutschrift")
 * @property-read ?string $profile Name of the profile of the run
 * @property-read ?string $profileKey Key of the profile of the run
 * @property-read string $kind "invoice" or the document type
 * @property-read bool $isInvoice True for invoices, false for documents
 * @property-read bool $showPrices False for delivery notes
 * @property-read bool $showTotals False for delivery notes, reminders and statements
 */
final class DocumentView extends ViewNode {}
