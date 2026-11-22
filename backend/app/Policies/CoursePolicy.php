<?php

namespace App\Policies;

use App\Models\Course;
use App\Models\CourseSection;
use App\Models\User;

class CoursePolicy
{
    /** A course's question bank: admins, or anyone who teaches (or taught) a section of it. */
    public function manageQuestionBank(User $user, Course $course): bool
    {
        return $user->isAdmin()
            || CourseSection::where('course_id', $course->id)->where('teacher_id', $user->id)->exists();
    }
}
