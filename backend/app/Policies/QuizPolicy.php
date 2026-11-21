<?php

namespace App\Policies;

use App\Models\Quiz;
use App\Models\User;

class QuizPolicy
{
    /** Teachers always; students only while the quiz is open. */
    public function view(User $user, Quiz $quiz): bool
    {
        $section = $quiz->section;

        return $section->canManage($user)
            || ($quiz->isOpen() && $section->canView($user));
    }

    /** Start or submit an attempt: enrolled students, published quizzes. Timing is checked in the controller. */
    public function take(User $user, Quiz $quiz): bool
    {
        return $quiz->published_at !== null && $quiz->section->isStudent($user);
    }

    public function update(User $user, Quiz $quiz): bool
    {
        return $quiz->section->canManage($user);
    }
}
