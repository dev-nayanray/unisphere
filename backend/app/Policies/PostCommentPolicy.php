<?php

namespace App\Policies;

use App\Models\PostComment;
use App\Models\User;

class PostCommentPolicy
{
    public function update(User $user, PostComment $comment): bool
    {
        return $comment->author_id === $user->id;
    }

    /** The author, or the class teacher (moderation). */
    public function delete(User $user, PostComment $comment): bool
    {
        return $comment->author_id === $user->id || $comment->post->section->canManage($user);
    }
}
