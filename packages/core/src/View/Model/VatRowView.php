<?php

declare(strict_types=1);

namespace Quittio\Core\View\Model;

use Quittio\Core\View\FormattedValue;
use Quittio\Core\View\ViewNode;

/**
 * A row of the VAT breakdown (BG-23).
 *
 * A typed, read-only node of the view model: object syntax and array syntax read the same
 * value, and every read is recorded for the mandatory field guard (see ViewNode).
 *
 * @property-read string $label Ready text, e.g. "19% VAT on 1,000.00 €"
 * @property-read string $category VAT category code (BT-118)
 * @property-read string $categoryLabel Name of the category
 * @property-read ?FormattedValue $rate VAT rate (BT-119)
 * @property-read ?FormattedValue $base Taxable amount (BT-116)
 * @property-read ?FormattedValue $amount VAT amount (BT-117); null for margin schemes and travel agents
 * @property-read bool $isSpecialScheme Margin scheme or travel agents
 * @property-read ?string $exemptionReason Exemption reason (BT-120)
 * @property-read ?string $exemptionReasonCode Exemption reason code (BT-121)
 */
final class VatRowView extends ViewNode {}
