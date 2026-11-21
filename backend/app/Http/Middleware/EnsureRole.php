<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Usage: ->middleware('role:teacher,department_head')
 * Super admins always pass.
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user || ! $user->is_active) {
            abort(403, 'Your account is inactive.');
        }

        if ($user->hasRole('super_admin') || $user->hasRole(...$roles)) {
            return $next($request);
        }

        abort(403, 'You do not have permission to perform this action.');
    }
}
