<?php

declare(strict_types=1);

namespace Quittio\Core\Validation;

/**
 * Creates validation messages from the message catalog.
 *
 * The catalog key is "{namespace}::rules.{code}" with the entries "message" and "hint".
 * If no translation exists, the fallback text given by the rule is used.
 */
final class Messages
{
    /**
     * @param  array<string, string|int>  $replace
     */
    public static function make(
        ValidationContext $context,
        Severity $severity,
        string $code,
        ValidationLevel $level,
        string $fallback,
        array $replace = [],
        ?string $bt = null,
        ?string $path = null,
        string $namespace = 'quittio',
    ): ValidationMessage {
        $translator = $context->translator();
        $base = $namespace.'::rules.'.$code;

        $message = $translator->has($base.'.message', $context->locale) || $translator->has($base.'.message', 'en')
            ? $translator->get($base.'.message', $replace, $context->locale)
            : self::replace($fallback, $replace);

        $hint = $translator->has($base.'.hint', $context->locale) || $translator->has($base.'.hint', 'en')
            ? $translator->get($base.'.hint', $replace, $context->locale)
            : null;

        return new ValidationMessage(
            severity: $severity,
            code: $code,
            message: $message,
            level: $level,
            bt: $bt,
            path: $path,
            hint: $hint,
        );
    }

    /**
     * @param  array<string, string|int>  $replace
     */
    private static function replace(string $text, array $replace): string
    {
        foreach ($replace as $key => $value) {
            $text = str_replace(':'.$key, (string) $value, $text);
        }

        return $text;
    }
}
