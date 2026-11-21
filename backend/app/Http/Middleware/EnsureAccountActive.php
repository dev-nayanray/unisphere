<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Deactivated accounts are refused on every authenticated route, even with a valid token. */
class EnsureAccountActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() && ! $request->user()->is_active) {
            return response()->json([
                'message' => 'Your account has been deactivated. Contact the registrar\'s office.',
                'code' => 'account_inactive',
            ], 403);
        }

        return $next($request);
    }
}
