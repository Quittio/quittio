<?php

declare(strict_types=1);

namespace Quittio\Core\View\Model;

use Quittio\Core\View\FormattedValue;
use Quittio\Core\View\ViewNode;

/**
 * An invoice line (BG-25), also a sub line.
 *
 * A typed, read-only node of the view model: object syntax and array syntax read the same
 * value, and every read is recorded for the mandatory field guard (see ViewNode).
 *
 * @property-read string $position Line identifier (BT-126)
 * @property-read string $id Line identifier (BT-126)
 * @property-read string $name Item name (BT-153)
 * @property-read DescriptionView $description Item description (BT-154)
 * @property-read ?string $note Line note (BT-127)
 * @property-read ?string $sellerId Seller's item identifier (BT-155)
 * @property-read ?string $buyerId Buyer's item identifier (BT-156)
 * @property-read FormattedValue $quantity Quantity (BT-129)
 * @property-read UnitView $unit Unit of measure (BT-130)
 * @property-read ?FormattedValue $unitPrice Net price (BT-146)
 * @property-read ?FormattedValue $grossUnitPrice Price including VAT
 * @property-read ?FormattedValue $listPrice Gross price before the price discount (BT-148)
 * @property-read ?FormattedValue $baseQuantity Price base quantity (BT-149)
 * @property-read ?FormattedValue $unitPriceBase Base quantity with unit, e.g. "100 pcs"; null for 1
 * @property-read bool $showGrossPrice Print grossUnitPrice
 * @property-read ?FormattedValue $total Line net amount (BT-131)
 * @property-read ?FormattedValue $carryOver Sum of the line net amounts up to this line; only in lineGroups
 * @property-read ?FormattedValue $vatRate VAT rate (BT-152)
 * @property-read string $vatCategory VAT category code (BT-151)
 * @property-read ?FormattedValue $period Line period (BG-26)
 * @property-read ViewList<AttributeView> $attributes Item attributes (BG-32)
 * @property-read ViewList<LineAllowanceChargeView> $allowances Line allowances (BG-27)
 * @property-read ViewList<LineAllowanceChargeView> $charges Line charges (BG-28)
 * @property-read ViewList<LineView> $subLines Sub lines
 * @property-read bool $highlight Shade the line
 * @property-read ?string $groupId Line group
 * @property-read bool $optional Optional line of a document
 * @property-read ?string $alternativeTo Line this line is an alternative to
 * @property-read ViewList<string> $serialNumbers Serial numbers of a document line
 */
final class LineView extends ViewNode {}
