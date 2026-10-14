<?php

declare(strict_types=1);

namespace Quittio\Core\View;

use League\CommonMark\CommonMarkConverter;

/**
 * Safe Markdown subset for texts: paragraphs, lists, bold and italic. HTML in the input is
 * shown as text, links keep only their text.
 *
 * Descriptions and notes are also part of the e-invoice XML and are printed as plain text
 * by default ({@see self::plain()}); Markdown for them is opt-in (option "markdown"), because
 * formatting changes the displayed text compared with the XML (e.g. "*" for emphasis).
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
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 5,
        ]);

        $html = (string) self::$converter->convert($text);

        return trim(strip_tags($html, self::ALLOWED_TAGS));
    }

    /**
     * Plain text as HTML: escaped, line breaks kept, nothing interpreted.
     */
    public static function plain(?string $text): string
    {
        if ($text === null || trim($text) === '') {
            return '';
        }

        return nl2br(htmlspecialchars(trim($text), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false);
    }
}
