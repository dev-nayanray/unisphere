<?php

namespace App\Policies;

use App\Models\Registration;
use App\Models\User;

class RegistrationPolicy
{
    /** Only the student can drop their own course. */
    public function drop(User $user, Registration $registration): bool
    {
        return $registration->student_id === $user->id;
    }

    /** The registrar, the student's advisor, or the head of the course's department. */
    public function decide(User $user, Registration $registration): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        $registration->loadMissing(['student.student', 'section.course.program.department']);

        return $registration->student->student?->advisor_id === $user->id
            || $registration->section->course->program->department->head_id === $user->id;
    }
}
