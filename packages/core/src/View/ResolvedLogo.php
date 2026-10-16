<?php

declare(strict_types=1);

namespace Quittio\Core\View;

/**
 * A logo of the presentation as the renderer gets it: a local file or a checked data URI
 * (Pdf\LogoCache), resolved before the view model is built, so that the view layer does no I/O.
 */
final readonly class ResolvedLogo
{
    /**
     * @param  string  $logo  the logo of the presentation (path, data URI or URL)
     * @param  string|null  $src  the resolved value for the template; null when a remote logo could not be loaded
     */
    public function __construct(
        public string $logo,
        public ?string $src,
    ) {}
}
