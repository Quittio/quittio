<?php

declare(strict_types=1);

namespace Quittio\Core\Data\Extensions;

use Quittio\Core\Data\Concerns\Immutable;
use Quittio\Core\Data\Payment\PaymentInstructions;
use Quittio\Core\Data\References\DocumentReference;
use Quittio\Core\Mapping\Attributes as Q;

/**
 * Document level data that exists only in the Factur-X/ZUGFeRD EXTENDED profile.
 * The selection covers the practically relevant elements.
 */
final readonly class ExtendedData
{
    use Immutable;

    /**
     * @param  list<DocumentReference>  $additionalReferences
     * @param  list<Installment>  $installments
     * @param  list<PaymentInstructions>  $additionalPaymentInstructions
     */
    public function __construct(
        #[Q\ExtendedOnly]
        public ?ExtendedParty $invoicer = null,
        #[Q\ExtendedOnly]
        public ?ExtendedParty $invoicee = null,
        #[Q\ExtendedOnly]
        public ?ExtendedParty $ultimateCustomer = null,
        #[Q\ExtendedOnly]
        public ?ExtendedParty $shipFrom = null,
        #[Q\ExtendedOnly]
        public ?string $deliveryTermsCode = null,
        #[Q\ExtendedOnly, Q\ListOf(DocumentReference::class)]
        public array $additionalReferences = [],
        #[Q\ExtendedOnly, Q\ListOf(Installment::class)]
        public array $installments = [],
        #[Q\ExtendedOnly, Q\ListOf(PaymentInstructions::class)]
        public array $additionalPaymentInstructions = [],
    ) {}

    public function isEmpty(): bool
    {
        return get_object_vars($this) === get_object_vars(new self);
    }
}
