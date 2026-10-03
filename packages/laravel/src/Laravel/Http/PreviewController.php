<?php

declare(strict_types=1);

namespace Quittio\Laravel\Http;

use Illuminate\Contracts\View\Factory;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Quittio\Laravel\Support\Preview;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Browser preview of the sample scenarios (local environment only, specification 11.12).
 */
final readonly class PreviewController
{
    public function __construct(
        private Preview $preview,
        private Factory $views,
    ) {}

    public function index(): Response
    {
        return new Response($this->views->make('quittio::preview.index', ['scenarios' => $this->preview->scenarios()])->render());
    }

    public function show(Request $request, string $scenario): SymfonyResponse
    {
        if (! in_array($scenario, $this->preview->scenarios(), true)) {
            throw new NotFoundHttpException;
        }

        $format = $request->query('format') === 'pdf' ? 'pdf' : 'html';
        $template = $request->query('template');
        $locale = $request->query('locale');
        $result = $this->preview->render($scenario, $format, is_string($template) ? $template : null, is_string($locale) ? $locale : null);

        if ($format === 'pdf') {
            return $result->stream();
        }

        return new Response($result->html(), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
