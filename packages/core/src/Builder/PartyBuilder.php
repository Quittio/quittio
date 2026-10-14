<?php

declare(strict_types=1);

namespace Quittio\Core\Builder;

use Quittio\Core\Codes\RawCode;
use Quittio\Core\Contracts\ProvidesParty;
use Quittio\Core\Data\Identifier;
use Quittio\Core\Data\Party\Address;
use Quittio\Core\Data\Party\BuyerParty;
use Quittio\Core\Data\Party\Contact;
use Quittio\Core\Data\Party\ElectronicAddress;
use Quittio\Core\Data\Party\PayeeParty;
use Quittio\Core\Data\Party\SellerParty;
use Quittio\Core\Enums\ElectronicAddressScheme;
use Quittio\Core\Enums\IdentifierScheme;
use Quittio\Core\Exceptions\InvalidInvoiceData;

/**
 * Fluent builder for the seller (BG-4), the buyer (BG-7) and the payee (BG-10).
 */
final class PartyBuilder
{
    private ?string $name = null;

    private ?string $tradingName = null;

    private ?string $vatId = null;

    private ?string $taxNumber = null;

    private ?Identifier $legalRegistration = null;

    private ?string $additionalLegalInfo = null;

    /** @var list<Identifier> */
    private array $identifiers = [];

    private ?Address $address = null;

    private ?ElectronicAddress $electronicAddress = null;

    private ?Contact $contact = null;

    private bool $consumer = false;

    public static function fromSeller(SellerParty $seller): self
    {
        $builder = new self;
        $builder->name = $seller->name;
        $builder->tradingName = $seller->tradingName;
        $builder->vatId = $seller->vatId;
        $builder->taxNumber = $seller->taxNumber;
        $builder->legalRegistration = $seller->legalRegistration;
        $builder->additionalLegalInfo = $seller->additionalLegalInfo;
        $builder->identifiers = $seller->identifiers;
        $builder->address = $seller->address;
        $builder->electronicAddress = $seller->electronicAddress;
        $builder->contact = $seller->contact;

        return $builder;
    }

    public static function fromBuyer(BuyerParty $buyer): self
    {
        $builder = new self;
        $builder->name = $buyer->name;
        $builder->tradingName = $buyer->tradingName;
        $builder->vatId = $buyer->vatId;
        $builder->legalRegistration = $buyer->legalRegistration;
        $builder->identifiers = $buyer->identifier === null ? [] : [$buyer->identifier];
        $builder->address = $buyer->address;
        $builder->electronicAddress = $buyer->electronicAddress;
        $builder->contact = $buyer->contact;
        $builder->consumer = $buyer->isConsumer;

        return $builder;
    }

    public static function fromPayee(PayeeParty $payee): self
    {
        $builder = new self;
        $builder->name = $payee->name;
        $builder->identifiers = $payee->identifier === null ? [] : [$payee->identifier];
        $builder->legalRegistration = $payee->legalRegistration;

        return $builder;
    }

    public function fromModel(ProvidesParty $model): self
    {
        $model->fillParty($this);

        return $this;
    }

    public function name(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function tradingName(?string $tradingName): self
    {
        $this->tradingName = $tradingName;

        return $this;
    }

    public function vatId(?string $vatId): self
    {
        $this->vatId = $vatId === null ? null : strtoupper(str_replace(' ', '', $vatId));

        return $this;
    }

    public function taxNumber(?string $taxNumber): self
    {
        $this->taxNumber = $taxNumber;

        return $this;
    }

    public function legalRegistration(?string $value, IdentifierScheme|RawCode|string|null $scheme = null): self
    {
        $this->legalRegistration = $value === null ? null : Identifier::of($value, $scheme);

        return $this;
    }

    public function additionalLegalInfo(?string $info): self
    {
        $this->additionalLegalInfo = $info;

        return $this;
    }

    /**
     * Adds an identifier (BT-29 seller, BT-46 buyer, BT-60 payee), e.g. a GLN or the customer
     * number. Buyer and payee take the first one.
     */
    public function identifier(string $value, IdentifierScheme|RawCode|string|null $scheme = null): self
    {
        $this->identifiers[] = Identifier::of($value, $scheme);

        return $this;
    }

    public function address(
        ?string $line1,
        ?string $postcode,
        ?string $city,
        string $countryCode,
        ?string $line2 = null,
        ?string $line3 = null,
        ?string $subdivision = null,
    ): self {
        $this->address = new Address(
            countryCode: strtoupper($countryCode),
            line1: $line1,
            line2: $line2,
            line3: $line3,
            city: $city,
            postcode: $postcode,
            subdivision: $subdivision,
        );

        return $this;
    }

    public function postalAddress(Address $address): self
    {
        $this->address = $address;

        return $this;
    }

    public function electronicAddress(string $value, ElectronicAddressScheme|RawCode|string $scheme = ElectronicAddressScheme::Email): self
    {
        $this->electronicAddress = ElectronicAddress::of($value, $scheme);

        return $this;
    }

    public function contact(?string $name = null, ?string $phone = null, ?string $email = null): self
    {
        $this->contact = new Contact($name, $phone, $email);

        return $this;
    }

    /**
     * Marks the buyer as a consumer (B2C): gross prices, no e-invoice obligation.
     */
    public function consumer(bool $consumer = true): self
    {
        $this->consumer = $consumer;

        return $this;
    }

    public function buildSeller(): SellerParty
    {
        return new SellerParty(
            name: $this->name ?? throw InvalidInvoiceData::missing('BT-27', 'seller name'),
            address: $this->address ?? throw InvalidInvoiceData::missing('BG-5', 'seller postal address'),
            tradingName: $this->tradingName,
            identifiers: $this->identifiers,
            legalRegistration: $this->legalRegistration,
            vatId: $this->vatId,
            taxNumber: $this->taxNumber,
            additionalLegalInfo: $this->additionalLegalInfo,
            electronicAddress: $this->electronicAddress,
            contact: $this->contact,
        );
    }

    /**
     * Payee (BG-10): name, the first identifier (BT-60) and the legal registration (BT-61);
     * the other values of the builder do not belong to the payee.
     */
    public function buildPayee(): PayeeParty
    {
        return new PayeeParty(
            name: $this->name ?? throw InvalidInvoiceData::missing('BT-59', 'payee name'),
            identifier: $this->identifiers[0] ?? null,
            legalRegistration: $this->legalRegistration,
        );
    }

    public function buildBuyer(): BuyerParty
    {
        return new BuyerParty(
            name: $this->name ?? throw InvalidInvoiceData::missing('BT-44', 'buyer name'),
            address: $this->address ?? throw InvalidInvoiceData::missing('BG-8', 'buyer postal address'),
            tradingName: $this->tradingName,
            identifier: $this->identifiers[0] ?? null,
            legalRegistration: $this->legalRegistration,
            vatId: $this->vatId,
            electronicAddress: $this->electronicAddress,
            contact: $this->contact,
            isConsumer: $this->consumer,
        );
    }
}
