<?php

declare(strict_types=1);

namespace Quittio\Core\View\Model;

use Quittio\Core\View\ViewNode;

/**
 * A postal address.
 *
 * A typed, read-only node of the view model: object syntax and array syntax read the same
 * value, and every read is recorded for the mandatory field guard (see ViewNode).
 *
 * @property-read ViewList<string> $lines Formatted address lines, starting with the name
 * @property-read ?string $street Address line 1
 * @property-read ?string $additional Address line 2
 * @property-read ?string $line3 Address line 3
 * @property-read ?string $postcode Post code
 * @property-read ?string $city City
 * @property-read ?string $subdivision Country subdivision
 * @property-read string $countryCode ISO 3166-1 alpha-2 country code
 * @property-read string $country Country name in the locale
 */
final class AddressView extends ViewNode {}
