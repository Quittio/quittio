<?php

declare(strict_types=1);

namespace Quittio\Core\Data\Party;

use Quittio\Core\Data\Concerns\Immutable;
use Quittio\Core\Mapping\Attributes as Q;

/**
 * Postal address (BG-5 seller, BG-8 buyer, BG-12 tax representative, BG-15 deliver to).
 *
 * Business terms: line1 BT-35/50/64/75, line2 BT-36/51/65/76, line3 BT-162/163/164/165,
 * city BT-37/52/66/77, postcode BT-38/53/67/78, subdivision BT-39/54/68/79, country BT-40/55/69/80.
 */
final readonly class Address
{
    use Immutable;

    public function __construct(
        #[Q\Bt('BT-40'), Q\Cardinality('1'), Q\Cii('ram:CountryID'), Q\Ubl('cac:Country/cbc:IdentificationCode')]
        public string $countryCode,
        #[Q\Bt('BT-35'), Q\Cardinality('0..1'), Q\Cii('ram:LineOne'), Q\Ubl('cbc:StreetName')]
        public ?string $line1 = null,
        #[Q\Bt('BT-36'), Q\Cardinality('0..1'), Q\Cii('ram:LineTwo'), Q\Ubl('cbc:AdditionalStreetName')]
        public ?string $line2 = null,
        #[Q\Bt('BT-162'), Q\Cardinality('0..1'), Q\Cii('ram:LineThree'), Q\Ubl('cac:AddressLine/cbc:Line')]
        public ?string $line3 = null,
        #[Q\Bt('BT-37'), Q\Cardinality('0..1'), Q\Cii('ram:CityName'), Q\Ubl('cbc:CityName')]
        public ?string $city = null,
        #[Q\Bt('BT-38'), Q\Cardinality('0..1'), Q\Cii('ram:PostcodeCode'), Q\Ubl('cbc:PostalZone')]
        public ?string $postcode = null,
        #[Q\Bt('BT-39'), Q\Cardinality('0..1'), Q\Cii('ram:CountrySubDivisionName'), Q\Ubl('cbc:CountrySubentity')]
        public ?string $subdivision = null,
    ) {}

    public static function make(?string $line1, ?string $postcode, ?string $city, string $countryCode): self
    {
        return new self(countryCode: strtoupper($countryCode), line1: $line1, city: $city, postcode: $postcode);
    }
}
