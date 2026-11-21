<?php

namespace App\Policies;

use App\Models\QuizAttempt;
use App\Models\User;

class QuizAttemptPolicy
{
    public function grade(User $user, QuizAttempt $attempt): bool
    {
        return $attempt->quiz->section->canManage($user);
    }
}
