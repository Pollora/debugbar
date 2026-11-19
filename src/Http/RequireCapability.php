<?php

declare(strict_types=1);

namespace Pollora\Debugbar\Http;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Pollora\Debugbar\Activation;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps Debugbar's endpoints (stored requests, assets, cache, query explain)
 * for users who hold `debugbar-pollora.capability`.
 *
 * Debugbar only guards them by environment and address; without this, any
 * visitor of a debug site could read stored requests: SQL, options, paths.
 */
final class RequireCapability
{
    public function __construct(private readonly Application $app) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! Activation::mayView($this->app)) {
            abort(403, 'The debug bar needs the '.Activation::capability($this->app).' capability.');
        }

        return $next($request);
    }
}
