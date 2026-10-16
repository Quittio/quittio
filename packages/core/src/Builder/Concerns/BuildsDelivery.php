<?php

declare(strict_types=1);

namespace Quittio\Core\Builder\Concerns;

use DateTimeInterface;
use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Builder\Party;
use Quittio\Core\Data\Delivery\Delivery;
use Quittio\Core\Data\Delivery\Period;
use Quittio\Core\Data\Identifier;
use Quittio\Core\Data\Party\Address;
use Quittio\Core\Support\Date;

/**
 * Delivery information (BG-13) and the invoicing period (BG-14).
 *
 * @internal part of {@see InvoiceBuilder}, which holds the state
 */
trait BuildsDelivery
{
    /**
     * Actual delivery or service date (BT-72).
     */
    public function deliveryDate(Date|DateTimeInterface|string $date): static
    {
        $this->delivery = ($this->delivery ?? new Delivery)->with(['actualDeliveryDate' => Date::parse($date, $this->meta->timezone)]);

        return $this;
    }

    /**
     * Deliver-to party and address (BT-70, BG-15).
     */
    public function deliverTo(?string $recipientName, ?Address $address = null, ?Identifier $locationId = null): static
    {
        $this->delivery = ($this->delivery ?? new Delivery)->with([
            'recipientName' => $recipientName,
            'address' => $address,
            'locationId' => $locationId,
        ]);

        return $this;
    }

    /**
     * Replaces the whole delivery information (BG-13), including the delivery date (BT-72).
     */
    public function delivery(Delivery $delivery): static
    {
        $this->delivery = $delivery;

        return $this;
    }

    /**
     * Invoicing period (BG-14).
     */
    public function invoicingPeriod(Date|string|null $start, Date|string|null $end = null): static
    {
        $this->invoicingPeriod = Period::between($start, $end);

        return $this;
    }
}
