<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 404s /register unless grocery_planner.registration_open is set. Checked
 * per request (not by leaving the route undefined) so the flag can be
 * flipped in .env without touching routes.
 */
class RegistrationOpen
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('grocery_planner.registration_open'), 404);

        return $next($request);
    }
}
