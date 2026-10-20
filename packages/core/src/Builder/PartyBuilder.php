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

    /**
     * A builder with the values of an existing seller, e.g. to change one of them.
     */
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

    /**
     * A builder with the values of an existing buyer.
     */
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

    /**
     * A builder with the values of an existing payee.
     */
    public static function fromPayee(PayeeParty $payee): self
    {
        $builder = new self;
        $builder->name = $payee->name;
        $builder->identifiers = $payee->identifier === null ? [] : [$payee->identifier];
        $builder->legalRegistration = $payee->legalRegistration;

        return $builder;
    }

    /**
     * Lets an application model fill this builder (ProvidesParty).
     */
    public function fromModel(ProvidesParty $model): self
    {
        $model->fillParty($this);

        return $this;
    }

    /**
     * Legal name: seller BT-27, buyer BT-44, payee BT-59. Required.
     */
    public function name(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Trading name: seller BT-28, buyer BT-45; null removes it.
     */
    public function tradingName(?string $tradingName): self
    {
        $this->tradingName = $tradingName;

        return $this;
    }

    /**
     * VAT identifier with country prefix, e.g. "DE123456789" (seller BT-31, buyer BT-48);
     * uppercased, spaces removed; null removes it. A Swiss UID number keeps one space before the
     * note of its VAT registration ("CHE-123.456.789 MWST", also TVA and IVA).
     */
    public function vatId(?string $vatId): self
    {
        $this->vatId = $vatId === null ? null : (string) preg_replace('/^(CHE-?\d{3}\.?\d{3}\.?\d{3})(MWST|TVA|IVA)$/', '$1 $2', strtoupper(str_replace(' ', '', $vatId)));

        return $this;
    }

    /**
     * The seller's tax registration number (BT-32), e.g. the German Steuernummer; ignored for
     * the buyer.
     */
    public function taxNumber(?string $taxNumber): self
    {
        $this->taxNumber = $taxNumber;

        return $this;
    }

    /**
     * Legal registration identifier, e.g. the commercial register number (seller BT-30,
     * buyer BT-47, payee BT-61), with its scheme.
     */
    public function legalRegistration(?string $value, IdentifierScheme|RawCode|string|null $scheme = null): self
    {
        $this->legalRegistration = $value === null ? null : Identifier::of($value, $scheme);

        return $this;
    }

    /**
     * The seller's additional legal information (BT-33), e.g. legal form and share capital;
     * ignored for the buyer.
     */
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

    /**
     * Postal address (seller BG-5, buyer BG-8); the country code (ISO 3166-1 alpha-2) is
     * uppercased. Required for seller and buyer.
     */
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

    /**
     * Postal address as object, see address().
     */
    public function postalAddress(Address $address): self
    {
        $this->address = $address;

        return $this;
    }

    /**
     * Electronic address (seller BT-34, buyer BT-49) with its EAS scheme; default e-mail (EM).
     * Required by XRechnung and Peppol.
     */
    public function electronicAddress(string $value, ElectronicAddressScheme|RawCode|string $scheme = ElectronicAddressScheme::Email): self
    {
        $this->electronicAddress = ElectronicAddress::of($value, $scheme);

        return $this;
    }

    /**
     * Contact (seller BG-6, buyer BG-9): name, phone and e-mail. XRechnung requires all three
     * for the seller.
     */
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

    /**
     * Country code of the postal address set so far (BT-40, BT-55), e.g. for defaults that
     * depend on the seller's country.
     */
    public function countryCode(): ?string
    {
        return $this->address?->countryCode;
    }

    /**
     * The seller party (BG-4).
     *
     * @throws InvalidInvoiceData when the name (BT-27) or the postal address (BG-5) is missing
     */
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

    /**
     * The buyer party (BG-7).
     *
     * @throws InvalidInvoiceData when the name (BT-44) or the postal address (BG-8) is missing
     */
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
