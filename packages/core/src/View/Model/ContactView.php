<?php

declare(strict_types=1);

namespace Quittio\Core\View\Model;

use Quittio\Core\View\ViewNode;

/**
 * Contact details of a party.
 *
 * A typed, read-only node of the view model: object syntax and array syntax read the same
 * value, and every read is recorded for the mandatory field guard (see ViewNode).
 *
 * @property-read ?string $name Contact point
 * @property-read ?string $phone Telephone number
 * @property-read ?string $email E-mail address
 */
final class ContactView extends ViewNode {}
