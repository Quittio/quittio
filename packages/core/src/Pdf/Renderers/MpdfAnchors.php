<?php

declare(strict_types=1);

namespace Quittio\Core\Pdf\Renderers;

use Mpdf\Mpdf;

/**
 * The pages of the named anchors of an mPDF document. The only place that reads mPDF's
 * internal link table (Mpdf::$internallink: name => ['PAGE' => …]); a contract test keeps
 * it in line with mPDF updates.
 *
 * @internal
 */
final class MpdfAnchors
{
    /**
     * @return array<string, int> anchor name => page
     */
    public static function pages(Mpdf $mpdf): array
    {
        $pages = [];
        foreach (is_array($mpdf->internallink) ? $mpdf->internallink : [] as $name => $link) {
            if (is_string($name) && is_array($link) && is_int($link['PAGE'] ?? null)) {
                $pages[$name] = $link['PAGE'];
            }
        }

        return $pages;
    }
}
