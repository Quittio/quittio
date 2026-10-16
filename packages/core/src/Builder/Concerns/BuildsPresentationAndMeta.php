<?php

declare(strict_types=1);

namespace Quittio\Core\Builder\Concerns;

use DateTimeInterface;
use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Data\Meta;
use Quittio\Core\Data\Presentation\Presentation;
use Quittio\Core\Exceptions\InvalidArgumentException;
use Quittio\Core\Support\Amount;
use Quittio\Core\Support\Date;

/**
 * Presentation, locale, time zone, custom values, flags and the source of the invoice.
 *
 * @internal part of {@see InvoiceBuilder}, which holds the state
 */
trait BuildsPresentationAndMeta
{
    /**
     * Design of this invoice in PDF and HTML: a Presentation replaces it, an array changes single
     * values (->presentation(['introText' => 'Thank you for your order.', 'showGrossPrices' => true])),
     * a callable receives the current Presentation and returns the new one.
     *
     * @param  Presentation|array<string, mixed>|callable(Presentation): Presentation  $presentation
     */
    public function presentation(Presentation|array|callable $presentation): static
    {
        $current = $this->presentation ?? new Presentation;
        if (is_array($presentation)) {
            $changes = [];
            foreach ($presentation as $property => $value) {
                if (! is_string($property)) {
                    throw new InvalidArgumentException('presentation() takes the changes by property name, e.g. [\'introText\' => \'Thank you.\'].');
                }
                $changes[$property] = $value;
            }
            $this->presentation = $current->with($changes);

            return $this;
        }
        $this->presentation = $presentation instanceof Presentation ? $presentation : $presentation($current);

        return $this;
    }

    /**
     * Language of the invoice: labels, generated texts and the exemption texts of presets.
     * The locale of a render call wins; without both, the configured locale or the country default.
     */
    public function locale(string $locale): static
    {
        $this->meta = $this->meta->with(['locale' => $locale]);

        return $this;
    }

    /**
     * Time zone for DateTimeInterface values and for the default issue date; call it before the
     * date setters.
     */
    public function timezone(string $timezone): static
    {
        $this->meta = $this->meta->with(['timezone' => $timezone]);

        return $this;
    }

    /**
     * Free data for templates (view model key meta.custom); the same key replaces the value.
     */
    public function custom(string $key, mixed $value): static
    {
        $this->meta = $this->meta->with(['custom' => [...$this->meta->custom, $key => $value]]);

        return $this;
    }

    /**
     * Sets a flag that country packs evaluate (e.g. a small amount invoice in Germany).
     */
    public function flag(string $flag, bool $enabled = true): static
    {
        $this->meta = $this->meta->withFlag($flag, $enabled);
        if ($this->defaults !== null) {
            $this->explicitFlags[$flag] = true;
        }

        return $this;
    }

    /**
     * Origin of the data, e.g. "shop" (view model key meta.source).
     */
    public function source(string $source): static
    {
        $this->meta = $this->meta->with(['source' => $source]);

        return $this;
    }
}
