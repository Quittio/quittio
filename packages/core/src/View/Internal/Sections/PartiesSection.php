<?php

declare(strict_types=1);

namespace Quittio\Core\View\Internal\Sections;

use Quittio\Core\Data\Party\Address;
use Quittio\Core\Data\Party\Contact;
use Quittio\Core\View\CountryNames;
use Quittio\Core\View\Internal\RunScope;
use Quittio\Core\View\InternationalAddressFormatter;

/**
 * Seller, buyer, issuer and recipient, payee, tax representative and delivery.
 *
 * @internal
 */
final readonly class PartiesSection
{
    public function __construct(private RunScope $scope) {}

    /**
     * @return array{seller: array<string, mixed>, buyer: array<string, mixed>, issuer: array<string, mixed>, recipient: array<string, mixed>, payee: array<string, ?string>|null, taxRepresentative: array<string, mixed>|null, delivery: array<string, mixed>|null}
     */
    public function build(): array
    {
        $invoice = $this->scope->invoice;
        $seller = $this->seller();
        $buyer = $this->buyer();

        return [
            'seller' => $seller,
            'buyer' => $buyer,
            // The issuer is shown in letterhead, sender line and footer, the recipient in the address
            // field. A self-billed invoice ("Gutschrift") is issued by the buyer to the seller.
            'issuer' => $this->scope->selfBilled ? $buyer : $seller,
            'recipient' => $this->scope->selfBilled ? $seller : $buyer,
            'payee' => $invoice->payee === null ? null : [
                'name' => $invoice->payee->name,
                'identifier' => $invoice->payee->identifier?->value,
                'legalRegistration' => $invoice->payee->legalRegistration?->value,
            ],
            'taxRepresentative' => $this->taxRepresentative(),
            'delivery' => $this->delivery(),
        ];
    }

    /**
     * View model nodes that stand for others, for the mandatory field guard.
     *
     * @return array<string, string>
     */
    public function aliases(): array
    {
        return $this->scope->selfBilled ? ['issuer' => 'buyer', 'recipient' => 'seller'] : ['issuer' => 'seller', 'recipient' => 'buyer'];
    }

    /**
     * @return array<string, mixed>
     */
    private function seller(): array
    {
        $seller = $this->scope->invoice->seller;

        return [
            'name' => $seller->name,
            'tradingName' => $seller->tradingName,
            'displayName' => $seller->tradingName ?? $seller->name,
            'address' => $this->address($seller->address, [$seller->name]),
            'vatId' => $seller->vatId,
            'taxNumber' => $seller->taxNumber,
            'legalRegistration' => $seller->legalRegistration?->value,
            'additionalLegalInfo' => $seller->additionalLegalInfo,
            'contact' => $this->contact($seller->contact),
            'electronicAddress' => $seller->electronicAddress?->value,
            'identifiers' => array_map(static fn ($i): string => $i->value, $seller->identifiers),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buyer(): array
    {
        $buyer = $this->scope->invoice->buyer;

        return [
            'name' => $buyer->name,
            'tradingName' => $buyer->tradingName,
            'displayName' => $buyer->tradingName ?? $buyer->name,
            'address' => $this->address($buyer->address, [$buyer->name]),
            'vatId' => $buyer->vatId,
            'customerNumber' => $buyer->identifier?->value,
            'legalRegistration' => $buyer->legalRegistration?->value,
            'contact' => $this->contact($buyer->contact),
            'isConsumer' => $buyer->isConsumer,
        ];
    }

    /**
     * @param  list<string>  $nameLines
     * @return array<string, mixed>
     */
    private function address(Address $address, array $nameLines = []): array
    {
        $invoice = $this->scope->invoice;
        $formatter = $this->scope->context->country?->addressFormatter() ?? new InternationalAddressFormatter;
        // The country line depends on the country of the issuer (sender of the letter).
        $sender = ($this->scope->selfBilled ? $invoice->buyer->address : $invoice->seller->address)->countryCode;

        return [
            'lines' => $formatter->format($address, $nameLines, $sender, $this->scope->locale),
            'street' => $address->line1,
            'additional' => $address->line2,
            'line3' => $address->line3,
            'postcode' => $address->postcode,
            'city' => $address->city,
            'subdivision' => $address->subdivision,
            'countryCode' => $address->countryCode,
            'country' => CountryNames::name($address->countryCode, $this->scope->locale),
        ];
    }

    /**
     * @return array<string, ?string>|null
     */
    private function contact(?Contact $contact): ?array
    {
        return $contact === null ? null : ['name' => $contact->name, 'phone' => $contact->phone, 'email' => $contact->email];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function delivery(): ?array
    {
        $delivery = $this->scope->invoice->delivery;
        if ($delivery === null) {
            return null;
        }

        return [
            'recipientName' => $delivery->recipientName,
            'date' => $this->scope->date($delivery->actualDeliveryDate),
            'address' => $delivery->address === null ? null : $this->address($delivery->address, RunScope::filled([$delivery->recipientName])),
        ];
    }

    /**
     * Seller's tax representative (BG-11), shown where the seller's own identifiers are missing.
     *
     * @return array<string, mixed>|null
     */
    private function taxRepresentative(): ?array
    {
        $representative = $this->scope->invoice->sellerTaxRepresentative;

        return $representative === null ? null : [
            'name' => $representative->name,
            'vatId' => $representative->vatId,
            'address' => $this->address($representative->address, [$representative->name]),
        ];
    }
}
