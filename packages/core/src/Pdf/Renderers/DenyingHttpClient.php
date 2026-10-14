<?php

declare(strict_types=1);

namespace Quittio\Core\Pdf\Renderers;

use InvalidArgumentException;
use Mpdf\Http\ClientInterface;
use Psr\Http\Message\RequestInterface;

/**
 * mPDF HTTP client that refuses every request (defence in depth).
 *
 * Quittio prepares every logo as a local file or an inlined data URI, so mPDF never needs to
 * reach the network. Should a reference still resolve to a URL, this client stops the request
 * instead of fetching it, so no bypass of {@see \Quittio\Core\Pdf\LogoCache} can cause a
 * server-side request. {@see \Mpdf\AssetFetcher} catches the exception and renders the asset
 * as missing.
 */
final class DenyingHttpClient implements ClientInterface
{
    public function sendRequest(RequestInterface $request): never
    {
        throw new InvalidArgumentException(sprintf('Quittio does not load remote resources while rendering (blocked "%s").', $request->getUri()));
    }
}
