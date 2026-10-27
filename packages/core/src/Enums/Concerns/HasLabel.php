<?php

declare(strict_types=1);

namespace Quittio\Core\Enums\Concerns;

use Quittio\Core\I18n\Translations;

/**
 * Translated labels for code list enums, e.g. "Stunde" for HUR.
 *
 * Labels live in resources/lang/{locale}/codes.php under the enum's label group.
 *
 * @phpstan-require-implements \BackedEnum
 */
trait HasLabel
{
    /**
     * Group of the labels of this code list in resources/lang/{locale}/codes.php.
     */
    abstract public static function labelGroup(): string;

    /**
     * Translated label of the code in $locale (null: the translator's locale), e.g. "Stunde" for
     * HUR; the code itself when neither $locale nor English has a label.
     */
    public function label(?string $locale = null): string
    {
        $key = 'quittio::codes.'.static::labelGroup().'.'.$this->value;
        $translator = Translations::translator();

        if ($translator->has($key, $locale) || $translator->has($key, 'en')) {
            return $translator->get($key, [], $locale);
        }

        return (string) $this->value;
    }
}
