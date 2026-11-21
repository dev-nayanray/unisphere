<?php

namespace App\Policies;

use App\Models\CourseSection;
use App\Models\User;

class CourseSectionPolicy
{
    /** Open the classroom: teacher, enrolled students, the department head, admins. */
    public function view(User $user, CourseSection $section): bool
    {
        return $section->canView($user);
    }

    /** Post classwork, take attendance, edit the gradebook. */
    public function manage(User $user, CourseSection $section): bool
    {
        return $section->canManage($user);
    }

    /** Check in to a class session. Students only. */
    public function attend(User $user, CourseSection $section): bool
    {
        return $section->isStudent($user);
    }

    /** See the section's official (draft or published) results. */
    public function viewResults(User $user, CourseSection $section): bool
    {
        return ! $user->isStudent() && $section->canView($user);
    }

    /** Publish results: the registrar or the head of the course's department. */
    public function publishResults(User $user, CourseSection $section): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        $section->loadMissing('course.program.department');

        return $section->course->program->department->head_id === $user->id;
    }
}
