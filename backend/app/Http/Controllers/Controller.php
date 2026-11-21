<?php

namespace App\Http\Controllers;

use App\Models\CourseSection;

abstract class Controller
{
    /** Abort unless the signed-in user can open this classroom. */
    protected function ensureCanView(CourseSection $section): void
    {
        abort_unless($section->canView(request()->user()), 403, 'You are not a member of this class.');
    }

    /** Abort unless the signed-in user teaches this classroom (or is an admin). */
    protected function ensureCanManage(CourseSection $section): void
    {
        abort_unless($section->canManage(request()->user()), 403, 'Only the class teacher can do this.');
    }
}
