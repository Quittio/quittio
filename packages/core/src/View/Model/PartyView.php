<?php

declare(strict_types=1);

namespace Quittio\Core\View\Model;

use Quittio\Core\View\ViewNode;

/**
 * A party: "seller" and "buyer", and "issuer" and "recipient", which stand for one of them.
 *
 * A typed, read-only node of the view model: object syntax and array syntax read the same
 * value, and every read is recorded for the mandatory field guard (see ViewNode).
 *
 * @property-read string $name Name (BT-27, BT-44)
 * @property-read ?string $tradingName Trading name (BT-28, BT-45)
 * @property-read string $displayName Trading name, otherwise name
 * @property-read AddressView $address Postal address (BG-5, BG-8)
 * @property-read ?string $vatId VAT identifier (BT-31, BT-48)
 * @property-read ?string $taxNumber Tax number (BT-32); seller only
 * @property-read ?string $legalRegistration Legal registration identifier (BT-30, BT-47)
 * @property-read ?string $additionalLegalInfo Additional legal information (BT-33); seller only
 * @property-read ?string $customerNumber Buyer identifier (BT-46); buyer only
 * @property-read ?ContactView $contact Contact (BG-6, BG-9)
 * @property-read ?string $electronicAddress Electronic address (BT-34); seller only
 * @property-read ?ViewList<string> $identifiers Seller identifiers (BT-29); null for the buyer
 * @property-read ?bool $isConsumer Consumer flag; null for the seller
 */
final class PartyView extends ViewNode {}
