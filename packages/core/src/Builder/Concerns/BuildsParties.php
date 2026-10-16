<?php

declare(strict_types=1);

namespace Quittio\Core\Builder\Concerns;

use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Builder\Party;
use Quittio\Core\Builder\PartyBuilder;
use Quittio\Core\Codes\RawCode;
use Quittio\Core\Contracts\ProvidesParty;
use Quittio\Core\Data\Identifier;
use Quittio\Core\Data\Party\Address;
use Quittio\Core\Data\Party\BuyerParty;
use Quittio\Core\Data\Party\PayeeParty;
use Quittio\Core\Data\Party\SellerParty;
use Quittio\Core\Data\Party\TaxRepresentativeParty;
use Quittio\Core\Data\Presentation\Presentation;
use Quittio\Core\Enums\IdentifierScheme;
use Quittio\Core\Exceptions\InvalidArgumentException;

/**
 * Seller, buyer, payee and tax representative (BG-4, BG-7, BG-10, BG-11).
 *
 * @internal part of {@see InvoiceBuilder}, which holds the state
 */
trait BuildsParties
{
    /**
     * The seller (BG-4).
     *
     * A SellerParty, a PartyBuilder (Party::make()) or an application model (ProvidesParty)
     * replaces the seller set before. If the builder was started with the defaults of a seller
     * (applyDefaults(), e.g. Quittio::invoice() with the seller of config/quittio.php or the
     * current Managed issuer), another seller gets nothing of them: everything they set is
     * removed again, i.e. the bank account and the small business flag, for a Managed issuer
     * also payment terms, presentation, locale, time zone and currency. Values the caller set
     * on the builder stay, also when they were set before seller().
     *
     * A callable receives the builder of the seller set before and changes it, e.g.
     * ->seller(fn ($s) => $s->contact('Max Muster')); the seller stays the same, and so do its
     * defaults.
     *
     * @param  SellerParty|PartyBuilder|ProvidesParty|callable(PartyBuilder): mixed  $seller
     */
    public function seller(SellerParty|PartyBuilder|ProvidesParty|callable $seller): static
    {
        if ($seller instanceof SellerParty || $seller instanceof PartyBuilder || $seller instanceof ProvidesParty) {
            $this->removeDefaults();
            $this->seller = $seller instanceof SellerParty ? $seller : self::partyBuilder($seller);

            return $this;
        }

        $builder = $this->seller instanceof SellerParty ? PartyBuilder::fromSeller($this->seller) : ($this->seller ?? new PartyBuilder);
        $seller($builder);
        $this->seller = $builder;

        return $this;
    }

    /**
     * The buyer (BG-7): a BuyerParty, a PartyBuilder (Party::make()) or an application model
     * (ProvidesParty), e.g. ->buyer($customer), replace the buyer set before; a callable
     * receives the builder of the buyer set before and changes it.
     *
     * @param  BuyerParty|PartyBuilder|ProvidesParty|callable(PartyBuilder): mixed  $buyer
     */
    public function buyer(BuyerParty|PartyBuilder|ProvidesParty|callable $buyer): static
    {
        if ($buyer instanceof BuyerParty || $buyer instanceof PartyBuilder || $buyer instanceof ProvidesParty) {
            $this->buyer = $buyer instanceof BuyerParty ? $buyer : self::partyBuilder($buyer);

            return $this;
        }

        $builder = $this->buyer instanceof BuyerParty ? PartyBuilder::fromBuyer($this->buyer) : ($this->buyer ?? new PartyBuilder);
        $buyer($builder);
        $this->buyer = $builder;

        return $this;
    }

    /**
     * Payee (BG-10), if different from the seller (e.g. a factoring company): the name with the
     * identifier (BT-60) and the legal registration identifier (BT-61) and their schemes, a
     * PartyBuilder (Party::make('Factoring AG')->identifier(…)), a PayeeParty, or a callable that
     * receives a PartyBuilder and, like seller() and buyer(), continues an earlier payee.
     *
     * @param  PayeeParty|PartyBuilder|string|callable(PartyBuilder): mixed  $payee
     *
     * @throws InvalidArgumentException for identifiers or schemes next to a PayeeParty or a callable
     */
    public function payee(
        PayeeParty|PartyBuilder|string|callable $payee,
        ?string $identifier = null,
        ?string $legalRegistration = null,
        IdentifierScheme|RawCode|string|null $identifierScheme = null,
        IdentifierScheme|RawCode|string|null $legalRegistrationScheme = null,
    ): static {
        if (is_string($payee)) {
            $this->payee = new PayeeParty(
                $payee,
                $identifier === null ? null : Identifier::of($identifier, $identifierScheme),
                $legalRegistration === null ? null : Identifier::of($legalRegistration, $legalRegistrationScheme),
            );

            return $this;
        }
        if ($identifier !== null || $legalRegistration !== null || $identifierScheme !== null || $legalRegistrationScheme !== null) {
            throw new InvalidArgumentException('Identifiers and schemes of the payee are given with its name only; set them on the PayeeParty or with the PartyBuilder of the callable.');
        }
        if ($payee instanceof PayeeParty) {
            $this->payee = $payee;

            return $this;
        }
        if ($payee instanceof PartyBuilder) {
            $this->payee = clone $payee;

            return $this;
        }

        $builder = $this->payee instanceof PayeeParty ? PartyBuilder::fromPayee($this->payee) : ($this->payee ?? new PartyBuilder);
        $payee($builder);
        $this->payee = $builder;

        return $this;
    }

    /**
     * The seller's tax representative (BG-11): name (BT-62), VAT identifier (BT-63) and
     * postal address (BG-12).
     */
    public function taxRepresentative(string $name, string $vatId, Address $address): static
    {
        $this->taxRepresentative = new TaxRepresentativeParty($name, $vatId, $address);

        return $this;
    }

    /**
     * The builder of a party given to seller() or buyer(): a builder is copied, so a party used
     * as template stays unchanged.
     */
    private static function partyBuilder(PartyBuilder|ProvidesParty $party): PartyBuilder
    {
        return $party instanceof PartyBuilder ? clone $party : (new PartyBuilder)->fromModel($party);
    }
}
