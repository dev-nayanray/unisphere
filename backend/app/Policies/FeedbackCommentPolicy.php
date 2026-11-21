<?php

namespace App\Policies;

use App\Models\FeedbackComment;
use App\Models\User;

class FeedbackCommentPolicy
{
    /** A comment bank is private to its teacher. */
    public function delete(User $user, FeedbackComment $feedback): bool
    {
        return $feedback->teacher_id === $user->id;
    }
}
