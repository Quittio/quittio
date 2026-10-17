<?php

declare(strict_types=1);

namespace Quittio\Core\View\Model;

use Quittio\Core\View\ViewNode;

/**
 * The design values: colours, font, logo, letter layout, sender line and footer.
 *
 * A typed, read-only node of the view model: object syntax and array syntax read the same
 * value, and every read is recorded for the mandatory field guard (see ViewNode).
 *
 * @property-read string $primaryColor Hex colour
 * @property-read string $secondaryColor Hex colour, falls back to the primary colour
 * @property-read string $textColor Hex colour
 * @property-read string $mutedColor Hex colour
 * @property-read string $font Font family
 * @property-read ?string $fontMedium Family for the medium and semibold weights; null for fonts other than Geist
 * @property-read ?string $logo Prepared logo
 * @property-read string $letterLayout Name of the letter layout
 * @property-read float $addressTop Address field from the top page edge in mm
 * @property-read float $addressLeft Address field from the left page edge in mm
 * @property-read float $pageMarginTop Top page margin of the first page in mm
 * @property-read float $continuationMarginTop Top page margin of the following pages in mm
 * @property-read float $letterheadHeight Space above the address field in mm
 * @property-read float $logoMaxHeight Height of the logo in mm
 * @property-read float $addressHeight Height of the address field in mm
 * @property-read float $addressWidth Width of the address field in mm
 * @property-read float $addressZoneTop Start of the recipient zone within the address field in mm
 * @property-read float $senderLineHeight Height of the sender line in mm
 * @property-read float $noticeZoneHeight Height of the zone for additions and postal notes in mm
 * @property-read ViewList<float> $foldMarks Fold mark positions in mm from the top
 * @property-read ?float $punchMark Punch mark position in mm from the top
 * @property-read string $paper Paper of the presentation
 * @property-read ?string $watermark Watermark text
 * @property-read ?string $watermarkCss Watermark as escaped CSS string
 * @property-read string $senderLine Sender line above the address
 * @property-read ViewList<ViewList<string>> $footerColumns Footer columns, one list of lines per column
 * @property-read bool $footerHasAddress The generated footer starts with the issuer's address
 * @property-read bool $footerHasTaxIds The generated footer shows the issuer's VAT identifier or tax number
 * @property-read bool $showItemNumbers Presentation switch
 * @property-read bool $showGrossPrices Presentation switch
 */
final class DesignView extends ViewNode {}
