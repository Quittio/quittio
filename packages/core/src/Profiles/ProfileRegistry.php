<?php

declare(strict_types=1);

namespace Quittio\Core\Profiles;

use Quittio\Core\Exceptions\InvalidArgumentException;

/**
 * Known profiles by key. Country packs and network packages register further profiles.
 */
final class ProfileRegistry
{
    /** @var array<string, Profile> */
    private array $profiles = [];

    /** @var array<string, string> */
    private array $aliases = [];

    /**
     * @param  iterable<Profile>  $profiles
     */
    public function __construct(iterable $profiles = [])
    {
        foreach ($profiles as $profile) {
            $this->register($profile);
        }
    }

    /**
     * A registry with the built-in profiles (MINIMUM, BASIC WL, BASIC, EN 16931, EXTENDED, Peppol
     * BIS 3) and the aliases basicwl, en-16931, comfort and peppol-bis-3.
     */
    public static function withDefaults(): self
    {
        $registry = new self([new Minimum, new BasicWl, new Basic, new En16931, new Extended, new PeppolBis3]);
        $registry->alias('basicwl', 'basic-wl');
        $registry->alias('en-16931', 'en16931');
        $registry->alias('comfort', 'en16931');
        $registry->alias('peppol-bis-3', 'peppol');

        return $registry;
    }

    /**
     * Adds $profile under its key(); a profile with the same key is replaced. Changes this
     * instance.
     */
    public function register(Profile $profile): self
    {
        $this->profiles[strtolower($profile->key())] = $profile;

        return $this;
    }

    /**
     * Makes $alias (case-insensitive) another name for the profile key $key, e.g. "comfort" for
     * "en16931". Changes this instance.
     */
    public function alias(string $alias, string $key): self
    {
        $this->aliases[strtolower($alias)] = strtolower($key);

        return $this;
    }

    /**
     * Whether a profile is registered under $key or an alias of it, ignoring case.
     */
    public function has(string $key): bool
    {
        return isset($this->profiles[$this->resolveKey($key)]);
    }

    /**
     * The profile with this key or alias, ignoring case; a Profile instance is returned as is.
     * Throws an InvalidArgumentException that lists the known keys for an unknown profile.
     */
    public function get(Profile|string $profile): Profile
    {
        if ($profile instanceof Profile) {
            return $profile;
        }

        return $this->profiles[$this->resolveKey($profile)]
            ?? throw new InvalidArgumentException(sprintf('Unknown profile "%s". Known profiles: %s.', $profile, implode(', ', array_keys($this->profiles))));
    }

    /**
     * Finds the profile for a specification identifier (BT-24), e.g. when reading an invoice.
     */
    public function bySpecificationId(string $specificationId): ?Profile
    {
        foreach ($this->profiles as $profile) {
            foreach ([\Quittio\Core\Standards\StandardVersion::En16931_2017, \Quittio\Core\Standards\StandardVersion::En16931_2026] as $version) {
                if ($profile->supportsStandard($version) && $profile->specificationId($version) === $specificationId) {
                    return $profile;
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, Profile>
     */
    public function all(): array
    {
        return $this->profiles;
    }

    private function resolveKey(string $key): string
    {
        $key = strtolower($key);

        return $this->aliases[$key] ?? $key;
    }
}
