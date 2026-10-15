<?php

declare(strict_types=1);

namespace Quittio\Core\Rendering;

use Quittio\Core\Data\Presentation\Presentation;
use Quittio\Core\Exceptions\InvalidArgumentException;
use Quittio\Core\Profiles\Profile;
use Quittio\Core\Serialization\ArrayHydrator;
use Quittio\Core\Serialization\ArrayNormalizer;

/**
 * Per call options of a render run; unset values fall back to the configuration.
 */
final readonly class RenderOptions
{
    /** Version of the array format of toArray(). */
    public const int ARRAY_VERSION = 1;

    /**
     * @param  list<string>  $formats  empty = configured or automatically chosen format
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public array $formats = [],
        public Profile|string|null $profile = null,
        public ?string $template = null,
        public ?string $locale = null,
        public ?string $country = null,
        public ?string $validation = null,
        public ?Presentation $presentation = null,
        public array $options = [],
    ) {}

    /**
     * The options as array of scalars, e.g. for a queued job; fromArray() restores them.
     * A profile object is stored by its key, so it must be registered where the array is read.
     *
     * @return array{_version: int, formats: list<string>, profile: ?string, template: ?string, locale: ?string, country: ?string, validation: ?string, presentation: ?array<string, mixed>, options: array<string, mixed>}
     *
     * @throws InvalidArgumentException when an option is not a scalar, null or an array of them
     */
    public function toArray(): array
    {
        $options = $this->options;
        array_walk_recursive($options, static function (mixed $value, int|string $key): void {
            if ($value !== null && ! is_scalar($value)) {
                throw new InvalidArgumentException(sprintf('The render option "%s" cannot be stored: %s is neither a scalar nor null.', $key, get_debug_type($value)));
            }
        });

        return [
            '_version' => self::ARRAY_VERSION,
            'formats' => $this->formats,
            'profile' => $this->profile instanceof Profile ? $this->profile->key() : $this->profile,
            'template' => $this->template,
            'locale' => $this->locale,
            'country' => $this->country,
            'validation' => $this->validation,
            'presentation' => $this->presentation === null ? null : (new ArrayNormalizer)->normalize($this->presentation),
            'options' => $this->options,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $data  an array of toArray()
     *
     * @throws InvalidArgumentException for an unknown version or invalid values
     */
    public static function fromArray(array $data): self
    {
        $version = $data['_version'] ?? null;
        if ($version !== self::ARRAY_VERSION) {
            throw new InvalidArgumentException(sprintf('Render options of version %s cannot be read; this installation reads version %d.', is_scalar($version) ? $version : get_debug_type($version), self::ARRAY_VERSION));
        }

        $string = static function (string $key) use ($data): ?string {
            $value = $data[$key] ?? null;
            if ($value !== null && ! is_string($value)) {
                throw new InvalidArgumentException(sprintf('The render option "%s" must be a string or null, %s given.', $key, get_debug_type($value)));
            }

            return $value;
        };

        return new self(
            formats: array_map(static fn (mixed $format): string => is_string($format) ? $format : throw new InvalidArgumentException('The render option "formats" must be a list of format keys.'), array_values(self::array($data, 'formats'))),
            profile: $string('profile'),
            template: $string('template'),
            locale: $string('locale'),
            country: $string('country'),
            validation: $string('validation'),
            presentation: ($data['presentation'] ?? null) === null ? null : (new ArrayHydrator)->hydrate(Presentation::class, self::array($data, 'presentation')),
            options: self::array($data, 'options'),
        );
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<string, mixed>
     */
    private static function array(array $data, string $key): array
    {
        $value = $data[$key] ?? [];
        if (! is_array($value)) {
            throw new InvalidArgumentException(sprintf('The render option "%s" must be an array, %s given.', $key, get_debug_type($value)));
        }

        $typed = [];
        foreach ($value as $name => $item) {
            $typed[is_int($name) ? (string) $name : $name] = $item;
        }

        return $typed;
    }
}
