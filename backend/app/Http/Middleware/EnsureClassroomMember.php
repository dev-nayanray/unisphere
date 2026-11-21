<?php

namespace App\Http\Middleware;

use App\Models\CourseSection;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Only the teacher, enrolled students, the department head and admins
 * can open a classroom. Expects a {section} route parameter.
 */
class EnsureClassroomMember
{
    public function handle(Request $request, Closure $next): Response
    {
        $section = $request->route('section');

        if (! $section instanceof CourseSection) {
            $section = CourseSection::findOrFail($section);
        }

        abort_unless($section->canView($request->user()), 403, 'You are not a member of this class.');

        return $next($request);
    }
}
