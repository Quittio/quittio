<?php

declare(strict_types=1);

namespace Quittio\Country\DE\Layouts;

use Quittio\Core\View\LetterLayout;

/**
 * DIN 5008 business letter layouts: the address field of 85 × 45 mm starts
 * 27 mm (form A) or 45 mm (form B) from the top edge and 20 mm from the left edge. In both forms it
 * holds the Zusatz- und Vermerkzone (17.7 mm, five lines filled from the bottom; without further
 * notes its last line is the Rücksendeangabe) and the Anschriftzone (27.3 mm, six lines), which
 * starts 44.7 mm (A) or 62.7 mm (B) from the top edge. Fold marks at 87/192 mm (A) or
 * 105/210 mm (B), punch mark at 148.5 mm.
 */
final class Din5008
{
    public const string FORM_A = 'din5008a';

    public const string FORM_B = 'din5008b';

    public static function formA(): LetterLayout
    {
        return new LetterLayout(
            name: self::FORM_A,
            addressTop: 27.0,
            addressLeft: 20.0,
            addressWidth: 85.0,
            addressHeight: 45.0,
            foldMarks: [87.0, 192.0],
            punchMark: 148.5,
        );
    }

    public static function formB(): LetterLayout
    {
        return new LetterLayout(
            name: self::FORM_B,
            addressTop: 45.0,
            addressLeft: 20.0,
            addressWidth: 85.0,
            addressHeight: 45.0,
            foldMarks: [105.0, 210.0],
            punchMark: 148.5,
        );
    }

    /**
     * @return array<string, LetterLayout>
     */
    public static function all(): array
    {
        return [self::FORM_A => self::formA(), self::FORM_B => self::formB(), 'free' => LetterLayout::free()];
    }
}
