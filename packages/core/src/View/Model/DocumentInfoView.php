<?php

declare(strict_types=1);

namespace Quittio\Core\View\Model;

use Quittio\Core\View\FormattedValue;
use Quittio\Core\View\ViewNode;

/**
 * Information of documents (quotes, delivery notes, reminders, statements …).
 *
 * A typed, read-only node of the view model: object syntax and array syntax read the same
 * value, and every read is recorded for the mandatory field guard (see ViewNode).
 *
 * @property-read string $type Document type value
 * @property-read ?FormattedValue $validUntil Quote validity
 * @property-read ?string $reference Preceding document
 * @property-read ?FormattedValue $referenceDate Date of the preceding document
 * @property-read ?FormattedValue $deliveryDate Delivery date
 * @property-read ViewList<PackageView> $packages Packages
 * @property-read ViewList<LineOptionsView> $lines Line options keyed by line identifier
 * @property-read ?DunningView $dunning Payment reminder
 * @property-read ?StatementView $statement Account statement
 * @property-read bool $showAcceptance Print the acceptance field (quotes)
 */
final class DocumentInfoView extends ViewNode {}
