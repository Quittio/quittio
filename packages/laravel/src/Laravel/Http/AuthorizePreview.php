<?php

declare(strict_types=1);

namespace Quittio\Laravel\Http;

use Closure;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Lets requests reach the preview only in the local environment, or where the gate
 * "viewQuittioPreview" allows it, e.g. for logged-in staff on a staging server:
 *
 *     Gate::define('viewQuittioPreview', fn (User $user) => $user->isAdmin());
 *
 * @internal
 */
final readonly class AuthorizePreview
{
    public const string GATE = 'viewQuittioPreview';

    public function __construct(
        private Application $app,
        private Gate $gate,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $allowed = $this->app->environment('local') === true
            || ($this->gate->has(self::GATE) && $this->gate->forUser($request->user())->allows(self::GATE));
        if (! $allowed) {
            throw new AccessDeniedHttpException;
        }

        return $next($request);
    }
}
