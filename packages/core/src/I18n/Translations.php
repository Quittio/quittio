<?php

declare(strict_types=1);

namespace Quittio\Core\I18n;

/**
 * Holds the translator used for code labels and validation messages.
 *
 * The Laravel integration replaces the default with a bridge to Laravel's translator.
 */
final class Translations
{
    private static ?Translator $translator = null;

    private static int $version = 0;

    public static function translator(): Translator
    {
        return self::$translator ??= new ArrayTranslator;
    }

    public static function use(Translator $translator): void
    {
        self::$translator = $translator;
        self::$version++;
    }

    /**
     * Incremented whenever the translator is replaced, so that lazy registrations can detect it.
     */
    public static function version(): int
    {
        return self::$version;
    }

    public static function reset(): void
    {
        self::$translator = null;
        self::$version++;
    }
}
