<?php

declare(strict_types=1);

namespace Quittio\Core\Validation;

use Quittio\Core\Countries\CountryPack;
use Quittio\Core\I18n\Translations;
use Quittio\Core\I18n\Translator;
use Quittio\Core\Profiles\Profile;

/**
 * Information available to rules while validating an invoice.
 */
final readonly class ValidationContext
{
    /**
     * @param  list<CountryPack>  $countryPacks  leading pack first
     * @param  array<string, mixed>  $options  e.g. attachment_max_bytes, attachments_max_total_bytes
     */
    public function __construct(
        public Profile $profile,
        public array $countryPacks = [],
        public string $locale = 'en',
        public ?Translator $translator = null,
        public array $options = [],
        public ?string $messageLocale = null,
    ) {}

    /**
     * The option $key of the run, e.g. "syntax" while the XML of a syntax is checked; $default when
     * it is not set or null.
     */
    public function option(string $key, mixed $default = null): mixed
    {
        return $this->options[$key] ?? $default;
    }

    /**
     * The option $key as an integer, e.g. "attachment_max_bytes"; $default when it is not set or
     * not an integer.
     */
    public function intOption(string $key, int $default): int
    {
        $value = $this->options[$key] ?? $default;

        return is_int($value) ? $value : $default;
    }

    /**
     * Language of the messages: the configured one (validation.locale), otherwise the language
     * of the run. Rules that check texts of the invoice use $locale instead.
     */
    public function messageLocale(): string
    {
        return $this->messageLocale ?? $this->locale;
    }

    /**
     * Translator for the messages: the one given to this context, otherwise the shared one of
     * Translations::translator().
     */
    public function translator(): Translator
    {
        return $this->translator ?? Translations::translator();
    }

    /**
     * Code of the leading country pack (the seller's country), e.g. "DE"; null without country
     * packs.
     */
    public function leadingCountry(): ?string
    {
        return ($this->countryPacks[0] ?? null)?->code();
    }

    /**
     * Whether a country pack with $code, e.g. "DE", takes part in the run, leading or for the
     * buyer's country.
     */
    public function hasCountry(string $code): bool
    {
        foreach ($this->countryPacks as $pack) {
            if ($pack->code() === $code) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the profile's key is one of $keys, e.g. "xrechnung" or "xrechnung-extension".
     */
    public function profileIs(string ...$keys): bool
    {
        return in_array($this->profile->key(), $keys, true);
    }
}
