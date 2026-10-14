<?php

declare(strict_types=1);

namespace Quittio\Core\View;

/**
 * Position of the address field and marks on the first page, in millimetres from the
 * top left corner (e.g. DIN 5008 form A/B in the German country pack).
 */
final readonly class LetterLayout
{
    /**
     * @param  list<float>  $foldMarks  distances of fold marks from the top edge
     * @param  float  $addressZoneTop  start of the recipient address within the address field
     *                                 (DIN 5008: below the 17.7 mm "Zusatz- und Vermerkzone", whose
     *                                 last line holds the sender line)
     */
    public function __construct(
        public string $name,
        public float $addressTop = 45.0,
        public float $addressLeft = 20.0,
        public float $addressWidth = 85.0,
        public float $addressHeight = 45.0,
        public float $senderLineHeight = 5.0,
        public array $foldMarks = [],
        public ?float $punchMark = null,
        public float $addressZoneTop = 17.7,
    ) {}

    /**
     * Layout without norm: address field where the default template places it.
     */
    public static function free(): self
    {
        return new self('free');
    }

    /**
     * Height of the letterhead above the address field for a given top page margin.
     */
    public function letterheadHeight(float $pageMarginTop): float
    {
        return max(0.0, $this->addressTop - $pageMarginTop);
    }
}
