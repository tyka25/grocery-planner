<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Shared-secret check for the home-machine sidecar (SIDECAR_TOKEN in .env). */
class SidecarToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('grocery_planner.sidecar.token');

        abort_if($expected === '' || !hash_equals($expected, (string) $request->bearerToken()), 401, 'Bad or missing sidecar token.');

        return $next($request);
    }
}
