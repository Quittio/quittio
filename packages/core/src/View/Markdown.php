<?php

declare(strict_types=1);

namespace Quittio\Core\View;

use League\CommonMark\CommonMarkConverter;

/**
 * Safe Markdown subset for descriptions and texts: paragraphs, lists, bold and italic.
 * Raw HTML in the input is removed, everything else is escaped.
 */
final class Markdown
{
    private const string ALLOWED_TAGS = '<p><br><ul><ol><li><strong><em>';

    private static ?CommonMarkConverter $converter = null;

    public static function toHtml(?string $text): string
    {
        if ($text === null || trim($text) === '') {
            return '';
        }

        self::$converter ??= new CommonMarkConverter([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 5,
        ]);

        $html = (string) self::$converter->convert($text);

        return trim(strip_tags($html, self::ALLOWED_TAGS));
    }
}
